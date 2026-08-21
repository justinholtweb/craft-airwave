<?php

namespace justinholtweb\airwave\twig;

use craft\helpers\Template;
use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\elements\db\ChannelQuery;
use justinholtweb\airwave\elements\db\SourceQuery;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\models\DisplayOptions;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\Plugin;
use Twig\Markup;
use yii\base\Behavior;

/**
 * `craft.airwave` in templates.
 *
 * Two levels on purpose. {@see self::render()} is the one-liner that puts a feed on a page and is
 * what most sites will ever use; {@see self::items()} hands over the models for a site that wants
 * to write its own markup. Nothing in between — an options bag that grows a flag for every
 * possible difference of opinion about markup is how a display API becomes unusable.
 */
class AirwaveVariable extends Behavior
{
    /**
     * A feed, rendered.
     *
     *     {{ craft.airwave.render('craft-news') }}
     *     {{ craft.airwave.render('craft-news', { limit: 3, layout: 'cards' }) }}
     *
     * @param array<string, mixed> $options
     */
    public function render(string $handle, array $options = []): Markup
    {
        $source = Plugin::getInstance()->sources->getSourceByHandle($handle);

        if (!$source) {
            return Template::raw('');
        }

        return $source->render($options);
    }

    /**
     * Several feeds as one stream, newest first. Pro.
     *
     *     {{ craft.airwave.renderMerged(['craft-news', 'php-weekly'], { limit: 10 }) }}
     *
     * @param string[] $handles Empty means every enabled feed.
     * @param array<string, mixed> $options
     */
    public function renderMerged(array $handles = [], array $options = []): Markup
    {
        return Plugin::getInstance()->renderer->renderMerged($handles, $options);
    }

    /**
     * The items themselves, for a template that wants to do its own thing.
     *
     *     {% for item in craft.airwave.items({ source: 'craft-news', limit: 5 }) %}
     *
     * @param array<string, mixed> $criteria
     * @return Item[]
     */
    public function items(array $criteria = []): array
    {
        $plugin = Plugin::getInstance();

        // Reading is what keeps a feed current, whichever way it is read.
        foreach ((array)($criteria['source'] ?? $criteria['sourceHandle'] ?? []) as $handle) {
            $source = $plugin->sources->getSourceByHandle((string)$handle);

            if ($source) {
                $plugin->sources->refreshIfDue($source);
            }
        }

        return $plugin->items->find($criteria);
    }

    /** One feed, by handle. */
    public function feed(string $handle): ?Source
    {
        return Plugin::getInstance()->sources->getSourceByHandle($handle);
    }

    /**
     * An element query for feeds — `craft.airwave.feeds.fetchStatus('error').all()`.
     */
    public function getFeeds(): SourceQuery
    {
        /** @var SourceQuery */
        return Source::find();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function feeds(array $criteria = []): SourceQuery
    {
        /** @var SourceQuery $query */
        $query = Source::find();

        return \Craft::configure($query, $criteria);
    }

    /** One published channel, by handle. */
    public function channel(string $handle): ?Channel
    {
        return Plugin::getInstance()->channels->getChannelByHandle($handle);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function channels(array $criteria = []): ChannelQuery
    {
        /** @var ChannelQuery $query */
        $query = Channel::find();

        return \Craft::configure($query, $criteria);
    }

    /**
     * The URL of one of your published channels.
     *
     *     <a href="{{ craft.airwave.channelUrl('blog') }}">Subscribe</a>
     */
    public function channelUrl(string $handle): ?string
    {
        return $this->channel($handle)?->getUrl();
    }

    /**
     * `<link rel="alternate">` tags for every channel on the current site, for a `<head>`.
     *
     *     {{ craft.airwave.autoDiscovery() }}
     */
    public function autoDiscovery(?string $handle = null): Markup
    {
        $channels = $handle
            ? array_filter([$this->channel($handle)])
            : Plugin::getInstance()->channels->getAllChannels();

        $siteId = \Craft::$app->getSites()->getCurrentSite()->id;
        $tags = [];

        foreach ($channels as $channel) {
            if ($channel->targetSiteId === $siteId) {
                $tags[] = $channel->getAutoDiscoveryTag();
            }
        }

        return Template::raw(implode("\n", $tags));
    }

    /** Default display options, for a template building its own overrides. */
    public function displayOptions(array $overrides = []): DisplayOptions
    {
        return Plugin::getInstance()->getSettings()->getDefaultDisplayOptions()->merge($overrides);
    }
}
