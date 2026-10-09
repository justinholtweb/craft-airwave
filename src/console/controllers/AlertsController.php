<?php

namespace justinholtweb\airwave\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\airwave\Plugin;
use justinholtweb\airwave\services\Alerts;
use yii\console\ExitCode;

/**
 * Failing-feed alerts from the command line — `php craft airwave/alerts/…`.
 *
 *     php craft airwave/alerts/check     # evaluate every feed, send what is owed
 *     php craft airwave/alerts/test      # send a sample through every configured channel
 *
 * `airwave/feeds/refresh` runs the same check when it finishes, so a site that already has that on
 * cron needs nothing more.
 */
class AlertsController extends Controller
{
    public $defaultAction = 'check';

    /**
     * Evaluates every enabled feed and sends any alert or recovery that is owed.
     */
    public function actionCheck(): int
    {
        $results = Plugin::getInstance()->alerts->check();

        if ($results === []) {
            $this->stdout("No enabled feeds.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        $open = 0;

        foreach ($results as $result) {
            $isOpen = $result['state'] === Alerts::STATE_OPEN;
            $open += $isOpen ? 1 : 0;

            $this->stdout(str_pad($result['source'], 28));
            $this->stdout(str_pad(Alerts::incidentLabel($result['incident']), 22));
            $this->stdout(str_pad($isOpen ? 'open' : 'ok', 6), $isOpen ? Console::FG_RED : Console::FG_GREEN);

            if ($result['transition'] !== null) {
                $this->stdout(' ' . $result['transition'], Console::FG_YELLOW);
            }

            if ($result['notified']) {
                $this->stdout(' · notified', Console::FG_YELLOW);
            }

            $this->stdout("\n");

            if ($isOpen && $result['detail']) {
                $this->stdout('  ' . $result['detail'] . "\n", Console::FG_GREY);
            }
        }

        $this->stdout(sprintf("\n%d open incident%s.\n", $open, $open === 1 ? '' : 's'));

        // Exit 0 either way: an open incident is news, not a failure of this command, and cron
        // would otherwise email somebody about the alert on top of the alert.
        return ExitCode::OK;
    }

    /**
     * Sends a sample alert through every configured channel.
     */
    public function actionTest(): int
    {
        $result = Plugin::getInstance()->alerts->sendTest();

        if ($result['email'] === null && $result['webhook'] === null) {
            $this->stderr("No recipients and no webhook are configured — nothing to send to.\n", Console::FG_YELLOW);

            return ExitCode::CONFIG;
        }

        $ok = true;

        if ($result['email'] !== null) {
            $this->stdout('Email:   ' . ($result['email'] ? "sent\n" : "failed — check Craft's email settings\n"), $result['email'] ? Console::FG_GREEN : Console::FG_RED);
            $ok = $result['email'];
        }

        if ($result['webhook'] !== null) {
            $this->stdout('Webhook: ' . ($result['webhook'] === true ? "sent\n" : 'failed — ' . $result['webhook'] . "\n"), $result['webhook'] === true ? Console::FG_GREEN : Console::FG_RED);
            $ok = $ok && $result['webhook'] === true;
        }

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
