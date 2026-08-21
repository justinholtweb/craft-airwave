<?php

namespace justinholtweb\airwave\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\airwave\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Browsing what has been collected.
 *
 * The screen that answers "is this feed actually giving me anything useful" — which is the
 * question an editor has right after adding one, and the thing that decides whether the filters
 * need tightening. Paged with plain limit/offset rather than an element index, because items are
 * rows rather than elements.
 */
class ItemsController extends Controller
{
    private const PER_PAGE = 50;

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
        $request = $this->request;

        $sourceHandle = $request->getParam('source');
        $search = trim((string)$request->getParam('search', ''));
        $sort = (string)$request->getParam('sort', 'newest');
        $imported = $request->getParam('imported');
        $page = max(1, (int)$request->getParam('page', 1));

        $criteria = [
            'limit' => self::PER_PAGE,
            'offset' => ($page - 1) * self::PER_PAGE,
            'sort' => $sort,
            'includeDisabled' => true,
            'withContent' => false,
        ];

        if ($sourceHandle) {
            $criteria['sourceHandle'] = $sourceHandle;
        }

        if ($search !== '') {
            $criteria['search'] = $search;
        }

        if ($imported === '1' || $imported === '0') {
            $criteria['imported'] = $imported === '1';
        }

        $items = $plugin->items->find($criteria);

        // One more than a page's worth would be an extra query; asking whether the *next* page has
        // anything on it is the same information for the cost of an offset.
        $more = count($plugin->items->find(['limit' => 1, 'offset' => $page * self::PER_PAGE] + $criteria)) > 0;

        return $this->renderTemplate('airwave/items/_index', [
            'items' => $items,
            'sources' => $plugin->sources->getAllSources(true),
            'sourceHandle' => $sourceHandle,
            'search' => $search,
            'sort' => $sort,
            'imported' => $imported,
            'page' => $page,
            'hasMore' => $more,
            'total' => $plugin->items->count($criteria),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDetail(int $itemId): Response
    {
        $item = Plugin::getInstance()->items->getItemById($itemId);

        if (!$item) {
            throw new NotFoundHttpException('Item not found');
        }

        return $this->renderTemplate('airwave/items/_detail', [
            'item' => $item,
            'source' => $item->sourceId ? Plugin::getInstance()->sources->getSourceById($item->sourceId) : null,
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE);

        $ids = array_map('intval', (array)$this->request->getRequiredBodyParam('itemId'));
        $deleted = Plugin::getInstance()->items->deleteByIds($ids);

        $message = Craft::t('airwave', '{count, plural, =1{1 item} other{# items}} deleted.', ['count' => $deleted]);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess($message);
        }

        $this->setSuccessFlash($message);

        return $this->redirect($this->request->getReferrer() ?: 'airwave/items');
    }
}
