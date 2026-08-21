<?php

namespace justinholtweb\airwave\models;

/**
 * What one import run did.
 *
 * {@see self::$previews} is only populated on a dry run, and is the reason dry running is worth
 * having: an import writes entries into a live section, and "what exactly would this do" is a
 * question worth being able to answer before finding out.
 */
class ImportResult
{
    public int $created = 0;
    public int $skipped = 0;
    public int $failed = 0;

    /** @var string[] */
    public array $errors = [];

    /** @var array<int, array{item: string, title: string, url: string|null, date: string|null}> */
    public array $previews = [];

    public bool $dryRun = false;

    public function getSummary(): string
    {
        if ($this->dryRun) {
            return sprintf('%d %s would be imported.', count($this->previews), count($this->previews) === 1 ? 'item' : 'items');
        }

        $parts = [sprintf('%d %s created', $this->created, $this->created === 1 ? 'entry' : 'entries')];

        if ($this->skipped) {
            $parts[] = "$this->skipped skipped";
        }

        if ($this->failed) {
            $parts[] = "$this->failed failed";
        }

        return implode(', ', $parts) . '.';
    }
}
