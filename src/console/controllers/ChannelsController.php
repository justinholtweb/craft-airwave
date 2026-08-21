<?php

namespace justinholtweb\airwave\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\airwave\Plugin;
use yii\console\ExitCode;

/**
 * Published channels from the command line — `php craft airwave/channels/…`.
 */
class ChannelsController extends Controller
{
    /**
     * Lists every published channel and its URL.
     */
    public function actionIndex(): int
    {
        $channels = Plugin::getInstance()->channels->getAllChannels(true);

        if (!$channels) {
            $this->stdout("No channels yet.\n");

            return ExitCode::OK;
        }

        foreach ($channels as $channel) {
            $this->stdout(sprintf('%-24s %-6s ', $channel->handle, strtoupper($channel->getServedFormat())));
            $this->stdout((string)$channel->getUrl(), $channel->enabled ? Console::FG_GREEN : Console::FG_GREY);
            $this->stdout($channel->enabled ? "\n" : " (disabled)\n");
        }

        return ExitCode::OK;
    }

    /**
     * Prints the document a channel would publish right now.
     *
     * @param string $handle The channel handle.
     */
    public function actionPreview(string $handle): int
    {
        $channel = Plugin::getInstance()->channels->getChannelByHandle($handle);

        if (!$channel) {
            $this->stderr("No channel with that handle.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout(Plugin::getInstance()->broadcaster->build($channel) . "\n");

        return ExitCode::OK;
    }
}
