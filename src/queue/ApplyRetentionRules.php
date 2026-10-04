<?php

namespace justinholtweb\lock\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\lock\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * Runs due retention rules out of band.
 *
 * One rule per progress step, so a queue that dies halfway leaves a ledger entry for every rule
 * that finished rather than an all-or-nothing mystery.
 *
 * **Never retried.** A sweep that failed partway has already deleted what it deleted, and its
 * outcome is in the run ledger; running it again from the top re-plans against a different set
 * of rows and sends a second report. The next scheduled occurrence picks up whatever is still
 * owed, which is the retry that is actually safe.
 *
 * **One at a time.** A mutex covers the whole job and the console `retention/due` command, so a
 * cron run and a control-panel-triggered job that land together cannot both plan the same rows.
 */
class ApplyRetentionRules extends BaseJob implements RetryableJobInterface
{
    public const MUTEX = 'lock-retention';

    /** @var string[] */
    public array $ruleKeys = [];

    public bool $dryRun = false;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        // Defence in depth: a job queued on Pro and run after a downgrade does nothing.
        if (!$plugin->isPro()) {
            Craft::warning('Lock: a queued retention job was skipped because retention is a Pro feature.', Plugin::LOG_CATEGORY);

            return;
        }

        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::MUTEX)) {
            Craft::warning('Lock: a retention run is already in progress, so this one was skipped.', Plugin::LOG_CATEGORY);

            return;
        }

        try {
            $retention = $plugin->retention;
            $outcomes = [];
            $total = max(1, count($this->ruleKeys));

            foreach (array_values($this->ruleKeys) as $i => $key) {
                $rule = $retention->rule($key);

                if ($rule === null) {
                    continue;
                }

                $this->setProgress($queue, $i / $total, Craft::t('lock', 'Applying “{rule}”', ['rule' => $rule->label]));
                $outcomes[$key] = $retention->run($rule, $this->dryRun, true);
            }

            $this->setProgress($queue, 1);

            $plugin->notifications->sendRetentionReport($outcomes);
        } finally {
            $mutex->release(self::MUTEX);
        }
    }

    /** Half an hour. A rule's limit bounds a sweep, but a large one through the element layer is slow. */
    public function getTtr(): int
    {
        return 1800;
    }

    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('lock', 'Applying retention rules');
    }
}
