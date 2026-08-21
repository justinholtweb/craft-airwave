<?php

namespace justinholtweb\airwave\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $handle
 * @property int $targetSiteId
 * @property string $uri
 * @property string $format
 * @property string $feedSource
 * @property string|null $elementType
 * @property string|null $criteria
 * @property string|null $sourceIds
 * @property int $itemLimit
 * @property string|null $mapping
 * @property string|null $feedTitle
 * @property string|null $feedDescription
 * @property string|null $feedLink
 * @property string|null $language
 * @property string|null $copyright
 * @property string|null $imageUrl
 * @property int|null $cacheDuration
 */
class ChannelRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%airwave_channels}}';
    }
}
