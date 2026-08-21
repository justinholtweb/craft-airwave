<?php

namespace justinholtweb\airwave\services;

use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\models\FilterRules;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\Plugin;
use yii\base\Component;

/**
 * Rules over incoming items. Pro.
 *
 * The rules themselves live in {@see FilterRules} — pure, testable, no application needed. This
 * service is what they mean once there are items already in the database, which is the part with
 * judgement in it: filters normally run on the way *in*, so tightening a rule leaves everything
 * already stored untouched. {@see self::reapply()} is the answer, and it is deliberately explicit
 * rather than automatic, because it deletes things.
 */
class Filters extends Component
{
    /**
     * @param Item[] $items
     * @return Item[]
     */
    public function apply(Source $source, array $items): array
    {
        return $source->getFilters()->apply($items);
    }

    /**
     * Removes stored items that the current rules would have rejected.
     *
     * Imported items are exempt — an entry exists, somebody may have edited it, and deleting the
     * row that records the import would let the article come back on the next refresh.
     *
     * @return array{removed: int, kept: int, protected: int}
     */
    public function reapply(Source $source): array
    {
        $plugin = Plugin::getInstance();
        $rules = $source->getFilters();

        if ($rules->isEmpty()) {
            return ['removed' => 0, 'kept' => $plugin->items->countForSource((int)$source->id), 'protected' => 0];
        }

        $items = $plugin->items->find([
            'sourceId' => $source->id,
            'limit' => null,
            'includeDisabled' => true,
        ]);

        $doomed = [];
        $kept = 0;
        $protected = 0;

        foreach ($items as $item) {
            if ($rules->matches($item)) {
                $kept++;
                continue;
            }

            if ($item->importedElementId) {
                $protected++;
                continue;
            }

            $doomed[] = $item->id;
        }

        $removed = $plugin->items->deleteByIds(array_filter($doomed));

        return ['removed' => $removed, 'kept' => $kept, 'protected' => $protected];
    }

    /**
     * What a set of rules would do, without doing it — the CP's "test filters" button.
     *
     * @return array{matched: Item[], rejected: Item[]}
     */
    public function preview(Source $source, FilterRules $rules, int $limit = 50): array
    {
        $items = Plugin::getInstance()->items->find([
            'sourceId' => $source->id,
            'limit' => $limit,
            'includeDisabled' => true,
        ]);

        $matched = [];
        $rejected = [];

        foreach ($items as $item) {
            if ($rules->matches($item)) {
                $matched[] = $item;
            } else {
                $rejected[] = $item;
            }
        }

        return ['matched' => $matched, 'rejected' => $rejected];
    }
}
