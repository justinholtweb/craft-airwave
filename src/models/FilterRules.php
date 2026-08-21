<?php

namespace justinholtweb\airwave\models;

use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use DateTimeZone;

/**
 * The rules deciding which items from a feed are worth keeping. Pro.
 *
 * Applied on the way **in**, at store time, not at render time. Two reasons: a rule that runs
 * once per refresh instead of once per page view is free, and an item that was never stored can
 * never be imported into an entry by mistake. The cost is that changing the rules only affects
 * items fetched afterwards, which is why the CP offers "re-apply filters" — see
 * {@see \justinholtweb\airwave\services\Filters::reapply()}.
 *
 * Every comparison is case-insensitive and accent-naive. A feed reader is not a search engine,
 * and an editor typing "obama" wants the item titled "Obama".
 */
class FilterRules
{
    public const MATCH_ANY = 'any';
    public const MATCH_ALL = 'all';

    public const FIELD_TITLE = 'title';
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_CONTENT = 'content';
    public const FIELD_AUTHOR = 'author';
    public const FIELD_CATEGORIES = 'categories';

    public const FIELDS = [
        self::FIELD_TITLE,
        self::FIELD_SUMMARY,
        self::FIELD_CONTENT,
        self::FIELD_AUTHOR,
        self::FIELD_CATEGORIES,
    ];

    /** @var string[] Keep an item only if it matches these. */
    public array $include = [];

    /** @var string[] Drop an item if it matches any of these. Exclusion always wins. */
    public array $exclude = [];

    /** @var string[] Which parts of an item the keywords are looked for in. */
    public array $searchIn = [self::FIELD_TITLE, self::FIELD_SUMMARY];

    public string $matchMode = self::MATCH_ANY;

    /** @var string[] Keep only items carrying one of these categories. */
    public array $categories = [];

    /** @var string[] */
    public array $excludeCategories = [];

    /** @var string[] Keep only items by these authors. */
    public array $authors = [];

    /** @var string[] */
    public array $excludeAuthors = [];

    /** Drop items older than this many days. */
    public ?int $maxAgeDays = null;

    /** Drop items that carry no image — for a card layout, an item without one is a hole. */
    public bool $requireImage = false;

    /** Drop items whose text is shorter than this. Catches "New post" stubs and link dumps. */
    public ?int $minLength = null;

    /** Drop later items repeating an earlier item's title. Applied across the batch, not per item. */
    public bool $dedupeTitles = false;

    public function isEmpty(): bool
    {
        return !$this->include
            && !$this->exclude
            && !$this->categories
            && !$this->excludeCategories
            && !$this->authors
            && !$this->excludeAuthors
            && $this->maxAgeDays === null
            && $this->minLength === null
            && !$this->requireImage
            && !$this->dedupeTitles;
    }

    /**
     * Whether one item survives on its own merits.
     *
     * {@see self::dedupeTitles} is not answerable here — it is about the batch — so
     * {@see self::apply()} is what callers should use.
     */
    public function matches(Item $item): bool
    {
        $haystack = $this->haystack($item);

        foreach ($this->exclude as $keyword) {
            if ($this->contains($haystack, $keyword)) {
                return false;
            }
        }

        if ($this->include) {
            $hits = 0;

            foreach ($this->include as $keyword) {
                if ($this->contains($haystack, $keyword)) {
                    $hits++;
                }
            }

            $needed = $this->matchMode === self::MATCH_ALL ? count($this->include) : 1;

            if ($hits < $needed) {
                return false;
            }
        }

        $itemCategories = array_map(fn($c) => StringHelper::toLowerCase(trim($c)), $item->categories);

        if ($this->categories && !array_intersect($this->lower($this->categories), $itemCategories)) {
            return false;
        }

        if ($this->excludeCategories && array_intersect($this->lower($this->excludeCategories), $itemCategories)) {
            return false;
        }

        $author = StringHelper::toLowerCase(trim((string)$item->author));

        if ($this->authors && !in_array($author, $this->lower($this->authors), true)) {
            return false;
        }

        if ($this->excludeAuthors && in_array($author, $this->lower($this->excludeAuthors), true)) {
            return false;
        }

        if ($this->maxAgeDays !== null && $this->maxAgeDays > 0) {
            // No date means no evidence it is old. Feeds omit `<pubDate>` more often than you
            // would like, and dropping those items silently empties a feed for no visible reason.
            if ($item->publishedAt) {
                $cutoff = (new DateTime('now', new DateTimeZone('UTC')))
                    ->sub(new DateInterval('P' . $this->maxAgeDays . 'D'));

                if ($item->publishedAt < $cutoff) {
                    return false;
                }
            }
        }

        if ($this->requireImage && !$item->getThumbnailUrl()) {
            return false;
        }

        if ($this->minLength !== null && $this->minLength > 0 && mb_strlen($item->getExcerpt(0)) < $this->minLength) {
            return false;
        }

        return true;
    }

