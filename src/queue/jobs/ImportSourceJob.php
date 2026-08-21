<?php

namespace justinholtweb\airwave\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\airwave\Plugin;

/**
 * Turns a feed's stored items into entries in the background.
 *
 * Separate from {@see RefreshSourceJob} because a first import of a large backlog is a long job
 * that has nothing to do with fetching, and because the CP offers "import now" on its own.
 * Re-queues itself while there is more to do, so a 500-item backlog is a chain of capped runs
 * rather than one job that times out and loses all of them.
 */
class ImportSourceJob extends BaseJob
{
    public ?int $sourceId = null;

    /** Whether to keep going once this run's cap is reached. */
    public bool $continue = true;

    public function execute($queue): void
    {
        if (!$this->sourceId) {
            return;
        }

        $plugin = Plugin::getInstance();
        $source = $plugin->sources->getSourceById($this->sourceId);

        if (!$source || !$source->importEnabled) {
            return;
        }

        $this->setProgress($queue, 0, Craft::t('airwave', 'Importing from {name}', ['name' => $source->getUiLabel()]));

        $result = $plugin->importer->import($source);

        $this->setProgress($queue, 1);

        // Nothing created means either the backlog is done or every remaining item is failing;
        // either way, chaining another run would loop.
        if ($this->continue && $result->created > 0) {
            $remaining = $plugin->items->count(['sourceId' => $source->id, 'imported' => false]);

            if ($remaining > 0) {
                Craft::$app->getQueue()->push(new self([
                    'sourceId' => $this->sourceId,
                    'continue' => true,
                ]));
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('airwave', 'Importing feed items');
    }
}
