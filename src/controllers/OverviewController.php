<?php

namespace justinholtweb\lock\controllers;

use craft\web\Controller;
use DateTime;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use yii\web\Response;

/**
 * The screen somebody looks at once a week.
 *
 * Built around the only two numbers that carry a penalty — how many requests are open, and how
 * many are late — and then the things that make those numbers worse later: consent that has gone
 * stale, sources holding data with no retention rule pointed at them, and gaps in the register.
 */
class OverviewController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $plugin = Plugin::getInstance();
        $month = (new DateTime())->modify('-30 days');

        return $this->renderTemplate('lock/overview/index', [
            'openRequests' => $plugin->requests->find(['open' => true], 25),
            'openCount' => $plugin->requests->countOpen(),
            'overdueCount' => $plugin->requests->countOverdue(),
            'consentTally' => $plugin->consent->tally(),
            'staleConsent' => count($plugin->consent->stale(500)),
            'activity' => $plugin->activity->recent(12),
            'tally' => $plugin->activity->tally($month),
            'erasures' => $plugin->activity->countSince($month, ActivityRecord::CATEGORY_ERASURE),
            'uncovered' => $plugin->isPro() ? $plugin->retention->uncoveredScopes() : [],
            'gaps' => $plugin->isPro() ? $plugin->register->gaps() : [],
            'nextRun' => $plugin->isPro() ? $plugin->schedules->next() : null,
            'isPro' => $plugin->isPro(),
        ]);
    }
}
