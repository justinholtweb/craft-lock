<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\RetentionScope;
use justinholtweb\lock\models\Subject;

/**
 * Freeform submissions.
 *
 * Freeform gives each form field its own column on `freeform_submissions`, added as the form is
 * built — so there is no single content column to search and no fixed list of columns to search
 * either. The columns are read from the schema at query time and every text-shaped one is
 * searched, which is the only way to be complete on a table whose shape is different on every
 * site.
 */
class FreeformCollector extends BaseCollector
{
    private const TABLE = '{{%freeform_submissions}}';

    /** Columns that belong to Freeform's own bookkeeping rather than to a form field. */
    private const RESERVED = [
        'id', 'incrementalId', 'userId', 'statusId', 'formId', 'token', 'ip', 'sourceUrl',
        'isSpam', 'isHidden', 'idempotencyKey', 'requestId', 'dateCreated', 'dateUpdated', 'uid',
    ];

    public static function handle(): string
    {
        return 'freeform';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Form submissions (Freeform)');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Freeform submissions with the address in any answer column.');
    }

    public function isAvailable(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('freeform')
            && Craft::$app->getDb()->tableExists(self::TABLE);
    }

    public function unavailableReason(): ?string
    {
        return Craft::t('lock', 'Freeform is not installed.');
    }

    /** @return string[] */
    private function searchableColumns(): array
    {
        $schema = Craft::$app->getDb()->getTableSchema(self::TABLE);

        if ($schema === null) {
            return [];
        }

        $columns = [];

        foreach ($schema->columns as $name => $column) {
            if (in_array($name, self::RESERVED, true)) {
                continue;
            }

            if (in_array($column->type, ['string', 'text', 'char'], true)) {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $columns = $this->searchableColumns();
        $email = $subject->normalisedEmail();
        $subject->resolve();

        $conditions = ['or'];

        foreach ($columns as $column) {
            $conditions[] = ['like', $column, $email];
        }

        if ($subject->userId !== null) {
            $conditions[] = ['userId' => $subject->userId];
        }

        if (count($conditions) === 1) {
            $bundle->notes[] = Craft::t('lock', 'No Freeform forms have any fields yet, so there is nothing to search.');

            return [];
        }

        $rows = (new Query())
            ->from([self::TABLE])
            ->where($conditions)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(500)
            ->all();

        $forms = (new Query())->select(['id', 'name'])->from(['{{%freeform_forms}}'])->pairs();
        $records = [];

        foreach ($rows as $row) {
            $data = ['Form' => $forms[$row['formId']] ?? $row['formId'], 'Submitted' => $row['dateCreated'], 'IP' => $row['ip'] ?? null];

            foreach ($columns as $column) {
                if (($row[$column] ?? null) !== null && $row[$column] !== '') {
                    $data[$this->humanise($column)] = $row[$column];
                }
            }

            $record = $this->record("submission:{$row['id']}", Craft::t('lock', '“{form}” submitted {date}', [
                'form' => $forms[$row['formId']] ?? Craft::t('lock', 'Form'),
                'date' => substr((string)$row['dateCreated'], 0, 10),
            ]), $data);
            $record->categories = [DataRecord::CATEGORY_CONTACT, DataRecord::CATEGORY_CONTENT];
            $records[] = $record;
        }

        return $records;
    }

    /** Freeform names its columns after field handles; a handle is not a label. */
    private function humanise(string $column): string
    {
        return ucfirst(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $column)) ?? $column));
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        $id = $this->keyId($target->key);

        if ($target->action === ErasureTarget::ACTION_ERASE) {
            // Deleted through the element layer, not the table: a Freeform submission is a Craft
            // element, and deleting only its row leaves the element behind pointing at nothing.
            $element = Craft::$app->getElements()->getElementById($id);

            if ($element !== null) {
                Craft::$app->getElements()->deleteElement($element, true);

                return;
            }

            Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['id' => $id])->execute();

            return;
        }

        $replacement = $subject->pseudonym() . '@' . ($this->settings()->anonymousDomain ?: 'anonymised.invalid');
        $values = ['ip' => null, 'userId' => null];

        $row = (new Query())->from([self::TABLE])->where(['id' => $id])->one();

        if ($row === null) {
            return;
        }

        foreach ($this->searchableColumns() as $column) {
            if (is_string($row[$column] ?? null) && stripos($row[$column], $subject->normalisedEmail()) !== false) {
                $values[$column] = str_ireplace($subject->normalisedEmail(), $replacement, $row[$column]);
            }
        }

        Craft::$app->getDb()->createCommand()->update(self::TABLE, $values, ['id' => $id])->execute();
    }

    public function scopes(): array
    {
        $scope = new RetentionScope();
        $scope->key = 'freeform:submissions';
        $scope->source = self::handle();
        $scope->label = Craft::t('lock', 'Freeform submissions');
        $scope->description = Craft::t('lock', 'Freeform submissions older than the period.');
        $scope->measuredFrom = Craft::t('lock', 'submission date');
        $scope->suggestedMonths = 24;

        return [$scope];
    }

    public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array
    {
        if ($scope->key !== 'freeform:submissions' || !$this->isAvailable()) {
            return [];
        }

        $rows = (new Query())
            ->select(['id', 'formId', 'dateCreated'])
            ->from([self::TABLE])
            ->where(['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->limit($limit)
            ->all();

        $records = [];

        foreach ($rows as $row) {
            $record = $this->record("submission:{$row['id']}", Craft::t('lock', 'Submission from {date}', ['date' => substr((string)$row['dateCreated'], 0, 10)]), []);
            $record->categories = [DataRecord::CATEGORY_CONTACT];
            $records[] = $record;
        }

        return $records;
    }
}
