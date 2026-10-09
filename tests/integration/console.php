<?php
/**
 * The console equivalents of the CP's bulk and maintenance actions, run for real.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-airwave/tests/integration/console.php
 *
 * Sets up two feeds with stored items and a channel, then runs each command as a separate
 * `php craft …` process — the way cron and a person would — and reads back what changed.
 *
 * Switches the edition to Pro for the run (re-apply filters is Pro) and back in a shutdown
 * handler, saving project config both times. Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\models\ChannelMapping;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\Plugin;

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

/** @return array{0:int,1:string} exit code and combined output */
function craft(string $args): array
{
    exec('php craft ' . $args . ' 2>&1', $out, $code);

    return [$code, implode("\n", $out)];
}

$plugin = Plugin::getInstance();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
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

    // Anything a command soft-deleted.
    foreach ([Source::find(), Channel::find()] as $query) {
        foreach ($query->trashed()->status(null)->limit(null)->all() as $element) {
            if (str_starts_with((string)$element->handle, 'aw-con-')) {
                Craft::$app->getElements()->deleteElement($element, true);
            }
        }
    }

    Craft::$app->getPlugins()->switchEdition('airwave', $originalEdition);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
});

$makeSource = function(string $suffix) use ($plugin, $run, &$created): Source {
    $source = new Source();
    $source->title = "Console feed $suffix $run";
    $source->handle = "aw-con-$suffix-$run";
    $source->url = "http://aw-con-$suffix-$run.invalid/feed.xml";
    $source->enabled = true;
    $plugin->sources->saveSource($source) or throw new RuntimeException(json_encode($source->getErrors()));
    $created[] = $source;

    return $source;
};

$a = $makeSource('a');
$b = $makeSource('b');

$items = [];
foreach (['Cycling news', 'Sponsored post', 'Cycling race', 'Old story'] as $i => $title) {
    $items[] = new Item([
        'guid' => "aw-con-$run-$i",
        'url' => "https://example.com/$run/$i",
        'title' => $title,
        'summary' => "$title summary",
        'publishedAt' => new DateTime($title === 'Old story' ? '2019-06-01' : 'now', new DateTimeZone('UTC')),
    ]);
}
$plugin->items->store($a, $items);
$plugin->items->store($b, $items);

$count = fn(Source $s, array $extra = []) => (int)(new Query())->from('{{%airwave_items}}')->where(['sourceId' => $s->id] + $extra)->count();
$fresh = fn(Source $s) => $plugin->sources->getSourceById($s->id);

// One of a's items has become an entry (any element id will do for the FK).
$importedId = (int)(new Query())->select(['id'])->from('{{%airwave_items}}')->where(['sourceId' => $a->id, 'title' => 'Sponsored post'])->scalar();
$anyElement = (int)(new Query())->select(['id'])->from('{{%elements}}')->where(['type' => 'craft\\elements\\Entry'])->scalar();
Craft::$app->getDb()->createCommand()->update('{{%airwave_items}}', ['importedElementId' => $anyElement], ['id' => $importedId])->execute();

$channel = new Channel();
$channel->title = "Console channel $run";
$channel->handle = "aw-con-ch-$run";
$channel->uri = "aw-con-ch-$run.xml";
$channel->targetSiteId = Craft::$app->getSites()->getPrimarySite()->id;
$channel->feedSource = Channel::SOURCE_ELEMENTS;
$channel->setCriteria([]);
$channel->setMapping(ChannelMapping::fromArray([]));
$plugin->channels->saveChannel($channel) or throw new RuntimeException(json_encode($channel->getErrors()));
$created[] = $channel;

echo "\nairwave/items/delete\n";

check('refuses to run with no filter at all', function() {
    [$code, $out] = craft('airwave/items/delete --interactive=0');

    return $code === 64 && str_contains($out, 'Choose items') ?: "$code $out";
});

check('--dry-run counts and deletes nothing', function() use ($a, $count, $run) {
    [$code, $out] = craft("airwave/items/delete --handle=aw-con-a-$run --dry-run");

    // 4 stored, 1 imported and so skipped.
    return $code === 0 && str_contains($out, '3 items would be deleted') && $count($a) === 4 ?: "$code $out";
});

check('--before deletes old items only, through the same service as the CP', function() use ($a, $b, $count, $run) {
    [$code, $out] = craft("airwave/items/delete --handle=aw-con-a-$run --before=2020-01-01 --interactive=0");

    return $code === 0 && $count($a) === 3 && $count($b) === 4 ?: "$code $out a=" . $count($a);
});

check('imported items are skipped unless --include-imported', function() use ($a, $count, $run, $importedId) {
    craft("airwave/items/delete --handle=aw-con-a-$run --search=Sponsored --interactive=0");
    $kept = $count($a, ['id' => $importedId]) === 1;
    craft("airwave/items/delete --handle=aw-con-a-$run --search=Sponsored --include-imported --interactive=0");

    return $kept && $count($a, ['id' => $importedId]) === 0 ?: 'kept=' . var_export($kept, true);
});

