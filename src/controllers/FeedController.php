<?php

namespace justinholtweb\airwave\controllers;

use craft\web\Controller;
use justinholtweb\airwave\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serving a published channel.
 *
 * The only public endpoint Airwave has. Routed from {@see \justinholtweb\airwave\services\Channels}
 * rather than by a URL rule with the handle in it, so a channel's address is whatever an author
 * typed — `blog/feed`, `feed.xml`, `news/rss` — rather than something with the plugin's name in it.
 */
class FeedController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    /**
     * @throws NotFoundHttpException
     */
    public function actionServe(string $handle): Response
    {
        $plugin = Plugin::getInstance();
        $channel = $plugin->channels->getChannelByHandle($handle);

        // A disabled channel is a 404 rather than an empty feed: readers unsubscribe from feeds
        // that go empty, and come back to ones that 404 temporarily.
        if (!$channel || !$channel->enabled) {
            throw new NotFoundHttpException('Feed not found');
        }

        $document = $plugin->broadcaster->render($channel);
        $duration = $channel->getCacheDuration();

        $this->response->getHeaders()
            ->set('Content-Type', $channel->getMimeType() . '; charset=UTF-8')
            // A feed reader that respects this is a feed reader that is not hitting your server
            // every minute, and there are a great many of them.
            ->set('Cache-Control', $duration > 0 ? "public, max-age=$duration" : 'no-cache')
            // Feeds get read from JavaScript on other origins more often than you would think, and
            // there is nothing here that is not already public.
            ->set('Access-Control-Allow-Origin', '*')
            ->set('X-Content-Type-Options', 'nosniff');

        // `asRaw()` rather than setting the body directly: Craft's Response has no `setRawContent`,
        // and leaving the format alone would let Craft try to render the string as a template.
        return $this->asRaw($document);
    }
}
