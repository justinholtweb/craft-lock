<?php

namespace justinholtweb\lock\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\lock\Plugin;
use yii\console\ExitCode;

/**
 * `craft lock/report/...` — the paperwork, on demand.
 *
 * `gaps` is designed to be run in CI. A build that fails when the register stops describing the
 * site is the only mechanism that keeps an Article 30 record true for longer than a quarter.
 */
class ReportController extends Controller
{
    public $defaultAction = 'gaps';

    /** @var bool Emit JSON instead of text. */
    public bool $json = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['json']);
    }

    /**
     * Lists what the register is missing. Exits non-zero when there is anything, so CI can fail.
     */
    public function actionGaps(): int
    {
        $gaps = Plugin::getInstance()->register->gaps();

        if ($this->json) {
            $this->stdout(Json::encode($gaps, JSON_PRETTY_PRINT) . "\n");

            return $gaps === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        if ($gaps === []) {
            $this->stdout("The register accounts for everything Lock can see.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        foreach ($gaps as $gap) {
            $this->stdout("  - $gap\n", Console::FG_YELLOW);
        }

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /** The whole Article 30 register as JSON. */
    public function actionRegister(): int
    {
        $this->stdout(Json::encode(Plugin::getInstance()->register->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return ExitCode::OK;
    }

    /** Where the site stands: open requests, consent, retention coverage. */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();

        $status = [
            // The edition is first because it changes what the rest of the answer means: a site on
            // Lite with no retention rules is configured correctly, and a site on Pro with none is
            // keeping personal data forever.
            'edition' => (string)$plugin->edition,
            'pro' => $plugin->isPro(),
            'requests' => [
                'open' => $plugin->requests->countOpen(),
                'overdue' => $plugin->requests->countOverdue(),
            ],
            'consent' => $plugin->consent->tally(),
            'stale_consent' => count($plugin->consent->stale(1000)),
            'sources' => array_map(
                static fn($c) => ['label' => $c->label(), 'available' => $c->isAvailable()],
                $plugin->collectors->enabled(),
            ),
            'retention' => [
                'rules' => count($plugin->retention->rules()),
                'enabled' => count($plugin->retention->enabledRules()),
                'uncovered' => array_keys($plugin->retention->uncoveredScopes()),
            ],
        ];

        if ($this->json) {
            $this->stdout(Json::encode($status, JSON_PRETTY_PRINT) . "\n");

            return ExitCode::OK;
        }

        $this->stdout('Edition    ' . $status['edition'] . "\n");
        $this->stdout("Requests   {$status['requests']['open']} open, {$status['requests']['overdue']} overdue\n",
            $status['requests']['overdue'] > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout('Consent    ' . count($status['consent']) . " purpose(s), {$status['stale_consent']} record(s) past their re-ask date\n");
        $this->stdout("Retention  {$status['retention']['enabled']} of {$status['retention']['rules']} rules enabled, "
            . count($status['retention']['uncovered']) . " scope(s) with no rule\n");

        return ExitCode::OK;
    }
}
