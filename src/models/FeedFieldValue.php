<?php

namespace justinholtweb\airwave\models;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\Plugin;
use Traversable;
use Twig\Markup;

/**
 * What a Feed field hands to a template.
 *
 * Stringable, so `{{ entry.sidebarFeeds }}` renders — which is the whole point of the field
 * existing, because the alternative is teaching every content editor a Twig function. Iterable
 * over the chosen feeds and able to hand over raw items for a template that wants to do its own
 * thing.
 *
 * @implements IteratorAggregate<int, Source>
 */
class FeedFieldValue implements IteratorAggregate, Countable
{
    /**
     * @param int[] $sourceIds
     * @param array<string, mixed> $defaults Display options set on the field.
     */
    public function __construct(
        private array $sourceIds = [],
        private array $defaults = [],
    ) {
    }

    /**
     * @return Source[]
     */
    public function getSources(): array
    {
        if (!$this->sourceIds) {
            return [];
        }

        $sources = [];

        foreach (Plugin::getInstance()->sources->getAllSources(true) as $source) {
            if (in_array($source->id, $this->sourceIds, true)) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * @return int[]
     */
    public function getSourceIds(): array
    {
        return $this->sourceIds;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function render(array $overrides = []): Markup
    {
        $sources = $this->getSources();

        if (!$sources) {
            return new Markup('', \Craft::$app->charset);
        }

        $options = array_merge($this->defaults, $overrides);

        // One feed keeps its own display options as the base; several have to be merged, and a
        // merged list without its source labels is a wall of unattributed headlines.
        if (count($sources) === 1) {
            return $sources[0]->render($options);
        }

        return Plugin::getInstance()->renderer->renderMerged(
            array_map(fn(Source $source) => $source->handle, $sources),
            $options,
        );
    }

    /**
     * @param array<string, mixed> $criteria
     * @return Item[]
     */
    public function items(array $criteria = []): array
    {
        if (!$this->sourceIds) {
            return [];
        }

        return Plugin::getInstance()->items->find(array_merge([
            'sourceId' => $this->sourceIds,
            'limit' => $this->defaults['limit'] ?? null,
        ], $criteria));
    }

    /**
     * @return Item[]
     */
    public function getItems(): array
    {
        return $this->items();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->getSources());
    }

    public function count(): int
    {
        return count($this->sourceIds);
    }

    public function isEmpty(): bool
    {
        return $this->sourceIds === [];
    }

    public function __toString(): string
    {
        return (string)$this->render();
    }
}
