<?php

namespace justinholtweb\airwave\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\airwave\elements\db\SourceQuery;
use justinholtweb\airwave\models\DisplayOptions;
use justinholtweb\airwave\models\FeedMeta;
use justinholtweb\airwave\models\FilterRules;
use justinholtweb\airwave\models\ImportConfig;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\Plugin;
use justinholtweb\airwave\records\SourceRecord;
use Twig\Markup;

/**
 * A feed you subscribe to.
 *
 * Being a real element buys the index, search, permissions, relations, restore-from-trash — and,
 * the reason it is an element rather than a row in a settings screen, **reference tags**.
 * `craft\htmlfield\HtmlFieldData` parses ref tags over every rich-text value, so
 * `{airwave:craft-news:render}` drops a rendered feed into CKEditor content, Redactor content or
 * any other HTML field with no template changes — which is the thing people actually miss when
 * they move off WordPress and lose their shortcodes.
 *
 * Not localized. A feed is the same feed on every site; what differs per site is which channel
 * re-broadcasts it, and that is {@see Channel}'s problem.
 *
 * @property-read Item[] $items
 * @property-read FeedMeta $meta
 * @property-read FilterRules $filters
 * @property-read ImportConfig $importConfig
 * @property-read DisplayOptions $displayOptions
 */
class Source extends Element
{
    /** Fetched cleanly last time. */
    public const STATUS_OK = 'ok';

    /** The last fetch failed. The items already stored are still served — a broken feed should
     *  degrade to a stale feed, not to an empty page. */
    public const STATUS_ERROR = 'error';

    /** Never fetched. */
    public const STATUS_NEVER = 'never';

    public const FETCH_STATUSES = [self::STATUS_OK, self::STATUS_ERROR, self::STATUS_NEVER];

    public ?string $handle = null;
    public ?string $url = null;
    public ?int $refreshInterval = null;
    public ?int $itemLimit = null;
    public bool $importEnabled = false;

    public ?string $etag = null;
    public ?string $lastModifiedHeader = null;
    public ?DateTime $lastFetchedAt = null;
    public ?DateTime $lastSuccessAt = null;
    public string $lastStatus = self::STATUS_NEVER;
    public ?string $lastError = null;
    public int $itemCount = 0;
    public int $newItemCount = 0;

    private ?FeedMeta $_meta = null;
    private ?FilterRules $_filters = null;
    private ?DisplayOptions $_displayOptions = null;
    private ?ImportConfig $_importConfig = null;
    private ?array $_fetchOptions = null;

    public static function displayName(): string
    {
        return Craft::t('airwave', 'Feed');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('airwave', 'feed');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('airwave', 'Feeds');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('airwave', 'feeds');
    }

    public static function refHandle(): ?string
    {
        return 'airwave';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
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

    public static function trackChanges(): bool
    {
        return false;
    }

    public static function find(): SourceQuery
    {
        return new SourceQuery(static::class);
    }

    // Configuration
    // -------------------------------------------------------------------------

    public function setMeta(mixed $value): void
    {
        $this->_meta = match (true) {
            $value instanceof FeedMeta => $value,
            is_array($value) => FeedMeta::fromArray($value),
            is_string($value) && $value !== '' => FeedMeta::fromArray(json_decode($value, true)),
            default => null,
        };
    }

    public function getMeta(): FeedMeta
    {
        return $this->_meta ??= new FeedMeta();
    }

    public function setFilters(mixed $value): void
    {
        $this->_filters = match (true) {
            $value instanceof FilterRules => $value,
            is_array($value) => FilterRules::fromArray($value),
            is_string($value) => FilterRules::fromJson($value),
            default => null,
        };
    }

    public function getFilters(): FilterRules
    {
        return $this->_filters ??= new FilterRules();
    }

    public function setDisplayOptions(mixed $value): void
    {
        $this->_displayOptions = match (true) {
            $value instanceof DisplayOptions => $value,
            is_array($value) => DisplayOptions::fromArray($value),
            is_string($value) => DisplayOptions::fromJson($value),
            default => null,
        };
    }

    public function getDisplayOptions(): DisplayOptions
    {
        return $this->_displayOptions ??= Plugin::getInstance()->getSettings()->getDefaultDisplayOptions();
    }

    public function setImportConfig(mixed $value): void
    {
        $this->_importConfig = match (true) {
            $value instanceof ImportConfig => $value,
            is_array($value) => ImportConfig::fromArray($value),
            is_string($value) => ImportConfig::fromJson($value),
            default => null,
        };
    }

    public function getImportConfig(): ImportConfig
    {
        return $this->_importConfig ??= new ImportConfig();
    }

    public function setFetchOptions(mixed $value): void
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        $this->_fetchOptions = is_array($value) ? $value : null;
    }

