<?php
/**
 * Failing-feed alerts and the Feed health widget.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-airwave/tests/integration/alerts.php
 *
 * Drives the real latch, the real refresh, the real mailer and the real webhook code. A feed
 * pointed at an `.invalid` host fails for real (the harness has no outbound network anyway); a
 * success is the same `afterRefresh()` call `Sources::refresh()` makes. The webhook goes to a
 * Guzzle MockHandler after passing the SSRF guard for real. The test-alert endpoint is checked
 * over HTTP as a non-admin, a guest and an admin.
 *
 * Settings and edition changes stay in memory. Self-cleaning: the fixture feeds — and with them,
 * by cascade, their alert rows — and the fixture user are removed in a shutdown handler.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\mail\Mailer;
use craft\web\View;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\events\AlertEvent;
use justinholtweb\airwave\models\Settings;
use justinholtweb\airwave\Plugin;
use justinholtweb\airwave\services\Alerts;
use justinholtweb\airwave\widgets\FeedHealthWidget;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$alerts = $plugin->alerts;
$settings = $plugin->getSettings();
$original = $settings->toArray();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$cleanup = ['sources' => [], 'users' => []];

// Pro in memory only, so the Lite feed limit cannot make a fixture fail to save.
$plugin->edition = Plugin::EDITION_PRO;

register_shutdown_function(function() use (&$cleanup) {
    foreach (array_merge($cleanup['sources'], $cleanup['users']) as $element) {
        try {
            Craft::$app->getElements()->deleteElement($element, true);
        } catch (Throwable) {
        }
    }
});

// The real mailer, on Symfony's null transport: the whole compose/send path runs, but the result
// does not depend on whether the harness's Mailpit happens to be up.
Craft::$app->getMailer()->setTransport(new Symfony\Component\Mailer\Transport\NullTransport());

$mail = [];
$mailFails = false;
Event::on(Mailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $e) use (&$mailFails) {
    if ($mailFails) {
        $e->isValid = false;
    }
});
Event::on(Mailer::class, BaseMailer::EVENT_AFTER_SEND, function(MailEvent $e) use (&$mail) {
    if ($e->isSuccessful) {
        $mail[] = ['subject' => $e->message->getSubject(), 'body' => (string)$e->message->toString()];
    }
});

$history = [];
$mock = new MockHandler();
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$alerts->webhookClient = new Client(['handler' => $stack]);

$reset = function(array $overrides = []) use ($settings, $original, &$mail, &$history, $mock, &$mailFails) {
    $settings->setAttributes(array_merge($original, [
        'alertRecipients' => 'ops@example.test',
        'alertWebhookUrl' => '',
        'alertWebhookSecret' => '',
        'alertWebhookFormat' => 'slack',
        'alertOnFailingFeeds' => true,
        'alertFailureThreshold' => 3,
        'alertStaleHours' => 0,
        'alertCooldownMinutes' => 0,
        'allowPrivateAlertWebhookHosts' => false,
        'allowPrivateNetworkFetches' => false,
    ], $overrides), false);
    $mail = [];
    $history = [];
    $mailFails = false;
    $mock->reset();
};

$makeSource = function(string $suffix, string $url, array $headers = []) use ($plugin, $run, &$cleanup): Source {
    $source = new Source();
    $source->title = "Alert feed $suffix $run";
    $source->handle = "aw-alert-$suffix-$run";
    $source->url = $url;
    $source->enabled = true;
    $source->setFetchOptions(['headers' => $headers]);
    $plugin->sources->saveSource($source) or throw new RuntimeException(json_encode($source->getErrors()));
    $cleanup['sources'][] = $source;

    return $source;
};

putenv("AIRWAVE_ALERT_TEST_TOKEN=hdr-secret-$run");
$_SERVER['AIRWAVE_ALERT_TEST_TOKEN'] = "hdr-secret-$run";

$source = $makeSource('a', "http://aw-alert-$run.invalid/feed.xml?token=qs-secret-$run", ['X-Api-Key' => '$AIRWAVE_ALERT_TEST_TOKEN']);
$latch = fn(Source $s, string $incident) => (new Query())->from(Alerts::TABLE)->where(['sourceId' => $s->id, 'incident' => $incident])->one() ?: [];

// -------------------------------------------------------------------------------------------
section('Settings');

check('a fresh install saves with no recipients and no webhook (nothing is required)', function() use ($original) {
    $s = new Settings(array_merge($original, ['alertRecipients' => '', 'alertWebhookUrl' => '']));

    return $s->validate() ?: json_encode($s->getErrors());
});

check('a bad address is refused, and named', function() use ($original) {
    $s = new Settings(array_merge($original, ['alertRecipients' => 'ops@example.test, not-an-address']));

    return !$s->validate() && str_contains(implode(' ', $s->getErrors('alertRecipients')), 'not-an-address') ?: json_encode($s->getErrors());
});

check('an unset $ENV reference is allowed and means nobody', function() use ($original) {
    $s = new Settings(array_merge($original, ['alertRecipients' => '$AIRWAVE_ALERTS_NOT_SET', 'alertWebhookUrl' => '$AIRWAVE_HOOK_NOT_SET']));

    return $s->validate() && $s->recipientList() === [] ?: json_encode($s->getErrors());
});

check('a non-http webhook URL is refused at save', function() use ($original) {
    $s = new Settings(array_merge($original, ['alertWebhookUrl' => 'gopher://hooks.example.test/x']));

    return !$s->validate() && $s->hasErrors('alertWebhookUrl') ?: 'accepted';
});

check('recipients split on commas, semicolons and newlines, de-duplicated', function() {
    $s = new Settings(['alertRecipients' => "a@example.test; b@example.test\nc@example.test, a@example.test"]);

    return $s->recipientList() === ['a@example.test', 'b@example.test', 'c@example.test'] ?: json_encode($s->recipientList());
});

// -------------------------------------------------------------------------------------------
section('The SSRF guard on the webhook');

$reset(['allowPrivateNetworkFetches' => true]);

foreach ([
    'http://127.0.0.1/hook' => 'loopback',
    'http://169.254.169.254/latest/meta-data/' => 'the cloud metadata service',
    'http://10.1.2.3/hook' => 'a private address',
    'http://[::1]/hook' => 'IPv6 loopback',
    'http://[::ffff:127.0.0.1]/hook' => 'IPv4-mapped loopback',
    'http://100.64.0.1/hook' => 'carrier-grade NAT',
    'ftp://93.184.215.14/hook' => 'a non-http scheme',
    'https://user:pass@93.184.215.14/hook' => 'credentials in the URL',
] as $url => $what) {
    check("refuses $what (even with private feed fetches allowed)", fn() => is_string($alerts->webhookTarget($url)) ?: 'allowed');
}

$reset();

check('a public address is allowed, and pinned', function() use ($alerts) {
    $t = $alerts->webhookTarget('https://93.184.215.14/hook');

    return is_array($t) && $t['addresses'] === ['93.184.215.14'] && $t['port'] === 443 ?: json_encode($t);
});

check('a refused URL is never requested', function() use ($alerts, &$history) {
    $result = $alerts->postWebhook('http://127.0.0.1:8080/hook', ['text' => 'x']);

    return is_string($result) && $history === [] ?: 'requested: ' . count($history);
});

check('the send pins the address, refuses redirects and does not throw on a 4xx', function() use ($alerts, $mock, &$history) {
    $mock->append(new Psr7Response(302, ['Location' => 'http://127.0.0.1/']));
    $result = $alerts->postWebhook('https://93.184.215.14/hook', ['text' => 'x']);
    $options = $history[0]['options'] ?? [];
    $pin = $options['curl'][CURLOPT_RESOLVE][0] ?? '';

    return $result === 'HTTP 302' && $pin === '93.184.215.14:443:93.184.215.14' && ($options['allow_redirects'] ?? null) === false && count($history) === 1
        ?: json_encode(['result' => $result, 'pin' => $pin, 'redirects' => $options['allow_redirects'] ?? null]);
});

check('allowPrivateAlertWebhookHosts lets a LAN host through, unpinned', function() use ($alerts, $settings) {
    $settings->allowPrivateAlertWebhookHosts = true;
    $t = $alerts->webhookTarget('http://10.1.2.3:8065/hooks/x');
    $settings->allowPrivateAlertWebhookHosts = false;

    return is_array($t) && $t['addresses'] === [] && $t['port'] === 8065 ?: json_encode($t);
});

// -------------------------------------------------------------------------------------------
section('Failing feed: one alert, one recovery');

$reset();

check('the first two failed refreshes count, and send nothing', function() use ($plugin, $source, $latch, &$mail) {
    $r1 = $plugin->sources->refresh($source);
    $r2 = $plugin->sources->refresh($source);
    $row = $latch($source, Alerts::INCIDENT_FAILING);

    return !$r1->ok && !$r2->ok && (int)$row['failures'] === 2 && $row['state'] === 'ok' && $mail === []
        ?: json_encode(['row' => $row, 'mail' => count($mail)]);
});

check('the third opens the incident and sends one email', function() use ($plugin, $source, $latch, &$mail) {
    $plugin->sources->refresh($source);
    $row = $latch($source, Alerts::INCIDENT_FAILING);

    return $row['state'] === 'open' && $row['notifiedAt'] !== null && count($mail) === 1
        && str_contains($mail[0]['subject'], 'Feed failing') && str_contains($mail[0]['body'], '3 refreshes in a row')
        ?: json_encode(['row' => $row, 'mail' => $mail]);
});

check('the email links to the feed and leaks neither the query-string token nor the header secret', function() use (&$mail, $source, $run) {
    $body = quoted_printable_decode($mail[0]['body'] ?? '');

    return str_contains($body, 'airwave/feeds/' . $source->id)
        && !str_contains($body, "qs-secret-$run") && !str_contains($body, "hdr-secret-$run")
        ?: $body;
});

check('a fourth failure stays silent', function() use ($plugin, $source, $latch, &$mail) {
    $plugin->sources->refresh($source);
    $row = $latch($source, Alerts::INCIDENT_FAILING);

    return $row['state'] === 'open' && (int)$row['failures'] === 4 && count($mail) === 1 ?: json_encode(['row' => $row, 'mail' => count($mail)]);
});

check('a check with nothing new sends nothing and does not nest the detail', function() use ($alerts, $source, $latch, &$mail) {
    $alerts->checkSource($source);
    $alerts->checkSource($source);
    $row = $latch($source, Alerts::INCIDENT_FAILING);

    return count($mail) === 1 && substr_count((string)$row['detail'], 'in a row') === 1 ?: json_encode(['row' => $row, 'mail' => count($mail)]);
});

check('the Feed health overview lists the feed with its incident', function() use ($alerts, $source) {
    $overview = $alerts->overview();
    $row = array_values(array_filter($overview['rows'], fn($r) => $r['source']->id === $source->id))[0] ?? null;

    return $row && ($row['incidents'][0]['incident'] ?? null) === Alerts::INCIDENT_FAILING && $overview['stats']['failing'] >= 1
        ?: json_encode(['rows' => count($overview['rows']), 'stats' => $overview['stats']]);
});

check('the widget template renders the feed, its incident and the totals', function() use ($alerts, $source) {
    $html = Craft::$app->getView()->renderTemplate('airwave/_widgets/health', ['overview' => $alerts->overview()], View::TEMPLATE_MODE_CP);

    return str_contains($html, (string)$source->title) && str_contains($html, 'Feed failing') && str_contains($html, 'airwave/feeds/' . $source->id)
        ?: substr(strip_tags($html), 0, 400);
});

check('a success closes it and sends one recovery', function() use ($alerts, $source, $latch, &$mail) {
    $alerts->afterRefresh($source, true);
    $row = $latch($source, Alerts::INCIDENT_FAILING);

    return $row['state'] === 'ok' && (int)$row['failures'] === 0 && $row['recoveryNotifiedAt'] !== null && count($mail) === 2
        && str_contains($mail[1]['subject'], 'Recovered')
        ?: json_encode(['row' => $row, 'mail' => array_column($mail, 'subject')]);
});

check('a disabled feed is skipped: not a recovery, not a new incident', function() use ($alerts, $source) {
    $source->enabled = false;
    $results = $alerts->checkSource($source);
    $source->enabled = true;

    return $results === [] ?: json_encode($results);
});

// -------------------------------------------------------------------------------------------
section('Cooldown, outages and handlers');

$reset(['alertCooldownMinutes' => 60, 'alertFailureThreshold' => 1]);

check('a reopening inside the cooldown is held', function() use ($alerts, $source, $latch, &$mail) {
    // The recovery above happened with no cooldown; give it one, as if it had been sent with it.
    Craft::$app->getDb()->createCommand()->update(Alerts::TABLE, ['quietUntil' => Db::prepareDateForDb((new DateTime())->modify('+30 minutes'))], ['sourceId' => $source->id, 'incident' => Alerts::INCIDENT_FAILING])->execute();
    $alerts->afterRefresh($source, false);
    $row = $latch($source, Alerts::INCIDENT_FAILING);

    return $row['state'] === 'open' && $row['notifiedAt'] === null && $mail === [] ?: json_encode(['row' => $row, 'mail' => count($mail)]);
});

check('…and sent once the cooldown has passed, if still open', function() use ($alerts, $source, $latch, &$mail) {
    Craft::$app->getDb()->createCommand()->update(Alerts::TABLE, ['quietUntil' => Db::prepareDateForDb((new DateTime())->modify('-1 minute'))], ['sourceId' => $source->id, 'incident' => Alerts::INCIDENT_FAILING])->execute();
    $alerts->check();
    $row = $latch($source, Alerts::INCIDENT_FAILING);

    return $row['notifiedAt'] !== null && count($mail) === 1 ?: json_encode(['row' => $row, 'mail' => count($mail)]);
});

check('a recovery that cannot be delivered is released and retried, not lost', function() use ($alerts, $source, $latch, &$mail, &$mailFails) {
    $mailFails = true;
    $alerts->afterRefresh($source, true);
    $held = $latch($source, Alerts::INCIDENT_FAILING);
    $mailFails = false;
    $alerts->checkSource($source);
    $row = $latch($source, Alerts::INCIDENT_FAILING);

    return $held['state'] === 'ok' && $held['recoveryNotifiedAt'] === null && $row['recoveryNotifiedAt'] !== null && count($mail) === 2
        ?: json_encode(['held' => $held, 'row' => $row, 'mail' => count($mail)]);
});

$second = $makeSource('b', "http://aw-alert-b-$run.invalid/feed.xml");

check('latches are per feed: a second feed opens its own incident', function() use ($alerts, $second, $source, $latch, &$mail, $reset) {
    $reset(['alertFailureThreshold' => 1]);
    $alerts->afterRefresh($second, false);

    return $latch($second, Alerts::INCIDENT_FAILING)['state'] === 'open' && $latch($source, Alerts::INCIDENT_FAILING)['state'] === 'ok' && count($mail) === 1
        ?: json_encode(['b' => $latch($second, Alerts::INCIDENT_FAILING), 'mail' => count($mail)]);
});

check('a handler on EVENT_BEFORE_NOTIFY can swallow an alert, and the latch counts it sent', function() use ($alerts, $second, $latch, &$mail) {
    $mail = [];
    $handler = function(AlertEvent $e) {
        $e->isValid = false;
    };
    $alerts->on(Alerts::EVENT_BEFORE_NOTIFY, $handler);
    $alerts->afterRefresh($second, true);
    $alerts->off(Alerts::EVENT_BEFORE_NOTIFY, $handler);

    return $mail === [] && $latch($second, Alerts::INCIDENT_FAILING)['recoveryNotifiedAt'] !== null ?: 'mail: ' . count($mail);
});

check('switching failing alerts off sends nothing', function() use ($alerts, $second, $latch, &$mail, $reset) {
    $reset(['alertOnFailingFeeds' => false, 'alertFailureThreshold' => 1]);
    $alerts->afterRefresh($second, false);
    $alerts->afterRefresh($second, false);
    $row = $latch($second, Alerts::INCIDENT_FAILING);
    $alerts->afterRefresh($second, true);

    return $row['state'] === 'ok' && $mail === [] ?: json_encode(['row' => $row, 'mail' => count($mail)]);
});

// -------------------------------------------------------------------------------------------
section('Webhook delivery');

check('an incident posts a Slack message, signed, to the pinned address', function() use ($alerts, $second, $mock, &$history, $reset, $run) {
    $reset(['alertRecipients' => '', 'alertWebhookUrl' => 'https://93.184.215.14/services/T/B/x', 'alertWebhookSecret' => "whsec-$run", 'alertFailureThreshold' => 1]);
    $mock->append(new Psr7Response(200));
    $alerts->afterRefresh($second, false);
    $request = $history[0]['request'] ?? null;

    if (!$request) {
        return 'no request';
    }

    $body = (string)$request->getBody();
    $timestamp = $request->getHeaderLine('X-Airwave-Timestamp');
    $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, "whsec-$run");
    $payload = json_decode($body, true);

    return $request->getHeaderLine('X-Airwave-Signature') === $expected
        && str_contains((string)($payload['text'] ?? ''), 'Feed failing')
        && ($history[0]['options']['curl'][CURLOPT_RESOLVE][0] ?? '') === '93.184.215.14:443:93.184.215.14'
        ?: $body;
});

check('the JSON format names the event, feed and incident', function() use ($alerts, $second, $mock, &$history, $settings) {
    $settings->alertWebhookFormat = 'json';
    $history = [];
    $mock->append(new Psr7Response(204));
    $alerts->afterRefresh($second, true);
    $payload = json_decode((string)($history[0]['request'] ?? null)?->getBody(), true);

    return ($payload['event'] ?? null) === 'airwave.alert.recovered' && ($payload['feed'] ?? null) === $second->handle && ($payload['incident'] ?? null) === 'failing'
        ?: json_encode($payload);
});

check('a test alert goes to every channel and reports each', function() use ($alerts, $mock, $settings) {
    $settings->alertRecipients = 'ops@example.test';
    $mock->append(new Psr7Response(200));
    $result = $alerts->sendTest();

    return $result === ['email' => true, 'webhook' => true] ?: json_encode($result);
});

// -------------------------------------------------------------------------------------------
section('Stale feed');

$reset(['alertStaleHours' => 1]);

check('a feed with no success for longer than twice its interval opens a stale incident', function() use ($alerts, $source, $latch, &$mail) {
    // Default interval is an hour, so allowed is max(1h, 2h) = 2h; three hours ago is stale.
    Craft::$app->getDb()->createCommand()->update('{{%airwave_sources}}', [
        'lastSuccessAt' => Db::prepareDateForDb((new DateTime('now', new DateTimeZone('UTC')))->modify('-3 hours')),
    ], ['id' => $source->id])->execute();
    $alerts->checkSource($source, [Alerts::INCIDENT_STALE]);
    $row = $latch($source, Alerts::INCIDENT_STALE);

    return $row['state'] === 'open' && count($mail) === 1 && str_contains($mail[0]['subject'], 'not refreshing')
        ?: json_encode(['row' => $row, 'mail' => array_column($mail, 'subject')]);
});

check('ninety minutes is not stale for an hourly feed (twice the interval wins)', function() use ($alerts, $source) {
    Craft::$app->getDb()->createCommand()->update('{{%airwave_sources}}', [
        'lastSuccessAt' => Db::prepareDateForDb((new DateTime('now', new DateTimeZone('UTC')))->modify('-90 minutes')),
    ], ['id' => $source->id])->execute();

    return $alerts->staleSince($source) === false ?: 'stale';
});

check('a fresh success recovers it', function() use ($alerts, $source, $latch, &$mail) {
    $alerts->checkSource($source, [Alerts::INCIDENT_STALE]);
    $row = $latch($source, Alerts::INCIDENT_STALE);

    return $row['state'] === 'ok' && count($mail) === 2 ?: json_encode(['row' => $row, 'mail' => count($mail)]);
});

check('stale alerts are off at 0 hours', function() use ($alerts, $source, $settings) {
    $settings->alertStaleHours = 0;

    return $alerts->staleSince($source) === false ?: 'stale';
});

// -------------------------------------------------------------------------------------------
section('Widget permissions');

check('the widget is not selectable and renders nothing without airwave:viewFeeds', function() {
    // Console request: there is no user, so no permission.
    return FeedHealthWidget::isSelectable() === false && (new FeedHealthWidget())->getBodyHtml() === null ?: 'visible';
});

// -------------------------------------------------------------------------------------------
section('The test-alert endpoint over HTTP');

$password = 'aw-' . bin2hex(random_bytes(12));
$user = new User();
$user->username = "aw-alerts-$run";
$user->email = "aw-alerts-$run@example.com";
$user->newPassword = $password;
$user->admin = false;
Craft::$app->getElements()->saveElement($user, false);
Craft::$app->getUsers()->activateUser($user);
$cleanup['users'][] = $user;
Craft::$app->getUserPermissions()->saveUserPermissions($user->id, [
    'accesscp', 'accessplugin-airwave',
    Plugin::PERMISSION_VIEW, Plugin::PERMISSION_MANAGE, Plugin::PERMISSION_DELETE, Plugin::PERMISSION_REFRESH,
    Plugin::PERMISSION_IMPORT, Plugin::PERMISSION_MANAGE_CHANNELS,
]);

$session = function(?string $login, ?string $pass) {
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $csrf = static function() use ($http): string {
        $info = json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);

        return (string)($info['csrfTokenValue'] ?? '');
    };

    if ($login !== null) {
        $http->post('index.php?p=actions/users/login', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['loginName' => $login, 'password' => $pass, 'CRAFT_CSRF_TOKEN' => $csrf()],
        ]);
    }

    return [$http, $csrf];
};

$hit = function(array $s, string $method = 'POST') {
    [$http, $csrf] = $s;
    $options = ['headers' => ['Accept' => 'application/json']];

    if ($method === 'POST') {
        $options['form_params'] = ['CRAFT_CSRF_TOKEN' => $csrf()];
    }

    $response = $http->request($method, 'index.php?p=admin/actions/airwave/alerts/test', $options);

    return [$response->getStatusCode(), json_decode((string)$response->getBody(), true)];
};

$editor = $session($user->username, $password);

check('a non-admin with every Airwave permission is refused (403)', function() use ($hit, $editor) {
    [$status] = $hit($editor);

    return $status === 403 ?: "status $status";
});

check('a guest is refused', function() use ($hit, $session) {
    [$status] = $hit($session(null, null));

    return in_array($status, [302, 401, 403], true) ?: "status $status";
});

$admin = $session('admin', 'claudepassword');

check('an admin GET is refused (POST only)', function() use ($hit, $admin) {
    [$status] = $hit($admin, 'GET');

    return in_array($status, [400, 405], true) ?: "status $status";
});

check('an admin POST reaches the action, which uses the saved settings only', function() use ($hit, $admin) {
    [$status, $body] = $hit($admin);
    $saved = Craft::$app->getPlugins()->getStoredPluginInfo('airwave')['settings'] ?? [];
    $configured = trim((string)($saved['alertRecipients'] ?? '')) !== '' || trim((string)($saved['alertWebhookUrl'] ?? '')) !== '';

    // The harness's saved settings have no recipients: the action answers that, not a 403.
    $ok = $configured ? in_array($status, [200, 400], true) : ($status === 400 && str_contains((string)($body['message'] ?? ''), 'Save some recipients'));

    return $ok ?: "status $status " . json_encode($body);
});

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
