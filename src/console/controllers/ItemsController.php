<?php

namespace justinholtweb\airwave\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use DateTimeZone;
use justinholtweb\airwave\Plugin;
use yii\console\ExitCode;

/**
 * Stored feed items from the command line — `php craft airwave/items/…`.
 *
 * `delete` is the CP's “delete items” action (`airwave/items/delete`), through the same
 * `Items::deleteByIds()`, with the Items screen's filters to choose them by.
 */
class ItemsController extends Controller
{
    /** @var string|null Item IDs, comma-separated. */
    public ?string $ids = null;

    /** @var string|null Only items from these feeds (handles, comma-separated). */
    public ?string $handle = null;

    /** @var string|null Only items whose title or summary contains this. */
    public ?string $search = null;

    /** @var string|null Only items published before this date (anything `strtotime()` reads). */
    public ?string $before = null;

    /** @var bool Also delete items that have been imported as entries. */
    public bool $includeImported = false;

    /** @var bool Show how many items would go, and delete nothing. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'delete' => array_merge(parent::options($actionID), ['ids', 'handle', 'search', 'before', 'includeImported', 'dryRun']),
            default => parent::options($actionID),
        };
    }

    /**
     * Deletes stored items by ID or by filter.
     *
     * Imported items are skipped unless `--include-imported` is passed: the entry an item became
     * points at it, and a deleted row would import again as a duplicate on the next refresh. A
     * deleted item that is still in the feed comes back on the next refresh too — to keep it
     * away, use a filter.
     */
    public function actionDelete(): int
    {
        $items = Plugin::getInstance()->items;
        $criteria = ['limit' => null, 'includeDisabled' => true, 'withContent' => false];

        if ($this->ids !== null) {
            $ids = array_values(array_filter(array_map('intval', explode(',', $this->ids))));

            if (!$ids) {
                $this->stderr("--ids needs at least one item ID.\n", Console::FG_RED);

                return ExitCode::USAGE;
            }

            $criteria['id'] = $ids;
        }

        if ($this->handle) {
            $criteria['sourceHandle'] = array_values(array_filter(array_map('trim', explode(',', $this->handle))));
        }

        if ($this->search !== null && trim($this->search) !== '') {
            $criteria['search'] = trim($this->search);
        }

        if ($this->before) {
            $timestamp = strtotime($this->before);

            if ($timestamp === false) {
                $this->stderr("--before isn't a date I can read.\n", Console::FG_RED);

                return ExitCode::USAGE;
            }

            $criteria['until'] = (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
        }

        // No filter at all would mean every item on the site — almost certainly a typo.
        if (count($criteria) === 3) {
            $this->stderr("Choose items with --ids, --handle, --search or --before.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if (!$this->includeImported) {
            $criteria['imported'] = false;
        }

        $ids = array_map(fn($item) => (int)$item->id, $items->find($criteria));

        if ($this->dryRun) {
            $this->stdout(sprintf("%d %s would be deleted.\n", count($ids), count($ids) === 1 ? 'item' : 'items'));

            return ExitCode::OK;
        }

        if (!$ids) {
            $this->stdout("No items match.\n");

            return ExitCode::OK;
        }

        if (!$this->confirm(sprintf('Delete %d %s?', count($ids), count($ids) === 1 ? 'item' : 'items'))) {
            return ExitCode::OK;
        }

        $deleted = 0;

        // Chunked, so a "everything before 2020" on a big table is a series of short statements
        // rather than one IN list with a hundred thousand members.
        foreach (array_chunk($ids, 500) as $chunk) {
            $deleted += $items->deleteByIds($chunk);
        }

        $this->stdout(sprintf("%d %s deleted.\n", $deleted, $deleted === 1 ? 'item' : 'items'), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
