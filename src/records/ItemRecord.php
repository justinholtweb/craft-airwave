<?php

namespace justinholtweb\airwave\records;

use craft\db\ActiveRecord;

/**
 * Items are written in batches by {@see \justinholtweb\airwave\services\Items::store()} with a
 * plain insert command rather than through this record — a refresh writing 200 rows one Active
 * Record at a time is 200 round trips for no benefit. This exists for the single-row reads and
 * updates where the convenience is worth it.
 *
 * @property int $id
 * @property int $sourceId
 * @property string|null $guid
 * @property string $guidHash
 * @property string|null $url
 * @property string|null $title
 * @property string|null $summary
 * @property string|null $content
 * @property string|null $author
 * @property string|null $imageUrl
 * @property string|null $enclosureUrl
 * @property string|null $enclosureType
 * @property int|null $enclosureLength
 * @property string|null $categories
 * @property string|null $extra
 * @property string|null $publishedAt
 * @property string $fetchedAt
 * @property int|null $importedElementId
 */
class ItemRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%airwave_items}}';
    }
}
