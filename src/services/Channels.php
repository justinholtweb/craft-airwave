<?php

namespace justinholtweb\airwave\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\StringHelper;
use craft\web\UrlManager;
use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\models\Edition;
use justinholtweb\airwave\models\FeedMeta;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\Plugin;
use Throwable;
use yii\base\Component;
use yii\base\Event;

/**
 * Published feeds: their routes, and what they contain.
 *
 * The route map is the part with a trap in it. Channel URIs have to be registered as site URL
 * rules on **every front-end request**, which means this must never be a database query — so the
 * map is cached, and every write path invalidates it. A channel that saves but does not appear in
 * the map is a feed URL that 404s, which is why {@see Channel::afterSave()},
 * {@see Channel::afterDelete()} and {@see Channel::afterRestore()} all call
 * {@see self::invalidateRouteMap()} rather than trusting a TTL.
 *
 * Channels are not given Craft's own element URIs on purpose — see {@see Channel::hasUris()}.
 */
class Channels extends Component
{
    private const CACHE_KEY = 'airwave:routes:';

    /**
     * Element query parameters a channel may set.
     *
     * An allowlist rather than "configure the query with whatever was posted": most of an element
     * query's surface is not a filter — `select`, `join`, `with`, `cache` — and letting stored
     * configuration reach those turns a content setting into something closer to code.
     */
    private const ALLOWED_CRITERIA = [
        'section', 'sectionId', 'type', 'typeId', 'id', 'slug', 'uri', 'title',
        'status', 'authorId', 'postDate', 'after', 'before', 'expiryDate',
        'level', 'descendantOf', 'ancestorOf', 'descendantDist', 'hasDescendants',
        'relatedTo', 'search', 'orderBy', 'group', 'groupId', 'volume', 'volumeId',
        'kind', 'filename', 'folderId', 'field', 'editable', 'site', 'siteId', 'limit', 'offset',
    ];

    /** @var Channel[]|null */
    private ?array $_all = null;

    /**
     * @return Channel[]
     */
    public function getAllChannels(bool $includeDisabled = false): array
    {
        if ($this->_all === null) {
            $this->_all = Channel::find()->status(null)->limit(null)->all();
        }

        if ($includeDisabled) {
            return $this->_all;
        }

        return array_values(array_filter($this->_all, fn(Channel $channel) => $channel->enabled));
    }

    public function getChannelById(int $id): ?Channel
    {
        return Channel::find()->id($id)->status(null)->one();
    }

    public function getChannelByHandle(string $handle): ?Channel
    {
        return Channel::find()->handle($handle)->status(null)->one();
    }

    public function clearCaches(): void
    {
        $this->_all = null;
    }

    // Routing
    // -------------------------------------------------------------------------

