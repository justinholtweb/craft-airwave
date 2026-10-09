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
 * per-feed rather than becoming whatever the crontab says. It also runs the failing-feed alert
 * check when it finishes.
 *
 * `reapply-filters`, `enable`, `disable` and `delete` are the CP's bulk actions, through the same
 * services: name feeds with `--handle=one,two` or pass `--all`.
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

    /** @var bool Act on every feed rather than the ones named with --handle. */
    public bool $all = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'refresh' => array_merge(parent::options($actionID), ['handle', 'force', 'due']),
            'import' => array_merge(parent::options($actionID), ['handle', 'dryRun', 'limit']),
            'prune' => array_merge(parent::options($actionID), ['handle', 'keep']),
            'reapply-filters', 'enable', 'disable', 'delete' => array_merge(parent::options($actionID), ['handle', 'all']),
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

        // Each refresh above already moved its own failing-feed latch; this is what notices a feed
        // that has not refreshed at all. Fail-open, like every other alert path.
        try {
            $plugin->alerts->check();
        } catch (\Throwable $e) {
            $this->stderr('Could not check alerts: ' . $e->getMessage() . "\n", Console::FG_YELLOW);
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
     * Re-runs each feed's current filters over the items it already has, deleting what no longer
     * matches (imported items are left alone). The CP's “Re-apply filters” button, for every feed
     * at once if you like. Pro.
     */
    public function actionReapplyFilters(): int
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsFilters($plugin->isPro())) {
            $this->stderr("Filters are a Pro feature.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $sources = $this->selectSources();

        if ($sources === null) {
            return ExitCode::USAGE;
        }

        $sources = array_filter($sources, fn(Source $source) => !$source->getFilters()->isEmpty());

        if (!$sources) {
            $this->stdout("No matching feed has any filters.\n");

            return ExitCode::OK;
        }

        if (!$this->confirm(sprintf('Delete stored items that no longer match the filters on %d feed(s)?', count($sources)))) {
            return ExitCode::OK;
        }

        foreach ($sources as $source) {
            $result = $plugin->filters->reapply($source);
            $this->stdout(sprintf(
                "%-28s %d removed, %d kept%s\n",
                $source->handle,
                $result['removed'],
                $result['kept'],
                $result['protected'] ? sprintf(', %d imported left alone', $result['protected']) : '',
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Enables feeds — the element index's “Set status → Enabled”.
     */
    public function actionEnable(): int
    {
        return $this->setEnabled(true);
    }

    /**
     * Disables feeds. A disabled feed keeps its items but stops refreshing and rendering.
     */
    public function actionDisable(): int
    {
        return $this->setEnabled(false);
    }

    /**
     * Deletes feeds and everything they have collected — the element index's “Delete”. Soft: a
     * deleted feed can be restored from the trash until garbage collection runs.
     */
    public function actionDelete(): int
    {
        $plugin = Plugin::getInstance();
        $sources = $this->selectSources();

        if ($sources === null) {
            return ExitCode::USAGE;
        }

        if (!$sources) {
            $this->stdout("No matching feeds.\n");

            return ExitCode::OK;
        }

        if (!$this->confirm(sprintf('Delete %d feed(s) and their items?', count($sources)))) {
            return ExitCode::OK;
        }

        $failed = 0;

        foreach ($sources as $source) {
            $ok = $plugin->sources->deleteSource($source);
            $failed += $ok ? 0 : 1;
            $this->stdout(sprintf("%-28s %s\n", $source->handle, $ok ? 'deleted' : 'could not be deleted'), $ok ? Console::FG_GREEN : Console::FG_RED);
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function setEnabled(bool $enabled): int
    {
        $plugin = Plugin::getInstance();
        $sources = $this->selectSources();

        if ($sources === null) {
            return ExitCode::USAGE;
        }

        $failed = 0;

        foreach ($sources as $source) {
            if ($source->enabled === $enabled) {
                continue;
            }

            $source->enabled = $enabled;
            // Same path as the CP's Set Status action: an element save, validated.
            $ok = $plugin->sources->saveSource($source);
            $failed += $ok ? 0 : 1;
            $this->stdout(
                sprintf("%-28s %s\n", $source->handle, $ok ? ($enabled ? 'enabled' : 'disabled') : implode(' ', $source->getFirstErrors())),
                $ok ? Console::FG_GREEN : Console::FG_RED,
            );
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * The feeds named by `--handle` (comma-separated), or every feed with `--all`. Null, with a
     * message, when neither was given: a bulk action with no target should not mean "everything".
     *
     * @return Source[]|null
     */
    private function selectSources(): ?array
    {
        $plugin = Plugin::getInstance();

        if ($this->all) {
            return $plugin->sources->getAllSources(true);
        }

        if (!$this->handle) {
            $this->stderr("Name the feeds with --handle=one,two, or pass --all.\n", Console::FG_RED);

            return null;
        }

        $handles = array_values(array_filter(array_map('trim', explode(',', $this->handle))));
        $sources = $plugin->sources->getSourcesByHandles($handles);
        $missing = array_diff($handles, array_map(fn(Source $source) => $source->handle, $sources));

        foreach ($missing as $handle) {
            $this->stderr("No feed with the handle “{$handle}”.\n", Console::FG_YELLOW);
        }

        return $sources;
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
