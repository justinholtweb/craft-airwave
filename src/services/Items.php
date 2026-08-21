<?php

namespace justinholtweb\airwave\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\Plugin;
use yii\base\Component;

/**
 * The item store.
 *
 * Items are rows, not elements — see {@see \justinholtweb\airwave\migrations\Install} for why — so
 * this service is where the query builder lives instead of an element query. Everything that reads
 * items goes through {@see self::find()}, including the renderer, the importer and channels that
 * re-broadcast, so "which items are visible" has one answer.
 *
 * The invariant worth knowing: **a refresh never deletes an item that has been imported.** Its
 * entry points at it, and a deleted row re-imports as a duplicate on the next run.
 */
class Items extends Component
{
    /** Columns read when a caller does not need the article body. */
    private const LIGHT_COLUMNS = [
        'id', 'sourceId', 'guid', 'url', 'title', 'summary', 'author', 'imageUrl',
        'enclosureUrl', 'enclosureType', 'enclosureLength', 'categories', 'extra',
        'publishedAt', 'fetchedAt', 'importedElementId',
    ];

    /**
     * Finds items.
     *
     * Recognised criteria: `sourceId`, `sourceHandle`, `id`, `limit`, `offset`, `search`,
     * `category`, `author`, `since`, `until`, `sort`, `imported`, `withContent`, `includeDisabled`.
     *
     * @param array<string, mixed> $criteria
     * @return Item[]
     */
    public function find(array $criteria = []): array
    {
        $query = $this->buildQuery($criteria);

        if (!$query) {
            return [];
        }

        $withContent = (bool)($criteria['withContent'] ?? true);
        $query->select($withContent ? array_merge(self::LIGHT_COLUMNS, ['content']) : self::LIGHT_COLUMNS);

        $this->applySort($query, (string)($criteria['sort'] ?? 'newest'));

        if (!empty($criteria['limit'])) {
            $query->limit((int)$criteria['limit']);
        }

        if (!empty($criteria['offset'])) {
            $query->offset((int)$criteria['offset']);
        }

        return $this->hydrate($query->all());
    }

