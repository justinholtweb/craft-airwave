<?php

namespace justinholtweb\airwave\models;

/**
 * The result of parsing one download: what the feed says about itself, plus its items.
 *
 * Deliberately not a model with validation — it is a return value, and the parser has already
 * decided what it could make sense of.
 */
class ParsedFeed
{
    /**
     * @param Item[] $items
     */
    public function __construct(
        public FeedMeta $meta,
        public array $items = [],
    ) {
    }

    public function count(): int
    {
        return count($this->items);
    }
}
