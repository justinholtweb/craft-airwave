<?php

namespace justinholtweb\airwave\services;

use Craft;
use craft\web\View;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\models\DisplayOptions;
use justinholtweb\airwave\models\Edition;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\Plugin;
use justinholtweb\airwave\web\assets\feed\FeedAsset;
use Throwable;
use Twig\Markup;
use yii\base\Component;

/**
 * Items on a page.
 *
 * The drop-in path — `{{ craft.airwave.render('craft-news') }}` or a reference tag in rich text —
 * is the whole reason a feed plugin gets adopted instead of admired. A template that has to be
 * written before anything appears is a template that never gets written.
 *
 * Overriding is by **template**, not by options: a site that outgrows the built-in markup puts its
 * own at `templates/_airwave/items.twig` and Airwave finds it. That keeps the option set small
 * rather than growing a flag for every possible difference of opinion about markup.
 */
class Renderer extends Component
{
    /** Where a site puts its own version of the item markup. */
    public const SITE_TEMPLATE = '_airwave/items';

    /**
     * @param array<string, mixed> $overrides
     */
    public function render(Source $source, array $overrides = []): Markup
    {
        $plugin = Plugin::getInstance();

        // Reading a feed is what keeps it current. A feed nobody renders never needs refreshing,
        // and one that is rendered on every page view refreshes exactly as often as its interval
        // says — see Sources::refreshIfDue() for why this is not a blocking fetch.
        $plugin->sources->refreshIfDue($source);

        $options = $source->getDisplayOptions()->merge($overrides);
        $items = $plugin->items->find([
            'sourceId' => $source->id,
            'limit' => $options->limit,
            'offset' => $options->offset,
            'sort' => $options->sort,
            'withContent' => $options->content === DisplayOptions::CONTENT_FULL,
            'includeDisabled' => true,
        ]);

        return $this->renderItems($items, $options, $source);
    }

    /**
     * Several feeds as one stream. Pro.
     *
     * @param string[] $handles
     * @param array<string, mixed> $overrides
     */
    public function renderMerged(array $handles, array $overrides = []): Markup
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsMerging($plugin->isPro())) {
            // Degrade to the first feed rather than to nothing: a lapsed licence should thin the
            // page out, not empty it.
            $handles = array_slice($handles, 0, 1);
        }

        $sources = $handles ? $plugin->sources->getSourcesByHandles($handles) : $plugin->sources->getAllSources();

        foreach ($sources as $source) {
            $plugin->sources->refreshIfDue($source);
        }

        $options = $plugin->getSettings()->getDefaultDisplayOptions()->merge($overrides);
        $options->showSource = $overrides['showSource'] ?? true;

        $items = $plugin->items->find([
            'sourceId' => array_map(fn(Source $source) => $source->id, $sources),
            'limit' => $options->limit,
            'offset' => $options->offset,
            'sort' => $options->sort,
            'withContent' => $options->content === DisplayOptions::CONTENT_FULL,
        ]);

        return $this->renderItems($items, $options);
    }

    /**
     * @param Item[] $items
     */
    public function renderItems(array $items, DisplayOptions $options, ?Source $source = null): Markup
    {
        $plugin = Plugin::getInstance();
        $options = Edition::applyToDisplay($options, $plugin->isPro());
        $view = Craft::$app->getView();

        if ($plugin->getSettings()->registerFrontEndStyles && !Craft::$app->getRequest()->getIsConsoleRequest()) {
            try {
                $view->registerAssetBundle(FeedAsset::class);
            } catch (Throwable $e) {
                Craft::warning("Airwave could not register its stylesheet: {$e->getMessage()}", 'airwave');
            }
        }

        $variables = [
            'items' => $items,
            'options' => $options,
            'source' => $source,
            'meta' => $source?->getMeta(),
        ];

        [$template, $mode] = $this->resolveTemplate($options);

        try {
            $html = $view->renderTemplate($template, $variables, $mode);
        } catch (Throwable $e) {
            Craft::error("Airwave could not render items: {$e->getMessage()}", 'airwave');

            // A broken feed template must not take the page down with it. In dev the exception is
            // more useful than a blank space.
            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $e;
            }

            $html = '';
        }

        return new Markup($html, Craft::$app->charset);
    }

    /**
     * @return array{0: string, 1: string} The template, and the mode to render it in.
     */
    private function resolveTemplate(DisplayOptions $options): array
    {
        $view = Craft::$app->getView();

        if ($options->template) {
            return [$options->template, View::TEMPLATE_MODE_SITE];
        }

        // A site template wins over the plugin's, silently — which is the point: dropping a file
        // in should be all it takes.
        if ($view->doesTemplateExist(self::SITE_TEMPLATE, View::TEMPLATE_MODE_SITE)) {
            return [self::SITE_TEMPLATE, View::TEMPLATE_MODE_SITE];
        }

        return ['airwave/_render/items', View::TEMPLATE_MODE_CP];
    }
}
