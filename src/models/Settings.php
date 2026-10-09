<?php

namespace justinholtweb\airwave\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings.
 *
 * Everything here is a *default* that a single feed can override, with two exceptions that are
 * deliberately site-wide because they are safety rails rather than preferences:
 * {@see self::$allowPrivateNetworkFetches} and {@see self::$purifyContent}. A feed is somebody
 * else's HTML arriving on your page; the decision to trust it is not one an editor should be
 * able to make per feed from the CP.
 *
 * No setting is marked `required` — a required plugin setting blocks a fresh install, because
 * Craft validates the settings model before an admin has ever seen the screen.
 */
class Settings extends Model
{
    /** How long a feed's cached items stay fresh, in seconds. */
    public int $defaultRefreshInterval = 3600;

    /** How many items a template gets when it does not ask for a number. */
    public int $defaultItemLimit = 10;

    /**
     * How many items to keep per feed. Older ones are pruned on refresh.
     *
     * Items are cheap rows, but a busy feed republished hourly for a year is 100k of them, and
     * nobody has ever read item 9,000. Imported items are never pruned — their entries point at
     * them, and a pruned row would re-import as a duplicate.
     */
    public int $keepItemsPerSource = 200;

    /** Seconds to wait on the remote server before giving up. */
    public int $requestTimeout = 10;

    /** Hard ceiling on a downloaded feed, in bytes. */
    public int $maxResponseBytes = 5242880;

    /** Sent as `User-Agent`. Blank composes one from the site name. */
    public ?string $userAgent = null;

    /**
     * Whether a front-end request may bring a stale feed up to date.
     *
     * A feed that has *never* been fetched is always fetched inline — a template that renders
     * nothing at all on the first hit looks broken. A feed that is merely stale queues a job
     * instead, so a slow remote server can never become a slow page.
     */
    public bool $refreshOnRequest = true;

    /**
     * Whether a feed URL may resolve to a private or reserved address.
     *
     * Off by default: a feed URL is attacker-controllable input in any site where a non-admin can
     * add one, and `http://169.254.169.254/…` is the classic way that ends badly. Turn it on for
     * an intranet aggregator, and only then.
     */
    public bool $allowPrivateNetworkFetches = false;

    /** Whether item HTML is run through HTML Purifier before it reaches a page. */
    public bool $purifyContent = true;

    /** A `config/htmlpurifier/*.json` file to purify with. Blank uses Airwave's own config. */
    public ?string $purifierConfig = null;

    /** Whether Airwave's small stylesheet is registered when it renders items. */
    public bool $registerFrontEndStyles = true;

    /** `target="_blank"` on item links. */
    public bool $openLinksInNewTab = true;

    /** `rel` for item links. Aggregated links point at somebody else's site; `nofollow` is polite. */
    public string $linkRel = 'noopener nofollow';

    /** Default `Cache-Control: max-age` on a published channel, in seconds. */
    public int $channelCacheDuration = 900;

    /** `<generator>` on published channels. Blank uses "Airwave for Craft CMS". */
    public ?string $feedGeneratorName = null;

    /** Whether refreshing a feed also runs its importer. */
    public bool $importOnRefresh = true;

    // Alerts
    // -------------------------------------------------------------------------
    // Nothing here is `required`: an empty recipient list and an empty webhook URL simply mean
    // nobody is told, and a fresh install must be able to save every other setting.

    /** Comma- or newline-separated addresses, or an `$ENV` reference that resolves to them. */
    public string $alertRecipients = '';

    /** A Slack or Teams incoming-webhook URL (or `$ENV`). Sent through the SSRF guard. */
    public string $alertWebhookUrl = '';

    /** `slack`, `teams` or `json` — the shape of the webhook body. */
    public string $alertWebhookFormat = 'slack';

    /** Optional. When set, the webhook carries an `X-Airwave-Signature` HMAC of its body. */
    public string $alertWebhookSecret = '';

    /** Alert when a feed keeps failing to refresh. */
    public bool $alertOnFailingFeeds = true;

    /** This many failed refreshes in a row opens a failing-feed incident. */
    public int $alertFailureThreshold = 3;

