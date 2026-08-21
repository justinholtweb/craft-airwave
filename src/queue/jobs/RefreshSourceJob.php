<?php

namespace justinholtweb\airwave\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\airwave\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * Refreshes one feed in the background.
 *
 * Retryable, and the retry policy is the interesting part: a feed that failed because the server
 * timed out is worth trying again in a few minutes, and a feed that failed because the URL 404s is
 * not. {@see \justinholtweb\airwave\models\RefreshResult::$isTransient} carries that distinction
 * out of the service, and this job throws only for the transient case — a permanent failure is
 * already recorded on the source, where somebody can see it, and a red job in the queue on top of
 * that is noise.
 */
class RefreshSourceJob extends BaseJob implements RetryableJobInterface
{
    public ?int $sourceId = null;
    public bool $force = false;

    public function execute($queue): void
    {
        if (!$this->sourceId) {
            return;
        }

        $plugin = Plugin::getInstance();
        $source = $plugin->sources->getSourceById($this->sourceId);

        if (!$source) {
            // Deleted between queueing and running. Nothing to do, and nothing to complain about.
            return;
        }

        $this->setProgress($queue, 0, Craft::t('airwave', 'Fetching {name}', ['name' => $source->getUiLabel()]));

        $result = $plugin->sources->refresh($source, $this->force);

        $this->setProgress($queue, 1);

        if (!$result->ok && $result->isTransient) {
            throw new \RuntimeException((string)$result->error);
        }
    }

    public function getTtr(): int
    {
        // Generous: the fetch has its own timeout, but a large feed on a slow connection followed
        // by an import of 25 items with images is not a five-second job.
        return 300;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('airwave', 'Refreshing a feed');
    }
}
