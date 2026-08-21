<?php

namespace justinholtweb\airwave\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * Element query for feeds.
 *
 * The `ref` param is what makes `{airwave:craft-news:render}` work: Craft's `parseRefs()` calls
 * `->ref()` with the non-numeric part of the tag and then indexes the results on `$element->ref`,
 * so this and `Source::getRef()` have to name the same column.
 *
 * `fetchStatus` rather than `status`: `status` is Craft's own enabled/disabled, and a feed can
 * perfectly well be enabled *and* failing — which is exactly the combination somebody opening the
 * CP wants to find.
 *
 * @method \justinholtweb\airwave\elements\Source[] all($db = null)
 * @method \justinholtweb\airwave\elements\Source|null one($db = null)
 * @method \justinholtweb\airwave\elements\Source|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class SourceQuery extends ElementQuery
{
    public mixed $handle = null;
    public mixed $fetchStatus = null;
    public mixed $importEnabled = null;
    public mixed $url = null;

    protected array $defaultOrderBy = ['elements_sites.title' => SORT_ASC];

    public function handle(mixed $value): static
    {
        $this->handle = $value;
        return $this;
    }

    public function fetchStatus(mixed $value): static
    {
        $this->fetchStatus = $value;
        return $this;
    }

    public function importEnabled(mixed $value): static
    {
        $this->importEnabled = $value;
        return $this;
    }

    public function url(mixed $value): static
    {
        $this->url = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('airwave_sources');

        $this->query->addSelect([
            'airwave_sources.handle',
            'airwave_sources.url',
            'airwave_sources.refreshInterval',
            'airwave_sources.itemLimit',
            'airwave_sources.filters',
            'airwave_sources.displayOptions',
            'airwave_sources.fetchOptions',
            'airwave_sources.importEnabled',
            'airwave_sources.importConfig',
            'airwave_sources.meta',
            'airwave_sources.etag',
            'airwave_sources.lastModifiedHeader',
            'airwave_sources.lastFetchedAt',
            'airwave_sources.lastSuccessAt',
            'airwave_sources.lastStatus',
            'airwave_sources.lastError',
            'airwave_sources.itemCount',
            'airwave_sources.newItemCount',
        ]);

        if ($this->handle) {
            $this->subQuery->andWhere(Db::parseParam('airwave_sources.handle', $this->handle));
        }

        if ($this->fetchStatus) {
            $this->subQuery->andWhere(Db::parseParam('airwave_sources.lastStatus', $this->fetchStatus));
        }

        if ($this->importEnabled !== null) {
            $this->subQuery->andWhere(['airwave_sources.importEnabled' => (bool)$this->importEnabled]);
        }

        if ($this->url) {
            $this->subQuery->andWhere(Db::parseParam('airwave_sources.url', $this->url));
        }

        if ($this->ref) {
            $this->subQuery->andWhere(Db::parseParam('airwave_sources.handle', $this->ref));
        }

        return true;
    }
}