    /**
     * No successful refresh for this many hours (or twice the feed's interval, if longer) is a
     * stale feed. 0 switches it off — the default, because with `refreshOnRequest` a feed nobody
     * has viewed legitimately goes unrefreshed.
     */
    public int $alertStaleHours = 0;

    /** An incident that reopens this soon after its recovery message waits out the rest. */
    public int $alertCooldownMinutes = 60;

    /**
     * Config-file only: let the alert webhook reach private, loopback and link-local hosts (a
     * self-hosted Mattermost on the LAN). The scheme and no-redirect rules still hold.
     */
    public bool $allowPrivateAlertWebhookHosts = false;

    protected function defineRules(): array
    {
        return [
            [['defaultRefreshInterval'], 'integer', 'min' => 60],
            [['defaultItemLimit'], 'integer', 'min' => 1, 'max' => 200],
            [['keepItemsPerSource'], 'integer', 'min' => 10, 'max' => 10000],
            [['requestTimeout'], 'integer', 'min' => 1, 'max' => 120],
            [['maxResponseBytes'], 'integer', 'min' => 1024],
            [['channelCacheDuration'], 'integer', 'min' => 0],
            [['userAgent', 'purifierConfig', 'linkRel', 'feedGeneratorName'], 'string'],
            [
                [
                    'refreshOnRequest',
                    'allowPrivateNetworkFetches',
                    'purifyContent',
                    'registerFrontEndStyles',
                    'openLinksInNewTab',
                    'importOnRefresh',
                    'alertOnFailingFeeds',
                    'allowPrivateAlertWebhookHosts',
                ],
                'boolean',
            ],
            [['alertFailureThreshold'], 'integer', 'min' => 1, 'max' => 1000],
            [['alertStaleHours'], 'integer', 'min' => 0, 'max' => 720],
            [['alertCooldownMinutes'], 'integer', 'min' => 0, 'max' => 10080],
            [['alertWebhookFormat'], 'in', 'range' => ['slack', 'teams', 'json']],
            [['alertRecipients', 'alertWebhookUrl', 'alertWebhookSecret'], 'string', 'max' => 2000],
            [['alertRecipients'], 'validateRecipients'],
            [['alertWebhookUrl'], 'validateWebhookUrl'],
        ];
    }

    /**
     * Every address must be one, when there are any. An `$ENV` reference that is not set yet is
     * allowed — a staging site legitimately has no recipients.
     */
    public function validateRecipients(string $attribute): void
    {
        foreach ($this->recipientList(false) as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $this->addError($attribute, Craft::t('airwave', '“{address}” is not an email address.', ['address' => $address]));
            }
        }
    }

    /**
     * Only the shape is checked here. Where the host resolves is checked at send time, every time,
     * because DNS can change between a save and a send.
     */
    public function validateWebhookUrl(string $attribute): void
    {
        $url = trim((string)App::parseEnv($this->alertWebhookUrl));

        if ($url === '' || str_starts_with($url, '$')) {
            return;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST)) {
            $this->addError($attribute, Craft::t('airwave', 'Only http:// and https:// webhook URLs are allowed.'));
        }
    }

    /**
     * The alert recipients, with `$ENV` resolved.
     *
     * @return string[]
     */
    public function recipientList(bool $validOnly = true): array
    {
        $raw = trim((string)App::parseEnv($this->alertRecipients));

        if ($raw === '' || str_starts_with($raw, '$')) {
            return [];
        }

        $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: []))));

        return $validOnly
            ? array_values(array_filter($list, static fn(string $a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false))
            : $list;
    }

    public function getUserAgent(): string
    {
        if ($this->userAgent) {
            return App::parseEnv($this->userAgent);
        }

        $name = Craft::$app->getSystemName() ?: 'Craft CMS';

        return sprintf('Airwave/1.0 (+%s; Craft CMS feed reader)', $name);
    }

    public function getGeneratorName(): string
    {
        return $this->feedGeneratorName ?: 'Airwave for Craft CMS';
    }

    public function getDefaultDisplayOptions(): DisplayOptions
    {
        $options = new DisplayOptions();
        $options->limit = $this->defaultItemLimit;
        $options->newTab = $this->openLinksInNewTab;
        $options->rel = $this->linkRel;

        return $options;
    }
}
