<?php

namespace justinholtweb\airwave\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\exceptions\FeedException;
use justinholtweb\airwave\models\Edition;
use justinholtweb\airwave\models\RefreshResult;
use justinholtweb\airwave\Plugin;
use justinholtweb\airwave\queue\jobs\RefreshSourceJob;
use Throwable;
use yii\base\Component;

/**
 * The feed library, and the refresh cycle.
 *
 * {@see self::refresh()} is the load-bearing method and reads top to bottom as the whole inbound
 * story: fetch conditionally, parse, filter, store, prune, import. Everything else here is
 * lookups and handles.
 *
 * Two rules the refresh never breaks:
 *
 * - **A failed refresh does not empty the feed.** The items already stored stay exactly where they
 *   are and keep rendering; only the health indicator changes. A publisher's bad afternoon should
 *   not blank a section of your homepage.
 * - **Status is written with an update, not an element save.** A refresh touching `lastFetchedAt`
 *   has no business re-validating a URL over DNS or re-indexing search keywords, and doing it
 *   every hour per feed adds up.
 */
class Sources extends Component
{
    /** @var Source[]|null Sources are read many times per request and change rarely. */
    private ?array $_all = null;

    /**
     * @return Source[]
     */
    public function getAllSources(bool $includeDisabled = false): array
    {
        if ($this->_all === null) {
            $this->_all = Source::find()->status(null)->limit(null)->all();
        }

        if ($includeDisabled) {
            return $this->_all;
        }

        return array_values(array_filter($this->_all, fn(Source $source) => $source->enabled));
    }

    public function getSourceById(int $id): ?Source
    {
        return Source::find()->id($id)->status(null)->one();
    }

    public function getSourceByHandle(string $handle): ?Source
    {
        return Source::find()->handle($handle)->status(null)->one();
    }

    /**
     * @param string[] $handles
     * @return Source[]
     */
    public function getSourcesByHandles(array $handles): array
    {
        return $handles ? Source::find()->handle($handles)->status(null)->all() : [];
    }

    public function clearCaches(): void
    {
        $this->_all = null;
    }

    // Handles
    // -------------------------------------------------------------------------

    public function handleIsTaken(string $handle, ?int $exceptId = null): bool
    {
        $query = Source::find()->handle($handle)->status(null)->siteId('*');

        if ($exceptId) {
            $query->id("not $exceptId");
        }

        return $query->exists();
    }

    public function uniqueHandle(string $seed, ?int $exceptId = null): string
    {
        $base = StringHelper::toKebabCase(StringHelper::toAscii($seed));
        $base = trim(preg_replace('/[^a-zA-Z0-9\-_]/', '-', $base) ?? '', '-');

        if ($base === '' || !preg_match('/^[a-zA-Z]/', $base)) {
            $base = 'feed' . ($base !== '' ? "-$base" : '');
        }

        $base = substr($base, 0, 180);
        $handle = $base;
        $suffix = 1;

        while ($this->handleIsTaken($handle, $exceptId)) {
            $handle = $base . '-' . ++$suffix;
        }

        return $handle;
    }

    // Edition
    // -------------------------------------------------------------------------

    public function canCreate(): bool
    {
        return $this->remaining() !== 0;
    }

    /** How many more feeds this edition allows, or null for no limit. */
    public function remaining(): ?int
    {
        $max = Edition::maxSources(Plugin::getInstance()->isPro());

        if ($max === null) {
            return null;
        }

        return max(0, $max - (int)Source::find()->status(null)->count());
    }

    // Saving
    // -------------------------------------------------------------------------

    public function saveSource(Source $source, bool $runValidation = true): bool
    {
        if ($source->id === null && !$this->canCreate()) {
            $source->addError('handle', Craft::t('airwave', 'Airwave Lite holds up to {max} feeds.', [
                'max' => Edition::LITE_MAX_SOURCES,
            ]));

            return false;
        }

        $saved = Craft::$app->getElements()->saveElement($source, $runValidation);

        if ($saved) {
            $this->clearCaches();
        }

        return $saved;
    }

    public function deleteSource(Source $source): bool
    {
        $deleted = Craft::$app->getElements()->deleteElement($source);

        if ($deleted) {
            $this->clearCaches();
        }

        return $deleted;
    }

    // Refreshing
    // -------------------------------------------------------------------------

