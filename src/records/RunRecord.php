<?php

namespace justinholtweb\lock\records;

use craft\db\ActiveRecord;
use craft\records\User;
use justinholtweb\lock\helpers\SubjectLabel;
use yii\db\ActiveQueryInterface;

/**
 * One execution — an erasure, a retention sweep, or a dossier build.
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property string|null $ruleKey
 * @property string|null $subjectHash
 * @property int|null $requestId
 * @property string|null $summary
 * @property array|null $plan
 * @property array|null $outcome
 * @property string|null $fingerprint
 * @property int $erased
 * @property int $anonymised
 * @property int $skipped
 * @property int $failed
 * @property float $duration
 * @property bool $dryRun
 * @property bool $scheduled
 * @property string|null $backupPath
 * @property int|null $userId
 * @property string $dateCreated
 * @property-read string|null $subjectLabel
 * @property-read string|null $subjectEmail
 */
class RunRecord extends ActiveRecord
{
    public const TYPE_ERASURE = 'erasure';
    public const TYPE_RETENTION = 'retention';
    public const TYPE_DOSSIER = 'dossier';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';

    public static function tableName(): string
    {
        return '{{%lock_runs}}';
    }

    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }

    /** Who a run was about, without the address — see {@see ActivityRecord::getSubjectLabel()}. */
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
