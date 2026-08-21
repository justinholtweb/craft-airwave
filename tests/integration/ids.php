<?php
/**
 * Prints `handle id` for every feed and channel, so the shell smoke test can build edit URLs
 * without scraping them out of a JavaScript-rendered element index.
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\Plugin;

// `--item` prints the id of one stored item instead, for the item detail screen.
if (in_array('--item', $argv, true)) {
    $items = Plugin::getInstance()->items->find(['limit' => 1, 'includeDisabled' => true]);

    if ($items) {
        echo $items[0]->id, "\n";
    }

    exit(0);
}

foreach (Source::find()->status(null)->limit(null)->all() as $source) {
    echo "feed $source->handle $source->id\n";
}

foreach (Channel::find()->status(null)->limit(null)->all() as $channel) {
    echo "channel $channel->handle $channel->id\n";
}