    /**
     * Brings one feed up to date.
     *
     * @param bool $force Ignore the stored ETag, and re-fetch even if the server would say 304.
     * Used by the CP's "refresh now" button, because a person clicking a button expects a real
     * request, not a conversation about cache headers.
     */
    public function refresh(Source $source, bool $force = false): RefreshResult
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $now = new DateTime('now', new DateTimeZone('UTC'));

        try {
            $fetched = $plugin->fetcher->fetch($source, !$force);
        } catch (FeedException $e) {
            $this->recordFailure($source, $e->getMessage(), $now);

            return RefreshResult::failed($e->getMessage(), $e->isTransient);
        } catch (Throwable $e) {
            Craft::error("Airwave could not refresh {$source->handle}: {$e->getMessage()}", 'airwave');
            $this->recordFailure($source, $e->getMessage(), $now);

            return RefreshResult::failed($e->getMessage());
        }

        if ($fetched->notModified) {
            $this->recordStatus($source, [
                'lastFetchedAt' => Db::prepareDateForDb($now),
                'lastSuccessAt' => Db::prepareDateForDb($now),
                'lastStatus' => Source::STATUS_OK,
                'lastError' => null,
                'newItemCount' => 0,
            ]);
            $plugin->alerts->afterRefresh($source, true);

            return RefreshResult::unchanged();
        }

        try {
            $parsed = $plugin->parser->parse($fetched->body, $fetched->finalUrl ?: $source->url);
        } catch (FeedException $e) {
            $this->recordFailure($source, $e->getMessage(), $now);

            return RefreshResult::failed($e->getMessage());
        }

        $result = new RefreshResult();
        $items = $parsed->items;
        $before = count($items);

        // Filters run on the way in, so an item that was never wanted is never stored and can
        // never be imported into an entry by accident.
        if (Edition::allowsFilters($plugin->isPro())) {
            $items = $source->getFilters()->apply($items);
        }

        $result->rejected = $before - count($items);

        // A feed's own item cap is applied after filtering: "keep 10" means ten items you wanted,
        // not ten items of which two survived.
        $limit = $source->itemLimit ?: null;

        if ($limit) {
            $items = array_slice($items, 0, $limit);
        }

        $stored = $plugin->items->store($source, $items);
        $result->new = $stored['new'];
        $result->updated = $stored['updated'];
        $result->pruned = $plugin->items->prune($source, $limit ?: $settings->keepItemsPerSource);

        $meta = $parsed->meta;
        $source->setMeta($meta);

        $this->recordStatus($source, [
            'meta' => json_encode($meta->toArray()),
            'etag' => $fetched->etag,
            'lastModifiedHeader' => $fetched->lastModified,
            'lastFetchedAt' => Db::prepareDateForDb($now),
            'lastSuccessAt' => Db::prepareDateForDb($now),
            'lastStatus' => Source::STATUS_OK,
            'lastError' => null,
            'itemCount' => $plugin->items->countForSource((int)$source->id),
            'newItemCount' => $result->new,
        ]);
        // Before the import: a failing import is a mapping problem, not a failing feed.
        $plugin->alerts->afterRefresh($source, true);

        if ($source->importEnabled && $settings->importOnRefresh && Edition::allowsImporting($plugin->isPro())) {
            try {
                $result->imported = $plugin->importer->import($source)->created;
            } catch (Throwable $e) {
                // An import that blows up must not mark the fetch as failed — the items are
                // stored, the feed is healthy, and the problem is in the mapping.
                Craft::error("Airwave imported nothing from {$source->handle}: {$e->getMessage()}", 'airwave');
            }
        }

