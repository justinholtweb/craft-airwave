<?php

namespace justinholtweb\airwave\services;

use Craft;
use DateTime;
use DateTimeZone;
use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\models\FeedMeta;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\Plugin;
use XMLWriter;
use yii\base\Component;

/**
 * Turning items into a feed document.
 *
 * Written with XMLWriter rather than a template, for one reason that matters: a feed is XML, and
 * XML has exactly one escaping rule, which a writer applies and a template does not. Feeds
 * assembled by string concatenation break the first time an entry title contains an ampersand,
 * and they break *silently* — readers just stop updating.
 *
 * Three formats, one item shape ({@see Item}), so a channel switching from RSS to JSON Feed is a
 * dropdown rather than a rewrite.
 *
 * Aggregated content is purified on the way out as well as on the way in: a channel that
 * re-broadcasts somebody else's items is republishing markup under *your* domain, and whatever a
 * subscriber's reader does with it is done in your name.
 */
class Broadcaster extends Component
{
    private const CACHE_KEY = 'airwave:channel:';

    /**
     * The rendered feed, from cache if it is there.
     */
    public function render(Channel $channel): string
    {
        $duration = $channel->getCacheDuration();

        if ($duration <= 0 || Craft::$app->getConfig()->getGeneral()->devMode) {
            return $this->build($channel);
        }

        $cache = Craft::$app->getCache();
        // The format is in the key because a lapsed licence changes what gets served, and a cached
        // Atom document answered with an RSS content type is worse than a slow response.
        $key = self::CACHE_KEY . $channel->id . ':' . $channel->getServedFormat() . ':' . $channel->dateUpdated?->getTimestamp();
        $cached = $cache->get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $document = $this->build($channel);
        $cache->set($key, $document, $duration);

        return $document;
    }

    public function invalidate(Channel $channel): void
    {
        foreach (Channel::FORMATS as $format) {
            Craft::$app->getCache()->delete(self::CACHE_KEY . $channel->id . ':' . $format . ':' . $channel->dateUpdated?->getTimestamp());
        }
    }

    public function build(Channel $channel): string
    {
        $items = $channel->getItems();
        $meta = $channel->getFeedMeta();
        $purify = $channel->feedSource === Channel::SOURCE_FEEDS;

        return match ($channel->getServedFormat()) {
            Channel::FORMAT_ATOM => $this->buildAtom($meta, $items, $channel, $purify),
            Channel::FORMAT_JSON => $this->buildJson($meta, $items, $channel, $purify),
            default => $this->buildRss($meta, $items, $channel, $purify),
        };
    }

    // RSS 2.0
    // -------------------------------------------------------------------------

