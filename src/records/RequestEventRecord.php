<?php

namespace justinholtweb\lock\records;

use craft\db\ActiveRecord;
use craft\records\User;
use yii\db\ActiveQueryInterface;

/**
 * One line of a request's timeline. Append-only by convention — nothing in the plugin updates one.
 *
 * @property int $id
 * @property int $requestId
 * @property string $type
 * @property string|null $message
 * @property array|null $data
 * @property int|null $userId
 * @property string $dateCreated
 */
class RequestEventRecord extends ActiveRecord
{
    public const TYPE_RECEIVED = 'received';
    public const TYPE_VERIFIED = 'verified';
    public const TYPE_ASSIGNED = 'assigned';
    public const TYPE_NOTE = 'note';
    public const TYPE_ASSEMBLED = 'assembled';
    public const TYPE_EXPORTED = 'exported';
    public const TYPE_DOWNLOADED = 'downloaded';
    public const TYPE_ERASED = 'erased';
    public const TYPE_EXTENDED = 'extended';
    public const TYPE_EMAILED = 'emailed';
    public const TYPE_CLOSED = 'closed';
    public const TYPE_REOPENED = 'reopened';

    public static function tableName(): string
    {
        return '{{%lock_requestevents}}';
    }

    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }
}