    /**
     * Everything both {@see self::find()} and {@see self::count()} agree on: which feeds are
     * visible, and which items within them match. Ordering and paging are the caller's business.
     *
     * @param array<string, mixed> $criteria
     * @return Query|null Null when no feed matches, which is different from "no filters".
     */
    private function buildQuery(array $criteria): ?Query
    {
        $sourceIds = $this->resolveSourceIds($criteria);

        if ($sourceIds === []) {
            return null;
        }

        $query = (new Query())
            ->from('{{%airwave_items}}')
            ->where(['sourceId' => $sourceIds]);

        if (!empty($criteria['id'])) {
            $query->andWhere(['id' => $criteria['id']]);
        }

        if (!empty($criteria['search'])) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$criteria['search']) . '%';
            $query->andWhere(['or', ['like', 'title', $term, false], ['like', 'summary', $term, false]]);
        }

        if (!empty($criteria['author'])) {
            $query->andWhere(Db::parseParam('author', $criteria['author']));
        }

        if (!empty($criteria['category'])) {
            // Categories are a JSON array in a text column. A LIKE over the encoded form is
            // approximate — it would also match a category containing another as a substring — and
            // that is the right trade for a feature nobody sorts a database around.
            $query->andWhere(['like', 'categories', '"' . trim((string)$criteria['category']) . '"', false]);
        }

        if (!empty($criteria['since'])) {
            $query->andWhere(['>=', 'publishedAt', Db::prepareDateForDb($criteria['since'])]);
        }

        if (!empty($criteria['until'])) {
            $query->andWhere(['<=', 'publishedAt', Db::prepareDateForDb($criteria['until'])]);
        }

        if (array_key_exists('imported', $criteria) && $criteria['imported'] !== null) {
            $query->andWhere($criteria['imported'] ? ['not', ['importedElementId' => null]] : ['importedElementId' => null]);
        }

        return $query;
    }

    /**
     * How many items match, ignoring `limit` and `offset`.
     *
     * Built from the same method as {@see self::find()} on purpose. A count that quietly ignored
     * `imported` would make {@see \justinholtweb\airwave\queue\jobs\ImportSourceJob} re-queue
     * itself forever against a backlog that was already empty.
     *
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        $query = $this->buildQuery($criteria);

        return $query ? (int)$query->count() : 0;
    }

    public function getItemById(int $id): ?Item
    {
        $row = (new Query())
            ->from('{{%airwave_items}}')
            ->where(['id' => $id])
            ->one();

        return $row ? $this->hydrate([$row])[0] : null;
    }

    /**
     * Writes a batch of freshly parsed items.
     *
     * Upsert rather than insert-if-missing: publishers do edit items in place — fixing a headline,
     * adding the image they forgot — and a reader that never updates shows the typo forever. The
     * new-item count comes from the hashes that were there *before*, so an edited item is not
     * counted as new and does not re-trigger an import.
     *
     * @param Item[] $items
     * @return array{new: int, updated: int}
     */
    public function store(Source $source, array $items): array
    {
        if (!$items || !$source->id) {
            return ['new' => 0, 'updated' => 0];
        }

        $existing = array_flip((new Query())
            ->select(['guidHash'])
            ->from('{{%airwave_items}}')
            ->where(['sourceId' => $source->id])
            ->column());

        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));
        $db = Craft::$app->getDb();
        $new = 0;
        $updated = 0;
        $seen = [];

        $transaction = $db->beginTransaction();

        try {
            foreach ($items as $item) {
                $hash = $item->getGuidHash();

                // A feed repeating the same GUID twice in one document is not rare, and the second
                // one would upsert over the first for no gain.
                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $isNew = !isset($existing[$hash]);

                $columns = [
                    'guid' => $this->clip($item->guid, 65535),
                    'url' => $item->url,
                    'title' => $this->clip($item->title, 65535),
                    'summary' => $this->clip($item->summary, 65535),
                    'content' => $this->clip($item->content, 16777215),
                    'author' => $this->clip($item->author, 255),
                    'imageUrl' => $item->imageUrl,
                    'enclosureUrl' => $item->enclosureUrl,
                    'enclosureType' => $this->clip($item->enclosureType, 255),
                    'enclosureLength' => $item->enclosureLength,
                    'categories' => $item->categories ? json_encode($item->categories) : null,
                    'extra' => $item->extra ? json_encode($item->extra) : null,
                    'publishedAt' => Db::prepareDateForDb($item->publishedAt),
                    'dateUpdated' => $now,
                ];

                $isNew ? $new++ : $updated++;

                // The insert half always carries `fetchedAt`, `dateCreated` and `uid`, even for a
                // row that already exists: an upsert is one statement, MySQL parses the INSERT
                // whether or not it uses it, and a NOT NULL column missing from it fails under
                // strict mode. The *update* half deliberately omits `fetchedAt` — it is when
                // Airwave first saw the item, which is what undated feeds are ordered by, and
                // rewriting it every refresh would reshuffle them hourly.
                //
                // `updateTimestamp` is off because `dateUpdated` is written explicitly above.
                Db::upsert('{{%airwave_items}}', [
                    'sourceId' => $source->id,
                    'guidHash' => $hash,
                    'fetchedAt' => $now,
                    'dateCreated' => $now,
                    'uid' => StringHelper::UUID(),
                ] + $columns, $columns, [], false, $db);
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return ['new' => $new, 'updated' => $updated];
    }

    /**
     * Drops the oldest items beyond the keep limit.
     *
     * Imported items are exempt: their entries point at them, and deleting the row would let the
     * next refresh import the same article a second time.
     */
    public function prune(Source $source, ?int $keep = null): int
    {
        $keep = $keep ?? Plugin::getInstance()->getSettings()->keepItemsPerSource;

        if ($keep < 1 || !$source->id) {
            return 0;
        }

        $doomed = (new Query())
            ->select(['id'])
            ->from('{{%airwave_items}}')
            ->where(['sourceId' => $source->id, 'importedElementId' => null])
            ->orderBy(['COALESCE([[publishedAt]], [[fetchedAt]])' => SORT_DESC, 'id' => SORT_DESC])
            ->offset($keep)
            ->limit(10000)
            ->column();

        if (!$doomed) {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->delete('{{%airwave_items}}', ['id' => $doomed])
            ->execute();
    }

    public function deleteAllForSource(Source $source): int
    {
        if (!$source->id) {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->delete('{{%airwave_items}}', ['sourceId' => $source->id])
            ->execute();
    }

    /**
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): int
    {
        if (!$ids) {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->delete('{{%airwave_items}}', ['id' => $ids])
            ->execute();
    }

    public function markImported(int $itemId, ?int $elementId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%airwave_items}}', [
                'importedElementId' => $elementId,
                'dateUpdated' => Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC'))),
            ], ['id' => $itemId])
            ->execute();
    }

    public function countForSource(int $sourceId): int
    {
        return (int)(new Query())
            ->from('{{%airwave_items}}')
            ->where(['sourceId' => $sourceId])
            ->count();
    }

    /**
     * The categories a feed's items actually carry, most common first — the list a CP filter
     * offers instead of asking an author to remember what a publisher tags things with.
     *
     * @return array<string, int>
     */
    public function getCategoryCounts(int $sourceId, int $limit = 50): array
    {
        $counts = [];

        $rows = (new Query())
            ->select(['categories'])
            ->from('{{%airwave_items}}')
            ->where(['sourceId' => $sourceId])
            ->andWhere(['not', ['categories' => null]])
            ->limit(2000)
            ->column();

        foreach ($rows as $encoded) {
            foreach ((array)json_decode((string)$encoded, true) as $category) {
                if (is_string($category) && $category !== '') {
                    $counts[$category] = ($counts[$category] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        return array_slice($counts, 0, $limit, true);
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * Which feeds a read is allowed to see.
     *
     * Resolved here rather than joined, because there are a handful of sources and thousands of
     * items: one cached lookup beats a join on every page.
     *
     * @param array<string, mixed> $criteria
     * @return int[]
     */
    private function resolveSourceIds(array $criteria): array
    {
        $sources = Plugin::getInstance()->sources;
        $includeDisabled = (bool)($criteria['includeDisabled'] ?? false);
        $all = $sources->getAllSources($includeDisabled);
        $byId = [];

        foreach ($all as $source) {
            $byId[$source->id] = $source;
        }

        $wanted = null;

        if (!empty($criteria['sourceId'])) {
            $wanted = array_map('intval', (array)$criteria['sourceId']);
        } elseif (!empty($criteria['sourceHandle']) || !empty($criteria['source'])) {
            $handles = (array)($criteria['sourceHandle'] ?? $criteria['source']);
            $wanted = [];

            foreach ($all as $source) {
                if (in_array($source->handle, $handles, true)) {
                    $wanted[] = $source->id;
                }
            }
        }

        if ($wanted === null) {
            return array_keys($byId);
        }

        // Asking for a disabled or unknown feed by name gets nothing rather than everything —
        // silently widening a query is how a curated sidebar becomes the whole internet.
        return array_values(array_filter($wanted, fn($id) => isset($byId[$id])));
    }

    private function applySort(Query $query, string $sort): void
    {
        // `COALESCE(publishedAt, fetchedAt)` rather than `publishedAt DESC`: undated items are
        // common, NULL ordering differs between MySQL and Postgres, and "when it turned up" is the
        // honest answer for an item whose publisher did not say.
        match ($sort) {
            'oldest' => $query->orderBy(['COALESCE([[publishedAt]], [[fetchedAt]])' => SORT_ASC, 'id' => SORT_ASC]),
            'title' => $query->orderBy(['title' => SORT_ASC]),
            'random' => $query->orderBy(Craft::$app->getDb()->getDriverName() === 'pgsql' ? 'RANDOM()' : 'RAND()'),
            default => $query->orderBy(['COALESCE([[publishedAt]], [[fetchedAt]])' => SORT_DESC, 'id' => SORT_DESC]),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return Item[]
     */
    private function hydrate(array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $sources = [];

        foreach (Plugin::getInstance()->sources->getAllSources(true) as $source) {
            $sources[$source->id] = $source;
        }

        $items = [];

        foreach ($rows as $row) {
            $item = Item::fromRow($row);
            $source = $sources[$item->sourceId] ?? null;

            if ($source) {
                $item->sourceHandle = $source->handle;
                $item->sourceName = $source->title ?: $source->getMeta()->title;
            }

            $items[] = $item;
        }

        return $items;
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        // Bytes, not characters: the column limit is bytes, and cutting mid-sequence would produce
        // invalid UTF-8 that MySQL rejects outright.
        if (strlen($value) <= $max) {
            return $value;
        }

        return mb_strcut($value, 0, $max - 3, 'UTF-8');
    }
}
