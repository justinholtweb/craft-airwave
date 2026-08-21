<?php

namespace justinholtweb\airwave\models;

use craft\base\Model;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\airwave\Plugin;
use Twig\Markup;

/**
 * One item, whatever kind of feed it arrived in.
 *
 * The same model is used for an item just parsed out of a download and an item read back out of
 * `airwave_items`, so the parser, the filters, the importer, the renderer and the broadcaster all
 * speak one shape. RSS `<description>`, Atom `<summary>` and JSON Feed `summary` are all
 * {@see self::$summary}; RSS `content:encoded`, Atom `<content>` and JSON Feed `content_html` are
 * all {@see self::$content}. Anything the normalisation cannot hold goes to {@see self::$extra},
 * which is what field mapping in the importer reaches into.
 */
class Item extends Model
{
    public ?int $id = null;
    public ?int $sourceId = null;

    /** The feed's own identifier, and the only thing dedupe trusts. */
    public ?string $guid = null;

    public ?string $url = null;
    public ?string $title = null;
    public ?string $summary = null;
    public ?string $content = null;
    public ?string $author = null;
    public ?string $imageUrl = null;
    public ?string $enclosureUrl = null;
    public ?string $enclosureType = null;
    public ?int $enclosureLength = null;

    /** @var string[] */
    public array $categories = [];

    public ?DateTime $publishedAt = null;
    public ?DateTime $fetchedAt = null;

    /** Set once this item has become an entry, so it is never imported twice. */
    public ?int $importedElementId = null;

    /** @var array<string, mixed> Everything the normalised fields do not hold. */
    public array $extra = [];

    /** Not persisted — set by {@see \justinholtweb\airwave\services\Items} for merged reads. */
    public ?string $sourceHandle = null;
    public ?string $sourceName = null;

    /**
     * A stable 40-character key for the unique index.
     *
     * A GUID can be a 2KB permalink with query parameters, which no useful index can cover, and
     * feeds exist whose GUIDs differ only past the 191st character. Hashing sidesteps both.
     */
    public function getGuidHash(): string
    {
        return sha1((string)($this->guid ?: $this->url ?: $this->title));
    }

    /**
     * The link, with whatever the display options want appended.
     *
     * Link rewriting is Pro, and gated by the caller: {@see Edition::applyToDisplay()} turns the
     * flag off before the options reach here.
     */
    public function getLink(?DisplayOptions $options = null): ?string
    {
        if (!$this->url) {
            return null;
        }

        if (!$options || !$options->rewriteLinks || !$options->linkParams) {
            return $this->url;
        }

        return UrlHelper::urlWithParams($this->url, $options->linkParams);
    }

    /** Plain text, no markup, trimmed to a word boundary. */
    public function getExcerpt(int $length = 200): string
    {
        $text = trim(html_entity_decode(strip_tags((string)($this->summary ?: $this->content)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        if ($length <= 0 || mb_strlen($text) <= $length) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $length), " \t\n\r\0\x0B.,;:!?-") . '…';
    }

    /**
     * The item's HTML, purified.
     *
     * Never return the stored string raw: it is markup from a server you do not control, sitting
     * one `|raw` away from your page.
     */
    public function getHtml(string $mode = DisplayOptions::CONTENT_SUMMARY): Markup
    {
        $raw = match ($mode) {
            DisplayOptions::CONTENT_FULL => $this->content ?: $this->summary,
            DisplayOptions::CONTENT_NONE => '',
            default => $this->summary ?: $this->content,
        };

        return Plugin::getInstance()->parser->purify((string)$raw);
    }

    public function getHasImage(): bool
    {
        return (bool)$this->imageUrl;
    }

    /** The image a template should show: the item's own, else the enclosure if it is one. */
    public function getThumbnailUrl(): ?string
    {
        if ($this->imageUrl) {
            return $this->imageUrl;
        }

        if ($this->enclosureUrl && str_starts_with((string)$this->enclosureType, 'image/')) {
            return $this->enclosureUrl;
        }

        return null;
    }

    /** The host the item came from, for a "via example.com" line. */
    public function getDomain(): ?string
    {
        $host = $this->url ? parse_url($this->url, PHP_URL_HOST) : null;

        return is_string($host) ? preg_replace('/^www\./', '', $host) : null;
    }

    public function getTitleText(): string
    {
        $title = trim(html_entity_decode(strip_tags((string)$this->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $title !== '' ? $title : 'Untitled';
    }

    /** Every word an item can be filtered or searched on, lowercased once. */
    public function getSearchText(): string
    {
        return StringHelper::toLowerCase(implode(' ', array_filter([
            $this->title,
            strip_tags((string)$this->summary),
            strip_tags((string)$this->content),
            $this->author,
            implode(' ', $this->categories),
        ])));
    }

    /**
     * @param array<string, mixed> $row A row from `airwave_items`.
     */
    public static function fromRow(array $row): self
    {
        $item = new self();
        $item->id = isset($row['id']) ? (int)$row['id'] : null;
        $item->sourceId = isset($row['sourceId']) ? (int)$row['sourceId'] : null;
        $item->guid = $row['guid'] ?? null;
        $item->url = $row['url'] ?? null;
        $item->title = $row['title'] ?? null;
        $item->summary = $row['summary'] ?? null;
        $item->content = $row['content'] ?? null;
        $item->author = $row['author'] ?? null;
        $item->imageUrl = $row['imageUrl'] ?? null;
        $item->enclosureUrl = $row['enclosureUrl'] ?? null;
        $item->enclosureType = $row['enclosureType'] ?? null;
        $item->enclosureLength = isset($row['enclosureLength']) ? (int)$row['enclosureLength'] : null;
        $item->categories = self::decodeArray($row['categories'] ?? null);
        $item->extra = self::decodeArray($row['extra'] ?? null);
        $item->publishedAt = self::decodeDate($row['publishedAt'] ?? null);
        $item->fetchedAt = self::decodeDate($row['fetchedAt'] ?? null);
        $item->importedElementId = isset($row['importedElementId']) ? (int)$row['importedElementId'] : null;
        $item->sourceHandle = $row['sourceHandle'] ?? null;
        $item->sourceName = $row['sourceName'] ?? null;

        return $item;
    }

    private static function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private static function decodeDate(mixed $value): ?DateTime
    {
        if ($value instanceof DateTime) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTime($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * What the importer and any custom mapping see as `{{ item.* }}`.
     *
     * @return array<string, mixed>
     */
    public function toTemplateVars(): array
    {
        return array_merge($this->extra, [
            'guid' => $this->guid,
            'url' => $this->url,
            'link' => $this->url,
            'title' => $this->getTitleText(),
            'summary' => $this->summary,
            'content' => $this->content,
            'author' => $this->author,
            'image' => $this->getThumbnailUrl(),
            'categories' => $this->categories,
            'publishedAt' => $this->publishedAt,
            'domain' => $this->getDomain(),
            'source' => $this->sourceName,
        ]);
    }

    /** So `{{ item }}` in a template is the title rather than a class name. */
    public function __toString(): string
    {
        return $this->getTitleText();
    }

    /** Escaped, because a feed title routinely contains an ampersand or a stray angle bracket. */
    public function getEscapedTitle(): string
    {
        return Html::encode($this->getTitleText());
    }
}
