<?php

namespace justinholtweb\airwave\services;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\errors\InvalidSubpathException;
use craft\fields\Assets as AssetsField;
use craft\fields\Categories as CategoriesField;
use craft\fields\Tags as TagsField;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\ElementHelper;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\Section;
use craft\models\Volume;
use DateInterval;
use DateTime;
use DateTimeZone;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\models\ImportConfig;
use justinholtweb\airwave\models\ImportResult;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Feed items into entries. Pro.
 *
 * This is the feature with teeth: it writes into a live section, on a schedule, from a document
 * somebody else controls. Everything here is shaped by that.
 *
 * - **`importedElementId` is the only dedupe.** Not the title, not the URL, not "did we run today"
 *   — the row that says *this item became that entry*. It survives the entry being edited, moved
 *   or renamed, and it is set to null rather than cascaded when the entry is deleted, so deleting
 *   an entry does not invite it back on the next refresh.
 * - **Runs are capped.** {@see ImportConfig::$limitPerRun} exists because the first run of a feed
 *   with 800 items in it would otherwise be one request that never ends.
 * - **Oldest first.** So a backfill lands in the order things were published, and a run that is
 *   interrupted resumes where it stopped instead of leaving a hole in the middle.
 * - **A failed item is skipped, not fatal.** One item with an unparseable date should not stop the
 *   other 24.
 */
class Importer extends Component
{
    public function import(Source $source, ?int $limit = null, bool $dryRun = false): ImportResult
    {
        $result = new ImportResult();
        $result->dryRun = $dryRun;

        $config = $source->getImportConfig();

        if (!$config->isConfigured()) {
            $result->errors[] = Craft::t('airwave', 'This feed has no section to import into.');

            return $result;
        }

        $entries = Craft::$app->getEntries();
        $section = $entries->getSectionByUid($config->sectionUid);
        $entryType = $entries->getEntryTypeByUid($config->entryTypeUid);

        if (!$section || !$entryType) {
            $result->errors[] = Craft::t('airwave', 'The section or entry type this feed imports into no longer exists.');

            return $result;
        }

        $items = Plugin::getInstance()->items->find([
            'sourceId' => $source->id,
            'imported' => false,
            'sort' => 'oldest',
            'limit' => $limit ?? $config->limitPerRun,
            'includeDisabled' => true,
        ]);

        $cutoff = $config->maxAgeDays
            ? (new DateTime('now', new DateTimeZone('UTC')))->sub(new DateInterval('P' . $config->maxAgeDays . 'D'))
            : null;

        foreach ($items as $item) {
            if ($cutoff && $item->publishedAt && $item->publishedAt < $cutoff) {
                $result->skipped++;
                continue;
            }

            if ($dryRun) {
                $result->previews[] = [
                    'item' => (string)$item->id,
                    'title' => $this->resolveTitle($item, $config),
                    'url' => $item->url,
                    'date' => $item->publishedAt?->format('Y-m-d H:i'),
                ];

                continue;
            }

            try {
                $entry = $this->createEntry($item, $source, $config, $section, $entryType);

                if ($entry) {
                    Plugin::getInstance()->items->markImported((int)$item->id, (int)$entry->id);
                    $result->created++;
                } else {
                    $result->failed++;
                }
            } catch (Throwable $e) {
                $result->failed++;
                $result->errors[] = sprintf('%s: %s', $item->getTitleText(), $e->getMessage());
                Craft::error("Airwave failed to import an item from {$source->handle}: {$e->getMessage()}", 'airwave');
            }
        }

        return $result;
    }

