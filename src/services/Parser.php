<?php

namespace justinholtweb\airwave\services;

use Craft;
use craft\helpers\FileHelper;
use DateTime;
use DateTimeZone;
use HTMLPurifier;
use HTMLPurifier_Config;
use justinholtweb\airwave\exceptions\FeedException;
use justinholtweb\airwave\models\FeedMeta;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\models\ParsedFeed;
use justinholtweb\airwave\Plugin;
use SimpleXMLElement;
use Throwable;
use Twig\Markup;
use yii\base\Component;

/**
 * Four dialects, one shape.
 *
 * RSS 2.0, RSS 1.0 (RDF), Atom 1.0 and JSON Feed all come out as {@see ParsedFeed} — a
 * {@see FeedMeta} and a list of {@see Item}s — so nothing downstream ever asks what kind of feed
 * something was. That is the whole design: the filters, the store, the importer, the renderer and
 * the broadcaster each exist once instead of four times.
 *
 * Written against libxml directly rather than pulling in SimplePie. A feed parser is about 400
 * lines of field-mapping and about 40 lines of judgement; the judgement is the part worth owning,
 * and a dependency that has to be kept current for security is a poor trade for the mapping.
 *
 * ### The judgement, in one place
 *
 * - **Never trust the extension or the `Content-Type`.** Feeds are served as `text/html`,
 *   `text/plain` and `application/octet-stream` constantly. The document is sniffed.
 * - **No DOCTYPE, ever.** A feed with a document type declaration is refused outright rather than
 *   parsed with entity loading disabled, because "disabled" is a property of a php.ini somebody
 *   else controls, and the billion-laughs attack costs nothing to send.
 * - **Missing dates are missing, not now.** An item with no date gets null, and the store sorts
 *   it by when Airwave first saw it. Defaulting to `now()` makes every backfilled item pretend to
 *   be breaking news.
 * - **Relative URLs are resolved.** Feeds carrying `/2026/01/post` in `<link>` are not rare.
 */
class Parser extends Component
{
    public const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    public const NS_DC = 'http://purl.org/dc/elements/1.1/';
    public const NS_ATOM = 'http://www.w3.org/2005/Atom';
    public const NS_MEDIA = 'http://search.yahoo.com/mrss/';
    public const NS_ITUNES = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    public const NS_SLASH = 'http://purl.org/rss/1.0/modules/slash/';

    private ?HTMLPurifier $_purifier = null;

    /**
     * @param string $body The downloaded document.
     * @param string|null $baseUrl Where it came from, for resolving relative links.
     * @throws FeedException
     */
    public function parse(string $body, ?string $baseUrl = null): ParsedFeed
    {
        $body = $this->stripPreamble($body);

        if ($body === '') {
            throw new FeedException(Craft::t('airwave', 'That document is empty.'));
        }

        if ($body[0] === '{' || $body[0] === '[') {
            return $this->parseJson($body, $baseUrl);
        }

        return $this->parseXml($body, $baseUrl);
    }

    /**
     * Feed URLs hiding in an HTML page.
     *
     * The reason this exists: nobody knows their own feed URL. They know their site address, and
     * every blogging platform in existence advertises the feed in a `<link rel="alternate">`. An
     * author pastes `example.com`, Airwave finds `example.com/feed`.
     *
     * @return array<int, array{url: string, title: string|null, type: string|null}>
     */
    public function discover(string $html, string $baseUrl): array
    {
        $found = [];

        if (!preg_match_all('/<link\b[^>]*>/i', $html, $matches)) {
            return [];
        }

        foreach ($matches[0] as $tag) {
            $rel = $this->attr($tag, 'rel');
            $type = $this->attr($tag, 'type');
            $href = $this->attr($tag, 'href');

            if (!$href || !$rel || !preg_match('/\balternate\b/i', $rel)) {
                continue;
            }

            if (!$type || !preg_match('#^application/(rss|atom|feed)\+(xml|json)|^application/json$#i', $type)) {
                continue;
            }

            $url = $this->absoluteUrl(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $baseUrl);

            if ($url && !isset($found[$url])) {
                $found[$url] = [
                    'url' => $url,
                    'title' => $this->attr($tag, 'title'),
                    'type' => $type,
                ];
            }
        }

        return array_values($found);
    }

