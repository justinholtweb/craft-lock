<?php

namespace justinholtweb\lock\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use craft\helpers\Console;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\queue\ApplyRetentionRules;
use yii\console\ExitCode;

/**
 * `craft lock/retention/...` — storage limitation on a cron.
 *
 * `due` is the one to schedule. It runs only the rules whose occurrence has passed, so running it
 * every five minutes and running it once a night produce the same deletions.
 */
class RetentionController extends Controller
{
    public $defaultAction = 'due';

    /** @var string|null A single rule key, instead of everything due. */
    public ?string $rule = null;

    /** @var bool Report without changing anything. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'due', 'run' => array_merge(parent::options($actionID), ['rule', 'dryRun']),
            default => parent::options($actionID),
        };
    }

    public function optionAliases(): array
    {
        return ['d' => 'dryRun', 'r' => 'rule'];
    }

    /**
     * Retention is a Pro feature, and every action says so with a non-zero exit.
     *
     * Not done in `beforeAction()`: returning false from there ends the command with exit code 0,
     * so a cron job on Lite would report success every night while deleting nothing — the one
     * failure a retention schedule must never hide.
     */
    private function refuseOnLite(): ?int
    {
        if (Plugin::getInstance()->isPro()) {
            return null;
        }

        $this->stderr("Retention rules are a Pro feature. Nothing was run.\n", Console::FG_RED);

        return ExitCode::UNAVAILABLE;
    }

    /** Runs every rule that is owed a run. */
    public function actionDue(): int
    {
        if (($refused = $this->refuseOnLite()) !== null) {
            return $refused;
        }

        App::maxPowerCaptain();

        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(ApplyRetentionRules::MUTEX)) {
            $this->stderr("Another retention run is in progress. Nothing was run.\n", Console::FG_YELLOW);

            return ExitCode::TEMPFAIL;
        }

        try {
            $outcomes = Plugin::getInstance()->retention->runDue($this->dryRun);

            if ($outcomes === []) {
                $this->stdout("Nothing due.\n", Console::FG_GREY);

                return ExitCode::OK;
            }

            foreach ($outcomes as $key => $outcome) {
                $this->stdout(sprintf("%-28s %s\n", $key, $outcome->summary()), $outcome->isClean() ? Console::FG_GREEN : Console::FG_RED);
            }

            Plugin::getInstance()->notifications->sendRetentionReport($outcomes);

            return ExitCode::OK;
        } finally {
            $mutex->release(ApplyRetentionRules::MUTEX);
        }
    }

    /** Runs one rule now, whether or not it is due. */
    public function actionRun(): int
    {
        if (($refused = $this->refuseOnLite()) !== null) {
            return $refused;
        }

        if ($this->rule === null) {
            $this->stderr("--rule is needed.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $rule = Plugin::getInstance()->retention->rule($this->rule);

        if ($rule === null) {
            $this->stderr("No rule called “{$this->rule}”.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        App::maxPowerCaptain();

        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(ApplyRetentionRules::MUTEX)) {
            $this->stderr("Another retention run is in progress. Nothing was run.\n", Console::FG_YELLOW);

            return ExitCode::TEMPFAIL;
        }

        try {
            $outcome = Plugin::getInstance()->retention->run($rule, $this->dryRun);
        } finally {
            $mutex->release(ApplyRetentionRules::MUTEX);
        }

        $this->stdout($outcome->summary() . "\n", $outcome->isClean() ? Console::FG_GREEN : Console::FG_RED);

        return $outcome->isClean() ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** Lists the rules, and the scopes nothing is pointed at. */
    public function actionStatus(): int
    {
        if (($refused = $this->refuseOnLite()) !== null) {
            return $refused;
        }

        $retention = Plugin::getInstance()->retention;

        foreach ($retention->allRules() as $key => $rule) {
            $last = $retention->lastRun($key, true);

            $this->stdout(sprintf('%-24s ', $key), $rule->enabled ? Console::FG_CYAN : Console::FG_GREY);
            $this->stdout(sprintf(
                "%-58s last run %s\n",
                $rule->sentence(),
                $last?->format('Y-m-d H:i') ?? 'never',
            ));
        }

        $uncovered = $retention->uncoveredScopes();

        if ($uncovered !== []) {
            $this->stdout("\nHolding personal data with no retention rule:\n", Console::FG_YELLOW);

            foreach ($uncovered as $scope) {
                $this->stdout("  $scope->key — $scope->label\n", Console::FG_GREY);
            }
        }

        return ExitCode::OK;
    }

    /** Shows what a rule would do. */
    public function actionPreview(): int
    {
        if (($refused = $this->refuseOnLite()) !== null) {
            return $refused;
        }

        if ($this->rule === null) {
            $this->stderr("--rule is needed.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $rule = Plugin::getInstance()->retention->rule($this->rule);

        if ($rule === null) {
            $this->stderr("No rule called “{$this->rule}”.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $plan = Plugin::getInstance()->retention->preview($rule);

        foreach ($plan->targets as $target) {
            $this->stdout(sprintf("  %-10s %s\n", $target->actionLabel(), $target->label), $target->isSkipped() ? Console::FG_GREY : Console::FG_YELLOW);
        }

        foreach ($plan->errors as $error) {
            $this->stdout("  ! $error\n", Console::FG_RED);
        }

        $this->stdout(sprintf(
            "\n%d to delete, %d to anonymise, %d kept.\n",
            $plan->countBy(ErasureTarget::ACTION_ERASE),
            $plan->countBy(ErasureTarget::ACTION_ANONYMISE),
            $plan->countBy(ErasureTarget::ACTION_SKIP),
        ));

        return ExitCode::OK;
    }
}