    /**
     * @throws Throwable
     */
    private function createEntry(Item $item, Source $source, ImportConfig $config, Section $section, \craft\models\EntryType $entryType): ?Entry
    {
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = $config->siteId ?: Craft::$app->getSites()->getPrimarySite()->id;
        $entry->enabled = $config->status === ImportConfig::STATUS_ENABLED;

        $title = $this->resolveTitle($item, $config);
        $entry->title = $title;

        // An entry type with no title field and no title format leaves Craft nothing to build a
        // slug out of, and the save fails with "Slug cannot be blank" — which reads like an
        // Airwave bug and is really a content-model detail nobody thinks about until an import
        // hits it. Title-less entry types are ordinary in Craft 5, so give it a slug from the
        // item rather than making that somebody else's problem.
        if (!$entryType->hasTitleField && !$entryType->titleFormat) {
            $entry->slug = ElementHelper::generateSlug($title) ?: 'item-' . $item->id;
        }

        if ($config->authorId) {
            $entry->setAuthorId($config->authorId);
        }

        if ($config->dateSource === ImportConfig::DATE_ITEM && $item->publishedAt) {
            $entry->postDate = $item->publishedAt;
        }

        // A section can be structured or a channel; a single is neither and cannot take an import.
        if ($section->type === Section::TYPE_SINGLE) {
            throw new \RuntimeException('A single section cannot take imported entries.');
        }

        $fieldValues = [];

        foreach ($config->fieldMap as $handle => $sourceToken) {
            $field = $entryType->getFieldLayout()?->getFieldByHandle($handle);

            if (!$field) {
                continue;
            }

            $value = $this->resolveFieldValue($field, $sourceToken, $item);

            if ($value !== null) {
                $fieldValues[$handle] = $value;
            }
        }

        if ($fieldValues) {
            $entry->setFieldValues($fieldValues);
        }

        if ($config->importImages && $config->imageField && $item->getThumbnailUrl()) {
            $assetId = $this->importImage($item, $config);

            if ($assetId) {
                $entry->setFieldValue($config->imageField, [$assetId]);
            }
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new \RuntimeException(implode('; ', array_map(
                fn($errors) => implode(', ', $errors),
                $entry->getErrors(),
            )) ?: 'The entry would not save.');
        }

        return $entry;
    }

    private function resolveTitle(Item $item, ImportConfig $config): string
    {
        if ($config->titleTemplate) {
            $rendered = $this->renderTemplate($config->titleTemplate, $item);

            if ($rendered !== null && $rendered !== '') {
                return mb_substr($rendered, 0, 255);
            }
        }

        return mb_substr($item->getTitleText(), 0, 255);
    }

    /**
     * Turns one mapping into something the target field will accept.
     *
     * A plain token is read off the item; anything else is an object template. Relation fields get
     * special handling because "categories" as a comma-joined string in a Tags field is useless,
     * and a Tags field is the single most common thing to point feed categories at.
     */
    private function resolveFieldValue(FieldInterface $field, string $sourceToken, Item $item): mixed
    {
        if ($field instanceof AssetsField) {
            // Images are handled by their own path, which has to download a file first.
            return null;
        }

        if ($field instanceof TagsField) {
            return $this->resolveTags($field, $item);
        }

        if ($field instanceof CategoriesField) {
            // Matched, never created: a category tree is deliberate information architecture, and
            // an aggregator inventing branches in it is not a favour.
            return $this->matchCategories($field, $item);
        }

        $value = in_array($sourceToken, ImportConfig::TOKENS, true)
            ? $this->tokenValue($sourceToken, $item)
            : $this->renderTemplate($sourceToken, $item);

        return $value !== '' ? $value : null;
    }

    private function tokenValue(string $token, Item $item): ?string
    {
        return match ($token) {
            'title' => $item->getTitleText(),
            'summary' => $item->summary,
            // Purified: this is going into a rich-text field that a template will output.
            'content' => (string)$item->getHtml(\justinholtweb\airwave\models\DisplayOptions::CONTENT_FULL),
            'url' => $item->url,
            'author' => $item->author,
            'image' => $item->getThumbnailUrl(),
            'categories' => implode(', ', $item->categories),
            'guid' => $item->guid,
            'domain' => $item->getDomain(),
            'publishedAt' => $item->publishedAt?->format(DATE_ATOM),
            default => null,
        };
    }

    private function renderTemplate(string $template, Item $item): ?string
    {
        try {
            return trim(Craft::$app->getView()->renderString($template, [
                'item' => $item,
                'object' => $item,
            ]));
        } catch (Throwable $e) {
            Craft::warning("Airwave import template failed: {$e->getMessage()}", 'airwave');

            return null;
        }
    }

