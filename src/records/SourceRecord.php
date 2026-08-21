<?php

namespace justinholtweb\airwave\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $handle
 * @property string $url
 * @property int|null $refreshInterval
 * @property int|null $itemLimit
 * @property string|null $filters
 * @property string|null $displayOptions
 * @property string|null $fetchOptions
 * @property bool $importEnabled
 * @property string|null $importConfig
 * @property string|null $meta
 * @property string|null $etag
 * @property string|null $lastModifiedHeader
 * @property string|null $lastFetchedAt
 * @property string|null $lastSuccessAt
 * @property string $lastStatus
 * @property string|null $lastError
 * @property int $itemCount
 * @property int $newItemCount
 */
class SourceRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%airwave_sources}}';
    }
}
