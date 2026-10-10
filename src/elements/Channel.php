<?php

namespace justinholtweb\airwave\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use justinholtweb\airwave\elements\db\ChannelQuery;
use justinholtweb\airwave\models\ChannelMapping;
use justinholtweb\airwave\models\FeedMeta;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\Plugin;
use justinholtweb\airwave\records\ChannelRecord;
use yii\base\InvalidConfigException;

/**
 * A feed you publish.
 *
 * A channel is a URI on the site plus enough configuration to answer it with RSS, Atom or JSON
 * Feed. What it broadcasts is either an element query of your own content or the items Airwave has
 * aggregated — which is what makes a curated "best of the web" feed possible without writing a
 * template, and what makes Airwave a *feed* plugin rather than two half-plugins that share an icon.
 *
 * Routes come from {@see \justinholtweb\airwave\services\Channels::getRouteMap()}, which is cached:
 * every front-end request registers these rules, so this must never become a query.
 *
 * @property-read Item[] $items
 * @property-read ChannelMapping $mapping
 */
class Channel extends Element
{
    public const FORMAT_RSS = 'rss';
    public const FORMAT_ATOM = 'atom';
    public const FORMAT_JSON = 'json';

    public const FORMATS = [self::FORMAT_RSS, self::FORMAT_ATOM, self::FORMAT_JSON];

    /** Broadcast your own content, chosen by an element query. */
    public const SOURCE_ELEMENTS = 'elements';

    /** Re-broadcast what Airwave has aggregated. */
    public const SOURCE_FEEDS = 'feeds';

    public const SOURCES = [self::SOURCE_ELEMENTS, self::SOURCE_FEEDS];

    public ?string $handle = null;
    public ?int $targetSiteId = null;
    public ?string $uri = null;
    public string $format = self::FORMAT_RSS;
    public string $feedSource = self::SOURCE_ELEMENTS;
    public ?string $elementType = Entry::class;
    public int $itemLimit = 20;

    public ?string $feedTitle = null;
    public ?string $feedDescription = null;
    public ?string $feedLink = null;
    public ?string $language = null;
    public ?string $copyright = null;
    public ?string $imageUrl = null;
    public ?int $cacheDuration = null;

    private ?array $_criteria = null;
    private ?array $_sourceIds = null;
    private ?ChannelMapping $_mapping = null;

    public static function displayName(): string
    {
        return Craft::t('airwave', 'Channel');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('airwave', 'channel');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('airwave', 'Channels');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('airwave', 'channels');
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        // Airwave routes channels itself rather than putting them in `elements_sites.uri`: a
        // channel is not a page, it has no template, and Craft's element URI machinery would make
        // it one — with a slug, a URI format, and a place in the "shared" URI namespace it can
        // collide with entries in.
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function find(): ChannelQuery
    {
        return new ChannelQuery(static::class);
    }

    // Configuration
    // -------------------------------------------------------------------------

    public function setCriteria(mixed $value): void
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        $this->_criteria = is_array($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCriteria(): array
    {
        return $this->_criteria ?? [];
    }

    public function setSourceIds(mixed $value): void
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : ($value !== '' ? explode(',', $value) : []);
        }

        $this->_sourceIds = is_array($value)
            ? array_values(array_filter(array_map('intval', $value)))
            : null;
    }

    /**
     * Which feeds a re-broadcasting channel pulls from. Empty means all of them.
     *
     * @return int[]
     */
    public function getSourceIds(): array
    {
        return $this->_sourceIds ?? [];
    }

    public function setMapping(mixed $value): void
    {
        $this->_mapping = match (true) {
            $value instanceof ChannelMapping => $value,
            is_array($value) => ChannelMapping::fromArray($value),
            is_string($value) => ChannelMapping::fromJson($value),
            default => null,
        };
    }

    public function getMapping(): ChannelMapping
    {
        return $this->_mapping ??= new ChannelMapping();
    }

    public function getTargetSite(): \craft\models\Site
    {
        $site = $this->targetSiteId ? Craft::$app->getSites()->getSiteById($this->targetSiteId) : null;

        return $site ?? Craft::$app->getSites()->getPrimarySite();
    }

    /**
     * The format this channel will actually be served as.
     *
     * A lapsed licence serves RSS rather than a 404 — a feed URL that has been in subscription
     * lists for a year should keep answering with *a* feed.
     */
    public function getServedFormat(): string
    {
        return \justinholtweb\airwave\models\Edition::applyToChannel($this->format, Plugin::getInstance()->isPro());
    }

    public function getUrl(): ?string
    {
        if (!$this->uri) {
            return null;
        }

        return UrlHelper::siteUrl($this->uri, null, null, $this->targetSiteId);
    }

    public function getCacheDuration(): int
    {
        return $this->cacheDuration ?? Plugin::getInstance()->getSettings()->channelCacheDuration;
    }