    /**
     * Extra request configuration — right now, `headers`.
     *
     * Header *values* are run through `App::parseEnv()` by the fetcher, so a private feed is
     * subscribed to with `Authorization: $FEED_TOKEN` and the token itself never reaches the
     * database. Airwave has no field for a password on purpose.
     *
     * @return array<string, mixed>
     */
    public function getFetchOptions(): array
    {
        return $this->_fetchOptions ?? [];
    }

    // Behaviour
    // -------------------------------------------------------------------------

    /** How stale this feed is allowed to get, in seconds. Per-feed intervals are Pro. */
    public function getRefreshInterval(): int
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($this->refreshInterval && Plugin::getInstance()->isPro()) {
            return max(60, $this->refreshInterval);
        }

        return max(60, $settings->defaultRefreshInterval);
    }

    public function getIsDue(): bool
    {
        if (!$this->lastFetchedAt) {
            return true;
        }

        return (time() - $this->lastFetchedAt->getTimestamp()) >= $this->getRefreshInterval();
    }

    public function getHasBeenFetched(): bool
    {
        return $this->lastStatus !== self::STATUS_NEVER;
    }

    /** When this feed goes stale, for the CP. */
    public function getNextRefreshAt(): ?DateTime
    {
        if (!$this->lastFetchedAt) {
            return null;
        }

        return (clone $this->lastFetchedAt)->modify('+' . $this->getRefreshInterval() . ' seconds');
    }

    /**
     * @param array<string, mixed> $criteria
     * @return Item[]
     */
    public function getItems(array $criteria = []): array
    {
        return Plugin::getInstance()->items->find(array_merge(['sourceId' => $this->id], $criteria));
    }

    /**
     * Renders this feed with its own display options.
     *
     * @param array<string, mixed> $options Per-call overrides. A reference tag cannot pass these,
     * which is why a feed's stored options are the ones that matter.
     */
    public function render(array $options = []): Markup
    {
        return Plugin::getInstance()->renderer->render($this, $options);
    }

    /**
     * What `{airwave:craft-news:render}` resolves to.
     *
     * Craft splices the result in raw, so this one method is the entire rich-text integration —
     * whichever editor produced the content, the feed renders the same way.
     */
    public function getRender(): Markup
    {
        return $this->render();
    }

    public function getEmbedCode(): string
    {
        return sprintf('{airwave:%s:render}', $this->handle ?: $this->id);
    }

    // Element plumbing
    // -------------------------------------------------------------------------

    public function getRef(): ?string
    {
        return $this->handle;
    }

    public function getUiLabel(): string
    {
        return $this->title ?: ($this->getMeta()->title ?: Craft::t('airwave', 'Untitled feed'));
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("airwave/feeds/$this->id");
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('airwave/feeds');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        // Dashes allowed: `{airwave:craft-news:render}` parses fine — Craft's ref pattern is far
        // looser than its handle pattern — and reads better than `craftNews`.
        $rules[] = [['handle'], 'required'];
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'message' => Craft::t('airwave', 'Handles must start with a letter and contain only letters, numbers, dashes and underscores.')];
        $rules[] = [['handle'], 'validateHandleIsFree'];
        $rules[] = [['url'], 'required'];
        $rules[] = [['url'], 'validateUrl'];
        $rules[] = [['refreshInterval'], 'integer', 'min' => 60];
        $rules[] = [['itemLimit'], 'integer', 'min' => 1, 'max' => 500];
        $rules[] = [['lastStatus'], 'in', 'range' => self::FETCH_STATUSES];

        return $rules;
    }

    /**
     * The URL has to be one the fetcher would agree to open.
     *
     * Checked here rather than only at fetch time so an author finds out while they are looking
     * at the field, not from a queue job that failed overnight.
     */
    public function validateUrl(string $attribute): void
    {
        $error = Plugin::getInstance()->fetcher->validateUrl((string)$this->url);

        if ($error !== null) {
            $this->addError($attribute, $error);
        }
    }

    /**
     * Handles have to be unique among *live* feeds, not among rows.
     *
     * A soft-deleted feed keeps its row, so a unique index alone would hold its handle forever
     * against a feed nobody can see. Element queries exclude trashed rows, which is the same
     * question an author is asking. {@see self::afterDelete()} handles the other side.
     */
    public function validateHandleIsFree(string $attribute): void
    {
        if (!$this->handle) {
            return;
        }

        if (Plugin::getInstance()->sources->handleIsTaken($this->handle, $this->id)) {
            $this->addError($attribute, Craft::t('airwave', 'Another feed is already using the handle “{handle}”.', [
                'handle' => $this->handle,
            ]));
        }
    }

    public function beforeSave(bool $isNew): bool
    {
        if (!$this->handle) {
            $seed = $this->title ?: ($this->getMeta()->title ?: (parse_url((string)$this->url, PHP_URL_HOST) ?: 'feed'));
            $this->handle = Plugin::getInstance()->sources->uniqueHandle($seed, $this->id);
        }

        if (!$this->title) {
            $this->title = $this->getMeta()->title ?: $this->handle;
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new SourceRecord() : SourceRecord::findOne($this->id);

            if (!$record) {
                // A restored element, or one whose row went missing — write it back rather than
                // failing the save and leaving a feed with no URL.
                $record = new SourceRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
            }

            $record->handle = $this->handle;
            $record->url = $this->url;
            $record->refreshInterval = $this->refreshInterval;
            $record->itemLimit = $this->itemLimit;
            $record->filters = $this->getFilters()->toJson();
            $record->displayOptions = $this->getDisplayOptions()->toJson();
            $record->fetchOptions = $this->_fetchOptions ? json_encode($this->_fetchOptions) : null;
            $record->importEnabled = $this->importEnabled;
            $record->importConfig = $this->getImportConfig()->toJson();
            $record->meta = json_encode($this->getMeta()->toArray());
            $record->etag = $this->etag;
            $record->lastModifiedHeader = $this->lastModifiedHeader;
            $record->lastFetchedAt = Db::prepareDateForDb($this->lastFetchedAt);
            $record->lastSuccessAt = Db::prepareDateForDb($this->lastSuccessAt);
            $record->lastStatus = $this->lastStatus;
            $record->lastError = $this->lastError;
            $record->itemCount = $this->itemCount;
            $record->newItemCount = $this->newItemCount;
            $record->save(false);
        }

        parent::afterSave($isNew);
    }

    /**
     * Frees the handle when a feed goes to the trash, so `craft-news` can be created again
     * immediately rather than colliding with something invisible.
     */
    public function afterDelete(): void
    {
        if ($this->handle && $this->id) {
            Craft::$app->getDb()->createCommand()
                ->update('{{%airwave_sources}}', ['handle' => $this->parkedHandle()], ['id' => $this->id])
                ->execute();
        }

        parent::afterDelete();
    }

    public function afterRestore(): void
    {
        if ($this->id) {
            $handle = preg_replace('/--trashed-\d+$/', '', (string)$this->handle) ?: 'feed';
            $sources = Plugin::getInstance()->sources;

            if ($sources->handleIsTaken($handle, $this->id)) {
                $handle = $sources->uniqueHandle($handle, $this->id);
            }

            $this->handle = $handle;
            Craft::$app->getDb()->createCommand()
                ->update('{{%airwave_sources}}', ['handle' => $handle], ['id' => $this->id])
                ->execute();
        }

        parent::afterRestore();
    }

    private function parkedHandle(): string
    {
        return substr((string)$this->handle, 0, 200) . '--trashed-' . $this->id;
    }

    public function attributeLabels(): array
    {
        return array_merge(parent::attributeLabels(), [
            'handle' => Craft::t('airwave', 'Handle'),
            'url' => Craft::t('airwave', 'Feed URL'),
            'refreshInterval' => Craft::t('airwave', 'Refresh every'),
            'itemLimit' => Craft::t('airwave', 'Items to keep'),
        ]);
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'handle', 'url'];
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('airwave', 'All feeds'),
                'defaultSort' => ['title', 'asc'],
            ],
            ['heading' => Craft::t('airwave', 'Health')],
            [
                'key' => 'status:ok',
                'label' => Craft::t('airwave', 'Healthy'),
                'criteria' => ['fetchStatus' => self::STATUS_OK],
            ],
            [
                'key' => 'status:error',
                'label' => Craft::t('airwave', 'Failing'),
                'criteria' => ['fetchStatus' => self::STATUS_ERROR],
            ],
            [
                'key' => 'status:never',
                'label' => Craft::t('airwave', 'Never fetched'),
                'criteria' => ['fetchStatus' => self::STATUS_NEVER],
            ],
            ['heading' => Craft::t('airwave', 'Importing')],
            [
                'key' => 'importing',
                'label' => Craft::t('airwave', 'Imports to entries'),
                'criteria' => ['importEnabled' => true],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'handle' => ['label' => Craft::t('airwave', 'Handle')],
            'url' => ['label' => Craft::t('airwave', 'Feed URL')],
            'health' => ['label' => Craft::t('airwave', 'Health')],
            'itemCount' => ['label' => Craft::t('airwave', 'Items')],
            'lastFetchedAt' => ['label' => Craft::t('airwave', 'Last checked')],
            'embedCode' => ['label' => Craft::t('airwave', 'Embed')],
            'importEnabled' => ['label' => Craft::t('airwave', 'Imports')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['handle', 'health', 'itemCount', 'lastFetchedAt', 'url'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'airwave_sources.handle' => Craft::t('airwave', 'Handle'),
            'airwave_sources.itemCount' => Craft::t('airwave', 'Items'),
            'airwave_sources.lastFetchedAt' => Craft::t('airwave', 'Last checked'),
            'dateCreated' => Craft::t('app', 'Date Created'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'handle' => Html::tag('code', Html::encode((string)$this->handle)),
            'url' => Html::a(Html::encode($this->shortUrl()), (string)$this->url, [
                'target' => '_blank',
                'rel' => 'noopener',
                'class' => 'go',
            ]),
            'health' => $this->healthHtml(),
            'importEnabled' => $this->importEnabled ? Craft::t('app', 'Yes') : '',
            'embedCode' => Html::tag('code', Html::encode($this->getEmbedCode()), ['class' => 'small light']),
            default => parent::attributeHtml($attribute),
        };
    }

    /** The status dot an author actually scans the index for. */
    private function healthHtml(): string
    {
        [$class, $label] = match ($this->lastStatus) {
            self::STATUS_OK => ['live', Craft::t('airwave', 'OK')],
            self::STATUS_ERROR => ['expired', Craft::t('airwave', 'Failing')],
            default => ['pending', Craft::t('airwave', 'Never fetched')],
        };

        return Html::beginTag('span', ['class' => 'status-label'])
            . Html::tag('span', '', ['class' => "status $class"])
            . Html::tag('span', Html::encode($label))
            . Html::endTag('span');
    }

    private function shortUrl(): string
    {
        $url = (string)$this->url;

        return mb_strlen($url) > 60 ? mb_substr($url, 0, 57) . '…' : $url;
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDuplicate(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE);
    }

    public function canCreateDrafts(User $user): bool
    {
        return false;
    }
}
