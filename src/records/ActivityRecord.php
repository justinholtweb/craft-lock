<?php

namespace justinholtweb\lock\records;

use craft\db\ActiveRecord;
use craft\records\User;
use justinholtweb\lock\helpers\SubjectLabel;
use yii\db\ActiveQueryInterface;

/**
 * The processing and administration ledger.
 *
 * Every read of a person's data through Lock, every export, every erasure, every consent change
 * and every automated purge lands here. It is append-only: the plugin has no code path that
 * updates or deletes a row except the explicit retention sweep of the ledger itself, which is off
 * by default.
 *
 * @property int $id
 * @property string $category
 * @property string $action
 * @property string|null $summary
 * @property string|null $subjectHash
 * @property int|null $requestId
 * @property array|null $data
 * @property int|null $userId
 * @property string|null $ip
 * @property string $dateCreated
 * @property-read string|null $subjectLabel
 * @property-read string|null $subjectEmail
 */
class ActivityRecord extends ActiveRecord
{
    public const CATEGORY_REQUEST = 'request';
    public const CATEGORY_ACCESS = 'access';
    public const CATEGORY_ERASURE = 'erasure';
    public const CATEGORY_CONSENT = 'consent';
    public const CATEGORY_RETENTION = 'retention';
    public const CATEGORY_ADMIN = 'admin';

    public static function tableName(): string
    {
        return '{{%lock_activity}}';
    }

    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }

    /**
     * Who a line is about, without the address.
     *
     * The ledger keeps only the keyed hash, so the label is the request's reference where there
     * is one and a short prefix of the hash where there is not — enough to tell lines about the
     * same person apart, and nothing an erasure would have to come back for.
     */
    public function getSubjectLabel(): ?string
    {
        return SubjectLabel::for($this->requestId, $this->subjectHash);
    }

    /**
     * @deprecated The address is no longer stored. Kept so that a template written against the
     * old column renders the label instead of failing; use `subjectLabel`.
     */
    public function getSubjectEmail(): ?string
    {
        return $this->getSubjectLabel();
    }
}