        return $result;
    }

    /**
     * Brings a feed up to date if it is stale, in whichever way costs the request least.
     *
     * A feed that has **never** been fetched is fetched inline: a template that renders nothing at
     * all on its first hit looks broken, and one slow request beats an empty page. A feed that is
     * merely stale queues a job instead, so a remote server having a bad day can never become a
     * slow page here.
     */
    public function refreshIfDue(Source $source): void
    {
        if (!Plugin::getInstance()->getSettings()->refreshOnRequest || !$source->getIsDue() || !$source->enabled) {
            return;
        }

        if (!$source->getHasBeenFetched()) {
            $this->refresh($source);

            return;
        }

        $this->queueRefresh($source);
    }

    public function queueRefresh(Source $source, bool $force = false): void
    {
        // `lastFetchedAt` is moved forward *now*, before the job runs, so that ten concurrent
        // requests for a stale feed queue one job rather than ten.
        $this->recordStatus($source, ['lastFetchedAt' => Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))]);

        Craft::$app->getQueue()->push(new RefreshSourceJob([
            'sourceId' => $source->id,
            'force' => $force,
            'description' => Craft::t('airwave', 'Refreshing feed “{name}”', ['name' => $source->getUiLabel()]),
        ]));
    }

    /**
     * @return array<string, RefreshResult> Keyed by handle.
     */
    public function refreshAll(bool $force = false, bool $dueOnly = false): array
    {
        $results = [];

        foreach ($this->getAllSources() as $source) {
            if ($dueOnly && !$source->getIsDue()) {
                continue;
            }

            $results[$source->handle] = $this->refresh($source, $force);
        }

        return $results;
    }

    /**
     * The feeds a scheduled run should pick up.
     *
     * @return Source[]
     */
    public function getDueSources(): array
    {
        return array_values(array_filter($this->getAllSources(), fn(Source $source) => $source->getIsDue()));
    }

    // Status
    // -------------------------------------------------------------------------

    /**
     * Writes fetch bookkeeping without going through the element save.
     *
     * Deliberate: a refresh has no business re-validating the URL over DNS, re-running the handle
     * uniqueness query, bumping `dateUpdated` or re-indexing search keywords. It also keeps the
     * in-memory element in step, so a caller that goes on to read `$source->lastStatus` sees the
     * truth.
     *
     * @param array<string, mixed> $columns
     */
    private function recordStatus(Source $source, array $columns): void
    {
        if (!$source->id) {
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->update('{{%airwave_sources}}', $columns + [
                'dateUpdated' => Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC'))),
            ], ['id' => $source->id])
            ->execute();

        foreach ($columns as $column => $value) {
            match ($column) {
                'lastFetchedAt', 'lastSuccessAt' => $source->$column = $value ? new DateTime($value, new DateTimeZone('UTC')) : null,
                'meta' => $source->setMeta($value),
                'itemCount', 'newItemCount' => $source->$column = (int)$value,
                'lastStatus', 'lastError', 'etag', 'lastModifiedHeader' => $source->$column = $value,
                default => null,
            };
        }
    }

    private function recordFailure(Source $source, string $message, DateTime $now): void
    {
        $this->recordStatus($source, [
            'lastFetchedAt' => Db::prepareDateForDb($now),
            'lastStatus' => Source::STATUS_ERROR,
            // Truncated: some servers answer a failed request with an HTML page, and the whole
            // thing in a text column helps nobody.
            'lastError' => mb_substr($message, 0, 1000),
            'newItemCount' => 0,
        ]);

        // Fail-open inside: counts the failure and sends an alert if this one crossed the line.
        Plugin::getInstance()->alerts->afterRefresh($source, false);
    }

    /**
     * Feeds that are currently failing, for the CP dashboard and the console.
     *
     * @return Source[]
     */
    public function getFailingSources(): array
    {
        return array_values(array_filter(
            $this->getAllSources(true),
            fn(Source $source) => $source->lastStatus === Source::STATUS_ERROR,
        ));
    }

    /**
     * @return array{sources: int, items: int, failing: int, imported: int}
     */
    public function getStats(): array
    {
        $sources = $this->getAllSources(true);
        // Scoped to feeds that still exist. Deleting a feed is a *soft* delete, so its items stay
        // in the table until garbage collection cascades them away — counting those would report
        // items nothing can reach and no screen can show.
        $sourceIds = array_map(fn(Source $source) => $source->id, $sources);

        if (!$sourceIds) {
            return ['sources' => 0, 'items' => 0, 'failing' => 0, 'imported' => 0];
        }

        return [
            'sources' => count($sources),
            'items' => (int)(new Query())
                ->from('{{%airwave_items}}')
                ->where(['sourceId' => $sourceIds])
                ->count(),
            'failing' => count($this->getFailingSources()),
            'imported' => (int)(new Query())
                ->from('{{%airwave_items}}')
                ->where(['sourceId' => $sourceIds])
                ->andWhere(['not', ['importedElementId' => null]])
                ->count(),
        ];
    }
}