    /**
     * Somebody else's HTML, made safe to put on your page.
     *
     * Everything that renders item markup comes through here. There is no "raw" path on purpose:
     * the whole point of an aggregator is that the markup is written by a server you do not
     * control, and one `|raw` in one template is a stored XSS on every page that lists the feed.
     */
    public function purify(string $html): Markup
    {
        if (trim($html) === '') {
            return new Markup('', Craft::$app->charset);
        }

        if (!Plugin::getInstance()->getSettings()->purifyContent) {
            return new Markup($html, Craft::$app->charset);
        }

        return new Markup($this->purifier()->purify($html), Craft::$app->charset);
    }

    // XML
    // -------------------------------------------------------------------------

    /**
     * @throws FeedException
     */
    private function parseXml(string $body, ?string $baseUrl): ParsedFeed
    {
        // A feed has no legitimate reason to declare a document type, and every illegitimate one:
        // external entities read local files, nested entities exhaust memory. Refuse rather than
        // rely on a libxml setting.
        if (preg_match('/<!DOCTYPE/i', substr($body, 0, 2048))) {
            throw new FeedException(Craft::t('airwave', 'That document declares a DOCTYPE, which Airwave will not parse.'));
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        } finally {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            $first = $errors[0] ?? null;

            throw new FeedException(Craft::t('airwave', 'That does not parse as XML{detail}.', [
                'detail' => $first ? ' (' . trim($first->message) . ', line ' . $first->line . ')' : '',
            ]));
        }

        $root = $xml->getName();

        return match (true) {
            $root === 'rss' => $this->parseRss2($xml, $baseUrl),
            $root === 'RDF' => $this->parseRdf($xml, $baseUrl),
            $root === 'feed' => $this->parseAtom($xml, $baseUrl),
            // Some feeds omit the `<rss>` wrapper entirely and start at `<channel>`.
            $root === 'channel' => $this->parseRss2Channel($xml, $baseUrl, FeedMeta::FORMAT_RSS),
            default => throw new FeedException(Craft::t('airwave', 'That is XML, but not a feed Airwave recognises (root element “{root}”).', ['root' => $root])),
        };
    }

    private function parseRss2(SimpleXMLElement $xml, ?string $baseUrl): ParsedFeed
    {
        if (!isset($xml->channel)) {
            return new ParsedFeed($this->emptyMeta(FeedMeta::FORMAT_RSS));
        }

        return $this->parseRss2Channel($xml->channel, $baseUrl, FeedMeta::FORMAT_RSS);
    }

    private function parseRss2Channel(SimpleXMLElement $channel, ?string $baseUrl, string $format): ParsedFeed
    {
        $meta = new FeedMeta();
        $meta->format = $format;
        $meta->title = $this->text($channel->title);
        $meta->link = $this->absoluteUrl($this->text($channel->link), $baseUrl);
        $meta->description = $this->text($channel->description);
        $meta->language = $this->text($channel->language);
        $meta->generator = $this->text($channel->generator);
        $meta->updatedAt = $this->date($this->text($channel->lastBuildDate) ?? $this->text($channel->pubDate));

        if (($ttl = $this->text($channel->ttl)) !== null && ctype_digit($ttl)) {
            $meta->ttl = (int)$ttl;
        }

        if (isset($channel->image->url)) {
            $meta->imageUrl = $this->absoluteUrl($this->text($channel->image->url), $baseUrl);
        }

        $itunes = $channel->children(self::NS_ITUNES);

        if (!$meta->imageUrl && isset($itunes->image)) {
            $meta->imageUrl = $this->absoluteUrl((string)$itunes->image->attributes()->href, $baseUrl);
        }

        $base = $meta->link ?: $baseUrl;
        $items = [];

        foreach ($channel->item as $node) {
            $items[] = $this->parseRssItem($node, $base);
        }

        return new ParsedFeed($meta, $items);
    }

    /**
     * RSS 1.0, which is RDF wearing a feed costume.
     *
     * Items are siblings of `<channel>` rather than children of it, which is the only structural
     * difference that matters — the element names are the same, so the item parser is shared.
     */
    private function parseRdf(SimpleXMLElement $xml, ?string $baseUrl): ParsedFeed
    {
        $meta = new FeedMeta();
        $meta->format = FeedMeta::FORMAT_RDF;

        if (isset($xml->channel)) {
            $meta->title = $this->text($xml->channel->title);
            $meta->link = $this->absoluteUrl($this->text($xml->channel->link), $baseUrl);
            $meta->description = $this->text($xml->channel->description);
        }

        if (isset($xml->image->url)) {
            $meta->imageUrl = $this->absoluteUrl($this->text($xml->image->url), $baseUrl);
        }

        $base = $meta->link ?: $baseUrl;
        $items = [];

        foreach ($xml->item as $node) {
            $items[] = $this->parseRssItem($node, $base);
        }

        return new ParsedFeed($meta, $items);
    }

