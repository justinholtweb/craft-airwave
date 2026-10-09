<?php

namespace justinholtweb\airwave\services;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\events\AlertEvent;
use justinholtweb\airwave\helpers\Ip;
use justinholtweb\airwave\Plugin;
use Throwable;
use yii\base\Component;
use yii\db\Expression;

/**
 * Failing-feed alerts: tell somebody when a feed is in trouble, once, and again when it is not.
 *
 * The nav badge and the feeds index already know which feeds are failing. What they cannot do is
 * make anybody look, and nobody opens a feed reader's admin screen on a day the homepage looks
 * fine — which it will, because a failed refresh never empties a feed. Two incidents are worth an
 * interruption:
 *
 * - **Failing** — `alertFailureThreshold` refreshes in a row have failed. One timeout is a
 *   publisher's bad minute; five in a row is a feed that has moved, died or started refusing us.
 *   Closes on the first successful refresh.
 * - **Stale** — no successful refresh for `alertStaleHours` (or twice the feed's interval,
 *   whichever is longer). The quiet failure: no errors, because nothing ran — the cron line went
 *   missing, or the queue stopped. Off by default, because with `refreshOnRequest` a feed nobody
 *   has looked at legitimately goes unrefreshed.
 *
 * Each incident is a latch in `airwave_alerts`, one row per feed and incident type, unique in the
 * database. Opening it sends one alert; it stays open — and silent — however many refreshes fail;
 * clearing it sends one recovery. A send is claimed with a conditional update before it goes out
 * and released if it fails, so a queued refresh and cron checking in the same minute cannot both
 * send, and a mail outage delays the alert rather than swallowing it.
 *
 * Detection runs from {@see afterRefresh()} at the end of every refresh (whoever triggered it:
 * the CP, a queue job, a page view or the console) and from {@see check()} — `airwave/alerts/check`
 * and the end of `airwave/feeds/refresh` — the only path that can notice a stale feed when
 * nothing is refreshing at all.
 *
 * Reference: craft-erpy's `Alerts` (Theme 1). Keyed per feed instead of per connection; the
 * claim/release, redaction and webhook guard are unchanged.
 */
class Alerts extends Component
{
    public const INCIDENT_FAILING = 'failing';
    public const INCIDENT_STALE = 'stale';

    public const STATE_OK = 'ok';
    public const STATE_OPEN = 'open';

    public const TABLE = '{{%airwave_alerts}}';

    /** @event AlertEvent before an alert or a recovery is sent; set `isValid` false to swallow it. */
    public const EVENT_BEFORE_NOTIFY = 'beforeNotify';

    /**
     * The HTTP client for the webhook. Null means a curl-only client built per send; tests put a
     * Guzzle `MockHandler` client here. Never a client on Guzzle's default stack: it can hand a
     * request to PHP's stream wrapper, which ignores `CURLOPT_RESOLVE` — the address pin.
     */
    public ?ClientInterface $webhookClient = null;

    /**
     * @return array<string,string> incident => label
     */
    public static function incidents(): array
    {
        return [
            self::INCIDENT_FAILING => Craft::t('airwave', 'Feed failing'),
            self::INCIDENT_STALE => Craft::t('airwave', 'Feed not refreshing'),
        ];
    }

    public static function incidentLabel(string $incident): string
    {
        return self::incidents()[$incident] ?? $incident;
    }

    // Detection
    // -------------------------------------------------------------------------

    /**
     * Evaluate every enabled feed.
     *
     * @return array<int,array{source:string,incident:string,state:string,transition:?string,notified:bool,detail:?string}>
     */
    public function check(): array
    {
        $results = [];

        foreach (Plugin::getInstance()->sources->getAllSources() as $source) {
            array_push($results, ...$this->checkSource($source));
        }

        return $results;
    }

    /**
     * Evaluate one feed. A disabled feed is left exactly as it is: switching it off is not a
     * recovery, and it is not a new incident either.
     *
     * @param string[]|null $only limit to these incidents
     * @return array<int,array{source:string,incident:string,state:string,transition:?string,notified:bool,detail:?string}>
     */
    public function checkSource(Source $source, ?array $only = null): array
    {
        if (!$source->id || !$source->enabled) {
            return [];
        }

        $results = [];

        foreach (array_keys(self::incidents()) as $incident) {
            if ($only !== null && !in_array($incident, $only, true)) {
                continue;
            }

            $row = $this->row($source, $incident);
            [$open, $detail] = $this->measure($source, $incident, $row);
            $results[] = $this->transition($source, $incident, $open, $detail);
        }

        return $results;
    }