    /**
     * @param Item[] $items
     */
    public function buildRss(FeedMeta $meta, array $items, ?Channel $channel = null, bool $purify = true): string
    {
        $writer = $this->writer();
        $writer->startElement('rss');
        $writer->writeAttribute('version', '2.0');
        $writer->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $writer->writeAttribute('xmlns:content', Parser::NS_CONTENT);
        $writer->writeAttribute('xmlns:dc', Parser::NS_DC);

        $writer->startElement('channel');
        $this->element($writer, 'title', $meta->title);
        $this->element($writer, 'link', $meta->link);
        // `<description>` is required by the spec even when it is empty, and readers do drop feeds
        // that omit it.
        $writer->writeElement('description', (string)$meta->description);
        $this->element($writer, 'language', $meta->language);
        $this->element($writer, 'generator', $meta->generator);
        $writer->writeElement('lastBuildDate', $this->rssDate($this->latestDate($items)));

        if ($channel?->copyright) {
            $writer->writeElement('copyright', $channel->copyright);
        }

        if ($self = $channel?->getUrl()) {
            $writer->startElement('atom:link');
            $writer->writeAttribute('href', $self);
            $writer->writeAttribute('rel', 'self');
            $writer->writeAttribute('type', 'application/rss+xml');
            $writer->endElement();
        }

        if ($meta->imageUrl) {
            $writer->startElement('image');
            $writer->writeElement('url', $meta->imageUrl);
            $writer->writeElement('title', (string)$meta->title);
            $writer->writeElement('link', (string)$meta->link);
            $writer->endElement();
        }

        foreach ($items as $item) {
            $writer->startElement('item');
            $this->element($writer, 'title', $item->getTitleText());
            $this->element($writer, 'link', $item->url);

            if ($item->guid) {
                $writer->startElement('guid');
                // Only claim it is a permalink if it actually looks like one. Readers that trust
                // the flag and fetch a non-URL guid produce noise in somebody's error log.
                $writer->writeAttribute('isPermaLink', preg_match('#^https?://#i', $item->guid) ? 'true' : 'false');
                $writer->text($this->clean($item->guid));
                $writer->endElement();
            }

            if ($item->publishedAt) {
                $writer->writeElement('pubDate', $this->rssDate($item->publishedAt));
            }

            if ($item->author) {
                $writer->writeElement('dc:creator', $this->clean($item->author));
            }

            $summary = $item->summary ?: $item->getExcerpt(400);

            if ($summary !== '') {
                $writer->startElement('description');
                $writer->writeCdata($this->cdata($this->maybePurify($summary, $purify)));
                $writer->endElement();
            }

            if ($item->content) {
                $writer->startElement('content:encoded');
                $writer->writeCdata($this->cdata($this->maybePurify($item->content, $purify)));
                $writer->endElement();
            }

            foreach ($item->categories as $category) {
                $writer->writeElement('category', $this->clean($category));
            }

            if ($item->enclosureUrl) {
                $writer->startElement('enclosure');
                $writer->writeAttribute('url', $item->enclosureUrl);
                $writer->writeAttribute('type', $item->enclosureType ?: 'application/octet-stream');
                // `length` is required by the spec, and plenty of readers will not show an
                // enclosure without one. Zero is the honest answer when the source did not say.
                $writer->writeAttribute('length', (string)($item->enclosureLength ?? 0));
                $writer->endElement();
            }

            $writer->endElement();
        }

        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    // Atom 1.0
    // -------------------------------------------------------------------------

    /**
     * @param Item[] $items
     */
    public function buildAtom(FeedMeta $meta, array $items, ?Channel $channel = null, bool $purify = true): string
    {
        $self = $channel?->getUrl();
        $writer = $this->writer();
        $writer->startElement('feed');
        $writer->writeAttribute('xmlns', Parser::NS_ATOM);

        if ($meta->language) {
            $writer->writeAttribute('xml:lang', $meta->language);
        }

        $this->element($writer, 'title', $meta->title);
        $this->element($writer, 'subtitle', $meta->description);
        // Atom requires a globally unique, permanent id. The feed's own URL is the only thing here
        // that qualifies without inventing a scheme.
        $writer->writeElement('id', $self ?: (string)$meta->link);
        $writer->writeElement('updated', $this->atomDate($this->latestDate($items)));
        $this->element($writer, 'generator', $meta->generator);

        if ($meta->link) {
            $this->atomLink($writer, 'alternate', $meta->link, 'text/html');
        }

        if ($self) {
            $this->atomLink($writer, 'self', $self, 'application/atom+xml');
        }

        if ($meta->imageUrl) {
            $writer->writeElement('logo', $meta->imageUrl);
        }

        if ($channel?->copyright) {
            $writer->writeElement('rights', $channel->copyright);
        }

        foreach ($items as $item) {
            $writer->startElement('entry');
            $this->element($writer, 'title', $item->getTitleText());
            $writer->writeElement('id', $this->clean($item->guid ?: (string)$item->url));
            $writer->writeElement('updated', $this->atomDate($item->publishedAt));

            if ($item->publishedAt) {
                $writer->writeElement('published', $this->atomDate($item->publishedAt));
            }

            if ($item->url) {
                $this->atomLink($writer, 'alternate', $item->url, 'text/html');
            }

            if ($item->author) {
                $writer->startElement('author');
                $writer->writeElement('name', $this->clean($item->author));
                $writer->endElement();
            }

            $summary = $item->summary ?: $item->getExcerpt(400);

            if ($summary !== '') {
                $writer->startElement('summary');
                $writer->writeAttribute('type', 'html');
                $writer->text($this->maybePurify($summary, $purify));
                $writer->endElement();
            }

            if ($item->content) {
                $writer->startElement('content');
                $writer->writeAttribute('type', 'html');
                $writer->text($this->maybePurify($item->content, $purify));
                $writer->endElement();
            }

            foreach ($item->categories as $category) {
                $writer->startElement('category');
                $writer->writeAttribute('term', $this->clean($category));
                $writer->endElement();
            }

            if ($item->enclosureUrl) {
                $writer->startElement('link');
                $writer->writeAttribute('rel', 'enclosure');
                $writer->writeAttribute('href', $item->enclosureUrl);
                $writer->writeAttribute('type', $item->enclosureType ?: 'application/octet-stream');

                if ($item->enclosureLength) {
                    $writer->writeAttribute('length', (string)$item->enclosureLength);
                }

                $writer->endElement();
            }

            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    // JSON Feed 1.1
    // -------------------------------------------------------------------------

    /**
     * @param Item[] $items
     */
    public function buildJson(FeedMeta $meta, array $items, ?Channel $channel = null, bool $purify = true): string
    {
        $feed = array_filter([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => $meta->title ?: 'Feed',
            'home_page_url' => $meta->link,
            'feed_url' => $channel?->getUrl(),
            'description' => $meta->description ?: null,
            'language' => $meta->language,
            'icon' => $meta->imageUrl,
        ], fn($value) => $value !== null && $value !== '');

        $feed['items'] = [];

        foreach ($items as $item) {
            $entry = array_filter([
                // `id` is the only required field, and it has to be stable across fetches.
                'id' => $item->guid ?: ($item->url ?: (string)$item->id),
                'url' => $item->url,
                'title' => $item->getTitleText(),
                'summary' => $item->summary ? $item->getExcerpt(400) : null,
                'content_html' => $item->content ? $this->maybePurify($item->content, $purify) : null,
                'date_published' => $item->publishedAt?->format(DATE_ATOM),
                'image' => $item->getThumbnailUrl(),
            ], fn($value) => $value !== null && $value !== '');

            if (!isset($entry['content_html'])) {
                // JSON Feed wants one of content_html/content_text. An item with neither is not a
                // valid entry, so fall back to the plain summary.
                $entry['content_text'] = $item->getExcerpt(400);
            }

            if ($item->author) {
                $entry['authors'] = [['name' => $item->author]];
            }

            if ($item->categories) {
                $entry['tags'] = array_values($item->categories);
            }

            if ($item->enclosureUrl) {
                $entry['attachments'] = [array_filter([
                    'url' => $item->enclosureUrl,
                    'mime_type' => $item->enclosureType ?: 'application/octet-stream',
                    'size_in_bytes' => $item->enclosureLength,
                ], fn($value) => $value !== null)];
            }

            $feed['items'][] = $entry;
        }

        return (string)json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // Plumbing
    // -------------------------------------------------------------------------

    private function writer(): XMLWriter
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        return $writer;
    }

    private function element(XMLWriter $writer, string $name, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $writer->writeElement($name, $this->clean($value));
        }
    }

    private function atomLink(XMLWriter $writer, string $rel, string $href, string $type): void
    {
        $writer->startElement('link');
        $writer->writeAttribute('rel', $rel);
        $writer->writeAttribute('href', $href);
        $writer->writeAttribute('type', $type);
        $writer->endElement();
    }

    private function maybePurify(string $html, bool $purify): string
    {
        return $purify ? (string)Plugin::getInstance()->parser->purify($html) : $html;
    }

    /**
     * Control characters are not legal in XML 1.0 at all — not even escaped — and one of them
     * anywhere in the document makes the whole feed unparseable.
     */
    private function clean(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;
    }

    /** CDATA cannot contain `]]>`, and the only way out is to split the section around it. */
    private function cdata(string $value): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $this->clean($value));
    }

    private function rssDate(?DateTime $date): string
    {
        return ($date ?? new DateTime('now', new DateTimeZone('UTC')))->format(DATE_RSS);
    }

    private function atomDate(?DateTime $date): string
    {
        return ($date ?? new DateTime('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    /**
     * @param Item[] $items
     */
    private function latestDate(array $items): ?DateTime
    {
        $latest = null;

        foreach ($items as $item) {
            if ($item->publishedAt && (!$latest || $item->publishedAt > $latest)) {
                $latest = $item->publishedAt;
            }
        }

        return $latest;
    }
}
