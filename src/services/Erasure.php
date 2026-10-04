<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use justinholtweb\lock\events\ErasureEvent;
use justinholtweb\lock\helpers\Address;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureOutcome;
use justinholtweb\lock\models\ErasurePlan;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use justinholtweb\lock\records\ErasureRecord;
use justinholtweb\lock\records\RunRecord;
use Throwable;
use yii\base\InvalidArgumentException;

/**
 * Deciding what happens to a person's data, and then doing exactly that.
 *
 * **The invariant of the whole plugin lives here.** A plan is a materialised list of specific
 * records with a decided action each, and execution reads that list. It never goes back to the
 * collectors to ask what to delete.
 *
 * The temptation to re-run the search at execution time is real — it would be shorter code and it
 * would catch rows added since the preview. It is also wrong twice over: rows created between the
 * preview and the button would be deleted without anybody having seen them, and rows the preview
 * listed might have gone, so the report of what happened would describe a different set from the
 * one that was approved. An approval that does not bind is not an approval.
 *
 * The fingerprint is how that promise is checked rather than asserted: the preview records one,
 * the execution recomputes it, and a mismatch stops the run.
 */
class Erasure extends Component
{
    public const EVENT_BEFORE_ERASE = 'beforeErase';
    public const EVENT_AFTER_ERASE = 'afterErase';

    /**
     * What would happen to everything held about this person.
     *
     * Built from a full dossier, so that the plan and the disclosure are two readings of one
     * search. A site cannot then disclose a record it has no plan for, or erase one it never
     * disclosed.
     */
    public function plan(Subject $subject, ?string $mode = null, ?int $requestId = null): ErasurePlan
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $mode ??= $settings->defaultMode;

        $plan = new ErasurePlan($subject);
        $plan->mode = $mode;
        $plan->requestId = $requestId;
        $plan->builtAt = new DateTime();

        $block = Plugin::getInstance()->holds->blockFor($subject, $requestId);

        if ($block !== null) {
            $plan->blocked = true;
            $plan->blockReason = $block;
        }

        $dossier = Plugin::getInstance()->dossiers->assemble($subject, $requestId);

        foreach ($dossier->bundles as $handle => $bundle) {
            foreach ($bundle->errors as $error) {
                // A source that could not be read is a source whose records cannot be planned.
                // Recorded on the plan so the operator sees an incomplete erasure *before* they
                // tell the subject it is done.
                $plan->errors[] = "$bundle->sourceLabel: $error";
            }

            $collector = Plugin::getInstance()->collectors->get($handle);

            if ($collector === null) {
                continue;
            }

            foreach ($bundle->records as $record) {
                $plan->targets[] = $collector->planFor($record, $mode, $subject);
            }
        }

