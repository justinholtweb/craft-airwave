<?php

namespace justinholtweb\airwave\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\Plugin;
use yii\console\ExitCode;

/**
 * Published channels from the command line — `php craft airwave/channels/…`.
 *
 * `enable`, `disable` and `delete` are the channel index's bulk actions, through the same
 * `Channels` service: name channels with `--handle=one,two` or pass `--all`.
 */
class ChannelsController extends Controller
{
    /** @var string|null Channel handles, comma-separated. */
    public ?string $handle = null;

    /** @var bool Act on every channel. */
    public bool $all = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'enable', 'disable', 'delete' => array_merge(parent::options($actionID), ['handle', 'all']),
            default => parent::options($actionID),
        };
    }
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

    /**
     * Enables channels, so their URLs serve again.
     */
    public function actionEnable(): int
    {
        return $this->setEnabled(true);
    }

    /**
     * Disables channels. A disabled channel's URL 404s until it is enabled again.
     */
    public function actionDisable(): int
    {
        return $this->setEnabled(false);
    }

    /**
     * Deletes channels. Soft: restorable from the trash until garbage collection runs.
     */
    public function actionDelete(): int
    {
        $channels = $this->selectChannels();

        if ($channels === null) {
            return ExitCode::USAGE;
        }

        if (!$channels) {
            $this->stdout("No matching channels.\n");

            return ExitCode::OK;
        }

        if (!$this->confirm(sprintf('Delete %d channel(s)? Their feed URLs will stop working.', count($channels)))) {
            return ExitCode::OK;
        }

        $failed = 0;

        foreach ($channels as $channel) {
            $ok = Plugin::getInstance()->channels->deleteChannel($channel);
            $failed += $ok ? 0 : 1;
            $this->stdout(sprintf("%-24s %s\n", $channel->handle, $ok ? 'deleted' : 'could not be deleted'), $ok ? Console::FG_GREEN : Console::FG_RED);
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function setEnabled(bool $enabled): int
    {
        $channels = $this->selectChannels();

        if ($channels === null) {
            return ExitCode::USAGE;
        }

        $failed = 0;

        foreach ($channels as $channel) {
            if ($channel->enabled === $enabled) {
                continue;
            }

            $channel->enabled = $enabled;
            $ok = Plugin::getInstance()->channels->saveChannel($channel);
            $failed += $ok ? 0 : 1;
            $this->stdout(
                sprintf("%-24s %s\n", $channel->handle, $ok ? ($enabled ? 'enabled' : 'disabled') : implode(' ', $channel->getFirstErrors())),
                $ok ? Console::FG_GREEN : Console::FG_RED,
            );
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * @return Channel[]|null Null, with a message, when neither --handle nor --all was given.
     */
    private function selectChannels(): ?array
    {
        $service = Plugin::getInstance()->channels;

        if ($this->all) {
            return $service->getAllChannels(true);
        }

        if (!$this->handle) {
            $this->stderr("Name the channels with --handle=one,two, or pass --all.\n", Console::FG_RED);

            return null;
        }

        $channels = [];

        foreach (array_filter(array_map('trim', explode(',', $this->handle))) as $handle) {
            $channel = $service->getChannelByHandle($handle);

            if ($channel) {
                $channels[] = $channel;
            } else {
                $this->stderr("No channel with the handle “{$handle}”.\n", Console::FG_YELLOW);
            }
        }

        return $channels;
    }
}
