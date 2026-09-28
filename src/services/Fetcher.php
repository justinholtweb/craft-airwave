<?php

namespace justinholtweb\airwave\services;

use Craft;
use craft\helpers\App;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\exceptions\FeedException;
use justinholtweb\airwave\models\FetchResult;
use justinholtweb\airwave\Plugin;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use yii\base\Component;

/**
 * Getting bytes off the internet, carefully.
 *
 * Three things this does that a bare `file_get_contents()` does not, and all three are the reason
 * it is a service rather than three lines in the parser:
 *
 * 1. **Conditional GET.** ETag and `Last-Modified` are stored per feed and sent back, so a feed
 *    that has not changed costs a header exchange rather than a megabyte. Most feeds have not
 *    changed most of the time, and publishers notice aggregators that ignore this.
 * 2. **An SSRF guard.** A feed URL is user input in any site where a non-admin can add one, and
 *    `http://169.254.169.254/latest/meta-data/` is how that ends badly. Every hop of a redirect
 *    chain is re-checked, because checking only the first one is the same as not checking.
 * 3. **A size ceiling.** The response is streamed and cut off at
 *    {@see \justinholtweb\airwave\models\Settings::$maxResponseBytes}, so a misconfigured endpoint
 *    that answers with a 2GB log file exhausts a chunk of memory rather than all of it.
 */
class Fetcher extends Component
{
    /**
     * Whether a URL is one Airwave is willing to open.
     *
     * @return string|null The reason it is not, or null if it is fine.
     */
    public function validateUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return Craft::t('airwave', 'Enter a feed URL.');
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return Craft::t('airwave', 'That does not look like a URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            return Craft::t('airwave', 'Feed URLs have to be http or https.');
        }

        if (!$this->hostIsAllowed($parts['host'])) {
            return Craft::t('airwave', 'That address is on a private or reserved network. Turn on “Allow private network fetches” in Airwave’s settings if this is an internal feed.');
        }

