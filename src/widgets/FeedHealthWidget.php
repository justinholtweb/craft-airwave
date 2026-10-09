<?php

namespace justinholtweb\airwave\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\airwave\Plugin;

/**
 * A Dashboard tile: the library's totals, and every feed that is failing or has an open alert.
 *
 * The feeds index is where the detail lives; this is what makes somebody go there. It reads the
 * same latch rows the alert emails come from, so the tile and the inbox cannot disagree.
 */
class FeedHealthWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('airwave', 'Feed health');
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/airwave/icon-mask.svg');
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW);
    }

    public function getTitle(): string
    {
        return Craft::t('airwave', 'Feed health');
    }

    public function getBodyHtml(): ?string
    {
        // A widget outlives the permission that let somebody add it.
        if (!Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW)) {
            return null;
        }

        return Craft::$app->getView()->renderTemplate('airwave/_widgets/health', [
            'overview' => Plugin::getInstance()->alerts->overview(),
        ]);
    }
}
