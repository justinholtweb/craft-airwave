<?php
/**
 * What a non-admin with every Airwave permission can and cannot author, checked over HTTP.
 *
 *     ddev exec php /var/www/craft-airwave/tests/integration/trust.php
 *
 * `checks.php` runs as a console request, which Airwave rightly treats as trusted, so the
 * admin-only rules in `helpers\Trust` can only be exercised through the control panel itself. This
 * signs in as a throwaway user holding every Airwave permission but not admin, posts the saves an
 * attacker would, and reads back what was stored.
 *
 * Idempotent and self-cleaning: the user, feed and channel are removed in a shutdown handler.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\models\ChannelMapping;
use justinholtweb\airwave\models\ImportConfig;
use justinholtweb\airwave\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

$plugin = Plugin::getInstance();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'aw-' . bin2hex(random_bytes(12));
$originalEdition = $plugin->edition;
$created = [];

Craft::$app->getPlugins()->switchEdition('airwave', Plugin::EDITION_PRO);
Craft::$app->getProjectConfig()->saveModifiedConfigData();

register_shutdown_function(function() use (&$created, $originalEdition) {
    foreach ($created as $element) {
        try {
            Craft::$app->getElements()->deleteElement($element, true);
        } catch (Throwable) {
        }
    }

    Craft::$app->getPlugins()->switchEdition('airwave', $originalEdition);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
});

// A user with every Airwave permission, and nothing that makes them an admin.
$user = new User();
$user->username = "aw-editor-$run";
$user->email = "aw-editor-$run@example.com";
$user->newPassword = $password;
$user->admin = false;
Craft::$app->getElements()->saveElement($user, false);
Craft::$app->getUsers()->activateUser($user);
$created[] = $user;

Craft::$app->getUserPermissions()->saveUserPermissions($user->id, [
    'accesscp',
    'accessplugin-airwave',
    Plugin::PERMISSION_VIEW,
    Plugin::PERMISSION_MANAGE,
    Plugin::PERMISSION_IMPORT,
    Plugin::PERMISSION_MANAGE_CHANNELS,
]);

// What an admin already set up: a header that uses the environment, and templates.
$source = new Source();
$source->title = "Trust feed $run";
$source->handle = "aw-trust-$run";
$source->url = 'https://example.com/trust.xml';
$source->enabled = false;
$source->setFetchOptions(['headers' => ['Authorization' => '$AIRWAVE_FEED_TOKEN']]);
$source->importEnabled = true;
$source->setImportConfig(ImportConfig::fromArray([
    'titleTemplate' => 'Imported: {{ item.title }}',
    'fieldMap' => ['body' => '{{ item.summary }}'],
]));
$plugin->sources->saveSource($source);
$created[] = $source;

$channel = new Channel();
$channel->title = "Trust channel $run";
$channel->handle = "aw-trust-$run";
$channel->uri = "aw-trust-$run";
$channel->targetSiteId = Craft::$app->getSites()->getPrimarySite()->id;
$channel->feedSource = Channel::SOURCE_ELEMENTS;
$channel->setCriteria([]);
$channel->setMapping(ChannelMapping::fromArray(['title' => 'Stored: {title}']));
$plugin->channels->saveChannel($channel);
$created[] = $channel;

// Sign in through the front door, the way a person would.
$jar = new CookieJar();
$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => $jar, 'http_errors' => false, 'allow_redirects' => false]);

$csrf = static function() use ($http): string {
    $info = json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);

    return (string)($info['csrfTokenValue'] ?? '');
};

$login = $http->post('index.php?p=actions/users/login', [
    'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
    'form_params' => ['loginName' => $user->username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()],
]);

if ($login->getStatusCode() !== 200) {
    echo "Could not sign in as the test user: {$login->getStatusCode()} " . substr((string)$login->getBody(), 0, 200) . "\n";
    exit(1);
}

$post = static function(string $action, array $fields) use ($http, $csrf): int {
    return $http->post('index.php?p=admin/actions/' . $action, [
        'form_params' => $fields + ['CRAFT_CSRF_TOKEN' => $csrf()],
    ])->getStatusCode();
};

// The real form always posts the whole field map, so the header checks do too. (Posting none
// would remove the stored template, which is allowed: taking code out is never the risky way.)
$feedFields = static fn(string $headers, array $importConfig = ['fieldMap' => [['field' => 'body', 'value' => '{{ item.summary }}']]]) => [
    'sourceId' => $source->id,
    'title' => $source->title,
    'handle' => $source->handle,
    'url' => $source->url,
    'enabled' => '',
    'headers' => $headers,
    'importEnabled' => '1',
    'importConfig' => $importConfig,
];

$storedSource = static fn() => Plugin::getInstance()->sources->getSourceById($source->id);
$storedChannel = static fn() => Plugin::getInstance()->channels->getChannelById($channel->id);

echo "\nRequest headers\n";

check('a non-admin cannot add a header that reads the environment', function() use ($post, $feedFields, $storedSource) {
    $status = $post('airwave/feeds/save', $feedFields("Authorization: \$AIRWAVE_FEED_TOKEN\nX-Leak: \$CRAFT_SECURITY_KEY"));
    $headers = $storedSource()->getFetchOptions()['headers'] ?? [];

    return !isset($headers['X-Leak']) && $status !== 302 ?: "status $status, stored " . json_encode($headers);
});

check('a non-admin cannot change one either', function() use ($post, $feedFields, $storedSource) {
    $post('airwave/feeds/save', $feedFields('Authorization: $CRAFT_DB_PASSWORD'));
    $headers = $storedSource()->getFetchOptions()['headers'] ?? [];

    return ($headers['Authorization'] ?? null) === '$AIRWAVE_FEED_TOKEN' ?: json_encode($headers);
});

check('but can keep one an admin set, and add plain headers beside it', function() use ($post, $feedFields, $storedSource) {
    $status = $post('airwave/feeds/save', $feedFields("Authorization: \$AIRWAVE_FEED_TOKEN\nX-Client: newsroom"));
    $headers = $storedSource()->getFetchOptions()['headers'] ?? [];

    return $status === 302 && $headers === ['Authorization' => '$AIRWAVE_FEED_TOKEN', 'X-Client' => 'newsroom']
        ?: "status $status, stored " . json_encode($headers);
});

check('nobody can override Host', function() use ($post, $feedFields, $storedSource) {
    $post('airwave/feeds/save', $feedFields("Authorization: \$AIRWAVE_FEED_TOKEN\nHost: metadata.internal"));
    $headers = $storedSource()->getFetchOptions()['headers'] ?? [];

    return !isset($headers['Host']) ?: json_encode($headers);
});

echo "\nImport templates\n";

check('a non-admin import save keeps the stored templates', function() use ($post, $feedFields, $storedSource) {
    $post('airwave/feeds/save', $feedFields('Authorization: $AIRWAVE_FEED_TOKEN', [
        'titleTemplate' => '{{ craft.app.config.general.securityKey }}',
        'fieldMap' => [
            ['field' => 'body', 'value' => '{{ craft.users.all()|length }}'],
            ['field' => 'intro', 'value' => 'summary'],
        ],
    ]));
    $config = $storedSource()->getImportConfig();

    return $config->titleTemplate === 'Imported: {{ item.title }}'
        && $config->fieldMap === ['body' => '{{ item.summary }}', 'intro' => 'summary']
        ?: json_encode([$config->titleTemplate, $config->fieldMap]);
});

echo "\nChannels\n";

$channelFields = static fn(array $extra) => $extra + [
    'channelId' => $channel->id,
    'title' => $channel->title,
    'handle' => $channel->handle,
    'uri' => $channel->uri,
    'enabled' => '1',
    'feedSource' => Channel::SOURCE_ELEMENTS,
    'elementType' => craft\elements\Entry::class,
    'format' => Channel::FORMAT_RSS,
];

check('a non-admin cannot change a channel template', function() use ($post, $channelFields, $storedChannel) {
    $post('airwave/channels/save', $channelFields(['mapping' => ['title' => '{{ craft.app.config.general.securityKey }}']]));

    return $storedChannel()->getMapping()->title === 'Stored: {title}' ?: $storedChannel()->getMapping()->title;
});

check('an SQL expression as the channel order is refused', function() use ($post, $channelFields, $storedChannel) {
    $status = $post('airwave/channels/save', $channelFields(['criteria' => ['orderBy' => '(SELECT SLEEP(5))']]));
    $criteria = $storedChannel()->getCriteria();

    return $status !== 302 && !isset($criteria['orderBy']) ?: "status $status, stored " . json_encode($criteria);
});

check('a channel cannot be pointed at users or given a status', function() use ($post, $channelFields, $storedChannel) {
    $post('airwave/channels/save', $channelFields([
        'elementType' => User::class,
        'criteria' => ['status' => 'disabled', 'orderBy' => 'title asc'],
    ]));
    $stored = $storedChannel();

    return $stored->elementType === craft\elements\Entry::class && $stored->getCriteria() === ['orderBy' => 'title asc']
        ?: json_encode([$stored->elementType, $stored->getCriteria()]);
});

echo "\nScreens\n";

check('the feed screen renders for a non-admin and explains the header rule', function() use ($http, $source) {
    $response = $http->get("admin/airwave/feeds/{$source->id}");
    $html = (string)$response->getBody();

    return $response->getStatusCode() === 200 && str_contains($html, 'Only admins can add or change headers')
        ?: 'status ' . $response->getStatusCode();
});

check('the channel screen renders for a non-admin with its templates locked', function() use ($http, $channel) {
    $response = $http->get("admin/airwave/channels/{$channel->id}");
    $html = (string)$response->getBody();

    return $response->getStatusCode() === 200
        && str_contains($html, 'Only admins can change these templates')
        && preg_match('/<input[^>]*id="mapping-title"[^>]*disabled/', $html) === 1
        ?: 'status ' . $response->getStatusCode();
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
