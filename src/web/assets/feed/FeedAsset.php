<?php

namespace justinholtweb\airwave\web\assets\feed;

use craft\web\AssetBundle;

/**
 * The front-end stylesheet for rendered items.
 *
 * One small CSS file and no JavaScript at all. A feed list is a list — it does not need a runtime,
 * and shipping one would put a script on every page that renders a feed for no behaviour anybody
 * asked for. Sites that want their own look either override
 * {@see \justinholtweb\airwave\services\Renderer::SITE_TEMPLATE} or turn the stylesheet off in
 * settings and style the classes themselves.
 */
class FeedAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->css = ['airwave.css'];

        parent::init();
    }
}
