<?php

namespace justinholtweb\airwave\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @method \justinholtweb\airwave\elements\Channel[] all($db = null)
 * @method \justinholtweb\airwave\elements\Channel|null one($db = null)
 * @method \justinholtweb\airwave\elements\Channel|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class ChannelQuery extends ElementQuery
{
    public mixed $handle = null;
    public mixed $uri = null;
    public mixed $format = null;
    public mixed $feedSource = null;
    public mixed $targetSiteId = null;

    protected array $defaultOrderBy = ['elements_sites.title' => SORT_ASC];

    public function handle(mixed $value): static
    {
        $this->handle = $value;
        return $this;
    }

    public function uri(mixed $value): static
    {
        $this->uri = $value;
        return $this;
    }

    public function format(mixed $value): static
    {
        $this->format = $value;
        return $this;
    }

    public function feedSource(mixed $value): static
    {
        $this->feedSource = $value;
        return $this;
    }

    public function targetSiteId(mixed $value): static
    {
        $this->targetSiteId = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('airwave_channels');

        $this->query->addSelect([
            'airwave_channels.handle',
            'airwave_channels.targetSiteId',
            'airwave_channels.uri',
            'airwave_channels.format',
            'airwave_channels.feedSource',
            'airwave_channels.elementType',
            'airwave_channels.criteria',
            'airwave_channels.sourceIds',
            'airwave_channels.itemLimit',
            'airwave_channels.mapping',
            'airwave_channels.feedTitle',
            'airwave_channels.feedDescription',
            'airwave_channels.feedLink',
            'airwave_channels.language',
            'airwave_channels.copyright',
            'airwave_channels.imageUrl',
            'airwave_channels.cacheDuration',
        ]);

        foreach (['handle', 'uri', 'format', 'feedSource', 'targetSiteId'] as $param) {
            if ($this->$param !== null && $this->$param !== '') {
                $this->subQuery->andWhere(Db::parseParam("airwave_channels.$param", $this->$param));
            }
        }

        return true;
    }
}