    public function register(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += $this->getRouteMap(Craft::$app->getSites()->getCurrentSite()->id);
        });
    }

    /**
     * @return array<string, array{route: string, params: array<string, mixed>}>
     */
    public function getRouteMap(int $siteId): array
    {
        $cache = Craft::$app->getCache();
        $key = self::CACHE_KEY . $siteId;
        $map = $cache->get($key);

        if (is_array($map)) {
            return $map;
        }

        $map = [];

        foreach ($this->getAllChannels() as $channel) {
            if ($channel->targetSiteId === $siteId && $channel->uri) {
                $map[$channel->uri] = [
                    'route' => 'airwave/feed/serve',
                    'params' => ['handle' => $channel->handle],
                ];
            }
        }

        $cache->set($key, $map);

        return $map;
    }

    public function invalidateRouteMap(): void
    {
        $this->clearCaches();
        $cache = Craft::$app->getCache();

        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            $cache->delete(self::CACHE_KEY . $siteId);
        }
    }

    // Handles and URIs
    // -------------------------------------------------------------------------

    public function handleIsTaken(string $handle, ?int $exceptId = null): bool
    {
        $query = Channel::find()->handle($handle)->status(null);

        if ($exceptId) {
            $query->id("not $exceptId");
        }

        return $query->exists();
    }

    public function uniqueHandle(string $seed, ?int $exceptId = null): string
    {
        $base = trim(preg_replace('/[^a-zA-Z0-9\-_]/', '-', StringHelper::toKebabCase(StringHelper::toAscii($seed))) ?? '', '-');

        if ($base === '' || !preg_match('/^[a-zA-Z]/', $base)) {
            $base = 'channel' . ($base !== '' ? "-$base" : '');
        }

        $base = substr($base, 0, 180);
        $handle = $base;
        $suffix = 1;

        while ($this->handleIsTaken($handle, $exceptId)) {
            $handle = $base . '-' . ++$suffix;
        }

        return $handle;
    }

    public function uriIsTaken(string $uri, int $siteId, ?int $exceptId = null): bool
    {
        $query = Channel::find()->uri($uri)->targetSiteId($siteId)->status(null);

        if ($exceptId) {
            $query->id("not $exceptId");
        }

        return $query->exists();
    }

    public function uniqueUri(string $uri, int $siteId, ?int $exceptId = null): string
    {
        $base = trim($uri, '/');
        $candidate = $base;
        $suffix = 1;

        while ($this->uriIsTaken($candidate, $siteId, $exceptId)) {
            $candidate = $base . '-' . ++$suffix;
        }

        return $candidate;
    }

    // Edition
    // -------------------------------------------------------------------------

    public function canCreate(): bool
    {
        return $this->remaining() !== 0;
    }

    public function remaining(): ?int
    {
        $max = Edition::maxChannels(Plugin::getInstance()->isPro());

        if ($max === null) {
            return null;
        }

        return max(0, $max - (int)Channel::find()->status(null)->count());
    }

    public function saveChannel(Channel $channel, bool $runValidation = true): bool
    {
        if ($channel->id === null && !$this->canCreate()) {
            $channel->addError('handle', Craft::t('airwave', 'Airwave Lite publishes {max} channel.', [
                'max' => Edition::LITE_MAX_CHANNELS,
            ]));

            return false;
        }

        return Craft::$app->getElements()->saveElement($channel, $runValidation);
    }

    public function deleteChannel(Channel $channel): bool
    {
        return Craft::$app->getElements()->deleteElement($channel);
    }

    // Contents
    // -------------------------------------------------------------------------

    /**
     * @return Item[]
     */
    public function getItems(Channel $channel): array
    {
        return $channel->feedSource === Channel::SOURCE_FEEDS
            ? $this->getAggregatedItems($channel)
            : $this->getElementItems($channel);
    }

    /**
     * @return Item[]
     */
    private function getAggregatedItems(Channel $channel): array
    {
        $criteria = ['limit' => $channel->itemLimit, 'sort' => 'newest'];
        $sourceIds = $channel->getSourceIds();

        if ($sourceIds) {
            $criteria['sourceId'] = $sourceIds;
        }

        return Plugin::getInstance()->items->find($criteria);
    }

    /**
     * @return Item[]
     */
    private function getElementItems(Channel $channel): array
    {
        $elementType = $channel->elementType ?: Entry::class;

        if (!is_subclass_of($elementType, ElementInterface::class)) {
            return [];
        }

        /** @var ElementQuery $query */
        $query = $elementType::find();
        $query->siteId = $channel->targetSiteId;
        $query->limit = $channel->itemLimit;

        // A feed of disabled or expired content is not a feed anybody wants.
        $query->status = ['enabled'];

        foreach ($channel->getCriteria() as $param => $value) {
            if (!in_array($param, self::ALLOWED_CRITERIA, true) || $value === '' || $value === null) {
                continue;
            }

            try {
                $query->$param($value);
            } catch (Throwable $e) {
                Craft::warning("Airwave channel {$channel->handle} ignored criteria “$param”: {$e->getMessage()}", 'airwave');
            }
        }

        // Set after the criteria so a stored `limit` cannot quietly publish 5,000 items.
        $query->limit($channel->itemLimit);

        if (!$query->orderBy) {
            $query->orderBy(['postDate' => SORT_DESC]);
        }

        $mapping = $channel->getMapping();
        $usesTemplates = $mapping->usesTemplates() && Edition::allowsChannelTemplates(Plugin::getInstance()->isPro());
        $items = [];

        foreach ($query->all() as $element) {
            $items[] = $usesTemplates
                ? $mapping->resolve($element)
                : $this->resolveWithoutTemplates($mapping, $element);
        }

        return $items;
    }

    /**
     * Lite's path: the guesses, with the object templates ignored rather than obeyed.
     *
     * A lapsed licence should keep the feed running with sensible defaults, not start rendering
     * `{{ object.body }}` as literal text into somebody's reader.
     */
    private function resolveWithoutTemplates(\justinholtweb\airwave\models\ChannelMapping $mapping, ElementInterface $element): Item
    {
        $plain = \justinholtweb\airwave\models\ChannelMapping::fromArray([
            'dateAttribute' => $mapping->dateAttribute,
            'fullContent' => $mapping->fullContent,
            'summaryLength' => $mapping->summaryLength,
        ]);

        return $plain->resolve($element);
    }

    public function getMeta(Channel $channel): FeedMeta
    {
        $site = $channel->getTargetSite();

        $meta = new FeedMeta();
        $meta->title = $channel->feedTitle ?: ($channel->title ?: $site->getName());
        $meta->link = $channel->feedLink ?: $site->getBaseUrl();
        $meta->description = $channel->feedDescription ?: '';
        $meta->language = $channel->language ?: str_replace('_', '-', $site->language);
        $meta->imageUrl = $channel->imageUrl;
        $meta->generator = Plugin::getInstance()->getSettings()->getGeneratorName();
        $meta->format = $channel->getServedFormat();

        return $meta;
    }
}
