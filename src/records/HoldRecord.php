<?php

namespace justinholtweb\lock\records;

use craft\db\ActiveRecord;
use craft\records\User;
use yii\db\ActiveQueryInterface;

/**
 * A reason not to delete somebody's data yet.
 *
 * @property int $id
 * @property string $email
 * @property string|null $emailHash
 * @property int|null $userId
 * @property string $reason
 * @property string|null $expiresAt
 * @property int|null $createdBy
 * @property string $dateCreated
 * @property string $uid
 */
class HoldRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%lock_holds}}';
    }

    public function getCreator(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'createdBy']);
    }
}
