<?php

namespace justinholtweb\airwave\exceptions;

use Exception;

/**
 * A feed could not be fetched or made sense of.
 *
 * Deliberately one exception rather than a hierarchy: everything that goes wrong with a feed ends
 * up in the same place — `lastError` on the source, shown in the CP — and the only thing that
 * varies is the sentence. A caller that wants to know *what* failed has {@see self::$isTransient},
 * which is the one distinction worth acting on: a timeout is worth retrying, a 404 is not.
 */
class FeedException extends Exception
{
    public bool $isTransient = false;

    public static function transient(string $message, ?\Throwable $previous = null): self
    {
        $e = new self($message, 0, $previous);
        $e->isTransient = true;

        return $e;
    }
}
