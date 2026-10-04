<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use justinholtweb\lock\models\Hold;
use justinholtweb\lock\models\Request;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\records\ErasureRecord;
use justinholtweb\lock\records\HoldRecord;
use justinholtweb\lock\records\RequestRecord;

/**
 * Reasons not to delete, and the record of what was already deleted.
 *
 * Two jobs that look unrelated and are the same job: knowing when *not* to act.
 *
 * A **hold** stops data going while a dispute, an investigation or a claim is live. A
 * **suppression** entry stops an erased address quietly coming back in next week's CSV import —
 * because an erasure that a re-import undoes was never an erasure, and the whole point of keeping
 * a hash of an address you were told to forget is to be able to honour the forgetting.
 */
class Holds extends Component
{
    /**
     * The reason this subject's data must not be touched, or null if there isn't one.
     *
     * Checked before every erasure and before every retention sweep. Returning the *sentence*
     * rather than a boolean is deliberate: an operator told "blocked" goes looking; an operator
     * told "held for the Fenwick claim until 30 June" already knows.
     */
    public function blockFor(Subject $subject, ?int $ignoreRequestId = null): ?string
    {
        $now = Db::prepareDateForDb(new DateTime());

        $hold = (new Query())
            ->select(['reason', 'expiresAt', 'email'])
            ->from([HoldRecord::tableName()])
            ->where(['or', ['email' => ''], ['emailHash' => $subject->emailHash()]])
            ->andWhere(['or', ['expiresAt' => null], ['>', 'expiresAt', $now]])
            ->one();

        if ($hold !== null) {
            $until = $hold['expiresAt'] !== null
                ? Craft::t('lock', ' until {date}', ['date' => substr((string)$hold['expiresAt'], 0, 10)])
                : '';

            return ($hold['email'] === ''
                ? Craft::t('lock', 'Everything is on hold site-wide: {reason}', ['reason' => $hold['reason']])
                : Craft::t('lock', 'This person is on hold: {reason}', ['reason' => $hold['reason']])) . $until;
        }

        return $this->openRequestBlock($subject, $ignoreRequestId);
    }

    /**
     * An open request of *another* kind is itself a hold.
     *
     * Somebody who has asked for a copy of their data and is waiting for it must not have that
     * data purged out from under the request by a retention rule that happened to fall due — the
     * disclosure would then be honest and empty, which is the worst possible answer.
     */
    private function openRequestBlock(Subject $subject, ?int $ignoreRequestId): ?string
    {
        /** @var \justinholtweb\lock\models\Settings $settings */
        $settings = \justinholtweb\lock\Plugin::getInstance()->getSettings();

        if (!$settings->holdBlocksRetention) {
            return null;
        }

        $query = (new Query())
            ->select(['reference', 'type'])
            ->from([RequestRecord::tableName()])
            ->where(['email' => $subject->normalisedEmail()])
            // Unverified requests do not count. Anybody can type an address into the form, and
            // an unconfirmed request that blocked retention would let a stranger stop a site's
            // retention rules from ever touching somebody's data, one form submission at a time.
            ->andWhere(['status' => [
                Request::STATUS_OPEN,
                Request::STATUS_ASSEMBLED,
                Request::STATUS_AWAITING,
            ]]);

        if ($ignoreRequestId !== null) {
            $query->andWhere(['not', ['id' => $ignoreRequestId]]);
        }

        $open = $query->one();

        if ($open === null) {
            return null;
        }

        return Craft::t('lock', 'Request {reference} is still open. Answer or close it before erasing anything.', [
            'reference' => $open['reference'],
        ]);
    }

    /** @return Hold[] */
    public function all(): array
    {
        return array_map(
            fn(HoldRecord $record) => $this->toModel($record),
            HoldRecord::find()->orderBy(['dateCreated' => SORT_DESC])->all(),
        );
    }

    public function get(int $id): ?Hold
    {
        $record = HoldRecord::findOne($id);

        return $record !== null ? $this->toModel($record) : null;
    }

    public function save(Hold $hold): bool
    {
        if (!$hold->validate()) {
            return false;
        }

        $record = $hold->id !== null ? HoldRecord::findOne($hold->id) : new HoldRecord();

        if ($record === null) {
            return false;
        }

        $email = mb_strtolower(trim($hold->email));

        $record->email = $email;
        $record->emailHash = $email !== '' ? (new Subject(email: $email))->emailHash() : null;
        $record->userId = $hold->userId;
        $record->reason = $hold->reason;
        $record->expiresAt = $hold->expiresAt !== null ? Db::prepareDateForDb($hold->expiresAt) : null;
        $record->createdBy ??= Craft::$app->getUser()->getId();

        if (!$record->save()) {
            return false;
        }

        $hold->id = $record->id;
        $hold->uid = $record->uid;

        return true;
    }

    public function delete(int $id): bool
    {
        $record = HoldRecord::findOne($id);

        return $record !== null && $record->delete() !== false;
    }

    /**
     * Whether this address has been erased before and must not be reinstated.
     *
     * The check a mailing-list import, a registration form or a CRM sync should make. It answers
     * from the keyed hash alone — Lock does not keep the address to compare against.
     */
    public function isSuppressed(string $email): bool
    {
        $email = trim($email);

        if ($email === '') {
            return false;
        }

        return ErasureRecord::find()
            ->where(['emailHash' => (new Subject(email: $email))->emailHash(), 'suppressed' => true])
            ->exists();
    }

    private function toModel(HoldRecord $record): Hold
    {
        $hold = new Hold();
        $hold->id = $record->id;
        $hold->uid = $record->uid;
        $hold->email = $record->email;
        $hold->userId = $record->userId;
        $hold->reason = $record->reason;
        $hold->expiresAt = $record->expiresAt !== null ? DateTimeHelper::toDateTime($record->expiresAt) ?: null : null;
        $hold->createdBy = $record->createdBy;
        $hold->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;

        return $hold;
    }
}
