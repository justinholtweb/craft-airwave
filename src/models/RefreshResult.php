<?php

namespace justinholtweb\airwave\models;

/**
 * What one refresh did.
 *
 * Returned by the service, printed by the console command, shown in the CP flash message and
 * logged — the same object each time, so the three never disagree about what happened.
 */
class RefreshResult
{
    public bool $ok = true;
    public bool $notModified = false;
    public int $new = 0;
    public int $updated = 0;
    public int $rejected = 0;
    public int $pruned = 0;
    public int $imported = 0;
    public ?string $error = null;

    /** Whether it is worth trying again shortly. A timeout is; a 404 is not. */
    public bool $isTransient = false;

    public static function failed(string $error, bool $isTransient = false): self
    {
        $result = new self();
        $result->ok = false;
        $result->error = $error;
        $result->isTransient = $isTransient;

        return $result;
    }

    public static function unchanged(): self
    {
        $result = new self();
        $result->notModified = true;

        return $result;
    }

    /** A one-line summary, phrased for a person rather than a log parser. */
    public function getSummary(): string
    {
        if (!$this->ok) {
            return (string)$this->error;
        }

        if ($this->notModified) {
            return 'No changes.';
        }

        $parts = [];
        $parts[] = $this->new === 1 ? '1 new item' : "$this->new new items";

        if ($this->updated) {
            $parts[] = "$this->updated updated";
        }

        if ($this->rejected) {
            $parts[] = "$this->rejected filtered out";
        }

        if ($this->imported) {
            $parts[] = "$this->imported imported";
        }

        if ($this->pruned) {
            $parts[] = "$this->pruned pruned";
        }

        return implode(', ', $parts) . '.';
    }
}
