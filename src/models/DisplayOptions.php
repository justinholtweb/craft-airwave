<?php

namespace justinholtweb\airwave\models;

/**
 * How a set of items should look.
 *
 * One shape shared by the feed field, the Twig call, the source's own defaults and the CP
 * preview, so "what does this feed look like" has a single answer no matter who is asking.
 * Unknown keys are ignored rather than rejected: a template passing `{ limit: 5, colour: 'red' }`
 * should render five items, not throw.
 */
class DisplayOptions
{
    public const CONTENT_NONE = 'none';
    public const CONTENT_SUMMARY = 'summary';
    public const CONTENT_FULL = 'full';

    public const CONTENT_MODES = [self::CONTENT_NONE, self::CONTENT_SUMMARY, self::CONTENT_FULL];

    public const LAYOUT_LIST = 'list';
    public const LAYOUT_GRID = 'grid';
    public const LAYOUT_CARDS = 'cards';

    public const LAYOUTS = [self::LAYOUT_LIST, self::LAYOUT_GRID, self::LAYOUT_CARDS];

    public const SORT_NEWEST = 'newest';
    public const SORT_OLDEST = 'oldest';
    public const SORT_TITLE = 'title';
    public const SORT_RANDOM = 'random';

    public const SORTS = [self::SORT_NEWEST, self::SORT_OLDEST, self::SORT_TITLE, self::SORT_RANDOM];

    public int $limit = 10;
    public int $offset = 0;
    public string $layout = self::LAYOUT_LIST;
    public int $columns = 3;
    public string $content = self::CONTENT_SUMMARY;
    public int $summaryLength = 200;
    public string $sort = self::SORT_NEWEST;

    public bool $showTitle = true;
    public bool $showImage = true;
    public bool $showDate = true;
    public bool $showAuthor = false;
    public bool $showSource = false;
    public bool $showCategories = false;
    public bool $showReadMore = false;

    public ?string $dateFormat = null;
    public ?string $readMoreLabel = null;
    public ?string $emptyMessage = null;
    public ?string $className = null;

    /** A site template to render instead of Airwave's own. */
    public ?string $template = null;

    public bool $newTab = true;
    public string $rel = 'noopener nofollow';

    /** Pro. Query parameters appended to every outgoing item link — UTM tags, an affiliate id. */
    public bool $rewriteLinks = false;

    /** @var array<string, string> */
    public array $linkParams = [];

    /**
     * @param array<string, mixed>|null $config
     */
    public static function fromArray(?array $config): self
    {
        $options = new self();

        if (!$config) {
            return $options;
        }

        foreach ($config as $key => $value) {
            if ($value === null || !property_exists($options, $key)) {
                continue;
            }

            match ($key) {
                'limit', 'offset', 'columns', 'summaryLength' => $options->$key = max(0, (int)$value),
                'showTitle', 'showImage', 'showDate', 'showAuthor', 'showSource', 'showCategories',
                'showReadMore', 'newTab', 'rewriteLinks' => $options->$key = (bool)$value,
                'linkParams' => $options->linkParams = self::normalizeParams($value),
                'layout' => $options->layout = in_array($value, self::LAYOUTS, true) ? $value : $options->layout,
                'content' => $options->content = in_array($value, self::CONTENT_MODES, true) ? $value : $options->content,
                'sort' => $options->sort = in_array($value, self::SORTS, true) ? $value : $options->sort,
                default => $options->$key = is_scalar($value) ? (string)$value : $options->$key,
            };
        }

        return $options;
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

    /** A copy with per-call overrides folded in, leaving the stored options alone. */
    public function merge(?array $overrides): self
    {
        if (!$overrides) {
            return $this;
        }

        return self::fromArray(array_merge($this->toArray(), $overrides));
    }

    /**
     * Query params can arrive as `utm_source=airwave&utm_medium=feed`, which is how a CP field
     * can offer one text box instead of a repeating table.
     *
     * @return array<string, string>
     */
    private static function normalizeParams(mixed $value): array
    {
        if (is_array($value)) {
            $params = [];

            foreach ($value as $key => $param) {
                if (is_array($param) && isset($param['name'])) {
                    // A Craft editable table posts rows, not pairs.
                    if ($param['name'] !== '') {
                        $params[(string)$param['name']] = (string)($param['value'] ?? '');
                    }
                } elseif (is_string($key)) {
                    $params[$key] = (string)$param;
                }
            }

            return $params;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        parse_str(ltrim(trim($value), '?&'), $parsed);

        return array_map(fn($v) => is_array($v) ? reset($v) : (string)$v, $parsed);
    }
}
