<?php

namespace justinholtweb\airwave;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\fields\FeedField;
use justinholtweb\airwave\models\Settings;
use justinholtweb\airwave\services\Broadcaster;
use justinholtweb\airwave\services\Channels;
use justinholtweb\airwave\services\Fetcher;
use justinholtweb\airwave\services\Filters;
use justinholtweb\airwave\services\Importer;
use justinholtweb\airwave\services\Items;
use justinholtweb\airwave\services\Parser;
use justinholtweb\airwave\services\Renderer;
use justinholtweb\airwave\services\Sources;
use justinholtweb\airwave\twig\AirwaveVariable;
use yii\base\Event;

/**
 * Airwave — read the web's feeds, publish your own.
 *
 * Two halves that share one item shape ({@see models\Item}) and therefore one of everything else:
 *
 * - **In.** {@see Source} elements are feeds you subscribe to. {@see Fetcher} gets the bytes,
 *   {@see Parser} normalises whichever of the four dialects it turned out to be, {@see Filters}
 *   decides what is worth keeping, {@see Items} stores it, {@see Importer} can turn it into
 *   entries, and {@see Renderer} puts it on a page.
 * - **Out.** {@see Channel} elements are feeds you publish. {@see Channels} routes them and works
 *   out what goes in them — your own content, or the feeds you have aggregated — and
 *   {@see Broadcaster} serialises that to RSS, Atom or JSON Feed.
 *
 * The join is the interesting bit: because a channel can broadcast aggregated items, a curated
 * "best of" feed is a dropdown rather than a template.
 *
 * @property-read Sources $sources
 * @property-read Items $items
 * @property-read Fetcher $fetcher
 * @property-read Parser $parser
 * @property-read Filters $filters
 * @property-read Importer $importer
 * @property-read Channels $channels
 * @property-read Broadcaster $broadcaster
 * @property-read Renderer $renderer
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'airwave:viewFeeds';
    public const PERMISSION_MANAGE = 'airwave:manageFeeds';
    public const PERMISSION_DELETE = 'airwave:deleteFeeds';
    public const PERMISSION_REFRESH = 'airwave:refreshFeeds';
    public const PERMISSION_IMPORT = 'airwave:importFeeds';
    public const PERMISSION_MANAGE_CHANNELS = 'airwave:manageChannels';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'airwave';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'sources' => Sources::class,
                'items' => Items::class,
                'fetcher' => Fetcher::class,
                'parser' => Parser::class,
                'filters' => Filters::class,
                'importer' => Importer::class,
                'channels' => Channels::class,
                'broadcaster' => Broadcaster::class,
                'renderer' => Renderer::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerFieldTypes();
        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerTwig();

        // Channel URIs are site URL rules, so this has to run on every front-end request. It reads
        // a cached map rather than the database — see Channels::getRouteMap().
        $this->channels->register();
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('airwave', 'Airwave');

        $item['subnav'] = [
            'feeds' => [
                'label' => Craft::t('airwave', 'Feeds'),
                'url' => 'airwave/feeds',
            ],
            'items' => [
                'label' => Craft::t('airwave', 'Items'),
                'url' => 'airwave/items',
            ],
            'channels' => [
                'label' => Craft::t('airwave', 'Channels'),
                'url' => 'airwave/channels',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('airwave', 'Settings'),
                'url' => 'settings/plugins/airwave',
            ];
        }

        // A feed that has been failing for a day is the single thing worth interrupting somebody
        // about, and the nav badge is the only place they will see it without going looking.
        try {
            $failing = count($this->sources->getFailingSources());

            if ($failing > 0) {
                $item['badgeCount'] = $failing;
            }
        } catch (\Throwable) {
            // Before install, or mid-migration. A nav item is not worth an exception.
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('airwave/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'purifierConfigs' => $this->purifierConfigOptions(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function purifierConfigOptions(): array
    {
        $options = ['' => Craft::t('airwave', 'Airwave’s default')];
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'htmlpurifier';

        if (is_dir($path)) {
            foreach (\craft\helpers\FileHelper::findFiles($path, ['only' => ['*.json'], 'recursive' => false]) as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $options[$name] = $name;
            }
        }

        return $options;
    }

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Source::class;
            $event->types[] = Channel::class;
        });
    }

    private function registerFieldTypes(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = FeedField::class;
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'airwave' => 'airwave/feeds/index',
                'airwave/feeds' => 'airwave/feeds/index',
                'airwave/feeds/new' => 'airwave/feeds/edit',
                'airwave/feeds/<sourceId:\d+>' => 'airwave/feeds/edit',
                'airwave/items' => 'airwave/items/index',
                'airwave/items/<itemId:\d+>' => 'airwave/items/detail',
                'airwave/channels' => 'airwave/channels/index',
                'airwave/channels/new' => 'airwave/channels/edit',
                'airwave/channels/<channelId:\d+>' => 'airwave/channels/edit',
            ];
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('airwave', 'Airwave'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('airwave', 'View feeds and items'),
                        'nested' => [
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('airwave', 'Add and edit feeds'),
                            ],
                            self::PERMISSION_DELETE => [
                                'label' => Craft::t('airwave', 'Delete feeds and items'),
                            ],
                            self::PERMISSION_REFRESH => [
                                'label' => Craft::t('airwave', 'Refresh feeds'),
                            ],
                            // Separate from “add and edit feeds” on purpose: importing writes
                            // entries into a live section, which is a bigger permission than
                            // subscribing to a URL.
                            self::PERMISSION_IMPORT => [
                                'label' => Craft::t('airwave', 'Import items as entries'),
                            ],
                            self::PERMISSION_MANAGE_CHANNELS => [
                                'label' => Craft::t('airwave', 'Publish and edit channels'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('airwave', AirwaveVariable::class);
        });
    }
}