        return $plan;
    }

    /**
     * A plan over records somebody else chose — the retention sweep's route in.
     *
     * @param DataRecord[] $records
     */
    public function planFor(array $records, string $mode, ?Subject $subject = null): ErasurePlan
    {
        $plan = new ErasurePlan($subject ?? new Subject());
        $plan->mode = $mode;
        $plan->builtAt = new DateTime();

        foreach ($records as $record) {
            $collector = Plugin::getInstance()->collectors->get($record->source);

            if ($collector === null) {
                continue;
            }

            $recordSubject = $subject ?? $collector->subjectFor($record);
            $target = $collector->planFor($record, $mode, $recordSubject);

            if ($subject === null) {
                $target->subjectEmail = $recordSubject->normalisedEmail();
            }

            $plan->targets[] = $target;
        }

        return $plan;
    }

    /**
     * Carries out a plan.
     *
     * @param string|null $expectedFingerprint The fingerprint the operator approved. When given
     *        and it does not match, nothing runs — see the class docblock.
     */
    public function run(
        ErasurePlan $plan,
        bool $dryRun = false,
        ?string $expectedFingerprint = null,
        bool $scheduled = false,
        ?string $ruleKey = null,
    ): ErasureOutcome {
        if ($expectedFingerprint !== null && $expectedFingerprint !== $plan->fingerprint()) {
            throw new InvalidArgumentException(Craft::t(
                'lock',
                'The data changed between the preview and now, so this is no longer the deletion that was approved. Preview it again.',
            ));
        }

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $outcome = new ErasureOutcome();
        $outcome->planFingerprint = $plan->fingerprint();
        $outcome->mode = $plan->mode;
        $outcome->dryRun = $dryRun;

        $event = new ErasureEvent(['plan' => $plan, 'dryRun' => $dryRun]);
        $this->trigger(self::EVENT_BEFORE_ERASE, $event);

        if (!$event->isValid || $plan->blocked) {
            $outcome->skipped = count($plan->targets);
            $outcome->finishedAt = new DateTime();
            $this->record($plan, $outcome, RunRecord::STATUS_BLOCKED, $scheduled, $ruleKey);

            return $outcome;
        }

        $started = microtime(true);

        if (!$dryRun && $settings->backupBeforeErasure && $plan->actionable() !== []) {
            $outcome->backupPath = $this->backup();
        }

        foreach ($plan->targets as $target) {
            if ($target->isSkipped()) {
                $outcome->skipped++;
                continue;
            }

            if ($dryRun) {
                $target->action === ErasureTarget::ACTION_ERASE ? $outcome->erased++ : $outcome->anonymised++;
                continue;
            }

            try {
                $collector = Plugin::getInstance()->collectors->require($target->source);
                $collector->apply($target, $this->subjectFor($plan, $target));

                $target->action === ErasureTarget::ACTION_ERASE ? $outcome->erased++ : $outcome->anonymised++;
                $outcome->completed[] = ['source' => $target->source, 'key' => $target->key, 'action' => $target->action];
            } catch (Throwable $e) {
                // One record failing must not abandon the rest. A half-finished erasure that
                // reports which half is recoverable; one that stops at the first error and says
                // nothing is a subject told "done" over an untouched database.
                //
                // No label, and the address taken out of the message: this goes into the run
                // ledger and the log, neither of which the erasure can reach afterwards, and a
                // database error quotes its SQL with the bound values in it.
                $error = $this->scrub($e->getMessage(), $this->subjectFor($plan, $target));

                $outcome->failed++;
                $outcome->failures[] = [
                    'source' => $target->source,
                    'key' => $target->key,
                    'action' => $target->action,
                    'error' => $error,
                ];

                Craft::error("Lock could not {$target->action} {$target->key} — {$error}", Plugin::LOG_CATEGORY);
            }
        }

        $outcome->duration = round(microtime(true) - $started, 3);
        $outcome->finishedAt = new DateTime();

        $this->record($plan, $outcome, $outcome->isClean() ? RunRecord::STATUS_DONE : RunRecord::STATUS_FAILED, $scheduled, $ruleKey);

        if (!$dryRun && $plan->subject->normalisedEmail() !== '' && $outcome->touched() > 0) {
            $this->certify($plan, $outcome);
        }

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_ERASURE,
            $dryRun ? 'erasure.previewed' : 'erasure.ran',
            $outcome->summary(),
            $plan->subject->normalisedEmail() !== '' ? $plan->subject : null,
            $plan->requestId,
            ['mode' => $plan->mode, 'fingerprint' => $outcome->planFingerprint, 'rule' => $ruleKey],
        );

        $event->outcome = $outcome;
        $this->trigger(self::EVENT_AFTER_ERASE, $event);

        return $outcome;
    }

    private function scrub(string $text, Subject $subject): string
    {
        $email = $subject->normalisedEmail();

        return $email !== '' && str_contains($email, '@') ? Address::replace($text, $email, '[address]') : $text;
    }

    /** The person a given target belongs to — the plan's subject unless the target names another. */
    private function subjectFor(ErasurePlan $plan, ErasureTarget $target): Subject
    {
        if ($target->subjectEmail === null || $target->subjectEmail === '') {
            return $plan->subject;
        }

        return new Subject(email: $target->subjectEmail);
    }

    /**
     * The certificate, and the suppression entry that keeps the erasure true.
     *
     * Written only for a subject-driven run. A retention sweep touches thousands of people who
     * never asked for anything, and adding all of them to a suppression list would silently stop
     * the site accepting new signups from anybody whose old cart it tidied up.
     */
    private function certify(ErasurePlan $plan, ErasureOutcome $outcome): void
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $certificate = new ErasureRecord();
        $certificate->emailHash = $plan->subject->emailHash();
        $certificate->pseudonym = $plan->subject->pseudonym();
        $certificate->mode = $plan->mode;
        $certificate->requestId = $plan->requestId;
        $certificate->scope = $plan->bySource();
        $certificate->erased = $outcome->erased;
        $certificate->anonymised = $outcome->anonymised;
        $certificate->suppressed = $settings->suppressAfterErasure;
        $certificate->completedAt = Db::prepareDateForDb($outcome->finishedAt ?? new DateTime());
        $certificate->userId = Craft::$app->getUser()->getId();
        $certificate->save(false);
    }

    private function record(ErasurePlan $plan, ErasureOutcome $outcome, string $status, bool $scheduled, ?string $ruleKey): void
    {
        $run = new RunRecord();
        $run->type = $ruleKey !== null ? RunRecord::TYPE_RETENTION : RunRecord::TYPE_ERASURE;
        $run->status = $status;
        $run->ruleKey = $ruleKey;
        $run->subjectHash = $plan->subject->normalisedEmail() !== '' ? $plan->subject->emailHash() : null;
        $run->requestId = $plan->requestId;
        $run->summary = $plan->blocked ? $this->scrub((string)$plan->blockReason, $plan->subject) : $outcome->summary();
        $run->plan = $plan->toArray();
        $run->outcome = $outcome->toArray();
        $run->fingerprint = $outcome->planFingerprint;
        $run->erased = $outcome->erased;
        $run->anonymised = $outcome->anonymised;
        $run->skipped = $outcome->skipped;
        $run->failed = $outcome->failed;
        $run->duration = $outcome->duration;
        $run->dryRun = $outcome->dryRun;
        $run->scheduled = $scheduled;
        $run->backupPath = $outcome->backupPath;
        $run->userId = Craft::$app->getUser()->getId();
        $run->save(false);
    }

    /**
     * A database backup, recorded on the run so that "undo" is a documented restore rather than a
     * hunt through storage/backups.
     */
    private function backup(): ?string
    {
        try {
            return Craft::$app->getDb()->backup();
        } catch (Throwable $e) {
            // Deliberately not fatal. A site with `backupCommand` disabled — which is most managed
            // hosting — would otherwise be unable to run an erasure at all, and the erasure is the
            // legal obligation while the backup is a convenience.
            Craft::warning('Lock could not take a backup before erasing: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }
    }
}
