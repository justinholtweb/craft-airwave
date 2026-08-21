<?php

namespace justinholtweb\airwave\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\exceptions\FeedException;
use justinholtweb\airwave\models\Edition;
use justinholtweb\airwave\Plugin;
use yii\console\ExitCode;

/**
 * Feeds from the command line — `php craft airwave/feeds/…`.
 *
 * `refresh` is the one that matters operationally. A site that would rather not have front-end
 * requests triggering fetches turns `refreshOnRequest` off and runs
 *
 *     * * * * * php craft airwave/feeds/refresh --due
 *
 * from cron, which fetches only the feeds whose interval has elapsed — so the schedule stays
 * per-feed rather than becoming whatever the crontab says.
 */
class FeedsController extends Controller
{
    /** @var string|null Only act on this feed. */
    public ?string $handle = null;

    /** @var bool Ignore stored ETags and fetch even if the server would answer 304. */
    public bool $force = false;

    /** @var bool Only feeds whose refresh interval has elapsed. */
    public bool $due = false;

    /** @var bool Show what an import would do without writing anything. */
    public bool $dryRun = false;

    /** @var int|null How many items to act on. */
    public ?int $limit = null;

    /** @var int|null How many items to keep per feed when pruning. */
    public ?int $keep = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'refresh' => array_merge(parent::options($actionID), ['handle', 'force', 'due']),
            'import' => array_merge(parent::options($actionID), ['handle', 'dryRun', 'limit']),
            'prune' => array_merge(parent::options($actionID), ['handle', 'keep']),
            default => parent::options($actionID),
        };
    }

    public function optionAliases(): array
    {
        return ['h' => 'handle', 'f' => 'force', 'l' => 'limit'];
    }

    /**
     * Lists every feed, with its health.
     */
    public function actionIndex(): int
    {
        $sources = Plugin::getInstance()->sources->getAllSources(true);

        if (!$sources) {
            $this->stdout("No feeds yet. Add one in the control panel, or try:\n");
            $this->stdout("  php craft airwave/feeds/discover https://example.com\n");

            return ExitCode::OK;
        }

        foreach ($sources as $source) {
            [$colour, $status] = match ($source->lastStatus) {
                Source::STATUS_OK => [Console::FG_GREEN, 'ok'],
                Source::STATUS_ERROR => [Console::FG_RED, 'error'],
                default => [Console::FG_YELLOW, 'never'],
            };

            $this->stdout(sprintf('%-28s ', $source->handle));
            $this->stdout(sprintf('%-7s', $status), $colour);
            $this->stdout(sprintf(
                ' %5d items  %-19s %s%s',
                $source->itemCount,
                $source->lastFetchedAt?->format('Y-m-d H:i') ?: '—',
                $source->getUiLabel(),
                $source->enabled ? '' : ' (disabled)',
            ) . "\n");

            if ($source->lastError) {
                $this->stdout('    ' . $source->lastError . "\n", Console::FG_GREY);
            }
        }

        $stats = Plugin::getInstance()->sources->getStats();
        $this->stdout(sprintf(
            "\n%d feeds, %d items, %d imported, %d failing.\n",
            $stats['sources'],
            $stats['items'],
            $stats['imported'],
            $stats['failing'],
        ));

        return ExitCode::OK;
    }

    /**
     * Fetches feeds and stores what is new.
     */
    public function actionRefresh(): int
    {
        $plugin = Plugin::getInstance();
        $sources = $this->handle
            ? array_filter([$plugin->sources->getSourceByHandle($this->handle)])
            : ($this->due ? $plugin->sources->getDueSources() : $plugin->sources->getAllSources());

        if (!$sources) {
            $this->stdout($this->handle ? "No feed with that handle.\n" : "Nothing to refresh.\n");

            return $this->handle ? ExitCode::DATAERR : ExitCode::OK;
        }

        $failed = 0;

        foreach ($sources as $source) {
            $this->stdout(sprintf('%-28s ', $source->handle));
            $result = $plugin->sources->refresh($source, $this->force);

            if ($result->ok) {
                $this->stdout($result->getSummary() . "\n", $result->new ? Console::FG_GREEN : Console::FG_GREY);
            } else {
                $failed++;
                $this->stdout($result->getSummary() . "\n", Console::FG_RED);
            }
        }

        // A partial failure is still a failure to cron, which is the only consumer that cares.
        return $failed ? ExitCode::UNAVAILABLE : ExitCode::OK;
    }

    /**
     * Turns stored feed items into entries.
     */
    public function actionImport(): int
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsImporting($plugin->isPro())) {
            $this->stderr("Importing feed items as entries is a Pro feature.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $sources = $this->handle
            ? array_filter([$plugin->sources->getSourceByHandle($this->handle)])
            : array_filter($plugin->sources->getAllSources(), fn(Source $source) => $source->importEnabled);

        if (!$sources) {
            $this->stdout("No feeds are set up to import.\n");

            return ExitCode::OK;
        }

        foreach ($sources as $source) {
            $this->stdout("$source->handle\n", Console::FG_CYAN);
            $result = $plugin->importer->import($source, $this->limit, $this->dryRun);

            if ($this->dryRun) {
                foreach ($result->previews as $preview) {
                    $this->stdout(sprintf("  %-19s %s\n", $preview['date'] ?: '—', $preview['title']));
                }
            }

            $this->stdout('  ' . $result->getSummary() . "\n", $result->failed ? Console::FG_YELLOW : Console::FG_GREY);

            foreach ($result->errors as $error) {
                $this->stdout("  ! $error\n", Console::FG_RED);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Drops old items beyond each feed's keep limit.
     */
    public function actionPrune(): int
    {
        $plugin = Plugin::getInstance();
        $sources = $this->handle
            ? array_filter([$plugin->sources->getSourceByHandle($this->handle)])
            : $plugin->sources->getAllSources(true);

        $total = 0;

        foreach ($sources as $source) {
            $removed = $plugin->items->prune($source, $this->keep);
            $total += $removed;

            if ($removed) {
                $this->stdout(sprintf("%-28s %d removed\n", $source->handle, $removed));
            }
        }

        $this->stdout(sprintf("%d %s pruned.\n", $total, $total === 1 ? 'item' : 'items'));

        return ExitCode::OK;
    }

    /**
     * Finds the feeds advertised on a web page.
     *
     * @param string $url A site address — `example.com` is fine.
     */
    public function actionDiscover(string $url): int
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = "https://$url";
        }

        $plugin = Plugin::getInstance();

        try {
            $fetched = $plugin->fetcher->fetchUrl($url);
        } catch (FeedException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $found = $plugin->parser->discover($fetched->body, $fetched->finalUrl ?: $url);

        if (!$found) {
            $this->stdout("No feeds advertised on that page.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        foreach ($found as $feed) {
            $this->stdout('  ' . $feed['url'] . "\n", Console::FG_GREEN);

            if ($feed['title']) {
                $this->stdout('    ' . $feed['title'] . "\n", Console::FG_GREY);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Fetches and parses a URL without subscribing to it — for working out why a feed is not
     * behaving before adding it to the site.
     *
     * @param string $url The feed URL.
     */
    public function actionTest(string $url): int
    {
        $plugin = Plugin::getInstance();

        try {
            $fetched = $plugin->fetcher->fetchUrl($url);
            $parsed = $plugin->parser->parse($fetched->body, $fetched->finalUrl ?: $url);
        } catch (FeedException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout(sprintf("%s\n", $parsed->meta->title ?: '(untitled)'), Console::FG_CYAN);
        $this->stdout(sprintf(
            "  format   %s\n  link     %s\n  items    %d\n  size     %s\n\n",
            strtoupper((string)$parsed->meta->format),
            $parsed->meta->link ?: '—',
            $parsed->count(),
            \Craft::$app->getFormatter()->asShortSize($fetched->bytes),
        ));

        foreach (array_slice($parsed->items, 0, 10) as $item) {
            $this->stdout(sprintf(
                "  %-19s %s\n",
                $item->publishedAt?->format('Y-m-d H:i') ?: '—',
                mb_substr($item->getTitleText(), 0, 70),
            ));
        }

        return ExitCode::OK;
    }
}
