<?php

namespace justinholtweb\lock\records;

use craft\db\ActiveRecord;
use craft\records\User;
use yii\db\ActiveQueryInterface;

/**
 * A data subject request, as stored.
 *
 * @property int $id
 * @property string $reference
 * @property string $type
 * @property string $status
 * @property string $source
 * @property string $email
 * @property string|null $name
 * @property int|null $userId
 * @property string|null $message
 * @property string|null $note
 * @property string|null $outcome
 * @property int|null $assigneeId
 * @property string|null $tokenHash
 * @property string|null $tokenExpiresAt
 * @property string $receivedAt
 * @property string|null $verifiedAt
 * @property string|null $dueAt
 * @property string|null $extendedAt
 * @property string|null $closedAt
 * @property string|null $dossierPath
 * @property string|null $dossierBuiltAt
 * @property array|null $context
 * @property int $remindersSent
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class RequestRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%lock_requests}}';
    }

    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }

    public function getAssignee(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'assigneeId']);
    }

    public function getEvents(): ActiveQueryInterface
    {
        return $this->hasMany(RequestEventRecord::class, ['requestId' => 'id']);
    }
}
