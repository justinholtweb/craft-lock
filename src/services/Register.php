<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use justinholtweb\lock\models\ProcessingActivity;
use justinholtweb\lock\models\RetentionRule;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use justinholtweb\lock\records\ProcessingRecord;

/**
 * The record of processing activities — Article 30.
 *
 * Every controller of any size has to keep one, almost nobody does, and the ones who do keep it in
 * a spreadsheet that stopped matching the site two redesigns ago. Keeping it *in* the site is the
 * only way it stays true, and it lets Lock do the thing a spreadsheet cannot: cross-check the
 * register against what the site demonstrably holds.
 *
 * {@see gaps()} is that cross-check. It is deliberately blunt — a source that holds personal data
 * and appears in no register entry is a gap, and saying so plainly is more useful than a score.
 */
class Register extends Component
{
    /** @return ProcessingActivity[] */
    public function all(bool $enabledOnly = false): array
    {
        $query = ProcessingRecord::find()->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC]);

        if ($enabledOnly) {
            $query->andWhere(['enabled' => true]);
        }

        return array_map(fn(ProcessingRecord $r) => $this->toModel($r), $query->all());
    }

    public function get(int $id): ?ProcessingActivity
    {
        $record = ProcessingRecord::findOne($id);

        return $record !== null ? $this->toModel($record) : null;
    }

    public function save(ProcessingActivity $activity): bool
    {
        if (!$activity->validate()) {
            return false;
        }

        $record = $activity->id !== null ? ProcessingRecord::findOne($activity->id) : new ProcessingRecord();

        if ($record === null) {
            return false;
        }

        $isNew = $record->getIsNewRecord();

        $record->name = $activity->name;
        $record->purpose = $activity->purpose;
        $record->basis = $activity->basis;
        $record->balancing = $activity->balancing;
        $record->dataCategories = $activity->dataCategories;
        $record->subjectCategories = $activity->subjectCategories;
        $record->recipients = $activity->recipients;
        $record->transfers = $activity->transfers;
        $record->retention = $activity->retention;
        $record->safeguards = $activity->safeguards;
        $record->systems = $activity->systems;
        $record->owner = $activity->owner;
        $record->enabled = $activity->enabled;
        $record->sortOrder = $activity->sortOrder;
        $record->reviewedAt = $activity->reviewedAt !== null ? Db::prepareDateForDb($activity->reviewedAt) : null;

        if (!$record->save(false)) {
            return false;
        }

        $activity->id = $record->id;
        $activity->uid = $record->uid;

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_ADMIN,
            $isNew ? 'register.added' : 'register.updated',
            Craft::t('lock', 'Processing activity “{name}” {verb}.', [
                'name' => $activity->name,
                'verb' => $isNew ? Craft::t('lock', 'added') : Craft::t('lock', 'updated'),
            ]),
        );

        return true;
    }

    public function delete(int $id): bool
    {
        $record = ProcessingRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_ADMIN,
            'register.deleted',
            Craft::t('lock', 'Processing activity “{name}” was removed from the register.', ['name' => $record->name]),
        );

        return $record->delete() !== false;
    }

    /** Marks the whole register as reviewed today, which is the act an auditor asks for evidence of. */
    public function markReviewed(): int
    {
        return (int)Craft::$app->getDb()->createCommand()
            ->update(ProcessingRecord::tableName(), ['reviewedAt' => Db::prepareDateForDb(new DateTime())])
            ->execute();
    }

    /**
     * What the register is missing, in plain sentences.
     *
     * Three kinds of gap: an entry with a required Article 30 field empty, a source holding
     * personal data that no entry accounts for, and a retention rule whose period is not written
     * down anywhere in the register — the last being the one that quietly makes a site's stated
     * retention policy differ from what its own cron job does.
     *
     * @return string[]
     */
    public function gaps(): array
    {
        $gaps = [];
        $entries = $this->all();

        if ($entries === []) {
            return [Craft::t('lock', 'The register is empty. Article 30 asks for one entry per purpose you process personal data for.')];
        }

        foreach ($entries as $entry) {
            foreach ($entry->defects() as $defect) {
                $gaps[] = "$entry->name: $defect";
            }
        }

        $accounted = [];

        foreach ($entries as $entry) {
            foreach ($entry->systems as $system) {
                $accounted[$system] = true;
            }
        }

        foreach (Plugin::getInstance()->collectors->enabled() as $collector) {
            if (!$collector->isAvailable() || isset($accounted[$collector::handle()])) {
                continue;
            }

            $gaps[] = Craft::t('lock', '“{source}” holds personal data and is not named in any register entry.', [
                'source' => $collector->label(),
            ]);
        }

        /** @var \justinholtweb\lock\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        foreach ($settings->retentionRules as $row) {
            $rule = RetentionRule::fromArray($row);

            if (!$rule->enabled || $rule->justification !== '') {
                continue;
            }

            $gaps[] = Craft::t('lock', 'The retention rule “{rule}” deletes data on a timer with no justification written down.', [
                'rule' => $rule->label,
            ]);
        }

        return $gaps;
    }

    /**
     * The register as an array, for the printable report and for handing to a regulator.
     *
     * The retention rules are included alongside the entries on purpose: a register that states a
     * retention period and a site that enforces a different one is the discrepancy an inspection
     * finds, and putting both on one page makes it impossible to miss.
     */
    public function report(): array
    {
        /** @var \justinholtweb\lock\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return [
            'controller' => [
                'name' => $settings->organisationName ?: Craft::$app->getSystemName(),
                'contact' => $settings->contactName,
                'email' => $settings->resolvedContactEmail(),
                'policy' => $settings->policyUrl,
            ],
            'generated' => (new DateTime())->format(DateTime::ATOM),
            'activities' => array_map(static fn(ProcessingActivity $a) => [
                'name' => $a->name,
                'purpose' => $a->purpose,
                'lawful_basis' => $a->basisLabel(),
                'balancing_test' => $a->balancing,
                'data_categories' => $a->dataCategories,
                'subject_categories' => $a->subjectCategories,
                'recipients' => $a->recipients,
                'transfers' => $a->transfers,
                'retention' => $a->retention,
                'security' => $a->safeguards,
                'systems' => $a->systems,
                'owner' => $a->owner,
                'last_reviewed' => $a->reviewedAt?->format('Y-m-d'),
            ], $this->all(true)),
            'retention_rules' => array_map(static function(array $row) {
                $rule = RetentionRule::fromArray($row);

                return [
                    'rule' => $rule->label,
                    'applies_to' => $rule->scope,
                    'period' => $rule->periodLabel(),
                    'treatment' => $rule->modeLabel(),
                    'enforced' => $rule->enabled,
                    'justification' => $rule->justification,
                ];
            }, $settings->retentionRules),
            'gaps' => $this->gaps(),
        ];
    }

    private function toModel(ProcessingRecord $record): ProcessingActivity
    {
        $activity = new ProcessingActivity();
        $activity->id = $record->id;
        $activity->uid = $record->uid;
        $activity->name = $record->name;
        $activity->purpose = (string)$record->purpose;
        $activity->basis = $record->basis;
        $activity->balancing = $record->balancing;
        $activity->dataCategories = is_array($record->dataCategories) ? $record->dataCategories : [];
        $activity->subjectCategories = is_array($record->subjectCategories) ? $record->subjectCategories : [];
        $activity->recipients = is_array($record->recipients) ? $record->recipients : [];
        $activity->transfers = $record->transfers;
        $activity->retention = (string)$record->retention;
        $activity->safeguards = $record->safeguards;
        $activity->systems = is_array($record->systems) ? $record->systems : [];
        $activity->owner = $record->owner;
        $activity->enabled = (bool)$record->enabled;
        $activity->sortOrder = (int)$record->sortOrder;
        $activity->reviewedAt = $record->reviewedAt !== null ? (DateTimeHelper::toDateTime($record->reviewedAt) ?: null) : null;
        $activity->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;

        return $activity;
    }
}