    /**
     * The end of every refresh. Counts consecutive failures, then moves the latches.
     *
     * Fail-open: an alert that cannot be worked out must never be the reason a refresh reports a
     * failure, or — worse — the reason one throws halfway through storing items.
     */
    public function afterRefresh(Source $source, bool $ok): void
    {
        if (!$source->id) {
            return;
        }

        try {
            $this->ensureRow($source, self::INCIDENT_FAILING);
            $where = ['sourceId' => $source->id, 'incident' => self::INCIDENT_FAILING];
            $command = Craft::$app->getDb()->createCommand();

            // An expression, so two refreshes failing at once count as two. The error itself is
            // read from the feed's own `lastError` when the alert is composed, already stored.
            $command->update(self::TABLE, [
                'failures' => $ok ? 0 : new Expression('[[failures]] + 1'),
                'dateUpdated' => $this->now(),
            ], $where)->execute();

            $this->checkSource($source);
        } catch (Throwable $e) {
            Craft::warning("Airwave could not evaluate alerts for {$source->handle}: {$e->getMessage()}", Plugin::LOG_CATEGORY);
        }
    }

    /**
     * Whether an incident is happening right now, and a redacted line saying what was seen.
     *
     * @param array<string,mixed> $row
     * @return array{0:bool,1:?string}
     */
    private function measure(Source $source, string $incident, array $row): array
    {
        $settings = Plugin::getInstance()->getSettings();

        switch ($incident) {
            case self::INCIDENT_FAILING:
                $failures = (int)($row['failures'] ?? 0);
                $wasOpen = ($row['state'] ?? self::STATE_OK) === self::STATE_OPEN;

                // Hysteresis: it takes the threshold to open, and a successful refresh to close.
                // Closing the moment the count dipped would be impossible anyway — it only ever
                // goes up or back to zero — but a lowered threshold must not close one either.
                $open = $settings->alertOnFailingFeeds
                    && ($wasOpen ? $failures > 0 : $failures >= max(1, $settings->alertFailureThreshold));

                if (!$open) {
                    return [false, null];
                }

                $error = $this->redact($source, (string)$source->lastError);

                return [true, Craft::t('airwave', '{count} refreshes in a row have failed. Latest: {error}', [
                    'count' => $failures,
                    'error' => $error !== '' ? $error : '—',
                ])];

            case self::INCIDENT_STALE:
                $since = $this->staleSince($source);

                if ($since === false) {
                    return [false, null];
                }

                return [true, Craft::t('airwave', 'No successful refresh since {since}.', [
                    'since' => $since ? $since->format('Y-m-d H:i') . ' UTC' : Craft::t('airwave', 'it was added'),
                ])];
        }

        return [false, null];
    }

    /**
     * When the feed last refreshed successfully, if that is longer ago than it is allowed; false
     * if it is fresh enough (or the check is off).
     *
     * Allowed is `alertStaleHours` or twice the feed's own interval, whichever is longer — a daily
     * feed is not stale at lunchtime. A 304 counts as a success: the feed is up to date.
     */
    public function staleSince(Source $source): DateTime|null|false
    {
        $hours = Plugin::getInstance()->getSettings()->alertStaleHours;

        if ($hours <= 0 || !$source->id || !$source->enabled) {
            return false;
        }

        $row = (new Query())
            ->select(['lastSuccessAt', 'dateCreated'])
            ->from('{{%airwave_sources}}')
            ->where(['id' => $source->id])
            ->one();

        if (!$row) {
            return false;
        }

        // Bare UTC strings, so the zone is named — read as site-local they shift by the offset.
        $utc = new DateTimeZone('UTC');
        $since = !empty($row['lastSuccessAt']) ? new DateTime($row['lastSuccessAt'], $utc) : null;
        $baseline = $since ?? (!empty($row['dateCreated']) ? new DateTime($row['dateCreated'], $utc) : null);
        $allowed = max($hours * 3600, $source->getRefreshInterval() * 2);

        if ($baseline === null || time() - $baseline->getTimestamp() <= $allowed) {
            return false;
        }

        return $since;
    }

    // The latch
    // -------------------------------------------------------------------------

