<?php

namespace justinholtweb\lock\tests\unit;

use justinholtweb\lock\models\ErasureOutcome;
use justinholtweb\lock\models\ErasurePlan;
use justinholtweb\lock\models\ErasureTarget;
use PHPUnit\Framework\TestCase;

/**
 * The plan fingerprint.
 *
 * The mechanism behind the plugin's central promise: what the operator approved is what runs. The
 * fingerprint has to change whenever the *set of decided actions* changes, and not change when
 * nothing meaningful has.
 */
class PlanTest extends TestCase
{
    private function target(string $source, string $key, string $action, ?string $label = null): ErasureTarget
    {
        $target = new ErasureTarget();
        $target->source = $source;
        $target->key = $key;
        $target->action = $action;
        $target->label = $label ?? "$source $key";

        return $target;
    }

    private function plan(array $targets): ErasurePlan
    {
        $plan = new ErasurePlan();
        $plan->targets = $targets;

        return $plan;
    }

    public function testIdenticalPlansFingerprintTheSame(): void
    {
        $a = $this->plan([$this->target('user', 'user:1', 'anonymise')]);
        $b = $this->plan([$this->target('user', 'user:1', 'anonymise')]);

        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function testChangingTheActionChangesTheFingerprint(): void
    {
        $a = $this->plan([$this->target('user', 'user:1', 'anonymise')]);
        $b = $this->plan([$this->target('user', 'user:1', 'erase')]);

        self::assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function testAnAddedRecordChangesTheFingerprint(): void
    {
        // The case the fingerprint exists for: a row created between the preview and the button.
        $a = $this->plan([$this->target('user', 'user:1', 'anonymise')]);
        $b = $this->plan([
            $this->target('user', 'user:1', 'anonymise'),
            $this->target('commerce', 'order:9', 'anonymise'),
        ]);

        self::assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function testARemovedRecordChangesTheFingerprint(): void
    {
        $a = $this->plan([
            $this->target('user', 'user:1', 'anonymise'),
            $this->target('commerce', 'order:9', 'anonymise'),
        ]);
        $b = $this->plan([$this->target('user', 'user:1', 'anonymise')]);

        self::assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function testALabelChangeDoesNotChangeTheFingerprint(): void
    {
        // An order that was renamed is still the same deletion. Fingerprinting cosmetic text would
        // make the guard fire on changes that do not affect what happens.
        $a = $this->plan([$this->target('user', 'user:1', 'anonymise', 'Account “ada”')]);
        $b = $this->plan([$this->target('user', 'user:1', 'anonymise', 'Account “anonymised”')]);

        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function testReorderingChangesIt(): void
    {
        // Order is part of the plan: an erasure that runs in a different order can fail differently.
        $a = $this->plan([
            $this->target('user', 'user:1', 'anonymise'),
            $this->target('commerce', 'order:9', 'anonymise'),
        ]);
        $b = $this->plan([
            $this->target('commerce', 'order:9', 'anonymise'),
            $this->target('user', 'user:1', 'anonymise'),
        ]);

        self::assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function testSkippedTargetsAreNotActionable(): void
    {
        $plan = $this->plan([
            $this->target('user', 'user:1', 'anonymise'),
            $this->target('logs', 'file:web.log', 'skip'),
        ]);

        self::assertCount(1, $plan->actionable());
        self::assertCount(1, $plan->skipped());
        self::assertFalse($plan->isEmpty());
    }

    public function testAPlanOfNothingButSkipsIsEmpty(): void
    {
        self::assertTrue($this->plan([$this->target('logs', 'file:web.log', 'skip')])->isEmpty());
    }

    public function testTheOutcomeKnowsWhetherItMatchesThePlan(): void
    {
        $plan = $this->plan([$this->target('user', 'user:1', 'anonymise')]);

        $outcome = new ErasureOutcome();
        $outcome->planFingerprint = $plan->fingerprint();
        self::assertTrue($outcome->matches($plan));

        $outcome->planFingerprint = 'something else';
        self::assertFalse($outcome->matches($plan));
    }

    public function testBySourceCountsEachTreatmentSeparately(): void
    {
        $plan = $this->plan([
            $this->target('commerce', 'order:1', 'anonymise'),
            $this->target('commerce', 'order:2', 'anonymise'),
            $this->target('commerce', 'cart:3', 'erase'),
            $this->target('logs', 'file:a.log', 'skip'),
        ]);

        $grouped = $plan->bySource();

        self::assertSame(2, $grouped['commerce']['anonymise']);
        self::assertSame(1, $grouped['commerce']['erase']);
        self::assertSame(1, $grouped['logs']['skip']);
    }

    public function testTheSummaryReadsAsASentence(): void
    {
        $outcome = new ErasureOutcome();
        $outcome->erased = 2;
        $outcome->anonymised = 3;
        $outcome->skipped = 1;

        self::assertSame('2 deleted, 3 anonymised, 1 kept', $outcome->summary());
        self::assertSame('Nothing to do', (new ErasureOutcome())->summary());
    }
}