check('--ids deletes exactly those items', function() use ($b, $count) {
    $id = (int)(new Query())->select(['id'])->from('{{%airwave_items}}')->where(['sourceId' => $b->id, 'title' => 'Old story'])->scalar();
    [$code, $out] = craft("airwave/items/delete --ids=$id --interactive=0");

    return $code === 0 && $count($b) === 3 && $count($b, ['id' => $id]) === 0 ?: "$code $out";
});

echo "\nairwave/feeds/reapply-filters\n";

check('needs --handle or --all', function() {
    [$code, $out] = craft('airwave/feeds/reapply-filters --interactive=0');

    return $code === 64 && str_contains($out, '--all') ?: "$code $out";
});

check('re-applies a feed\'s current filters and leaves other feeds alone', function() use ($a, $b, $plugin, $count, $run) {
    $source = $plugin->sources->getSourceById($a->id);
    $source->setFilters(['include' => 'cycling']);
    $plugin->sources->saveSource($source) or throw new RuntimeException(json_encode($source->getErrors()));
    [$code, $out] = craft("airwave/feeds/reapply-filters --handle=aw-con-a-$run,aw-con-b-$run --interactive=0");

    // a: Cycling news + Cycling race kept. b has no filters, so it is not touched at all.
    return $code === 0 && $count($a) === 2 && $count($b) === 3 && str_contains($out, 'aw-con-a-') && !str_contains($out, 'aw-con-b-')
        ?: "$code $out a=" . $count($a) . ' b=' . $count($b);
});

echo "\nairwave/feeds/disable, enable, delete\n";

check('disable and enable go through an element save', function() use ($a, $fresh, $run) {
    [$c1] = craft("airwave/feeds/disable --handle=aw-con-a-$run");
    $off = $fresh($a)->enabled === false;
    [$c2] = craft("airwave/feeds/enable --handle=aw-con-a-$run");

    return $c1 === 0 && $c2 === 0 && $off && $fresh($a)->enabled === true ?: "codes $c1/$c2 off=" . var_export($off, true);
});

check('an unknown handle is reported, not silently ignored', function() {
    [$code, $out] = craft('airwave/feeds/disable --handle=aw-con-nope');

    return $code === 0 && str_contains($out, 'No feed with the handle') ?: "$code $out";
});

check('delete soft-deletes the feed', function() use ($b, $plugin, $run) {
    [$code, $out] = craft("airwave/feeds/delete --handle=aw-con-b-$run --interactive=0");
    $trashed = Source::find()->id($b->id)->trashed()->status(null)->one();

    return $code === 0 && $plugin->sources->getSourceById($b->id) === null && $trashed !== null ?: "$code $out";
});

echo "\nairwave/channels/disable, enable, delete\n";

check('disable and enable a channel', function() use ($channel, $plugin, $run) {
    [$c1] = craft("airwave/channels/disable --handle=aw-con-ch-$run");
    $off = $plugin->channels->getChannelById($channel->id)->enabled === false;
    [$c2] = craft("airwave/channels/enable --handle=aw-con-ch-$run");

    return $c1 === 0 && $c2 === 0 && $off && $plugin->channels->getChannelById($channel->id)->enabled === true ?: "codes $c1/$c2";
});

check('delete a channel', function() use ($channel, $plugin, $run) {
    [$code, $out] = craft("airwave/channels/delete --handle=aw-con-ch-$run --interactive=0");

    return $code === 0 && $plugin->channels->getChannelById($channel->id) === null ?: "$code $out";
});

echo "\nairwave/alerts\n";

check('alerts/check evaluates every enabled feed and exits 0', function() use ($run) {
    [$code, $out] = craft('airwave/alerts/check');

    return $code === 0 && str_contains($out, "aw-con-a-$run") && str_contains($out, 'open incident') ?: "$code $out";
});

check('alerts/test with nothing configured says so (exit 78)', function() use ($plugin) {
    $saved = Craft::$app->getPlugins()->getStoredPluginInfo('airwave')['settings'] ?? [];

    if (trim((string)($saved['alertRecipients'] ?? '')) !== '' || trim((string)($saved['alertWebhookUrl'] ?? '')) !== '') {
        return true; // configured on this site; nothing to assert without sending
    }

    [$code, $out] = craft('airwave/alerts/test');

    return $code === 78 && str_contains($out, 'nothing to send') ?: "$code $out";
});

check('feeds/refresh records the failure in the alert latch', function() use ($a, $run) {
    [$code] = craft("airwave/feeds/refresh --handle=aw-con-a-$run");
    $failures = (int)(new Query())->select(['failures'])->from('{{%airwave_alerts}}')->where(['sourceId' => $a->id, 'incident' => 'failing'])->scalar();

    return $code !== 0 && $failures === 1 ?: "code $code failures $failures";
});

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