    private function parseRssItem(SimpleXMLElement $node, ?string $baseUrl): Item
    {
        $content = $node->children(self::NS_CONTENT);
        $dc = $node->children(self::NS_DC);
        $media = $node->children(self::NS_MEDIA);

        $item = new Item();
        $item->title = $this->text($node->title);
        $item->url = $this->absoluteUrl($this->text($node->link) ?? $this->rdfAbout($node), $baseUrl);
        $item->summary = $this->text($node->description);
        $item->content = $this->text($content->encoded ?? null);
        $item->author = $this->cleanAuthor($this->text($node->author) ?? $this->text($dc->creator ?? null));
        $item->publishedAt = $this->date($this->text($node->pubDate) ?? $this->text($dc->date ?? null));

        // `<guid>` is the feed's own identity for the item, and the only thing worth deduping on.
        // Falling back to the link is standard; falling back to the title is a last resort that
        // makes a retitled item look new, which is better than every item looking new.
        $item->guid = $this->text($node->guid) ?? $this->rdfAbout($node) ?? $item->url ?? $item->title;

        foreach ($node->category as $category) {
            $value = $this->text($category);

            if ($value !== null) {
                $item->categories[] = $value;
            }
        }

        if (isset($node->enclosure)) {
            $attributes = $node->enclosure->attributes();
            $item->enclosureUrl = $this->absoluteUrl((string)($attributes->url ?? ''), $baseUrl);
            $item->enclosureType = ((string)($attributes->type ?? '')) ?: null;
            $length = (int)($attributes->length ?? 0);
            $item->enclosureLength = $length ?: null;
        }

        $item->imageUrl = $this->rssImage($node, $media, $baseUrl) ?? $this->firstImageIn($item->content ?? $item->summary, $baseUrl);

        return $this->finish($item);
    }

    /**
     * Where an RSS item's image actually lives, in order of how much the publisher meant it.
     */
    private function rssImage(SimpleXMLElement $node, SimpleXMLElement $media, ?string $baseUrl): ?string
    {
        if (isset($media->thumbnail)) {
            $url = (string)$media->thumbnail->attributes()->url;

            if ($url !== '') {
                return $this->absoluteUrl($url, $baseUrl);
            }
        }

        foreach ($media->content ?? [] as $content) {
            $attributes = $content->attributes();
            $medium = (string)($attributes->medium ?? '');
            $type = (string)($attributes->type ?? '');

            if ($medium === 'image' || str_starts_with($type, 'image/')) {
                $url = (string)($attributes->url ?? '');

                if ($url !== '') {
                    return $this->absoluteUrl($url, $baseUrl);
                }
            }
        }

        $itunes = $node->children(self::NS_ITUNES);

        if (isset($itunes->image)) {
            $url = (string)$itunes->image->attributes()->href;

            if ($url !== '') {
                return $this->absoluteUrl($url, $baseUrl);
            }
        }

        if (isset($node->enclosure)) {
            $attributes = $node->enclosure->attributes();

            if (str_starts_with((string)($attributes->type ?? ''), 'image/')) {
                return $this->absoluteUrl((string)($attributes->url ?? ''), $baseUrl);
            }
        }

        return null;
    }

    private function parseAtom(SimpleXMLElement $xml, ?string $baseUrl): ParsedFeed
    {
        $meta = new FeedMeta();
        $meta->format = FeedMeta::FORMAT_ATOM;
        $meta->title = $this->text($xml->title);
        $meta->description = $this->text($xml->subtitle);
        $meta->link = $this->atomLink($xml, 'alternate', $baseUrl) ?? $this->absoluteUrl($this->text($xml->id), $baseUrl);
        $meta->updatedAt = $this->date($this->text($xml->updated));
        $meta->generator = $this->text($xml->generator);
        $meta->iconUrl = $this->absoluteUrl($this->text($xml->icon), $baseUrl);
        $meta->imageUrl = $this->absoluteUrl($this->text($xml->logo), $baseUrl) ?? $meta->iconUrl;
        $meta->language = $this->xmlLang($xml);

        $base = $meta->link ?: $baseUrl;
        $items = [];

        foreach ($xml->entry as $node) {
            $items[] = $this->parseAtomEntry($node, $base);
        }

        return new ParsedFeed($meta, $items);
    }