    /**
     * @param Item[] $items
     * @return Item[] Re-indexed, so a template can use `items[0]`.
     */
    public function apply(array $items): array
    {
        $kept = [];
        $titles = [];

        foreach ($items as $item) {
            if (!$this->matches($item)) {
                continue;
            }

            if ($this->dedupeTitles) {
                $key = StringHelper::toLowerCase(preg_replace('/[^a-z0-9]+/i', ' ', $item->getTitleText()) ?? '');
                $key = trim($key);

                if ($key !== '' && isset($titles[$key])) {
                    continue;
                }

                $titles[$key] = true;
            }

            $kept[] = $item;
        }

        return $kept;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public static function fromArray(?array $config): self
    {
        $rules = new self();

        if (!$config) {
            return $rules;
        }

        foreach (['include', 'exclude', 'categories', 'excludeCategories', 'authors', 'excludeAuthors'] as $key) {
            if (isset($config[$key])) {
                $rules->$key = self::toList($config[$key]);
            }
        }

        if (isset($config['searchIn'])) {
            $fields = array_values(array_intersect(self::toList($config['searchIn']), self::FIELDS));
            $rules->searchIn = $fields ?: $rules->searchIn;
        }

        if (isset($config['matchMode']) && in_array($config['matchMode'], [self::MATCH_ANY, self::MATCH_ALL], true)) {
            $rules->matchMode = $config['matchMode'];
        }

        foreach (['maxAgeDays', 'minLength'] as $key) {
            if (isset($config[$key]) && $config[$key] !== '' && (int)$config[$key] > 0) {
                $rules->$key = (int)$config[$key];
            }
        }

        $rules->requireImage = !empty($config['requireImage']);
        $rules->dedupeTitles = !empty($config['dedupeTitles']);

        return $rules;
    }

    public static function fromJson(?string $json): self
    {
        $decoded = $json ? json_decode($json, true) : null;

        return self::fromArray(is_array($decoded) ? $decoded : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /** Keywords come out of a CP text box as one comma- or newline-separated string. */
    private static function toList(mixed $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif (is_string($value)) {
            $parts = preg_split('/[,\r\n]+/', $value) ?: [];
        } else {
            return [];
        }

        $clean = [];

        foreach ($parts as $part) {
            $part = trim((string)$part);

            if ($part !== '') {
                $clean[] = $part;
            }
        }

        return array_values(array_unique($clean));
    }

    private function haystack(Item $item): string
    {
        $parts = [];

        foreach ($this->searchIn as $field) {
            $parts[] = match ($field) {
                self::FIELD_TITLE => (string)$item->title,
                self::FIELD_SUMMARY => strip_tags((string)$item->summary),
                self::FIELD_CONTENT => strip_tags((string)$item->content),
                self::FIELD_AUTHOR => (string)$item->author,
                self::FIELD_CATEGORIES => implode(' ', $item->categories),
                default => '',
            };
        }

        return StringHelper::toLowerCase(html_entity_decode(implode(' ', $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Quoted keywords match as a phrase, bare ones as a substring.
     *
     * Substring rather than whole-word on purpose: "cycl" finding "cycling" is what an editor
     * building a curated feed expects, and whole-word matching over feed prose loses to
     * punctuation constantly.
     */
    private function contains(string $haystack, string $keyword): bool
    {
        $keyword = StringHelper::toLowerCase(trim($keyword, " \t\n\r\0\x0B\"'"));

        return $keyword !== '' && str_contains($haystack, $keyword);
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private function lower(array $values): array
    {
        return array_map(fn($v) => StringHelper::toLowerCase(trim($v)), $values);
    }
}
