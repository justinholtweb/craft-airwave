<?php

namespace justinholtweb\airwave\models;

use craft\base\Model;
use DateTime;

/**
 * What a downloaded feed says about itself.
 *
 * Stored on the source so the CP can show a real title and icon for a feed the moment it is
 * added, without a second request, and so a published channel can re-broadcast an aggregated feed
 * with attribution intact.
 */
class FeedMeta extends Model
{
    public const FORMAT_RSS = 'rss';
    public const FORMAT_RDF = 'rdf';
    public const FORMAT_ATOM = 'atom';
    public const FORMAT_JSON = 'json';

    public ?string $title = null;
    public ?string $link = null;
    public ?string $description = null;
    public ?string $language = null;
    public ?string $imageUrl = null;
    public ?string $iconUrl = null;
    public ?string $generator = null;
    public ?DateTime $updatedAt = null;

    /** Which dialect it turned out to be — useful in the CP when a URL is not what an author thought. */
    public ?string $format = null;

    /** `<ttl>` in minutes, if the feed asked for one. Advisory; Airwave never refreshes faster. */
    public ?int $ttl = null;

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'title' => $this->title,
            'link' => $this->link,
            'description' => $this->description,
            'language' => $this->language,
            'imageUrl' => $this->imageUrl,
            'iconUrl' => $this->iconUrl,
            'generator' => $this->generator,
            'format' => $this->format,
            'ttl' => $this->ttl,
            'updatedAt' => $this->updatedAt?->format(DATE_ATOM),
        ];
    }

    public static function fromArray(?array $data): self
    {
        $meta = new self();

        foreach (['title', 'link', 'description', 'language', 'imageUrl', 'iconUrl', 'generator', 'format'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $meta->$key = $data[$key];
            }
        }

        if (isset($data['ttl'])) {
            $meta->ttl = (int)$data['ttl'];
        }

        if (!empty($data['updatedAt'])) {
            try {
                $meta->updatedAt = new DateTime($data['updatedAt']);
            } catch (\Throwable) {
            }
        }

        return $meta;
    }
}