    private function parseAtomEntry(SimpleXMLElement $node, ?string $baseUrl): Item
    {
        $item = new Item();
        $item->title = $this->text($node->title);
        $item->url = $this->atomLink($node, 'alternate', $baseUrl);
        $item->summary = $this->text($node->summary);
        $item->content = $this->atomContent($node);
        $item->guid = $this->text($node->id) ?? $item->url ?? $item->title;
        // `published` is when it was published; `updated` is required and often the only one
        // present. Prefer the former and fall back, rather than showing everything as edited today.
        $item->publishedAt = $this->date($this->text($node->published) ?? $this->text($node->updated));

        if (isset($node->author->name)) {
            $item->author = $this->cleanAuthor($this->text($node->author->name));
        }

        foreach ($node->category as $category) {
            $term = (string)($category->attributes()->term ?? '');

            if ($term !== '') {
                $item->categories[] = $term;
            }
        }

        $enclosure = $this->atomLinkNode($node, 'enclosure');

        if ($enclosure) {
            $attributes = $enclosure->attributes();
            $item->enclosureUrl = $this->absoluteUrl((string)($attributes->href ?? ''), $baseUrl);
            $item->enclosureType = ((string)($attributes->type ?? '')) ?: null;
            $length = (int)($attributes->length ?? 0);
            $item->enclosureLength = $length ?: null;
        }

        $media = $node->children(self::NS_MEDIA);
        $item->imageUrl = $this->rssImage($node, $media, $baseUrl) ?? $this->firstImageIn($item->content ?? $item->summary, $baseUrl);

        return $this->finish($item);
    }

    /**
     * Atom `<content>` can be text, HTML, or XHTML inlined as real child elements — and the third
     * one is the reason this is not `(string)$node->content`.
     */
    private function atomContent(SimpleXMLElement $node): ?string
    {
        if (!isset($node->content)) {
            return null;
        }

        $type = (string)($node->content->attributes()->type ?? 'text');

        if ($type === 'xhtml') {
            $inner = '';

            foreach ($node->content->children('http://www.w3.org/1999/xhtml') as $child) {
                $inner .= $child->asXML();
            }

            // A single wrapping `<div>` is what the spec asks for; unwrap it so the stored content
            // is the content rather than the container.
            if (preg_match('#^\s*<div[^>]*>(.*)</div>\s*$#s', $inner, $matches)) {
                $inner = $matches[1];
            }

            return trim($inner) !== '' ? $inner : null;
        }

        return $this->text($node->content);
    }

    private function atomLink(SimpleXMLElement $node, string $rel, ?string $baseUrl): ?string
    {
        $link = $this->atomLinkNode($node, $rel);

        return $link ? $this->absoluteUrl((string)($link->attributes()->href ?? ''), $baseUrl) : null;
    }

    private function atomLinkNode(SimpleXMLElement $node, string $rel): ?SimpleXMLElement
    {
        $fallback = null;

        foreach ($node->link as $link) {
            $linkRel = (string)($link->attributes()->rel ?? 'alternate');

            if ($linkRel === $rel) {
                return $link;
            }

            // A `<link>` with no rel is an alternate by definition.
            if ($rel === 'alternate' && $linkRel === 'alternate' && !$fallback) {
                $fallback = $link;
            }
        }

        return $fallback;
    }

    private function xmlLang(SimpleXMLElement $node): ?string
    {
        $attributes = $node->attributes('xml', true);

        return isset($attributes->lang) ? (string)$attributes->lang : null;
    }

    private function rdfAbout(SimpleXMLElement $node): ?string
    {
        $attributes = $node->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $about = isset($attributes->about) ? (string)$attributes->about : '';

        return $about !== '' ? $about : null;
    }

    // JSON Feed
    // -------------------------------------------------------------------------

