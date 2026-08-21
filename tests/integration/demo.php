<?php
/**
 * Sets up a live demo in the harness: a real subscribed feed, and a channel re-broadcasting it.
 *
 *     ddev exec php /var/www/craft-airwave/tests/integration/demo.php
 *     curl -s https://plugin-testing.ddev.site/airwave-demo.xml | head
 *
 * Re-runnable — it reuses what is already there rather than making a second copy.
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\Plugin;

$plugin = Plugin::getInstance();
Craft::$app->getPlugins()->switchEdition('airwave', Plugin::EDITION_PRO);

$source = $plugin->sources->getSourceByHandle('craft-news') ?? new Source();
$source->title = 'Craft CMS Blog';
$source->handle = 'craft-news';
$source->url = 'https://craftcms.com/blog.rss';
$source->enabled = true;
$source->itemLimit = 20;

if (!$plugin->sources->saveSource($source)) {
    echo "could not save the feed: " . json_encode($source->getErrors()) . "\n";
    exit(1);
}

$result = $plugin->sources->refresh($source, true);
echo "feed: {$source->handle} — {$result->getSummary()}\n";

$channel = $plugin->channels->getChannelByHandle('airwave-demo') ?? new Channel();
$channel->title = 'Airwave demo';
$channel->handle = 'airwave-demo';
$channel->uri = 'airwave-demo.xml';
$channel->targetSiteId = Craft::$app->getSites()->getPrimarySite()->id;
$channel->format = Channel::FORMAT_RSS;
$channel->feedSource = Channel::SOURCE_FEEDS;
$channel->setSourceIds([$source->id]);
$channel->itemLimit = 10;
$channel->feedTitle = 'Airwave demo — curated & collected';
$channel->feedDescription = 'Re-broadcasting what Airwave has collected';
$channel->cacheDuration = 0;
$channel->enabled = true;

if (!$plugin->channels->saveChannel($channel)) {
    echo "could not save the channel: " . json_encode($channel->getErrors()) . "\n";
    exit(1);
}

echo "channel: {$channel->getUrl()}\n";
echo "items:   " . count($channel->getItems()) . "\n";
