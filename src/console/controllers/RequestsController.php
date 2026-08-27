<?php

namespace justinholtweb\lock\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\lock\Plugin;
use yii\console\ExitCode;

/**
 * `craft lock/requests/...` — the commands a cron job needs.
 *
 * `deadlines` is the one that matters and the one to put on a schedule. Nothing else in the
 * plugin will tell a site it is about to miss a statutory deadline while there is still time to
 * do something about it.
 */
class RequestsController extends Controller
{
    public $defaultAction = 'list';

    /** @var string|null Only show requests with this status. */
    public ?string $status = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'list' => array_merge(parent::options($actionID), ['status']),
            default => parent::options($actionID),
        };
    }

    /** Lists requests, soonest deadline first. */
    public function actionList(): int
    {
        $criteria = $this->status !== null ? ['status' => $this->status] : ['open' => true];
        $requests = Plugin::getInstance()->requests->find($criteria, 200);

        if ($requests === []) {
            $this->stdout("Nothing open.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        foreach ($requests as $request) {
            $days = $request->daysRemaining();
            $colour = match ($request->urgency()) {
                'overdue' => Console::FG_RED,
                'critical' => Console::FG_YELLOW,
                default => Console::FG_GREY,
            };

            $this->stdout(sprintf('%-18s ', $request->reference), Console::FG_CYAN);
            $this->stdout(sprintf('%-14s %-34s ', $request->type, $request->email));
            $this->stdout(sprintf("%s\n", $days === null ? '—' : ($days < 0 ? abs($days) . ' days late' : "$days days left")), $colour);
        }

        return ExitCode::OK;
    }

    /**
     * Sends deadline reminders and expires unconfirmed requests. Run this daily.
     */
    public function actionDeadlines(): int
    {
        $plugin = Plugin::getInstance();
        $sent = 0;

        foreach ($plugin->requests->dueForReminder() as $request) {
            if ($plugin->notifications->sendReminder($request)) {
                $sent++;
                $this->stdout("Reminded: $request->reference ({$request->daysRemaining()} days left)\n", Console::FG_YELLOW);
            }
        }

        $expired = $plugin->requests->expireUnverified();

        $this->stdout("$sent reminder(s) sent, $expired unconfirmed request(s) expired.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Deletes dossier archives past their window, and trims the ledger if a limit is set. */
    public function actionTidy(): int
    {
        $plugin = Plugin::getInstance();
        /** @var \justinholtweb\lock\models\Settings $settings */
        $settings = $plugin->getSettings();

        $dossiers = $plugin->dossiers->prune();
        $ledger = $plugin->activity->prune($settings->activityRetentionDays);

        $this->stdout("$dossiers dossier archive(s) removed, $ledger ledger row(s) removed.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
