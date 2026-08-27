<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\lock\helpers\Readable;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\RetentionScope;
use justinholtweb\lock\models\Subject;
use Throwable;

/**
 * The Craft user account, its custom fields, its preferences and its photo.
 *
 * Anonymising an account here means four things, and all four are needed for it to be true:
 * the identifiers are overwritten, the photo is deleted, the custom fields are emptied, and the
 * account is locked out. An "anonymised" account that still accepts the old password is still
 * that person's account, and any of the four alone leaves a way back to the name.
 */
class UserCollector extends BaseCollector
{
    public static function handle(): string
    {
        return 'user';
    }

    public function label(): string
    {
        return Craft::t('lock', 'User account');
    }

    public function description(): string
    {
        return Craft::t('lock', 'The Craft account matching the address, with its custom fields, groups, photo and preferences.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $user = $subject->getUser();

        if ($user === null) {
            $bundle->notes[] = Craft::t('lock', 'No account exists for this address.');

            return [];
        }

        $data = [
            'Username' => $user->username,
            'Email' => $user->email,
            'First name' => $user->firstName,
            'Last name' => $user->lastName,
            'Status' => $user->getStatus(),
            'Groups' => implode(', ', array_map(static fn($g) => $g->name, $user->getGroups())),
            'Admin' => $user->admin ? 'yes' : 'no',
            'Registered' => $user->dateCreated?->format('Y-m-d H:i:s'),
            'Last login' => $user->lastLoginDate?->format('Y-m-d H:i:s'),
            'Last password change' => $user->lastPasswordChangeDate?->format('Y-m-d H:i:s'),
            'Preferred language' => $user->getPreference('language'),
            'Time zone' => $user->getPreference('timeZone'),
        ];

        foreach ($this->customFieldValues($user) as $label => $value) {
            $data[$label] = $value;
        }

        $record = $this->record("user:$user->id", Craft::t('lock', 'Account “{name}”', ['name' => $user->username]), $data);
        $record->kind = DataRecord::KIND_ELEMENT;
        $record->categories = [DataRecord::CATEGORY_IDENTITY, DataRecord::CATEGORY_CONTACT, DataRecord::CATEGORY_ACCOUNT];
        $record->dateCreated = $user->dateCreated;
        $record->cpUrl = $user->getCpEditUrl();
        $record->basis = Craft::t('lock', 'Needed to operate the account the person asked for.');

        // An administrator's account is not erased through a subject request. The request may be
        // perfectly genuine; the point is that an intake form that can disable the site's admin
        // account is a way in, and a real employee erasure is a deliberate act done knowingly on
        // the Users screen.
        if ($user->admin) {
            $record->retainReason = Craft::t('lock', 'This is an administrator account. Remove it deliberately from Users — a subject request will not do it.');
        }

        $records = [$record];

        if ($user->photoId !== null) {
            $photo = $user->getPhoto();

            if ($photo !== null) {
                $photoRecord = $this->record("photo:$photo->id", Craft::t('lock', 'Profile photo'), [
                    'Filename' => $photo->filename,
                    'Uploaded' => $photo->dateCreated?->format('Y-m-d'),
                ]);
                $photoRecord->kind = DataRecord::KIND_FILE;
                $photoRecord->categories = [DataRecord::CATEGORY_IDENTITY];
                $photoRecord->dateCreated = $photo->dateCreated;
                $photoRecord->anonymisable = false;
                $records[] = $photoRecord;
            }
        }

        return $records;
    }

    /** @return array<string, mixed> */
    private function customFieldValues(User $user): array
    {
        $values = [];
        $layout = $user->getFieldLayout();

        if ($layout === null) {
            return $values;
        }

        foreach ($layout->getCustomFields() as $field) {
            try {
                $value = Readable::value($user->getFieldValue($field->handle));
            } catch (Throwable) {
                continue;
            }

            if (Readable::isEmpty($value)) {
                continue;
            }

            $values[$field->name] = is_array($value) ? Readable::flatten($value) : $value;
        }

        return $values;
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        $body = $this->keyBody($target->key);

        if (str_starts_with($body, 'photo:')) {
            $this->applyToPhoto((int)substr($body, 6));

            return;
        }

        $user = Craft::$app->getUsers()->getUserById((int)$body);

        if ($user === null) {
            return;
        }

        if ($target->action === ErasureTarget::ACTION_ERASE) {
            Craft::$app->getElements()->deleteElement($user, true);

            return;
        }

        $this->anonymise($user, $subject);
    }

    private function applyToPhoto(int $assetId): void
    {
        $photo = Craft::$app->getAssets()->getAssetById($assetId);

        if ($photo !== null) {
            Craft::$app->getElements()->deleteElement($photo, true);
        }
    }

    /**
     * Overwrite the person out of the account without deleting the account.
     *
     * Saved with validation off on purpose. A four-year-old account routinely fails a field that
     * was made required last year, and an anonymisation that refuses to run because of an unrelated
     * validation rule is an anonymisation that does not happen.
     */
    private function anonymise(User $user, Subject $subject): void
    {
        $pseudonym = $subject->pseudonym();
        $domain = $this->settings()->anonymousDomain ?: 'anonymised.invalid';

        if ($user->photoId !== null) {
            $photo = $user->getPhoto();

            if ($photo instanceof Asset) {
                Craft::$app->getElements()->deleteElement($photo, true);
            }

            $user->photoId = null;
        }

        $user->username = $pseudonym;
        $user->email = "$pseudonym@$domain";
        $user->firstName = $this->settings()->anonymousName;
        $user->lastName = null;

        $layout = $user->getFieldLayout();

        if ($layout !== null) {
            foreach ($layout->getCustomFields() as $field) {
                try {
                    $user->setFieldValue($field->handle, null);
                } catch (Throwable) {
                    // A field that refuses null keeps whatever it had; the run records the
                    // anonymisation as done for the columns it could reach, and the dossier for
                    // this subject will still show that field if anything survived in it.
                }
            }
        }

        Craft::$app->getElements()->saveElement($user, false);

        // The identifiers are gone, but a session cookie and a stored password hash are both
        // still routes back into the account. Both go.
        Craft::$app->getDb()->createCommand()
            ->update(Table::USERS, [
                'password' => Craft::$app->getSecurity()->generatePasswordHash(StringHelper::randomString(40)),
                'suspended' => true,
                'verificationCode' => null,
                'verificationCodeIssuedDate' => null,
                'lastLoginAttemptIp' => null,
            ], ['id' => $user->id])
            ->execute();

        Craft::$app->getDb()->createCommand()->delete(Table::SESSIONS, ['userId' => $user->id])->execute();
    }

    public function scopes(): array
    {
        $scope = new RetentionScope();
        $scope->key = 'user:dormant';
        $scope->source = self::handle();
        $scope->label = Craft::t('lock', 'Dormant accounts');
        $scope->description = Craft::t('lock', 'Accounts that have not been signed into for the period, counting from registration where they never have been. Administrators are never included.');
        $scope->measuredFrom = Craft::t('lock', 'last sign-in, or registration');
        $scope->suggestedMonths = 36;
        $scope->rationale = Craft::t('lock', 'An account nobody has used for three years is a set of personal data with no purpose left.');

        return [$scope];
    }

    public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array
    {
        if ($scope->key !== 'user:dormant') {
            return [];
        }

        $rows = (new Query())
            ->select(['users.id', 'users.username', 'users.email', 'users.lastLoginDate', 'elements.dateCreated'])
            ->from(['users' => Table::USERS])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[users.id]]')
            ->where(['users.admin' => false])
            ->andWhere(['elements.dateDeleted' => null])
            // `COALESCE` rather than two queries: an account that has never been signed into is
            // measured from the day it was made, which is the only other date it has.
            ->andWhere(['<', new \yii\db\Expression('COALESCE([[users.lastLoginDate]], [[elements.dateCreated]])'), Db::prepareDateForDb($cutoff)])
            ->orderBy(['elements.dateCreated' => SORT_ASC])
            ->limit($limit)
            ->all();

        $records = [];

        foreach ($rows as $row) {
            $record = $this->record("user:{$row['id']}", Craft::t('lock', 'Account “{name}”', ['name' => $row['username']]), [
                'Email' => $row['email'],
                'Last login' => $row['lastLoginDate'] ?? Craft::t('lock', 'never'),
            ]);
            $record->kind = DataRecord::KIND_ELEMENT;
            $record->categories = [DataRecord::CATEGORY_IDENTITY, DataRecord::CATEGORY_ACCOUNT];
            $records[] = $record;
        }

        return $records;
    }
}