    /**
     * @return Item[]
     */
    public function getItems(): array
    {
        return Plugin::getInstance()->channels->getItems($this);
    }

    public function getFeedMeta(): FeedMeta
    {
        return Plugin::getInstance()->channels->getMeta($this);
    }

    public function getMimeType(): string
    {
        return match ($this->getServedFormat()) {
            self::FORMAT_ATOM => 'application/atom+xml',
            self::FORMAT_JSON => 'application/feed+json',
            default => 'application/rss+xml',
        };
    }

    /** The `<link rel="alternate">` a site template puts in its `<head>`. */
    public function getAutoDiscoveryTag(): string
    {
        return Html::tag('link', '', [
            'rel' => 'alternate',
            'type' => $this->getMimeType(),
            'title' => $this->feedTitle ?: $this->title,
            'href' => $this->getUrl(),
        ]);
    }

    // Element plumbing
    // -------------------------------------------------------------------------

    public function getUiLabel(): string
    {
        return $this->title ?: Craft::t('airwave', 'Untitled channel');
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("airwave/channels/$this->id");
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('airwave/channels');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['handle', 'uri'], 'required'];
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'message' => Craft::t('airwave', 'Handles must start with a letter and contain only letters, numbers, dashes and underscores.')];
        $rules[] = [['handle'], 'validateHandleIsFree'];
        $rules[] = [['uri'], 'validateUri'];
        $rules[] = [['format'], 'in', 'range' => self::FORMATS];
        $rules[] = [['feedSource'], 'in', 'range' => self::SOURCES];
        $rules[] = [['itemLimit'], 'integer', 'min' => 1, 'max' => 500];
        $rules[] = [['cacheDuration'], 'integer', 'min' => 0];
        $rules[] = [['targetSiteId'], 'required'];

        return $rules;
    }

    public function validateHandleIsFree(string $attribute): void
    {
        if ($this->handle && Plugin::getInstance()->channels->handleIsTaken($this->handle, $this->id)) {
            $this->addError($attribute, Craft::t('airwave', 'Another channel is already using the handle “{handle}”.', [
                'handle' => $this->handle,
            ]));
        }
    }

    /**
     * A channel URI has to be a URI, and has to be free.
     *
     * Free means free of *other channels* on this site. It is deliberately not checked against
     * entry URIs: Craft resolves element URIs first, so a collision means the entry wins and the
     * channel 404s, which is confusing but not dangerous — and refusing to save would stop the
     * legitimate case of a channel at `blog/feed` next to an entry at `blog`.
     */
    public function validateUri(string $attribute): void
    {
        $uri = trim((string)$this->uri, '/');

        if ($uri === '') {
            $this->addError($attribute, Craft::t('airwave', 'A channel needs a URI.'));

            return;
        }

        if (!preg_match('#^[a-zA-Z0-9\-_./]+$#', $uri)) {
            $this->addError($attribute, Craft::t('airwave', 'URIs can contain letters, numbers, dashes, underscores, dots and slashes.'));

            return;
        }

        $this->uri = $uri;

        if (Plugin::getInstance()->channels->uriIsTaken($uri, (int)$this->targetSiteId, $this->id)) {
            $this->addError($attribute, Craft::t('airwave', 'Another channel on this site is already at “{uri}”.', ['uri' => $uri]));
        }
    }

    public function beforeSave(bool $isNew): bool
    {
        if (!$this->targetSiteId) {
            $this->targetSiteId = Craft::$app->getSites()->getPrimarySite()->id;
        }

        if (!$this->handle && $this->title) {
            $this->handle = Plugin::getInstance()->channels->uniqueHandle($this->title, $this->id);
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new ChannelRecord() : ChannelRecord::findOne($this->id);

            if (!$record) {
                $record = new ChannelRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
            }

            $record->handle = $this->handle;
            $record->targetSiteId = $this->targetSiteId;
            $record->uri = $this->uri;
            $record->format = $this->format;
            $record->feedSource = $this->feedSource;
            $record->elementType = $this->elementType;
            $record->criteria = $this->_criteria ? json_encode($this->_criteria) : null;
            $record->sourceIds = $this->_sourceIds ? json_encode($this->_sourceIds) : null;
            $record->itemLimit = $this->itemLimit;
            $record->mapping = $this->getMapping()->toJson();
            $record->feedTitle = $this->feedTitle;
            $record->feedDescription = $this->feedDescription;
            $record->feedLink = $this->feedLink;
            $record->language = $this->language;
            $record->copyright = $this->copyright;
            $record->imageUrl = $this->imageUrl;
            $record->cacheDuration = $this->cacheDuration;
            $record->save(false);
        }

        // The route map is registered on every front-end request from cache, so a saved channel
        // that is not in it is a channel at a URL that 404s.
        Plugin::getInstance()->channels->invalidateRouteMap();

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        if ($this->handle && $this->id) {
            Craft::$app->getDb()->createCommand()
                ->update('{{%airwave_channels}}', [
                    'handle' => substr((string)$this->handle, 0, 200) . '--trashed-' . $this->id,
                    // The URI is parked too: the unique index is on (site, uri), and a deleted
                    // channel holding `blog/feed` would stop the replacement being created.
                    'uri' => substr((string)$this->uri, 0, 200) . '--trashed-' . $this->id,
                ], ['id' => $this->id])
                ->execute();
        }

        Plugin::getInstance()->channels->invalidateRouteMap();

        parent::afterDelete();
    }

    public function afterRestore(): void
    {
        if ($this->id) {
            $channels = Plugin::getInstance()->channels;
            $handle = preg_replace('/--trashed-\d+$/', '', (string)$this->handle) ?: 'channel';
            $uri = preg_replace('/--trashed-\d+$/', '', (string)$this->uri) ?: 'feed';

            if ($channels->handleIsTaken($handle, $this->id)) {
                $handle = $channels->uniqueHandle($handle, $this->id);
            }

            if ($channels->uriIsTaken($uri, (int)$this->targetSiteId, $this->id)) {
                $uri = $channels->uniqueUri($uri, (int)$this->targetSiteId, $this->id);
            }

            $this->handle = $handle;
            $this->uri = $uri;

            Craft::$app->getDb()->createCommand()
                ->update('{{%airwave_channels}}', ['handle' => $handle, 'uri' => $uri], ['id' => $this->id])
                ->execute();
        }

        Plugin::getInstance()->channels->invalidateRouteMap();

        parent::afterRestore();
    }

    public function attributeLabels(): array
    {
        return array_merge(parent::attributeLabels(), [
            'handle' => Craft::t('airwave', 'Handle'),
            'uri' => Craft::t('airwave', 'URI'),
            'format' => Craft::t('airwave', 'Format'),
            'itemLimit' => Craft::t('airwave', 'Items'),
        ]);
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'handle', 'uri'];
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('airwave', 'All channels'),
                'defaultSort' => ['title', 'asc'],
            ],
            ['heading' => Craft::t('airwave', 'Broadcasting')],
            [
                'key' => 'source:elements',
                'label' => Craft::t('airwave', 'Your content'),
                'criteria' => ['feedSource' => self::SOURCE_ELEMENTS],
            ],
            [
                'key' => 'source:feeds',
                'label' => Craft::t('airwave', 'Aggregated feeds'),
                'criteria' => ['feedSource' => self::SOURCE_FEEDS],
            ],
        ];

        $allSites = Craft::$app->getSites()->getAllSites();

        if (count($allSites) > 1) {
            $sources[] = ['heading' => Craft::t('app', 'Sites')];

            foreach ($allSites as $site) {
                $sources[] = [
                    'key' => 'site:' . $site->handle,
                    'label' => $site->name,
                    'criteria' => ['targetSiteId' => $site->id],
                ];
            }
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'handle' => ['label' => Craft::t('airwave', 'Handle')],
            'feedUrl' => ['label' => Craft::t('airwave', 'URL')],
            'format' => ['label' => Craft::t('airwave', 'Format')],
            'broadcasting' => ['label' => Craft::t('airwave', 'Broadcasting')],
            'itemLimit' => ['label' => Craft::t('airwave', 'Items')],
            'dateUpdated' => ['label' => Craft::t('app', 'Last Updated')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['handle', 'feedUrl', 'format', 'broadcasting'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'airwave_channels.handle' => Craft::t('airwave', 'Handle'),
            'airwave_channels.uri' => Craft::t('airwave', 'URI'),
            'dateUpdated' => Craft::t('app', 'Last Updated'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'handle' => Html::tag('code', Html::encode((string)$this->handle)),
            'feedUrl' => Html::a(Html::encode('/' . ltrim((string)$this->uri, '/')), (string)$this->getUrl(), [
                'target' => '_blank',
                'rel' => 'noopener',
                'class' => 'go',
            ]),
            'format' => Html::tag('span', Html::encode(strtoupper($this->getServedFormat())), ['class' => 'light']),
            'broadcasting' => $this->feedSource === self::SOURCE_FEEDS
                ? Craft::t('airwave', 'Aggregated feeds')
                : Craft::t('airwave', 'Your content'),
            default => parent::attributeHtml($attribute),
        };
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_CHANNELS);
    }

    public function canDuplicate(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_CHANNELS);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_CHANNELS);
    }

    public function canCreateDrafts(User $user): bool
    {
        return false;
    }

    /**
     * @throws InvalidConfigException
     */
    public function getPlugin(): Plugin
    {
        $plugin = Plugin::getInstance();

        if (!$plugin) {
            throw new InvalidConfigException('Airwave is not installed.');
        }

        return $plugin;
    }
}
