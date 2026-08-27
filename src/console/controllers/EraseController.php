<?php

namespace justinholtweb\lock\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use yii\console\ExitCode;

/**
 * `craft lock/erase` — assembling and erasing from the command line.
 *
 * Defaults to a dry run. Every destructive command in this family does, and this one more than
 * most: the failure mode of getting it wrong is a person's data, deleted, with no undo beyond a
 * database restore.
 */
class EraseController extends Controller
{
    public $defaultAction = 'preview';

    /** @var string|null The address to act on. */
    public ?string $email = null;

    /** @var string|null `anonymise` or `erase`. Defaults to the plugin setting. */
    public ?string $mode = null;

    /** @var bool Actually do it. Without this, nothing is changed. */
    public bool $force = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'preview' => array_merge(parent::options($actionID), ['email', 'mode']),
            'run' => array_merge(parent::options($actionID), ['email', 'mode', 'force']),
            'assemble' => array_merge(parent::options($actionID), ['email']),
            'check' => array_merge(parent::options($actionID), ['email']),
            default => parent::options($actionID),
        };
    }

    /** Shows what would happen, and changes nothing. */
    public function actionPreview(): int
    {
        $subject = $this->subject();

        if ($subject === null) {
            return ExitCode::USAGE;
        }

        $plan = Plugin::getInstance()->erasure->plan($subject, $this->mode);

        if ($plan->blocked) {
            $this->stdout("BLOCKED: $plan->blockReason\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($plan->targets as $target) {
            $colour = match ($target->action) {
                ErasureTarget::ACTION_ERASE => Console::FG_RED,
                ErasureTarget::ACTION_ANONYMISE => Console::FG_YELLOW,
                default => Console::FG_GREY,
            };

            $this->stdout(sprintf('  %-10s ', $target->actionLabel()), $colour);
            $this->stdout(sprintf("%-24s %s\n", $target->sourceLabel, $target->label));

            if ($target->reason !== null) {
                $this->stdout("             $target->reason\n", Console::FG_GREY);
            }
        }

        foreach ($plan->errors as $error) {
            $this->stdout("  ! $error\n", Console::FG_RED);
        }

        $this->stdout(sprintf(
            "\n%d to delete, %d to anonymise, %d kept.\nFingerprint: %s\n",
            $plan->countBy(ErasureTarget::ACTION_ERASE),
            $plan->countBy(ErasureTarget::ACTION_ANONYMISE),
            $plan->countBy(ErasureTarget::ACTION_SKIP),
            $plan->fingerprint(),
        ));

        return ExitCode::OK;
    }

    /** Carries it out. Needs `--force`. */
    public function actionRun(): int
    {
        $subject = $this->subject();

        if ($subject === null) {
            return ExitCode::USAGE;
        }

        $plan = Plugin::getInstance()->erasure->plan($subject, $this->mode);

        if ($plan->blocked) {
            $this->stdout("BLOCKED: $plan->blockReason\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$this->force) {
            $this->stdout("Dry run — nothing was changed. Add --force to apply it.\n", Console::FG_YELLOW);
        }

        $outcome = Plugin::getInstance()->erasure->run($plan, !$this->force);

        $this->stdout($outcome->summary() . "\n", $outcome->isClean() ? Console::FG_GREEN : Console::FG_RED);

        foreach ($outcome->failures as $failure) {
            $this->stdout("  ! {$failure['source']} {$failure['key']}: {$failure['error']}\n", Console::FG_RED);
        }

        return $outcome->isClean() ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** Assembles a dossier and writes the archive, for answering a request from the shell. */
    public function actionAssemble(): int
    {
        $subject = $this->subject();

        if ($subject === null) {
            return ExitCode::USAGE;
        }

        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        $dossier = $plugin->dossiers->assemble($subject);
        $password = $settings->protectDossier ? $plugin->dossiers->generatePassword() : null;
        $filename = $plugin->dossiers->export($dossier, $password);

        foreach ($dossier->bundles as $bundle) {
            $this->stdout(sprintf(
                "  %-40s %s\n",
                $bundle->sourceLabel,
                $bundle->searched ? $bundle->count() . ' record(s)' : 'not searched — ' . $bundle->skipReason,
            ), $bundle->errors !== [] ? Console::FG_RED : Console::FG_GREY);
        }

        $this->stdout("\n" . $plugin->dossiers->pathFor($filename) . "\n", Console::FG_GREEN);

        if ($password !== null) {
            $this->stdout("Password: $password  (shown once, not stored)\n", Console::FG_YELLOW);
        }

        if ($dossier->isPartial()) {
            $this->stdout("This copy is incomplete — see the covering note.\n", Console::FG_RED);
        }

        return ExitCode::OK;
    }

    /** Whether an address has been erased and must not be re-imported. */
    public function actionCheck(): int
    {
        if ($this->email === null) {
            $this->stderr("--email is needed.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $suppressed = Plugin::getInstance()->holds->isSuppressed($this->email);

        $this->stdout($suppressed
            ? "SUPPRESSED — this address was erased and must not be reinstated.\n"
            : "Not suppressed.\n", $suppressed ? Console::FG_RED : Console::FG_GREEN);

        return $suppressed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function subject(): ?Subject
    {
        if ($this->email === null || trim($this->email) === '') {
            $this->stderr("--email is needed.\n", Console::FG_RED);

            return null;
        }

        return (new Subject(email: $this->email))->resolve();
    }
}
