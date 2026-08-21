<?php

namespace justinholtweb\airwave\models;

/**
 * How a feed's items become entries. Pro.
 *
 * Section and entry type are held by **uid**, not id: an import config is content configuration
 * that gets copied between environments, and ids are not stable across a database that was built
 * by project config rather than restored from a dump.
 *
 * {@see self::$fieldMap} is `craftFieldHandle => token-or-template`. A token is one of
 * {@see self::TOKENS} and is taken from the item directly; anything else is rendered as an object
 * template with the item exposed as `item`, which is the escape hatch for "the body should be the
 * summary followed by a link back".
 */
class ImportConfig
{
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    public const DATE_ITEM = 'item';
    public const DATE_NOW = 'now';

    /** Item properties that can be mapped straight onto a field without writing a template. */
    public const TOKENS = [
        'title',
        'summary',
        'content',
        'url',
        'author',
        'image',
        'categories',
        'guid',
        'domain',
        'publishedAt',
    ];

    public ?string $sectionUid = null;
    public ?string $entryTypeUid = null;
    public ?int $authorId = null;
    public ?int $siteId = null;
    public string $status = self::STATUS_DISABLED;

    /** Object template for the entry title. Blank uses the item's own title. */
    public ?string $titleTemplate = null;

    public string $dateSource = self::DATE_ITEM;

    /** @var array<string, string> */
    public array $fieldMap = [];

    /** Download the item's image into a volume and put it in an Assets field. */
    public bool $importImages = false;
    public ?string $imageVolumeUid = null;
    public ?string $imageField = null;

    /** How many items one run may import. Keeps a first run of a 500-item feed off a web request. */
    public int $limitPerRun = 25;

    /** Ignore items published before this many days ago. Blank imports the whole backlog. */
    public ?int $maxAgeDays = null;

    public function isConfigured(): bool
    {
        return $this->sectionUid !== null && $this->entryTypeUid !== null;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public static function fromArray(?array $config): self
    {
        $import = new self();

        if (!$config) {
            return $import;
        }

        foreach (['sectionUid', 'entryTypeUid', 'titleTemplate', 'imageVolumeUid', 'imageField'] as $key) {
            if (!empty($config[$key]) && is_string($config[$key])) {
                $import->$key = $config[$key];
            }
        }

        foreach (['authorId', 'siteId'] as $key) {
            if (!empty($config[$key])) {
                $import->$key = (int)$config[$key];
            }
        }

        if (isset($config['status']) && in_array($config['status'], [self::STATUS_ENABLED, self::STATUS_DISABLED], true)) {
            $import->status = $config['status'];
        }

        if (isset($config['dateSource']) && in_array($config['dateSource'], [self::DATE_ITEM, self::DATE_NOW], true)) {
            $import->dateSource = $config['dateSource'];
        }

        if (isset($config['fieldMap'])) {
            $import->fieldMap = self::normalizeFieldMap($config['fieldMap']);
        }

        $import->importImages = !empty($config['importImages']);

        if (isset($config['limitPerRun']) && (int)$config['limitPerRun'] > 0) {
            $import->limitPerRun = min(500, (int)$config['limitPerRun']);
        }

        if (!empty($config['maxAgeDays'])) {
            $import->maxAgeDays = (int)$config['maxAgeDays'];
        }

        return $import;
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

    /**
     * A Craft editable table posts `[{field: 'body', value: 'content'}, …]`; a config file and the
     * console hand over a plain map. Both mean the same thing.
     *
     * @return array<string, string>
     */
    private static function normalizeFieldMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $field = trim((string)($entry['field'] ?? ''));
                $source = trim((string)($entry['value'] ?? ''));
            } else {
                $field = trim((string)$key);
                $source = trim((string)$entry);
            }

            if ($field !== '' && $source !== '') {
                $map[$field] = $source;
            }
        }

        return $map;
    }
}
