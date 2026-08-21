<?php

namespace justinholtweb\airwave\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\models\ChannelMapping;
use justinholtweb\airwave\models\Edition;
use justinholtweb\airwave\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The channel screens.
 */
class ChannelsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('airwave/channels/_index', [
            'canCreate' => $plugin->channels->canCreate() && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_CHANNELS),
            'maxChannels' => Edition::maxChannels($plugin->isPro()),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionEdit(?int $channelId = null, ?Channel $channel = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($channel === null) {
            if ($channelId !== null) {
                $channel = $plugin->channels->getChannelById($channelId);

                if (!$channel) {
                    throw new NotFoundHttpException('Channel not found');
                }
            } else {
                $this->requirePermission(Plugin::PERMISSION_MANAGE_CHANNELS);

                if (!$plugin->channels->canCreate()) {
                    throw new ForbiddenHttpException(Craft::t('airwave', 'Airwave Lite publishes {max} channel.', [
                        'max' => Edition::LITE_MAX_CHANNELS,
                    ]));
                }

                $channel = new Channel();
                $channel->enabled = true;
                $channel->targetSiteId = Craft::$app->getSites()->getCurrentSite()->id;
            }
        }

        $isNew = !$channel->id;

        return $this->renderTemplate('airwave/channels/_edit', [
            'channel' => $channel,
            'isNew' => $isNew,
            'isPro' => $plugin->isPro(),
            'title' => $isNew ? Craft::t('airwave', 'New channel') : $channel->getUiLabel(),
            'formats' => $this->formatOptions($plugin->isPro()),
            'siteOptions' => $this->siteOptions(),
            'sectionOptions' => $this->sectionOptions(),
            'feedOptions' => $this->feedOptions(),
            'continueEditingUrl' => 'airwave/channels/{id}',
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CHANNELS);

        $plugin = Plugin::getInstance();
        $request = $this->request;
        $channelId = $request->getBodyParam('channelId');

        if ($channelId) {
            $channel = $plugin->channels->getChannelById((int)$channelId);

            if (!$channel) {
                throw new NotFoundHttpException('Channel not found');
            }
        } else {
            $channel = new Channel();
        }

        $channel->title = $request->getBodyParam('title') ?: null;
        $channel->uri = trim((string)$request->getBodyParam('uri'));
        $channel->enabled = (bool)$request->getBodyParam('enabled', true);
        $channel->targetSiteId = (int)$request->getBodyParam('targetSiteId') ?: Craft::$app->getSites()->getPrimarySite()->id;
        $channel->feedSource = (string)$request->getBodyParam('feedSource', Channel::SOURCE_ELEMENTS);
        $channel->itemLimit = max(1, (int)$request->getBodyParam('itemLimit', 20));
        $channel->feedTitle = $request->getBodyParam('feedTitle') ?: null;
        $channel->feedDescription = $request->getBodyParam('feedDescription') ?: null;
        $channel->feedLink = $request->getBodyParam('feedLink') ?: null;
        $channel->language = $request->getBodyParam('language') ?: null;
        $channel->copyright = $request->getBodyParam('copyright') ?: null;
        $channel->imageUrl = $request->getBodyParam('imageUrl') ?: null;

        $cacheDuration = $request->getBodyParam('cacheDuration');
        $channel->cacheDuration = ($cacheDuration === '' || $cacheDuration === null) ? null : max(0, (int)$cacheDuration);

        $handle = trim((string)$request->getBodyParam('handle'));

        if ($handle !== '') {
            $channel->handle = $handle;
        }

        $format = (string)$request->getBodyParam('format', Channel::FORMAT_RSS);

        // Refuse rather than downgrade here: an author choosing Atom on Lite should be told, not
        // quietly given RSS. The *serving* side downgrades, which is a different situation — there
        // the licence lapsed after the choice was made.
        if (!Edition::allowsFormat($format, $plugin->isPro())) {
            $channel->addError('format', Craft::t('airwave', 'Atom and JSON Feed are Pro features.'));
        } else {
            $channel->format = $format;
        }

        if ($channel->feedSource === Channel::SOURCE_FEEDS) {
            $channel->setSourceIds((array)$request->getBodyParam('sourceIds', []));
            $channel->elementType = null;
        } else {
            $channel->elementType = (string)$request->getBodyParam('elementType', \craft\elements\Entry::class);
            $channel->setCriteria($this->parseCriteria($request->getBodyParam('criteria', [])));
        }

        $mapping = (array)$request->getBodyParam('mapping', []);

        if (!Edition::allowsChannelTemplates($plugin->isPro())) {
            // Keep the knobs Lite does have; drop the templates rather than storing something the
            // renderer will ignore and an author will think is broken.
            $mapping = array_intersect_key($mapping, array_flip(['dateAttribute', 'fullContent', 'summaryLength']));
        }

        $channel->setMapping(ChannelMapping::fromArray($mapping));

        if ($channel->hasErrors() || !$plugin->channels->saveChannel($channel)) {
            $this->setFailFlash(Craft::t('airwave', 'Couldn’t save channel.'));

            Craft::$app->getUrlManager()->setRouteParams(['channel' => $channel]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('airwave', 'Channel saved.'));

        return $this->redirectToPostedUrl($channel, UrlHelper::cpUrl("airwave/channels/$channel->id"));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CHANNELS);

        $plugin = Plugin::getInstance();
        $channel = $plugin->channels->getChannelById((int)$this->request->getRequiredBodyParam('channelId'));

        if (!$channel) {
            throw new NotFoundHttpException('Channel not found');
        }

        $plugin->channels->deleteChannel($channel);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('airwave', 'Channel deleted.'));
        }

        $this->setSuccessFlash(Craft::t('airwave', 'Channel deleted.'));

        return $this->redirect('airwave/channels');
    }

    /**
     * The document this channel would publish right now, as text.
     *
     * Built rather than rendered from cache, because the point of previewing is to see the effect
     * of what you just changed.
     */
    public function actionPreview(int $channelId): Response
    {
        $plugin = Plugin::getInstance();
        $channel = $plugin->channels->getChannelById($channelId);

        if (!$channel) {
            throw new NotFoundHttpException('Channel not found');
        }

        $document = $plugin->broadcaster->build($channel);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'document' => $document,
                'items' => count($channel->getItems()),
            ]);
        }

        // Shown in the browser rather than downloaded: the point of a preview is to read it.
        $this->response->getHeaders()
            ->set('Content-Type', $channel->getMimeType() . '; charset=UTF-8')
            ->set('X-Content-Type-Options', 'nosniff');

        return $this->asRaw($document);
    }

    // Options
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array{label: string, value: string, disabled?: bool}>
     */
    private function formatOptions(bool $isPro): array
    {
        return [
            ['label' => 'RSS 2.0', 'value' => Channel::FORMAT_RSS],
            ['label' => $isPro ? 'Atom 1.0' : Craft::t('airwave', 'Atom 1.0 (Pro)'), 'value' => Channel::FORMAT_ATOM, 'disabled' => !$isPro],
            ['label' => $isPro ? 'JSON Feed 1.1' : Craft::t('airwave', 'JSON Feed 1.1 (Pro)'), 'value' => Channel::FORMAT_JSON, 'disabled' => !$isPro],
        ];
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function siteOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $options[] = ['label' => $site->name, 'value' => (string)$site->id];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function sectionOptions(): array
    {
        $options = [['label' => Craft::t('airwave', 'All sections'), 'value' => '']];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $options[] = ['label' => $section->name, 'value' => $section->handle];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function feedOptions(): array
    {
        $options = [];

        foreach (Plugin::getInstance()->sources->getAllSources(true) as $source) {
            $options[] = ['label' => $source->getUiLabel(), 'value' => (string)$source->id];
        }

        return $options;
    }

    /**
     * The CP offers the two criteria people actually use as fields, and everything else as a
     * key/value table — which keeps the common case a dropdown without making the uncommon one
     * impossible.
     *
     * @return array<string, mixed>
     */
    private function parseCriteria(mixed $posted): array
    {
        $criteria = [];

        foreach ((array)$posted as $key => $value) {
            if (is_array($value)) {
                $name = trim((string)($value['name'] ?? ''));
                $setting = $value['value'] ?? '';

                if ($name !== '' && $setting !== '') {
                    $criteria[$name] = $setting;
                }
            } elseif (is_string($key) && $value !== '' && $value !== null) {
                $criteria[$key] = $value;
            }
        }

        return $criteria;
    }
}
