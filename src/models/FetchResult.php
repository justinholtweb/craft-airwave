<?php

namespace justinholtweb\airwave\models;

/**
 * What came back from one HTTP request for a feed.
 *
 * {@see self::$notModified} is the interesting case: a feed that answers 304 has told you nothing
 * changed, and the correct response is to touch `lastFetchedAt` and stop — not to re-parse, not to
 * re-store, and above all not to clear the items already on the site.
 */
class FetchResult
{
    public function __construct(
        public string $body = '',
        public int $statusCode = 200,
        public bool $notModified = false,
        public ?string $contentType = null,
        public ?string $etag = null,
        public ?string $lastModified = null,
        public ?string $finalUrl = null,
        public int $bytes = 0,
    ) {
    }
}