    /**
     * @return int[]|null
     */
    private function resolveTags(TagsField $field, Item $item): ?array
    {
        if (!$item->categories) {
            return null;
        }

        $groupUid = $this->relationSourceUid($field, 'taggroup');
        $group = $groupUid ? Craft::$app->getTags()->getTagGroupByUid($groupUid) : null;

        if (!$group) {
            return null;
        }

        $ids = [];

        foreach ($item->categories as $name) {
            $tag = Tag::find()->groupId($group->id)->title(\craft\helpers\Db::escapeParam($name))->one();

            if (!$tag) {
                $tag = new Tag();
                $tag->groupId = $group->id;
                $tag->title = mb_substr($name, 0, 255);

                if (!Craft::$app->getElements()->saveElement($tag)) {
                    continue;
                }
            }

            $ids[] = $tag->id;
        }

        return $ids ?: null;
    }

    /**
     * @return int[]|null
     */
    private function matchCategories(CategoriesField $field, Item $item): ?array
    {
        if (!$item->categories) {
            return null;
        }

        $groupUid = $this->relationSourceUid($field, 'group');
        $group = $groupUid ? Craft::$app->getCategories()->getGroupByUid($groupUid) : null;

        if (!$group) {
            return null;
        }

        $ids = \craft\elements\Category::find()
            ->groupId($group->id)
            ->title(array_map(fn($c) => \craft\helpers\Db::escapeParam($c), $item->categories))
            ->ids();

        return $ids ?: null;
    }

    /**
     * A relation field's sources are `taggroup:<uid>` or `group:<uid>`, or the string `*`.
     */
    private function relationSourceUid(FieldInterface $field, string $prefix): ?string
    {
        $sources = $field->sources ?? null;

        foreach ((array)$sources as $source) {
            if (is_string($source) && str_starts_with($source, "$prefix:")) {
                return substr($source, strlen($prefix) + 1);
            }
        }

        return null;
    }

    /**
     * Downloads the item's image into a volume and returns the asset id.
     *
     * Failure is silent and non-fatal on purpose: an image that 404s should cost the entry its
     * picture, not its existence.
     */
    private function importImage(Item $item, ImportConfig $config): ?int
    {
        $url = $item->getThumbnailUrl();

        if (!$url || !$config->imageVolumeUid) {
            return null;
        }

        $volume = Craft::$app->getVolumes()->getVolumeByUid($config->imageVolumeUid);

        if (!$volume instanceof Volume) {
            return null;
        }

        $temp = null;

        try {
            $temp = Plugin::getInstance()->fetcher->downloadToTemp($url);

            if (!$temp) {
                return null;
            }

            $filename = $this->filenameFor($url, $temp);
            $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

            if (!$folder) {
                return null;
            }

            $asset = new Asset();
            $asset->tempFilePath = $temp;
            $asset->setFilename($filename);
            $asset->newFolderId = $folder->id;
            $asset->setVolumeId($volume->id);
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (!Craft::$app->getElements()->saveElement($asset)) {
                return null;
            }

            // Craft moves the temp file into the volume on save; deleting it here would be a race
            // at best, so the finally block only cleans up what is still there.
            $temp = null;

            return $asset->id;
        } catch (InvalidSubpathException|Throwable $e) {
            Craft::warning("Airwave could not import an image: {$e->getMessage()}", 'airwave');

            return null;
        } finally {
            if ($temp && is_file($temp)) {
                FileHelper::unlink($temp);
            }
        }
    }

    private function filenameFor(string $url, string $path): string
    {
        $name = basename((string)parse_url($url, PHP_URL_PATH));
        $name = preg_replace('/\?.*$/', '', $name) ?? $name;
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        if (!$extension) {
            // Plenty of image URLs end in `/image` or a hash with no extension. Ask the file.
            $mime = FileHelper::getMimeType($path) ?: 'image/jpeg';
            $extension = match ($mime) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                default => 'jpg',
            };

            $name = ($name ?: 'image') . '.' . $extension;
        }

        return AssetsHelper::prepareAssetName($name) ?: (StringHelper::UUID() . '.' . $extension);
    }
}