    /**
     * Move the latch, then send whatever it says is owed.
     *
     * @return array{source:string,incident:string,state:string,transition:?string,notified:bool,detail:?string}
     */
    private function transition(Source $source, string $incident, bool $open, ?string $detail): array
    {
        $db = Craft::$app->getDb();
        $row = $this->row($source, $incident);
        $transition = null;
        $where = ['id' => $row['id']];

        if ($open && $row['state'] !== self::STATE_OPEN) {
            // Conditional on the old state, so two checks racing open it once.
            $won = $db->createCommand()->update(self::TABLE, [
                'state' => self::STATE_OPEN,
                'openedAt' => $this->now(),
                'notifiedAt' => null,
                'recoveryNotifiedAt' => null,
                'detail' => $detail,
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OK])->execute();
            $transition = $won ? 'opened' : null;
        } elseif ($open && $detail !== null && $detail !== $row['detail']) {
            $db->createCommand()->update(self::TABLE, ['detail' => $detail, 'dateUpdated' => $this->now()], $where)->execute();
        } elseif (!$open && $row['state'] === self::STATE_OPEN) {
            $won = $db->createCommand()->update(self::TABLE, [
                'state' => self::STATE_OK,
                'recoveredAt' => $this->now(),
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OPEN])->execute();
            $transition = $won ? 'recovered' : null;
        }

        $notified = $this->deliverOwed($source, $incident);
        $row = $this->row($source, $incident);

        return [
            'source' => (string)$source->handle,
            'incident' => $incident,
            'state' => (string)$row['state'],
            'transition' => $transition,
            'notified' => $notified,
            'detail' => $row['detail'] ?? null,
        ];
    }

