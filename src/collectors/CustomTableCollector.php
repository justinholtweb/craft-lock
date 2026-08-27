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
 * Tables Lock has never heard of.
 *
 * The collector that makes the answer *complete* rather than merely thorough. Every real Craft
 * site has a table somebody wrote — a mailing list, a module's enquiry log, an old import — and a
 * disclosure that covers thirteen known sources and silently omits the fourteenth is wrong in
 * precisely the way that matters.
 *
 * Configured, not coded: name the table, name the column holding the address, say whether rows
 * there may be deleted, anonymised, or only read. Identifiers are validated as plain identifiers
 * when the setting is saved, so nothing arriving here can be anything but a name.
 */
class CustomTableCollector extends BaseCollector
{
    public static function handle(): string
    {
        return 'custom';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Other tables');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Tables named in Lock’s settings — modules, old imports, anything Lock does not know about on its own.');
    }

    public function isAvailable(): bool
    {
        return $this->definitions() !== [];
    }

    public function unavailableReason(): ?string
    {
        return Craft::t('lock', 'No extra tables have been configured.');
    }

    /** @return array<int, array<string, string>> */
    private function definitions(): array
    {
        $definitions = [];

        foreach ($this->settings()->customTables as $row) {
            $table = $this->identifier($row['table'] ?? '');

            if ($table === null) {
                continue;
            }

            $definitions[] = [
                'table' => $table,
                'label' => trim((string)($row['label'] ?? '')) ?: $table,
                'emailColumn' => $this->identifier($row['emailColumn'] ?? ''),
                'userColumn' => $this->identifier($row['userColumn'] ?? ''),
                'dateColumn' => $this->identifier($row['dateColumn'] ?? ''),
                'mode' => in_array($row['mode'] ?? '', ['erase', 'anonymise', 'read'], true) ? (string)$row['mode'] : 'read',
            ];
        }

        return $definitions;
    }

    /**
     * A second gate on the same value the settings validator already checked.
     *
     * Project config is a file, and a file is editable by hand. One validation on the way in is
     * a policy; validation again at the point the string reaches a query is what makes it true.
     */
    private function identifier(mixed $value): ?string
    {
        $value = trim((string)$value);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1 ? $value : null;
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $subject->resolve();
        $records = [];

        foreach ($this->definitions() as $definition) {
            $table = "{{%{$definition['table']}}}";

            if (!Craft::$app->getDb()->tableExists($table)) {
                $bundle->errors[] = Craft::t('lock', 'The table “{table}” is configured but does not exist.', ['table' => $definition['table']]);
                continue;
            }

            $conditions = ['or'];

            if ($definition['emailColumn'] !== null) {
                $conditions[] = [$definition['emailColumn'] => $subject->normalisedEmail()];
            }

            if ($definition['userColumn'] !== null && $subject->userId !== null) {
                $conditions[] = [$definition['userColumn'] => $subject->userId];
            }

            if (count($conditions) === 1) {
                continue;
            }

            $rows = (new Query())->from([$table])->where($conditions)->limit(200)->all();

            foreach ($rows as $row) {
                $id = $row['id'] ?? null;

                if ($id === null) {
                    $bundle->errors[] = Craft::t('lock', '“{table}” has no id column, so its rows can be shown but not acted on.', ['table' => $definition['table']]);
                }

                $record = $this->record(
                    "{$definition['table']}:" . ($id ?? '0'),
                    Craft::t('lock', '{label} row {id}', ['label' => $definition['label'], 'id' => $id ?? '?']),
                    $this->readable($row),
                );
                $record->categories = [DataRecord::CATEGORY_CONTACT];
                $record->erasable = $id !== null && $definition['mode'] === 'erase';
                $record->anonymisable = $id !== null && $definition['mode'] !== 'read' && $definition['emailColumn'] !== null;

                if ($definition['mode'] === 'read') {
                    $record->retainReason = Craft::t('lock', 'This table is configured as read-only in Lock’s settings — it is disclosed, and nothing is changed in it.');
                }

                if ($definition['dateColumn'] !== null && isset($row[$definition['dateColumn']])) {
                    $record->dateCreated = $this->toDate($row[$definition['dateColumn']]);
                }

                $records[] = $record;
            }
        }

        return $records;
    }

    /** @return array<string, mixed> */
    private function readable(array $row): array
    {
        $data = [];

        foreach ($row as $column => $value) {
            if ($value === null || $value === '' || in_array($column, ['uid'], true)) {
                continue;
            }

            $data[ucfirst(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', (string)$column) ?? $column))] = is_scalar($value) ? $value : json_encode($value);
        }

        return $data;
    }

    private function toDate(mixed $value): ?DateTime
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The definition a record key belongs to, re-validated rather than trusted. */
    private function definitionFor(string $key): ?array
    {
        $table = explode(':', $this->keyBody($key), 2)[0] ?? '';

        foreach ($this->definitions() as $definition) {
            if ($definition['table'] === $table) {
                return $definition;
            }
        }

        return null;
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        $definition = $this->definitionFor($target->key);

        if ($definition === null || $definition['mode'] === 'read') {
            return;
        }

        $parts = explode(':', $this->keyBody($target->key), 2);
        $id = (int)($parts[1] ?? 0);

        if ($id === 0) {
            return;
        }

        $table = "{{%{$definition['table']}}}";

        if ($target->action === ErasureTarget::ACTION_ERASE) {
            Craft::$app->getDb()->createCommand()->delete($table, ['id' => $id])->execute();

            return;
        }

        $values = [];

        if ($definition['emailColumn'] !== null) {
            $values[$definition['emailColumn']] = $subject->pseudonym() . '@' . ($this->settings()->anonymousDomain ?: 'anonymised.invalid');
        }

        if ($definition['userColumn'] !== null) {
            $values[$definition['userColumn']] = null;
        }

        if ($values !== []) {
            Craft::$app->getDb()->createCommand()->update($table, $values, ['id' => $id])->execute();
        }
    }

    public function scopes(): array
    {
        $scopes = [];

        foreach ($this->definitions() as $definition) {
            if ($definition['dateColumn'] === null || $definition['mode'] === 'read') {
                continue;
            }

            $scope = new RetentionScope();
            $scope->key = "custom:{$definition['table']}";
            $scope->source = self::handle();
            $scope->label = $definition['label'];
            $scope->description = Craft::t('lock', 'Rows in {table} older than the period.', ['table' => $definition['table']]);
            $scope->measuredFrom = $definition['dateColumn'];
            $scope->erasable = $definition['mode'] === 'erase';
            $scope->anonymisable = $definition['emailColumn'] !== null;
            $scopes[] = $scope;
        }

        return $scopes;
    }

    public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array
    {
        $table = substr($scope->key, strlen('custom:'));

        foreach ($this->definitions() as $definition) {
            if ($definition['table'] !== $table || $definition['dateColumn'] === null) {
                continue;
            }

            $rows = (new Query())
                ->from(["{{%{$definition['table']}}}"])
                ->where(['<', $definition['dateColumn'], Db::prepareDateForDb($cutoff)])
                ->orderBy([$definition['dateColumn'] => SORT_ASC])
                ->limit($limit)
                ->all();

            $records = [];

            foreach ($rows as $row) {
                if (!isset($row['id'])) {
                    continue;
                }

                $record = $this->record("{$definition['table']}:{$row['id']}", Craft::t('lock', '{label} row {id}', [
                    'label' => $definition['label'],
                    'id' => $row['id'],
                ]), $this->readable($row));
                $record->erasable = $definition['mode'] === 'erase';
                $record->anonymisable = $definition['emailColumn'] !== null;
                $records[] = $record;
            }

            return $records;
        }

        return [];
    }
}
