<?php

namespace justinholtweb\lock\records;

use craft\db\ActiveRecord;
use craft\records\User;
use yii\db\ActiveQueryInterface;

/**
 * One consent decision. Withdrawal is a new row, never an update to an old one.
 *
 * @property int $id
 * @property string $email
 * @property string $emailHash
 * @property int|null $userId
 * @property string $purpose
 * @property string $state
 * @property string $source
 * @property string|null $policyVersion
 * @property array|null $evidence
 * @property int|null $siteId
 * @property string $recordedAt
 * @property string|null $expiresAt
 * @property string $dateCreated
 * @property string $uid
 */
class ConsentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%lock_consents}}';
    }

    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }
}
