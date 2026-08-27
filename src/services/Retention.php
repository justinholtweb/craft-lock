<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\lock\helpers\Cadence;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureOutcome;
use justinholtweb\lock\models\ErasurePlan;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\RetentionRule;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use justinholtweb\lock\records\RunRecord;

/**
 * Storage limitation, made to actually happen.
 *
 * **A retention run is an erasure with a different way of choosing the records.** Rather than
 * "everything belonging to this person", it is "everything in this scope older than this date" —
 * and from there it is the same plan, the same preview, the same executor and the same ledger.
 * That is what keeps a nightly automated deletion held to exactly the guarantees a hand-run
 * erasure gets, instead of being a second, less careful deletion path.
 *
 * The safety property that matters most: a subject with an open request or a legal hold is
 * skipped, per record, at plan time. A retention rule that purged the data somebody has just asked
 * for a copy of would turn a routine request into an incident.
 */
class Retention extends Component
{
    /** @return RetentionRule[] keyed by rule key */
    public function rules(): array
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $rules = [];

        foreach ($settings->retentionRules as $row) {
            $rule = RetentionRule::fromArray($row);

            if ($rule->key !== '') {
                $rules[$rule->key] = $rule;
            }
        }

        return $rules;
    }

    public function rule(string $key): ?RetentionRule
    {
        return $this->rules()[$key] ?? null;
    }

    /** @return RetentionRule[] */
    public function enabledRules(): array
    {
        return array_filter($this->rules(), static fn(RetentionRule $r) => $r->enabled);
    }

    /**
     * What a rule would do right now.
     *
     * Report-only rules come back as a plan of skips with the reason on each, rather than as an
     * empty plan — "we found 4,000 old submissions and did nothing, because you asked us not to"
     * is the useful answer, and an empty plan says the opposite.
     */
    public function preview(RetentionRule $rule, ?DateTime $now = null): ErasurePlan
    {
        $scope = Plugin::getInstance()->collectors->scope($rule->scope);

        $plan = new ErasurePlan();
        $plan->mode = $rule->mode === RetentionRule::MODE_ERASE ? Settings::MODE_ERASE : Settings::MODE_ANONYMISE;
        $plan->builtAt = new DateTime();

        if ($scope === null) {
            $plan->errors[] = Craft::t('lock', 'The rule “{rule}” points at “{scope}”, which no installed source offers any more.', [
                'rule' => $rule->label,
                'scope' => $rule->scope,
            ]);

            return $plan;
        }

        $collector = Plugin::getInstance()->collectors->get($scope->source);

        if ($collector === null || !$collector->isAvailable()) {
            $plan->errors[] = Craft::t('lock', '“{source}” is not available, so this rule cannot run.', ['source' => $scope->source]);

            return $plan;
        }

        $records = $collector->stale($scope, $rule->cutoff($now), $rule->limit);
        $plan->targets = $this->targetsFor($records, $rule, $collector);

        return $plan;
    }

    /**
     * Turns found records into decided targets, applying the holds.
     *
     * @param DataRecord[] $records
     * @return ErasureTarget[]
     */
    private function targetsFor(array $records, RetentionRule $rule, \justinholtweb\lock\collectors\CollectorInterface $collector): array
    {
        $mode = $rule->mode === RetentionRule::MODE_ERASE ? Settings::MODE_ERASE : Settings::MODE_ANONYMISE;
        $holds = Plugin::getInstance()->holds;
        $targets = [];

        // Held subjects are memoised per run. A sweep over ten thousand orders belonging to a few
        // hundred people would otherwise ask the same question of the database ten thousand times.
        $blocks = [];

        foreach ($records as $record) {
            $subject = $collector->subjectFor($record);
            $target = $collector->planFor($record, $mode, $subject);
            $target->subjectEmail = $subject->normalisedEmail();

            if ($rule->mode === RetentionRule::MODE_REPORT) {
                $target->action = ErasureTarget::ACTION_SKIP;
                $target->reason = Craft::t('lock', 'This rule is set to report only.');
                $targets[] = $target;
                continue;
            }

            if (!$target->isSkipped()) {
                $hash = $subject->emailHash();
                $blocks[$hash] ??= $holds->blockFor($subject) ?? false;

                if ($blocks[$hash] !== false) {
                    $target->action = ErasureTarget::ACTION_SKIP;
                    $target->reason = $blocks[$hash];
                }
            }

            $targets[] = $target;
        }

        return $targets;
    }

    /**
     * Runs one rule.
     *
     * The first run of a rule is forced to be a dry run when `retentionDryRunFirst` is on, which
     * it is by default — the first thing a new rule that deletes things should do is tell you what
     * it would have deleted.
     */
    public function run(RetentionRule $rule, bool $dryRun = false, bool $scheduled = false): ErasureOutcome
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$dryRun && $settings->retentionDryRunFirst && !$this->hasEverRun($rule->key)) {
            $dryRun = true;

            Craft::info("Lock: first run of retention rule “{$rule->key}” forced to a dry run.", Plugin::LOG_CATEGORY);
        }

        $plan = $this->preview($rule);

        return Plugin::getInstance()->erasure->run($plan, $dryRun, null, $scheduled, $rule->key);
    }

    /**
     * Runs everything that is due. Returns one outcome per rule, keyed by rule key.
     *
     * @return array<string, ErasureOutcome>
     */
    public function runDue(bool $dryRun = false, ?DateTime $now = null): array
    {
        $outcomes = [];

        foreach ($this->enabledRules() as $key => $rule) {
            if (!$this->isDue($rule, $now)) {
                continue;
            }

            $outcomes[$key] = $this->run($rule, $dryRun, true);
        }

        if ($outcomes !== []) {
            Plugin::getInstance()->activity->log(
                ActivityRecord::CATEGORY_RETENTION,
                'retention.swept',
                Craft::t('lock', '{n} retention rules ran.', ['n' => count($outcomes)]),
            );
        }

        return $outcomes;
    }

    public function hasEverRun(string $ruleKey): bool
    {
        return RunRecord::find()->where(['ruleKey' => $ruleKey, 'dryRun' => false])->exists();
    }

    public function lastRun(string $ruleKey, bool $includeDryRuns = false): ?DateTime
    {
        $query = (new Query())
            ->select(['dateCreated'])
            ->from([RunRecord::tableName()])
            ->where(['ruleKey' => $ruleKey])
            ->orderBy(['dateCreated' => SORT_DESC]);

        if (!$includeDryRuns) {
            $query->andWhere(['dryRun' => false]);
        }

        $date = $query->scalar();

        if (!is_string($date)) {
            return null;
        }

        return DateTimeHelper::toDateTime($date) ?: null;
    }

    /**
     * Whether a rule is owed a run.
     *
     * Occurrence-based rather than "has a day passed since the last run", so a 3am purge stays at
     * 3am instead of drifting an hour later every night until it lands in the working day.
     */
    public function isDue(RetentionRule $rule, ?DateTime $now = null): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $now ??= new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));

        [$hour] = array_map('intval', explode(':', $settings->scheduleTime));

        return Cadence::isDue(
            $settings->scheduleFrequency,
            $hour,
            1,
            1,
            $this->lastRun($rule->key, true),
            $now,
        );
    }

    public function nextRun(?DateTime $now = null): DateTime
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $now ??= new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));

        [$hour] = array_map('intval', explode(':', $settings->scheduleTime));

        return DateTime::createFromInterface(Cadence::nextOccurrence($settings->scheduleFrequency, $hour, 1, 1, $now));
    }

    /**
     * @return RunRecord[]
     */
    public function history(int $limit = 100, ?string $ruleKey = null): array
    {
        $query = RunRecord::find()
            ->where(['type' => [RunRecord::TYPE_RETENTION, RunRecord::TYPE_ERASURE]])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if ($ruleKey !== null) {
            $query->andWhere(['ruleKey' => $ruleKey]);
        }

        return $query->all();
    }

    /**
     * A guess at how much personal data the site is sitting on that no rule covers.
     *
     * Not a number of rows — a list of scopes with nothing pointed at them. The useful output of a
     * retention screen is "these four kinds of data have no expiry at all", which is a sentence an
     * organisation can act on.
     *
     * @return \justinholtweb\lock\models\RetentionScope[]
     */
    public function uncoveredScopes(): array
    {
        $covered = [];

        foreach ($this->rules() as $rule) {
            $covered[$rule->scope] = true;
        }

        return array_filter(
            Plugin::getInstance()->collectors->scopes(),
            static fn($scope) => !isset($covered[$scope->key]),
        );
    }
}