    /**
     * Send what the latch says is owed: an opening alert nobody has had yet, or the recovery for
     * one somebody has. Claimed by a conditional update first, released again if every channel
     * failed — so it is sent once, and a mail outage delays it rather than losing it.
     */
    private function deliverOwed(Source $source, string $incident): bool
    {
        $db = Craft::$app->getDb();
        $row = $this->row($source, $incident);
        $where = ['id' => $row['id']];

        if ($row['state'] === self::STATE_OPEN && $row['notifiedAt'] === null) {
            // A flapping feed gets one alert and one recovery per cooldown, not one each per
            // refresh. A reopening inside the cooldown is told about once the cooldown ends, if it
            // is still open by then.
            if ($row['quietUntil'] !== null && $row['quietUntil'] > $this->now()) {
                return false;
            }

            $claimed = $db->createCommand()->update(self::TABLE, ['notifiedAt' => $this->now()], $where + ['notifiedAt' => null, 'state' => self::STATE_OPEN])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($source, $incident, false, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(self::TABLE, ['notifiedAt' => null], $where)->execute();

            return false;
        }

        // A recovery is only owed for an incident somebody was told about.
        if ($row['state'] === self::STATE_OK && $row['notifiedAt'] !== null && $row['recoveryNotifiedAt'] === null) {
            $cooldown = max(0, Plugin::getInstance()->getSettings()->alertCooldownMinutes);
            $claimed = $db->createCommand()->update(self::TABLE, [
                'recoveryNotifiedAt' => $this->now(),
                'quietUntil' => $cooldown > 0 ? Db::prepareDateForDb((new DateTime())->modify("+$cooldown minutes")) : null,
            ], $where + ['state' => self::STATE_OK, 'recoveryNotifiedAt' => null])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($source, $incident, true, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(self::TABLE, [
                'recoveryNotifiedAt' => null,
                'quietUntil' => $row['quietUntil'],
            ], $where)->execute();
        }

        return false;
    }

    /**
     * The latch row, created on first sight.
     *
     * @return array<string,mixed>
     */
    private function row(Source $source, string $incident): array
    {
        $this->ensureRow($source, $incident);

        return (array)(new Query())
            ->from(self::TABLE)
            ->where(['sourceId' => $source->id, 'incident' => $incident])
            ->one();
    }

    private function ensureRow(Source $source, string $incident): void
    {
        $exists = (new Query())
            ->from(self::TABLE)
            ->where(['sourceId' => $source->id, 'incident' => $incident])
            ->exists();

        if ($exists) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
                'sourceId' => $source->id,
                'incident' => $incident,
                'state' => self::STATE_OK,
                'failures' => 0,
                'dateCreated' => $this->now(),
                'dateUpdated' => $this->now(),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\yii\db\IntegrityException) {
            // Another process created it between the check and the insert. The unique index is
            // the point; there is nothing to do.
        }
    }

    /**
     * Every open latch row, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function openIncidents(?Source $source = null): array
    {
        $query = (new Query())->from(self::TABLE)->where(['state' => self::STATE_OPEN])->orderBy(['openedAt' => SORT_DESC]);

        if ($source) {
            $query->andWhere(['sourceId' => $source->id]);
        }

        return $query->all();
    }

    /**
     * What the Dashboard widget shows: the library's totals, and every feed that is failing or has
     * an open incident, with its last success and item count.
     *
     * @return array{stats:array{sources:int,items:int,failing:int,imported:int},rows:array<int,array{source:Source,incidents:array<int,array<string,mixed>>}>}
     */
    public function overview(): array
    {
        $plugin = Plugin::getInstance();
        $incidents = [];

        foreach ($this->openIncidents() as $row) {
            $incidents[(int)$row['sourceId']][] = $row + ['label' => self::incidentLabel((string)$row['incident'])];
        }

        $rows = [];

        // Only feeds that still exist: a soft-deleted feed's latch stays until GC cascades it.
        foreach ($plugin->sources->getAllSources(true) as $source) {
            $open = $incidents[$source->id] ?? [];

            if ($open !== [] || $source->lastStatus === Source::STATUS_ERROR) {
                $rows[] = ['source' => $source, 'incidents' => $open];
            }
        }

        return ['stats' => $plugin->sources->getStats(), 'rows' => $rows];
    }

    // Delivery
    // -------------------------------------------------------------------------

    /**
     * Send one alert to every configured channel. True when at least one channel took it — a
     * second email because the webhook failed would be worse than a missing Slack message.
     */
    public function notify(?Source $source, string $incident, bool $recovered, string $detail): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $hasWebhook = $webhookUrl !== '' && !str_starts_with($webhookUrl, '$');

        if ($recipients === [] && !$hasWebhook) {
            return false;
        }

        $message = $this->compose($source, $incident, $recovered, $detail);

        $event = new AlertEvent([
            'source' => $source,
            'incident' => $incident,
            'recovered' => $recovered,
            'detail' => $message['detail'],
            'subject' => $message['subject'],
            'body' => $message['body'],
            'payload' => $this->payload($settings->alertWebhookFormat, $message),
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_NOTIFY)) {
            $this->trigger(self::EVENT_BEFORE_NOTIFY, $event);

            if (!$event->isValid) {
                return true;
            }
        }

        $sent = false;

        if ($recipients !== []) {
            try {
                $sent = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($event->subject)
                    ->setTextBody($event->body)
                    ->send();
            } catch (Throwable $e) {
                Craft::error('Airwave could not email an alert: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        if ($hasWebhook) {
            $result = $this->postWebhook($webhookUrl, $event->payload);

            if ($result === true) {
                $sent = true;
            } else {
                Craft::error('Airwave could not post an alert webhook: ' . $result, Plugin::LOG_CATEGORY);
            }
        }

        return $sent;
    }

    /**
     * Send a sample through every channel, for the settings screen's "Send a test alert" and
     * `airwave/alerts/test`.
     *
     * @return array{email:?bool,webhook:bool|string|null}
     */
    public function sendTest(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $message = $this->compose(null, 'test', false, Craft::t('airwave', 'This is a test. If you can read it, failing-feed alerts will reach you here.'));
        $result = ['email' => null, 'webhook' => null];

        if ($recipients !== []) {
            try {
                $result['email'] = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($message['subject'])
                    ->setTextBody($message['body'])
                    ->send();
            } catch (Throwable $e) {
                Craft::error('Airwave could not email a test alert: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
                $result['email'] = false;
            }
        }

        if ($webhookUrl !== '' && !str_starts_with($webhookUrl, '$')) {
            $result['webhook'] = $this->postWebhook($webhookUrl, $this->payload($settings->alertWebhookFormat, $message));
        }

        return $result;
    }

    /**
     * Subject, plain-text body and links for one alert.
     *
     * Plain text on purpose: it may be read on a phone, and it should say which feed, what
     * happened and where to go. `UrlHelper::cpUrl()` rather than a hand-assembled host, because
     * this runs from the queue and the console, where there is no request to read a host from.
     * The feed's URL is not included — it can carry a token in its query string.
     *
     * @return array{subject:string,body:string,title:string,detail:string,url:string,feedsUrl:string,source:?string,incident:string,recovered:bool,site:string}
     */
    public function compose(?Source $source, string $incident, bool $recovered, string $detail): array
    {
        $site = Craft::$app->getSites()->getPrimarySite()->getName();
        $name = $source ? $source->getUiLabel() : Craft::t('airwave', 'Test');
        $label = $incident === 'test' ? Craft::t('airwave', 'Test alert') : self::incidentLabel($incident);
        $detail = $source ? $this->redact($source, $detail) : $detail;
        $feedsUrl = UrlHelper::cpUrl('airwave/feeds');
        $url = $source?->id ? UrlHelper::cpUrl('airwave/feeds/' . $source->id) : $feedsUrl;

        $title = $recovered
            ? Craft::t('airwave', 'Recovered: {label}: {feed}', ['label' => $label, 'feed' => $name])
            : Craft::t('airwave', '{label}: {feed}', ['label' => $label, 'feed' => $name]);

        $lines = [
            $recovered
                ? Craft::t('airwave', 'Airwave on {site}: this feed is refreshing again. No action is needed.', ['site' => $site])
                : Craft::t('airwave', 'Airwave on {site}: a feed needs attention. Its stored items are still being shown.', ['site' => $site]),
            '',
            Craft::t('airwave', 'Feed: {name}', ['name' => $name]),
            Craft::t('airwave', 'Incident: {label}', ['label' => $label]),
        ];

        if (!$recovered && $detail !== '') {
            $lines[] = '';
            $lines[] = $detail;
        }

        if (!$recovered && $incident === self::INCIDENT_STALE) {
            $lines[] = '';
            $lines[] = Craft::t('airwave', 'Nothing has refreshed it. Check that `php craft airwave/feeds/refresh --due` is on cron, or that the queue is running.');
        }

        $lines[] = '';
        $lines[] = Craft::t('airwave', 'Open: {url}', ['url' => $url]);
        $lines[] = '';
        $lines[] = Craft::t('airwave', 'You get one message when this starts and one when it clears. Change who gets them in Airwave → Settings.');

        return [
            'subject' => '[' . $site . '] ' . $title,
            'body' => implode("\n", $lines) . "\n",
            'title' => $title,
            'detail' => $recovered ? '' : $detail,
            'url' => $url,
            'feedsUrl' => $feedsUrl,
            'source' => $source?->handle,
            'incident' => $incident,
            'recovered' => $recovered,
            'site' => $site,
        ];
    }

    /**
     * The webhook body in the receiver's own shape.
     *
     * @param array{subject:string,body:string,title:string,detail:string,url:string,feedsUrl:string,source:?string,incident:string,recovered:bool,site:string} $m
     */
    public function payload(string $format, array $m): array
    {
        $text = $m['title'] . ($m['detail'] !== '' ? "\n" . $m['detail'] : '');

        return match ($format) {
            'teams' => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => array_values(array_filter([
                            ['type' => 'TextBlock', 'size' => 'Large', 'weight' => 'Bolder', 'color' => $m['recovered'] ? 'Good' : 'Attention', 'text' => $m['title'], 'wrap' => true],
                            $m['detail'] !== '' ? ['type' => 'TextBlock', 'text' => $m['detail'], 'wrap' => true] : null,
                            ['type' => 'FactSet', 'facts' => [['title' => 'Site', 'value' => $m['site']]]],
                        ])),
                        'actions' => [['type' => 'Action.OpenUrl', 'title' => Craft::t('airwave', 'Open in Craft'), 'url' => $m['url']]],
                    ],
                ]],
            ],
            'json' => [
                'event' => $m['recovered'] ? 'airwave.alert.recovered' : 'airwave.alert.opened',
                'incident' => $m['incident'],
                'feed' => $m['source'],
                'site' => $m['site'],
                'title' => $m['title'],
                'detail' => $m['detail'],
                'url' => $m['url'],
                'at' => (new DateTime('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ],
            default => [
                'text' => $text,
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . $m['title'] . '*' . ($m['detail'] !== '' ? "\n" . $m['detail'] : '')]],
                    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $m['site'] . ' · <' . $m['url'] . '|' . Craft::t('airwave', 'Open in Craft') . '>']]],
                ],
            ],
        };
    }

    /**
     * Where an alert webhook may be sent, or why it may not. The family SSRF rules:
     *
     * 1. `http` and `https` only, with no credentials in the URL.
     * 2. Every address the host resolves to must be public ({@see Ip::resolvePublic()}).
     * 3. The send pins the connection to those addresses with `CURLOPT_RESOLVE`, so a second
     *    lookup at connect time cannot rebind the host somewhere private.
     * 4. Redirects are never followed.
     *
     * Deliberately not tied to `allowPrivateNetworkFetches`: that setting trusts feed hosts, not
     * the alert destination. `allowPrivateAlertWebhookHosts` (config file only) skips 2 and 3;
     * 1 and 4 still hold.
     *
     * @return array{host:string,port:int,addresses:string[]}|string the pinned target, or the refusal
     */
    public function webhookTarget(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)(is_array($parts) ? ($parts['scheme'] ?? '') : ''));
        $host = (string)(is_array($parts) ? ($parts['host'] ?? '') : '');

        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            return Craft::t('airwave', 'Only http:// and https:// webhook URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return Craft::t('airwave', 'Webhook URLs may not carry a username or password.');
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (Plugin::getInstance()->getSettings()->allowPrivateAlertWebhookHosts) {
            return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => []];
        }

        $addresses = Ip::resolvePublic($host);

        if ($addresses === []) {
            return Craft::t('airwave', 'That host doesn’t resolve, or resolves to a private, loopback or link-local address. Alert webhooks only go to public addresses.');
        }

        return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => $addresses];
    }

    /**
     * POST the payload. True on a 2xx, otherwise the reason — never an exception.
     */
    public function postWebhook(string $url, array $payload): bool|string
    {
        $target = $this->webhookTarget($url);

        if (is_string($target)) {
            return $target;
        }

        $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Airwave alerts'];
        $secret = trim((string)App::parseEnv(Plugin::getInstance()->getSettings()->alertWebhookSecret));

        if ($secret !== '' && !str_starts_with($secret, '$')) {
            $timestamp = (string)time();
            $headers['X-Airwave-Timestamp'] = $timestamp;
            $headers['X-Airwave-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        }

        $options = [
            'body' => $body,
            'headers' => $headers,
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'http_errors' => false,
        ];

        if ($target['addresses'] !== []) {
            // One entry per host:port, addresses comma-joined — one entry per address would leave
            // only the last one pinned.
            $options['curl'][CURLOPT_RESOLVE] = [sprintf(
                '%s:%d:%s',
                $target['host'],
                $target['port'],
                implode(',', array_map(static fn(string $ip) => str_contains($ip, ':') ? "[$ip]" : $ip, $target['addresses'])),
            )];
        }

        try {
            $client = $this->webhookClient ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]);
            $response = $client->request('POST', $url, $options);
            $status = $response->getStatusCode();

            return $status >= 200 && $status < 300 ? true : 'HTTP ' . $status;
        } catch (Throwable $e) {
            // A Slack or Teams webhook URL is itself the credential; the reason ends up in logs.
            return str_replace($url, $target['host'], $e->getMessage());
        }
    }

    /**
     * Take anything secret out of a line that is about to leave the building.
     *
     * A refresh error quotes whatever the HTTP client said, and the client quotes the URL — which
     * for a private feed carries a token in its query string — and a feed's request headers are
     * resolved from `.env`. So: the feed URL is replaced by its address without the query, the
     * resolved header values are removed by value, anything shaped like a credential by pattern,
     * tags stripped, and a length cap so a publisher's HTML error page cannot ride along.
     */
    public function redact(Source $source, string $text): string
    {
        $url = trim((string)$source->url);

        if ($url !== '') {
            $bare = preg_replace('/[?#].*$/s', '', $url) ?? $url;
            $text = str_replace($url, $bare . (str_contains($url, '?') ? '?…' : ''), $text);
        }

        $secrets = [];

        foreach ((array)($source->getFetchOptions()['headers'] ?? []) as $value) {
            if (is_string($value)) {
                $secrets[] = $value;
                $secrets[] = (string)(App::parseEnv($value) ?? '');
            }
        }

        foreach ($secrets as $secret) {
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, '••••', $text);
            }
        }

        $text = (string)preg_replace('/\b(Bearer|Basic|Token)\s+[A-Za-z0-9\-._~+\/=]{6,}/i', '$1 ••••', $text);
        $text = (string)preg_replace(
            '/(["\']?\b(?:password|passwd|pwd|secret|client_secret|api[_-]?key|apikey|key|access_token|refresh_token|token|signature|sig)\b["\']?\s*[:=]\s*["\']?)[^"\'&\s,;}]+/i',
            '$1••••',
            $text,
        );
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($text)));

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 499) . '…' : $text;
    }

    private function now(): string
    {
        return (string)Db::prepareDateForDb(new DateTime());
    }
}
