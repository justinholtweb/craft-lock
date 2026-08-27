<?php

namespace justinholtweb\lock\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\lock\Plugin;

/**
 * Runs due retention rules out of band.
 *
 * One rule per progress step, so a queue that dies halfway leaves a ledger entry for every rule
 * that finished rather than an all-or-nothing mystery.
 */
class RetentionJob extends BaseJob
{
    /** @var string[] */
    public array $ruleKeys = [];

    public bool $dryRun = false;

    public function execute($queue): void
    {
        $retention = Plugin::getInstance()->retention;
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

        Plugin::getInstance()->notifications->sendRetentionReport($outcomes);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('lock', 'Applying retention rules');
    }
}
