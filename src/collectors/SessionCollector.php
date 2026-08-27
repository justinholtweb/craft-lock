<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use DateTime;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\RetentionScope;
use justinholtweb\lock\models\Subject;

/**
 * The technical residue of having been signed in: sessions, sign-in history, second factors.
 *
 * Small, unglamorous, and the part most disclosures forget — which is a shame, because it is
 * often the most sensitive thing on the list. A sign-in history is a record of when a person was
 * at their computer and what address they were at, and a passkey called "Sam's work MacBook" is
 * both a device inventory and a name.
 *
 * Craft's `tokens` table is deliberately absent from this list. It carries no user ID in Craft 5,
 * so a token cannot honestly be attributed to anybody — claiming otherwise in a disclosure would
 * be worse than omitting it.
 */
class SessionCollector extends BaseCollector
{
    public static function handle(): string
    {
        return 'session';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Sign-in records');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Stay-signed-in sessions, sign-in and failed-sign-in history, two-factor secrets and passkeys.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $subject->resolve();

        if ($subject->userId === null) {
            return [];
        }

        return array_merge(
            $this->sessions($subject->userId),
            $this->history($subject->userId),
            $this->factors($subject->userId),
        );
    }

    /** @return DataRecord[] */
    private function sessions(int $userId): array
    {
        $rows = (new Query())
            ->select(['id', 'dateCreated', 'dateUpdated'])
            ->from([Table::SESSIONS])
            ->where(['userId' => $userId])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit(200)
            ->all();

        $records = [];

        foreach ($rows as $row) {
            $record = $this->record("session:{$row['id']}", Craft::t('lock', 'Signed-in session'), [
                'Started' => $row['dateCreated'],
                'Last seen' => $row['dateUpdated'],
            ]);
            $record->categories = [DataRecord::CATEGORY_TECHNICAL];
            $record->anonymisable = false;
            $record->basis = Craft::t('lock', 'Keeps the person signed in between visits.');
            $records[] = $record;
        }

        return $records;
    }

    /**
     * The sign-in history kept on the user row itself.
     *
     * One record rather than several, because it is one row — and it is anonymisable rather than
     * erasable for the same reason: the row is the account, and the account is somebody else's
     * collector to delete.
     *
     * @return DataRecord[]
     */
    private function history(int $userId): array
    {
        $row = (new Query())
            ->select([
                'lastLoginDate', 'lastLoginAttemptIp', 'invalidLoginCount',
                'lastInvalidLoginDate', 'lockoutDate', 'lastPasswordChangeDate',
            ])
            ->from([Table::USERS])
            ->where(['id' => $userId])
            ->one();

        if ($row === null) {
            return [];
        }

        $data = [
            'Last signed in' => $row['lastLoginDate'],
            'From address' => $row['lastLoginAttemptIp'],
            'Failed attempts' => $row['invalidLoginCount'],
            'Last failed attempt' => $row['lastInvalidLoginDate'],
            'Locked out' => $row['lockoutDate'],
            'Password last changed' => $row['lastPasswordChangeDate'],
        ];

        if (array_filter($data, static fn($v) => $v !== null && $v !== '') === []) {
            return [];
        }

        $record = $this->record("history:$userId", Craft::t('lock', 'Sign-in history'), $data);
        $record->categories = [DataRecord::CATEGORY_TECHNICAL, DataRecord::CATEGORY_BEHAVIOUR];
        $record->erasable = false;
        $record->basis = Craft::t('lock', 'Detecting and blocking attempts to break into the account.');

        return [$record];
    }

    /** @return DataRecord[] */
    private function factors(int $userId): array
    {
        $records = [];

        if (Craft::$app->getDb()->tableExists('{{%authenticator}}')) {
            $has = (new Query())->from(['{{%authenticator}}'])->where(['userId' => $userId])->exists();

            if ($has) {
                // The shared secret itself is never put in a disclosure. Handing somebody a copy
                // of their own TOTP seed by email is a worse outcome than not disclosing it, and
                // "you have two-factor authentication set up" is the disclosable fact.
                $record = $this->record("2fa:$userId", Craft::t('lock', 'Two-factor authentication'), [
                    'Set up' => Craft::t('lock', 'yes'),
                    'Secret' => Craft::t('lock', 'held, and deliberately not reproduced here'),
                ]);
                $record->categories = [DataRecord::CATEGORY_TECHNICAL];
                $record->anonymisable = false;
                $records[] = $record;
            }
        }

        if (Craft::$app->getDb()->tableExists('{{%webauthn}}')) {
            $rows = (new Query())
                ->select(['id', 'credentialName', 'dateLastUsed', 'dateCreated'])
                ->from(['{{%webauthn}}'])
                ->where(['userId' => $userId])
                ->limit(50)
                ->all();

            foreach ($rows as $row) {
                $record = $this->record("passkey:{$row['id']}", Craft::t('lock', 'Passkey “{name}”', [
                    'name' => $row['credentialName'] ?: Craft::t('lock', 'unnamed'),
                ]), [
                    'Name given to it' => $row['credentialName'],
                    'Registered' => $row['dateCreated'],
                    'Last used' => $row['dateLastUsed'],
                ]);
                $record->categories = [DataRecord::CATEGORY_TECHNICAL, DataRecord::CATEGORY_IDENTITY];
                $record->anonymisable = false;
                $records[] = $record;
            }
        }

        return $records;
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        $body = $this->keyBody($target->key);
        [$kind, $id] = array_pad(explode(':', $body, 2), 2, null);
        $id = (int)$id;
        $db = Craft::$app->getDb();

        match ($kind) {
            'session' => $db->createCommand()->delete(Table::SESSIONS, ['id' => $id])->execute(),
            '2fa' => $db->createCommand()->delete('{{%authenticator}}', ['userId' => $id])->execute(),
            'passkey' => $db->createCommand()->delete('{{%webauthn}}', ['id' => $id])->execute(),
            'history' => $db->createCommand()->update(Table::USERS, [
                'lastLoginAttemptIp' => null,
                'lastInvalidLoginDate' => null,
                'invalidLoginCount' => null,
                'invalidLoginWindowStart' => null,
                'lockoutDate' => null,
            ], ['id' => $id])->execute(),
            default => null,
        };
    }

    public function scopes(): array
    {
        $scope = new RetentionScope();
        $scope->key = 'session:stale';
        $scope->source = self::handle();
        $scope->label = Craft::t('lock', 'Idle sessions');
        $scope->description = Craft::t('lock', 'Stay-signed-in sessions nobody has used for the period. Craft prunes these by its own rules; this is for sites that want a shorter one.');
        $scope->measuredFrom = Craft::t('lock', 'last use');
        $scope->anonymisable = false;
        $scope->suggestedMonths = 3;

        return [$scope];
    }

    public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array
    {
        if ($scope->key !== 'session:stale') {
            return [];
        }

        $rows = (new Query())
            ->select(['id', 'userId', 'dateUpdated'])
            ->from([Table::SESSIONS])
            ->where(['<', 'dateUpdated', Db::prepareDateForDb($cutoff)])
            ->orderBy(['dateUpdated' => SORT_ASC])
            ->limit($limit)
            ->all();

        $records = [];

        foreach ($rows as $row) {
            $record = $this->record("session:{$row['id']}", Craft::t('lock', 'Session last used {date}', ['date' => $row['dateUpdated']]), []);
            $record->categories = [DataRecord::CATEGORY_TECHNICAL];
            $record->anonymisable = false;
            $records[] = $record;
        }

        return $records;
    }
}
