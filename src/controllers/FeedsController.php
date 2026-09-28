<?php

namespace justinholtweb\airwave\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\exceptions\FeedException;
use justinholtweb\airwave\helpers\Trust;
use justinholtweb\airwave\models\DisplayOptions;
use justinholtweb\airwave\models\Edition;
use justinholtweb\airwave\models\FilterRules;
use justinholtweb\airwave\models\ImportConfig;
use justinholtweb\airwave\Plugin;
use justinholtweb\airwave\queue\jobs\ImportSourceJob;
use justinholtweb\airwave\services\Fetcher;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The feed screens.
 *
 * A hand-written edit screen rather than Craft's element editor: a feed is configuration with a
 * live status attached — the sidebar wants to show health, the last error and a refresh button
 * that does something *now* — and there is nothing for the autosave machinery to usefully do with
 * a URL that has to be fetched before anybody knows whether it works.
 */
class FeedsController extends Controller
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

        return $this->renderTemplate('airwave/feeds/_index', [
            'canCreate' => $plugin->sources->canCreate() && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
            'remaining' => $plugin->sources->remaining(),
            'maxSources' => Edition::maxSources($plugin->isPro()),
            'stats' => $plugin->sources->getStats(),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionEdit(?int $sourceId = null, ?Source $source = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($source === null) {
            if ($sourceId !== null) {
                $source = $plugin->sources->getSourceById($sourceId);

                if (!$source) {
                    throw new NotFoundHttpException('Feed not found');
                }
            } else {
                $this->requirePermission(Plugin::PERMISSION_MANAGE);

                if (!$plugin->sources->canCreate()) {
                    throw new ForbiddenHttpException(Craft::t('airwave', 'Airwave Lite holds up to {max} feeds.', [
                        'max' => Edition::LITE_MAX_SOURCES,
                    ]));
                }

                $source = new Source();
                $source->enabled = true;
            }
        }

        $isNew = !$source->id;

        return $this->renderTemplate('airwave/feeds/_edit', [
            'source' => $source,
            'isNew' => $isNew,
            'plugin' => $plugin,
            'isPro' => $plugin->isPro(),
            'title' => $isNew ? Craft::t('airwave', 'New feed') : $source->getUiLabel(),
            'sections' => $this->sectionOptions(),
            'volumes' => $this->volumeOptions(),
            'intervals' => $this->intervalOptions(),
            'canImport' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_IMPORT),
            'canRefresh' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_REFRESH),
            'categoryCounts' => $source->id ? $plugin->items->getCategoryCounts($source->id, 25) : [],
            'continueEditingUrl' => 'airwave/feeds/{id}',
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $request = $this->request;
        $sourceId = $request->getBodyParam('sourceId');

        if ($sourceId) {
            $source = $plugin->sources->getSourceById((int)$sourceId);

            if (!$source) {
                throw new NotFoundHttpException('Feed not found');
            }
        } else {
            $source = new Source();
        }

        $source->title = $request->getBodyParam('title') ?: null;
        $source->url = trim((string)$request->getBodyParam('url'));
        $source->enabled = (bool)$request->getBodyParam('enabled', true);
        $source->itemLimit = $this->intOrNull($request->getBodyParam('itemLimit'));

        $handle = trim((string)$request->getBodyParam('handle'));

        if ($handle !== '') {
            $source->handle = $handle;
        }

        if (Edition::allowsPerSourceIntervals($plugin->isPro())) {
            $source->refreshInterval = $this->intOrNull($request->getBodyParam('refreshInterval'));
        }

        $source->setDisplayOptions(DisplayOptions::fromArray((array)$request->getBodyParam('displayOptions', [])));

        if (Edition::allowsFilters($plugin->isPro())) {
            $source->setFilters(FilterRules::fromArray((array)$request->getBodyParam('filters', [])));
        }

        if (Edition::allowsImporting($plugin->isPro()) && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_IMPORT)) {
            $source->importEnabled = (bool)$request->getBodyParam('importEnabled');
            $importConfig = ImportConfig::fromArray((array)$request->getBodyParam('importConfig', []));

            if (!Trust::canAuthorCode()) {
                $importConfig->keepTemplatesFrom($source->getImportConfig());
            }

            $source->setImportConfig($importConfig);
        }

        $headers = $this->parseHeaders((string)$request->getBodyParam('headers', ''));
        $headerError = $this->checkHeaders($headers, (array)($source->getFetchOptions()['headers'] ?? []));
        $source->setFetchOptions($headers ? ['headers' => $headers] : null);

        if ($headerError !== null) {
            // Added after the other assignments so the author sees everything they typed, and
            // checked before saving because saving re-validates and would clear it.
            $source->addError('headers', $headerError);
        }

        if ($source->hasErrors() || !$plugin->sources->saveSource($source)) {
            $this->setFailFlash(Craft::t('airwave', 'Couldn’t save feed.'));

            Craft::$app->getUrlManager()->setRouteParams(['source' => $source]);

            return null;
        }

        // A brand new feed is fetched immediately: an author who has just pasted a URL wants to
        // know whether it works, and "saved" on its own does not answer that.
        if (!$source->getHasBeenFetched() && $source->enabled) {
            $result = $plugin->sources->refresh($source);

            if (!$result->ok) {
                $this->setFailFlash(Craft::t('airwave', 'Feed saved, but it could not be read: {error}', ['error' => $result->error]));

                return $this->redirectToPostedUrl($source, UrlHelper::cpUrl("airwave/feeds/$source->id"));
            }
        }

        $this->setSuccessFlash(Craft::t('airwave', 'Feed saved.'));

        return $this->redirectToPostedUrl($source, UrlHelper::cpUrl("airwave/feeds/$source->id"));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE);

        $plugin = Plugin::getInstance();
        $source = $plugin->sources->getSourceById((int)$this->request->getRequiredBodyParam('sourceId'));

        if (!$source) {
            throw new NotFoundHttpException('Feed not found');
        }

        $plugin->sources->deleteSource($source);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('airwave', 'Feed deleted.'));
        }

        $this->setSuccessFlash(Craft::t('airwave', 'Feed deleted.'));

        return $this->redirect('airwave/feeds');
    }

    /**
     * Fetches a feed now, whatever its interval says.
     */
    public function actionRefresh(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REFRESH);

        $plugin = Plugin::getInstance();
        $sourceId = $this->request->getBodyParam('sourceId');

        if (!$sourceId) {
            $results = $plugin->sources->refreshAll(true);
            $failed = count(array_filter($results, fn($result) => !$result->ok));
            $message = $failed
                ? Craft::t('airwave', '{count} of {total} feeds could not be read.', ['count' => $failed, 'total' => count($results)])
                : Craft::t('airwave', 'All feeds refreshed.');

            return $this->request->getAcceptsJson()
                ? $this->asJson(['success' => $failed === 0, 'message' => $message])
                : $this->redirectWithFlash($message, $failed === 0);
        }

        $source = $plugin->sources->getSourceById((int)$sourceId);

        if (!$source) {
            throw new NotFoundHttpException('Feed not found');
        }

        // Forced: somebody pressed a button, so send a real request rather than a conditional one
        // that may well answer 304 and look like nothing happened.
        $result = $plugin->sources->refresh($source, true);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $result->ok,
                'message' => $result->getSummary(),
                'itemCount' => $source->itemCount,
                'status' => $source->lastStatus,
            ]);
        }

        return $this->redirectWithFlash($result->getSummary(), $result->ok);
    }

    /**
     * Finds the feed URL hiding in a site's HTML.
     *
     * Nobody knows their own feed URL. They know the site address, and every publishing platform
     * advertises the feed in the page head — so an author pastes `example.com` and gets a list to
     * pick from.
     */
    public function actionDiscover(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $url = trim((string)$this->request->getRequiredBodyParam('url'));

        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $plugin = Plugin::getInstance();

        try {
            $fetched = $plugin->fetcher->fetchUrl($url);
        } catch (FeedException $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }

        // It might already be a feed, in which case there is nothing to discover.
        try {
            $parsed = $plugin->parser->parse($fetched->body, $fetched->finalUrl);

            return $this->asJson([
                'success' => true,
                'isFeed' => true,
                'feeds' => [[
                    'url' => $fetched->finalUrl ?: $url,
                    'title' => $parsed->meta->title,
                    'type' => $parsed->meta->format,
                    'items' => $parsed->count(),
                ]],
            ]);
        } catch (FeedException) {
            // Not a feed — so treat it as a page that might advertise one.
        }

        $found = $plugin->parser->discover($fetched->body, $fetched->finalUrl ?: $url);

        return $this->asJson([
            'success' => (bool)$found,
            'isFeed' => false,
            'feeds' => $found,
            'message' => $found ? null : Craft::t('airwave', 'No feeds advertised on that page.'),
        ]);
    }

    /**
     * Re-runs the current filters over items that are already stored.
     */
    public function actionReapplyFilters(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            throw new ForbiddenHttpException(Craft::t('airwave', 'Filters are a Pro feature.'));
        }

        $source = $plugin->sources->getSourceById((int)$this->request->getRequiredBodyParam('sourceId'));

        if (!$source) {
            throw new NotFoundHttpException('Feed not found');
        }

        $result = $plugin->filters->reapply($source);

        $message = Craft::t('airwave', '{removed} removed, {kept} kept{protected}.', [
            'removed' => $result['removed'],
            'kept' => $result['kept'],
            'protected' => $result['protected']
                ? Craft::t('airwave', ', {count} left alone because they have been imported', ['count' => $result['protected']])
                : '',
        ]);

        return $this->request->getAcceptsJson()
            ? $this->asJson(['success' => true, 'message' => $message])
            : $this->redirectWithFlash($message, true);
    }

    /**
     * Imports a feed's items as entries, or shows what that would do.
     */
    public function actionImport(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_IMPORT);

        $plugin = Plugin::getInstance();

        if (!Edition::allowsImporting($plugin->isPro())) {
            throw new ForbiddenHttpException(Craft::t('airwave', 'Importing is a Pro feature.'));
        }

        $source = $plugin->sources->getSourceById((int)$this->request->getRequiredBodyParam('sourceId'));

        if (!$source) {
            throw new NotFoundHttpException('Feed not found');
        }

        $dryRun = (bool)$this->request->getBodyParam('dryRun');

        if ($dryRun) {
            $result = $plugin->importer->import($source, null, true);

            return $this->asJson([
                'success' => true,
                'message' => $result->getSummary(),
                'previews' => $result->previews,
                'errors' => $result->errors,
            ]);
        }

        // Queued rather than run inline: a backlog of a few hundred items with images to download
        // is not something to do inside a CP request.
        Craft::$app->getQueue()->push(new ImportSourceJob(['sourceId' => $source->id]));

        $message = Craft::t('airwave', 'Importing in the background.');

        return $this->request->getAcceptsJson()
            ? $this->asJson(['success' => true, 'message' => $message])
            : $this->redirectWithFlash($message, true);
    }

    // Helpers
    // -------------------------------------------------------------------------

    private function redirectWithFlash(string $message, bool $success): Response
    {
        $success ? $this->setSuccessFlash($message) : $this->setFailFlash($message);

        return $this->redirect($this->request->getReferrer() ?: 'airwave/feeds');
    }

    private function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '' || (int)$value <= 0) ? null : (int)$value;
    }

    /**
     * Request headers come out of the CP as `Name: value` lines, which is how a token gets in
     * without Airwave growing a password field it would then have to store.
     *
     * @return array<string, string>
     */
    /**
     * Why these headers cannot be saved by the current user, or null if they can.
     *
     * A header value can name an environment variable, and `Fetcher` resolves it and sends the
     * result to whatever server the feed URL names. Pointing a feed at your own server with
     * `X-Anything: $CRAFT_SECURITY_KEY` would read `.env`, so only an admin may add or change a
     * header that references the environment. Headers already saved that way are kept, so a
     * non-admin can still edit the rest of the feed.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $stored
     */
    private function checkHeaders(array $headers, array $stored): ?string
    {
        foreach ($headers as $name => $value) {
            if (Fetcher::isForbiddenHeader($name)) {
                return Craft::t('airwave', 'The “{name}” header is set by Airwave and can’t be overridden.', ['name' => $name]);
            }

            if (!Trust::canAuthorCode() && Trust::referencesEnvironment($value) && ($stored[$name] ?? null) !== $value) {
                return Craft::t('airwave', 'Only admins can use environment variables in request headers.');
            }
        }

        return null;
    }

    private function parseHeaders(string $raw): array
    {
        $headers = [];

        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if ($name !== '' && $value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function sectionOptions(): array
    {
        $options = [['label' => Craft::t('airwave', 'Choose a section'), 'value' => '']];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($section->type === \craft\models\Section::TYPE_SINGLE) {
                continue;
            }

            $options[] = ['label' => $section->name, 'value' => $section->uid];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function volumeOptions(): array
    {
        $options = [['label' => Craft::t('airwave', 'Choose a volume'), 'value' => '']];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[] = ['label' => $volume->name, 'value' => $volume->uid];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function intervalOptions(): array
    {
        return [
            ['label' => Craft::t('airwave', 'Use the site default'), 'value' => ''],
            ['label' => Craft::t('airwave', 'Every 15 minutes'), 'value' => '900'],
            ['label' => Craft::t('airwave', 'Every 30 minutes'), 'value' => '1800'],
            ['label' => Craft::t('airwave', 'Hourly'), 'value' => '3600'],
            ['label' => Craft::t('airwave', 'Every 6 hours'), 'value' => '21600'],
            ['label' => Craft::t('airwave', 'Daily'), 'value' => '86400'],
            ['label' => Craft::t('airwave', 'Weekly'), 'value' => '604800'],
        ];
    }
}