        return null;
    }

    /**
     * @throws FeedException
     */
    public function fetch(Source $source, bool $conditional = true): FetchResult
    {
        $url = trim((string)$source->url);

        if (($error = $this->validateUrl($url)) !== null) {
            throw new FeedException($error);
        }

        $settings = Plugin::getInstance()->getSettings();
        $headers = $this->headers($source, $conditional);

        try {
            $response = $this->client()->request('GET', $url, [
                'headers' => $headers,
                'timeout' => $settings->requestTimeout,
                'connect_timeout' => min(10, $settings->requestTimeout),
                'stream' => true,
                'http_errors' => false,
                'decode_content' => true,
                'allow_redirects' => [
                    'max' => 5,
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['http', 'https'],
                    'track_redirects' => true,
                    // Checking only the URL an author typed is the same as not checking: an open
                    // redirect on a public host is the standard way past a naive allowlist.
                    'on_redirect' => function(RequestInterface $request, ResponseInterface $response, UriInterface $uri) {
                        if (!$this->hostIsAllowed($uri->getHost())) {
                            throw new FeedException(Craft::t('airwave', 'That feed redirected to a private address, which Airwave will not follow.'));
                        }
                    },
                ],
            ]);
        } catch (ConnectException $e) {
            throw FeedException::transient(Craft::t('airwave', 'Could not reach the server: {message}', ['message' => $e->getMessage()]), $e);
        } catch (FeedException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw FeedException::transient($e->getMessage(), $e);
        }

        $status = $response->getStatusCode();

        if ($status === 304) {
            return new FetchResult(notModified: true, statusCode: 304, finalUrl: $url);
        }

        if ($status >= 400) {
            $message = Craft::t('airwave', 'The server answered {status}.', ['status' => $status]);

            // 5xx and 429 are somebody else's bad afternoon; 404 is a feed that has moved.
            throw $status >= 500 || $status === 429
                ? FeedException::transient($message)
                : new FeedException($message);
        }

        $body = $this->readCapped($response, $settings->maxResponseBytes);

        if (trim($body) === '') {
            throw new FeedException(Craft::t('airwave', 'The server answered with an empty document.'));
        }

        $redirects = $response->getHeader('X-Guzzle-Redirect-History');

        return new FetchResult(
            body: $body,
            statusCode: $status,
            contentType: $response->getHeaderLine('Content-Type') ?: null,
            etag: $response->getHeaderLine('ETag') ?: null,
            lastModified: $response->getHeaderLine('Last-Modified') ?: null,
            finalUrl: $redirects ? (string)end($redirects) : $url,
            bytes: strlen($body),
        );
    }

    /**
     * Fetches a URL that is not a subscribed feed yet — used by feed discovery, where an author
     * pastes a site address and expects Airwave to work out where its feed is.
     *
     * @throws FeedException
     */
    public function fetchUrl(string $url): FetchResult
    {
        $source = new Source();
        $source->url = $url;

        return $this->fetch($source, false);
    }

    /**
     * @return array<string, string>
     */
    private function headers(Source $source, bool $conditional): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $headers = [
            'User-Agent' => $settings->getUserAgent(),
            'Accept' => 'application/atom+xml, application/rss+xml, application/feed+json, application/xml;q=0.9, text/xml;q=0.9, application/json;q=0.8, */*;q=0.5',
            'Accept-Encoding' => 'gzip, deflate',
        ];

        if ($conditional) {
            if ($source->etag) {
                $headers['If-None-Match'] = $source->etag;
            }

            if ($source->lastModifiedHeader) {
                $headers['If-Modified-Since'] = $source->lastModifiedHeader;
            }
        }

        // Values go through `parseEnv`, which is the whole point: a private feed is subscribed to
        // with `Authorization: $FEED_TOKEN` and the token stays in `.env`, not in the database and
        // not in a database backup somebody emails around.
        foreach ((array)($source->getFetchOptions()['headers'] ?? []) as $name => $value) {
            if (is_string($name) && $name !== '' && is_string($value) && !self::isForbiddenHeader($name)) {
                $headers[$name] = App::parseEnv($value) ?? $value;
            }
        }

        return $headers;
    }

    /**
     * Headers that describe the connection rather than the request, so an author may not set them.
     *
     * `Host` above all: overriding it sends the request to one server while claiming to be for
     * another, which is how a URL that passed the private-network check reaches a virtual host it
     * was never meant to.
     */
    public static function isForbiddenHeader(string $name): bool
    {
        return in_array(strtolower(trim($name)), [
            'host', 'content-length', 'transfer-encoding', 'connection', 'keep-alive', 'upgrade',
            'te', 'trailer', 'proxy-connection',
        ], true);
    }

    /**
     * Reads at most `$max` bytes off the response body.
     *
     * `Content-Length` is checked first when the server bothered to send one, so an oversized feed
     * usually costs one header exchange rather than a full download to the ceiling.
     *
     * @throws FeedException
     */
    private function readCapped(ResponseInterface $response, int $max): string
    {
        $declared = (int)$response->getHeaderLine('Content-Length');

        if ($declared > $max) {
            throw new FeedException(Craft::t('airwave', 'That feed is larger than the {size} limit.', [
                'size' => Craft::$app->getFormatter()->asShortSize($max),
            ]));
        }

        $stream = $response->getBody();
        $body = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;

            if (strlen($body) > $max) {
                $stream->close();

                throw new FeedException(Craft::t('airwave', 'That feed is larger than the {size} limit.', [
                    'size' => Craft::$app->getFormatter()->asShortSize($max),
                ]));
            }
        }

        $stream->close();

        return $body;
    }

    /**
     * Whether a host resolves somewhere Airwave is willing to talk to.
     *
     * A literal IP is checked directly; a name is resolved and *every* address it resolves to has
     * to pass, because a name with one public and one private A record would otherwise be a way
     * through. This is not proof against DNS rebinding — the name is resolved again by the socket
     * — but that is a much narrower window than an unchecked URL, and closing it entirely means
     * pinning connections to an IP, which breaks CDN-hosted feeds.
     */
    private function hostIsAllowed(string $host): bool
    {
        if (Plugin::getInstance()->getSettings()->allowPrivateNetworkFetches) {
            return true;
        }

        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->ipIsPublic($host);
        }

        // `localhost` and friends never make it as far as a DNS lookup on some systems.
        if (preg_match('/(^|\.)(localhost|local|internal|localdomain)$/i', $host)) {
            return false;
        }

        $addresses = $this->resolve($host);

        if (!$addresses) {
            // Unresolvable. Let the request go and fail with a connection error, which is a much
            // more useful message to an author than "private address".
            return true;
        }

        foreach ($addresses as $address) {
            if (!$this->ipIsPublic($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    /**
     * Ranges PHP's `NO_PRIV_RANGE | NO_RES_RANGE` flags do not cover, or cover only on some PHP
     * versions. Carrier-grade NAT is where some clouds put their metadata service
     * (`100.100.100.200`), and the IPv6 transition prefixes embed an IPv4 address that may be
     * private.
     */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24',
        '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    private function ipIsPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        // `::ffff:10.0.0.1` and `::10.0.0.1` are IPv4 addresses wearing IPv6 clothes; judge the
        // address inside.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10))) {
            $tail = substr($packed, 10, 2);

            if ($tail === "\xff\xff" || $tail === "\0\0") {
                $ip = (string)inet_ntop(substr($packed, 12));
                $packed = substr($packed, 12);
            }
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    private function inRange(string $packed, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $subnet = inet_pton($subnet);

        if ($subnet === false || strlen($subnet) !== strlen($packed)) {
            return false;
        }

        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);

        if (strncmp($packed, $subnet, $bytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($subnet[$bytes]) & $mask);
    }

    /**
     * Downloads a file to a temporary path, for the importer's image handling.
     *
     * Same guards as a feed fetch — scheme, private networks, redirect hops, size ceiling — for
     * exactly the same reason: the URL came out of somebody else's document.
     *
     * @return string|null The path, or null if the download was refused or failed. The caller owns
     * the file and is responsible for removing it.
     */
    public function downloadToTemp(string $url, int $maxBytes = 20971520): ?string
    {
        if ($this->validateUrl($url) !== null) {
            return null;
        }

        $settings = Plugin::getInstance()->getSettings();
        $path = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'airwave_' . md5($url . microtime(true));

        try {
            $response = $this->client()->request('GET', $url, [
                'headers' => ['User-Agent' => $settings->getUserAgent()],
                'timeout' => $settings->requestTimeout,
                'connect_timeout' => min(10, $settings->requestTimeout),
                'stream' => true,
                'http_errors' => false,
                'allow_redirects' => [
                    'max' => 5,
                    'protocols' => ['http', 'https'],
                    'on_redirect' => function(RequestInterface $request, ResponseInterface $response, UriInterface $uri) {
                        if (!$this->hostIsAllowed($uri->getHost())) {
                            throw new FeedException('Refusing to follow a redirect to a private address.');
                        }
                    },
                ],
            ]);

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $body = $this->readCapped($response, $maxBytes);
        } catch (FeedException|GuzzleException $e) {
            Craft::warning("Airwave could not download $url: {$e->getMessage()}", 'airwave');

            return null;
        }

        if ($body === '' || file_put_contents($path, $body) === false) {
            return null;
        }

        return $path;
    }

    private function client(): \GuzzleHttp\Client
    {
        return Craft::createGuzzleClient();
    }
}