    /**
     * @throws FeedException
     */
    private function parseJson(string $body, ?string $baseUrl): ParsedFeed
    {
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new FeedException(Craft::t('airwave', 'That does not parse as JSON.'));
        }

        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new FeedException(Craft::t('airwave', 'That is JSON, but not a JSON Feed — it has no “items”.'));
        }

        $meta = new FeedMeta();
        $meta->format = FeedMeta::FORMAT_JSON;
        $meta->title = $this->str($data['title'] ?? null);
        $meta->link = $this->absoluteUrl($this->str($data['home_page_url'] ?? null), $baseUrl);
        $meta->description = $this->str($data['description'] ?? null);
        $meta->language = $this->str($data['language'] ?? null);
        $meta->imageUrl = $this->absoluteUrl($this->str($data['icon'] ?? null), $baseUrl);
        $meta->iconUrl = $this->absoluteUrl($this->str($data['favicon'] ?? null), $baseUrl) ?? $meta->imageUrl;

        $base = $meta->link ?: $baseUrl;
        $items = [];

        foreach ($data['items'] as $entry) {
            if (is_array($entry)) {
                $items[] = $this->parseJsonItem($entry, $base);
            }
        }

        return new ParsedFeed($meta, $items);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function parseJsonItem(array $entry, ?string $baseUrl): Item
    {
        $item = new Item();
        $item->title = $this->str($entry['title'] ?? null);
        $item->url = $this->absoluteUrl($this->str($entry['url'] ?? null), $baseUrl);
        $item->guid = $this->str($entry['id'] ?? null) ?? $item->url ?? $item->title;
        $item->summary = $this->str($entry['summary'] ?? null);
        $item->content = $this->str($entry['content_html'] ?? null) ?? $this->str($entry['content_text'] ?? null);
        $item->publishedAt = $this->date($this->str($entry['date_published'] ?? null) ?? $this->str($entry['date_modified'] ?? null));
        $item->imageUrl = $this->absoluteUrl($this->str($entry['image'] ?? null) ?? $this->str($entry['banner_image'] ?? null), $baseUrl);

        // JSON Feed 1.0 had `author`, 1.1 has `authors`. Both are still in the wild.
        $author = $entry['author']['name'] ?? ($entry['authors'][0]['name'] ?? null);
        $item->author = $this->cleanAuthor($this->str($author));

        foreach ((array)($entry['tags'] ?? []) as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $item->categories[] = trim($tag);
            }
        }

        $attachment = $entry['attachments'][0] ?? null;

        if (is_array($attachment) && !empty($attachment['url'])) {
            $item->enclosureUrl = $this->absoluteUrl($this->str($attachment['url']), $baseUrl);
            $item->enclosureType = $this->str($attachment['mime_type'] ?? null);
            $item->enclosureLength = isset($attachment['size_in_bytes']) ? (int)$attachment['size_in_bytes'] : null;
        }

        $item->imageUrl ??= $this->firstImageIn($item->content, $baseUrl);

        return $this->finish($item);
    }

    // Shared
    // -------------------------------------------------------------------------

    /**
     * The last word on every parsed item, whatever dialect it came from.
     */
    private function finish(Item $item): Item
    {
        $item->title = $item->title !== null ? $this->collapse($item->title) : null;
        $item->author = $item->author !== null ? $this->collapse($item->author) : null;
        $item->categories = array_values(array_unique(array_filter(array_map(
            fn($c) => $this->collapse($c),
            $item->categories,
        ))));

        // Feeds routinely put the whole article in `<description>` and nothing in
        // `content:encoded`. Treating that as a summary means "full content" shows less than
        // "summary" does, so promote it and let the renderer truncate.
        if ($item->content === null && $item->summary !== null && mb_strlen($item->summary) > 600) {
            $item->content = $item->summary;
        }

        return $item;
    }

    private function stripPreamble(string $body): string
    {
        // A UTF-8 BOM before `<?xml` makes libxml refuse the whole document, and enough feeds are
        // served with one that this is not a theoretical concern.
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;

        return trim($body);
    }

    private function text(mixed $node): ?string
    {
        if ($node === null) {
            return null;
        }

        $value = trim((string)$node);

        return $value !== '' ? $value : null;
    }

    private function str(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * Strips the `"Name" <email>` and `email (Name)` forms RSS `<author>` is specified as.
     *
     * Publishing somebody's address on a page that aggregates their feed is rude at best.
     */
    private function cleanAuthor(?string $author): ?string
    {
        if ($author === null) {
            return null;
        }

        if (preg_match('/^\s*\S+@\S+\s*\((.+)\)\s*$/', $author, $matches)) {
            return trim($matches[1]);
        }

        $author = preg_replace('/<[^>]*@[^>]*>/', '', $author) ?? $author;
        $author = trim($author, " \t\"'");

        // Nothing but an address left — better to show nothing than a harvestable mailto.
        if (filter_var($author, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $author !== '' ? $author : null;
    }

    /**
     * Feeds carry dates in RFC 822, RFC 3339, and a long tail of near-misses. `strtotime()`
     * understands nearly all of them; the ones it does not are not worth guessing at, because a
     * wrong date sorts an item to the top of somebody's page forever.
     */
    private function date(?string $value): ?DateTime
    {
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        // Feeds with clock skew, or a publisher scheduling posts, are common enough that a date a
        // few days in the future is fine. A date in 2087 is a parse that went wrong.
        if ($timestamp > time() + 31536000) {
            return null;
        }

        return (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The first `<img>` in the item's own markup.
     *
     * Last resort, after every field a publisher could have used deliberately — but the difference
     * between a card layout that works and one that does not, because a great many feeds carry
     * their image only in the body.
     */
    private function firstImageIn(?string $html, ?string $baseUrl): ?string
    {
        if (!$html || !preg_match('/<img\b[^>]*>/i', $html, $matches)) {
            return null;
        }

        $src = $this->attr($matches[0], 'src') ?? $this->attr($matches[0], 'data-src');

        if (!$src) {
            return null;
        }

        // Tracking pixels and spacers, which is what a 1x1 in a feed body always is. Compared
        // numerically rather than by pattern: `width="600"` matches a "one or two digits" regex
        // on its first two characters and would throw away every real image in the feed.
        if (preg_match('/\b(?:width|height)\s*=\s*["\']?(\d+)/i', $matches[0], $size) && (int)$size[1] <= 2) {
            return null;
        }

        return $this->absoluteUrl(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $baseUrl);
    }

    private function attr(string $tag, string $name): ?string
    {
        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $matches)) {
            $value = $matches[2] ?: ($matches[3] ?: ($matches[4] ?? ''));

            return trim($value) !== '' ? trim($value) : null;
        }

        return null;
    }

    /**
     * Resolves a possibly-relative URL against the feed's own address.
     *
     * Also the gate that keeps `javascript:` out of an `href` the renderer is about to write.
     */
    public function absoluteUrl(?string $url, ?string $baseUrl): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            return preg_match('#^(https?|mailto):#i', $url) ? $url : null;
        }

        if (!$baseUrl) {
            return null;
        }

        $base = parse_url($baseUrl);

        if (empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($url, '//')) {
            return $base['scheme'] . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        $path = $base['path'] ?? '/';
        $directory = substr($path, 0, (int)strrpos($path, '/') + 1) ?: '/';

        return $origin . $directory . $url;
    }

    /**
     * Purifier configuration for feed content.
     *
     * Wider than a comment field and narrower than a rich-text field: an item legitimately
     * contains images, figures, iframes for embedded video, and headings. It never legitimately
     * contains a form, a style block, or an `id` that could collide with the host page.
     */
    private function purifier(): HTMLPurifier
    {
        if ($this->_purifier !== null) {
            return $this->_purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->autoFinalize = false;

        // HTML Purifier writes its compiled definitions here and throws rather than creating the
        // directory itself, which turns the first purified item on a fresh install into a 500.
        $cachePath = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'htmlpurifier';
        FileHelper::createDirectory($cachePath);
        $config->set('Cache.SerializerPath', $cachePath);

        $options = [
            'Attr.AllowedFrameTargets' => ['_blank'],
            // Off, unlike Craft's rich-text default: these ids come from another site and would
            // collide with your page's own.
            'Attr.EnableID' => false,
            'HTML.SafeIframe' => true,
            'URI.SafeIframeRegexp' => '%^(https?:)?//(www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%',
            'HTML.ForbiddenElements' => ['form', 'input', 'button', 'style', 'script'],
            'CSS.AllowedProperties' => [],
            'AutoFormat.RemoveEmpty' => true,
        ];

        $file = Plugin::getInstance()->getSettings()->purifierConfig;

        if ($file) {
            $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'htmlpurifier' . DIRECTORY_SEPARATOR . $file . '.json';

            if (is_file($path)) {
                $decoded = json_decode(file_get_contents($path), true);

                if (is_array($decoded)) {
                    $options = $decoded;
                }
            }
        }

        foreach ($options as $option => $value) {
            try {
                $config->set($option, $value);
            } catch (Throwable $e) {
                Craft::warning("Airwave ignored HTML Purifier option $option: {$e->getMessage()}", 'airwave');
            }
        }

        $config->finalize();

        return $this->_purifier = new HTMLPurifier($config);
    }

    private function emptyMeta(string $format): FeedMeta
    {
        $meta = new FeedMeta();
        $meta->format = $format;

        return $meta;
    }
}
