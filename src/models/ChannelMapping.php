<?php

namespace justinholtweb\airwave\models;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Db;
use DateTime;
use Throwable;
use justinholtweb\airwave\helpers\Trust;

/**
 * How one of your elements becomes one feed item.
 *
 * Every field is an **object template** — `{title}`, `{{ object.body }}`, `{{ object.author.name }}`
 * — evaluated against the element. Blank means "work it out", and the guesses are deliberate and
 * listed in constants rather than hidden in an `if`: a channel that produces sensible output the
 * moment it is created is the difference between a feature people use and a feature people
 * configure once and abandon.
 *
 * Object templates are Pro. Lite gets the guesses, which are the right answer for most sites.
 */
class ChannelMapping
{
    /** Field handles tried, in order, when no summary template is given. */
    public const SUMMARY_HANDLES = ['summary', 'excerpt', 'description', 'subtitle', 'standfirst', 'teaser'];

    /** Field handles tried, in order, when no content template is given. */
    public const CONTENT_HANDLES = ['body', 'content', 'articleBody', 'text', 'richText'];

    /** Field handles tried, in order, when no image template is given. */
    public const IMAGE_HANDLES = ['featureImage', 'featuredImage', 'image', 'heroImage', 'thumbnail', 'coverImage'];

    /**
     * The properties that hold Twig object templates. Only admins may change them — see
     * {@see \justinholtweb\airwave\helpers\Trust}.
     */
    public const TEMPLATE_KEYS = ['title', 'link', 'summary', 'content', 'author', 'image', 'categories'];

    public ?string $title = null;
    public ?string $link = null;
    public ?string $summary = null;
    public ?string $content = null;
    public ?string $author = null;
    public ?string $image = null;
    public ?string $categories = null;

    /** The element attribute the item's date comes from. */
    public string $dateAttribute = 'postDate';

    /** How much of the content to put in the item. Full content is what readers actually want. */
    public bool $fullContent = true;

    /** Characters of summary to include. 0 means the whole thing. */
    public int $summaryLength = 400;

    public function resolve(ElementInterface $element): Item
    {
        $item = new Item();

        $item->title = $this->render($this->title, $element) ?? (string)$element;
        $item->url = $this->render($this->link, $element) ?? $element->getUrl();
        $item->summary = $this->render($this->summary, $element) ?? $this->guess($element, self::SUMMARY_HANDLES);
        $item->content = $this->fullContent
            ? ($this->render($this->content, $element) ?? $this->guess($element, self::CONTENT_HANDLES))
            : null;
        $item->author = $this->render($this->author, $element) ?? $this->guessAuthor($element);
        $item->imageUrl = $this->render($this->image, $element) ?? $this->guessImage($element);
        $item->categories = $this->splitCategories($this->render($this->categories, $element));
        $item->publishedAt = $this->resolveDate($element);
        $item->guid = $item->url ?: sprintf('urn:uuid:%s', $element->uid);

        // No summary and no content is an item with nothing to read. Borrow from the other side
        // rather than publishing a bare headline.
        if (!$item->summary && $item->content) {
            $item->summary = $item->getExcerpt($this->summaryLength ?: 400);
        } elseif ($item->summary && $this->summaryLength > 0) {
            $item->summary = $item->getExcerpt($this->summaryLength);
        }

        return $item;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public static function fromArray(?array $config): self
    {
        $mapping = new self();

        if (!$config) {
            return $mapping;
        }

        foreach (self::TEMPLATE_KEYS as $key) {
            if (!empty($config[$key]) && is_string($config[$key])) {
                $mapping->$key = $config[$key];
            }
        }

        if (!empty($config['dateAttribute']) && is_string($config['dateAttribute'])) {
            $mapping->dateAttribute = $config['dateAttribute'];
        }

        if (array_key_exists('fullContent', $config)) {
            $mapping->fullContent = (bool)$config['fullContent'];
        }

        if (isset($config['summaryLength'])) {
            $mapping->summaryLength = max(0, (int)$config['summaryLength']);
        }

        return $mapping;
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

    /** Whether anything here needs Pro. The guesses do not. */
    public function usesTemplates(): bool
    {
        foreach (self::TEMPLATE_KEYS as $key) {
            if ($this->$key) {
                return true;
            }
        }

        return false;
    }

    /**
     * A template failing is not a reason to serve a 500 to a feed reader.
     *
     * The subject is exposed as `object` because that is what `renderObjectTemplate()` calls it,
     * and as `element` as well, because that is what everybody types first.
     */
    private function render(?string $template, ElementInterface $element): ?string
    {
        if (!$template) {
            return null;
        }

        try {
            $value = Trust::renderObjectTemplate($template, $element, [
                'element' => $element,
                'object' => $element,
            ]);
        } catch (Throwable $e) {
            Craft::warning("Airwave channel template failed: {$e->getMessage()}", 'airwave');

            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param string[] $handles
     */
    private function guess(ElementInterface $element, array $handles): ?string
    {
        foreach ($handles as $handle) {
            try {
                $value = $element->getFieldValue($handle);
            } catch (Throwable) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            // Rich text comes back as HtmlFieldData, Markup, or a string, and all three stringify.
            if (is_scalar($value) || $value instanceof \Stringable) {
                $string = trim((string)$value);

                if ($string !== '') {
                    return $string;
                }
            }
        }

        return null;
    }

    private function guessAuthor(ElementInterface $element): ?string
    {
        if (!$element instanceof Entry) {
            return null;
        }

        try {
            return $element->getAuthor()?->getName();
        } catch (Throwable) {
            return null;
        }
    }

    private function guessImage(ElementInterface $element): ?string
    {
        foreach (self::IMAGE_HANDLES as $handle) {
            try {
                $value = $element->getFieldValue($handle);
            } catch (Throwable) {
                continue;
            }

            $asset = match (true) {
                $value instanceof Asset => $value,
                // An Assets field value is a query until somebody asks it a question.
                is_object($value) && method_exists($value, 'one') => $value->one(),
                default => null,
            };

            if ($asset instanceof Asset && ($url = $asset->getUrl())) {
                return $url;
            }
        }

        return null;
    }

    private function resolveDate(ElementInterface $element): ?DateTime
    {
        foreach ([$this->dateAttribute, 'postDate', 'dateCreated'] as $attribute) {
            $value = $element->$attribute ?? null;

            if ($value instanceof DateTime) {
                return $value;
            }

            if (is_string($value) && $value !== '') {
                $parsed = Db::prepareDateForDb($value) ? new DateTime($value) : null;

                if ($parsed) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function splitCategories(?string $value): array
    {
        if (!$value) {
            return [];
        }

        $parts = array_map('trim', preg_split('/[,\r\n]+/', $value) ?: []);

        return array_values(array_filter($parts, fn($p) => $p !== ''));
    }
}
