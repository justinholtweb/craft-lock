<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\lock\helpers\Address;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\RetentionScope;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\records\ConsentRecord;
use justinholtweb\lock\records\PendingConsentRecord;

/**
 * Lock's own consent ledger.
 *
 * A source that discloses itself. If the site holds "this person agreed to marketing on the 3rd
 * of March", that is personal data and belongs in the disclosure like anything else — and a
 * privacy tool that exempts its own tables from the disclosure it produces is not one.
 *
 * Consent records are **never deleted**, only anonymised, and the reason is Article 7(1): the
 * controller must be able to demonstrate that consent was given. Delete the proof and every email
 * you ever sent becomes unlawful retrospectively. Anonymising keeps the proof — the purpose, the
 * date, the wording, the keyed hash — and removes the name.
 */
class ConsentCollector extends BaseCollector
{
    public static function handle(): string
    {
        return 'consent';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Consent records');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Consent given, refused and withdrawn, with the evidence of each decision, and consent from forms still waiting for its emailed confirmation.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $rows = (new Query())
            ->from([ConsentRecord::tableName()])
            ->where(['or', ['emailHash' => $subject->emailHash()], ['email' => $subject->normalisedEmail()]])
            ->orderBy(['recordedAt' => SORT_DESC])
            ->limit(500)
            ->all();

        $records = [];

        foreach ($rows as $row) {
            $evidence = is_string($row['evidence'] ?? null) ? json_decode($row['evidence'], true) : ($row['evidence'] ?? []);

            $record = $this->record("consent:{$row['id']}", Craft::t('lock', '{state} “{purpose}” on {date}', [
                'state' => ucfirst((string)$row['state']),
                'purpose' => $row['purpose'],
                'date' => substr((string)$row['recordedAt'], 0, 10),
            ]), [
                'Purpose' => $row['purpose'],
                'Decision' => $row['state'],
                'Recorded' => $row['recordedAt'],
                'Given through' => $row['source'],
                'Policy version' => $row['policyVersion'],
                'Wording shown' => is_array($evidence) ? ($evidence['text'] ?? null) : null,
                'Page' => is_array($evidence) ? ($evidence['url'] ?? null) : null,
                'IP address' => is_array($evidence) ? ($evidence['ip'] ?? null) : null,
            ]);
            $record->categories = [DataRecord::CATEGORY_ACCOUNT, DataRecord::CATEGORY_TECHNICAL];
            $record->erasable = false;
            $record->basis = Craft::t('lock', 'Required in order to demonstrate that consent was given — Article 7(1).');
            $record->retention = Craft::t('lock', 'Kept while the consent is relied on, and for as long afterwards as a complaint could be made about it.');
            $record->dateCreated = ($row['recordedAt'] ?? null) !== null ? new DateTime((string)$row['recordedAt']) : null;
            $records[] = $record;
        }

        return array_merge($records, $this->findPending($subject));
    }

    /**
     * Consent ticked on a form and not yet confirmed. Not consent yet, so nothing Article 7(1)
     * needs kept: it is disclosed like anything else held about the address, and an erasure
     * deletes it outright — anonymising a request to email somebody would leave nothing worth
     * having.
     *
     * @return DataRecord[]
     */
    private function findPending(Subject $subject): array
    {
        $rows = (new Query())
            ->from([PendingConsentRecord::tableName()])
            ->where(['or', ['emailHash' => $subject->emailHash()], ['email' => $subject->normalisedEmail()]])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(100)
            ->all();

        $records = [];

        foreach ($rows as $row) {
            $grants = is_string($row['grants'] ?? null) ? json_decode($row['grants'], true) : ($row['grants'] ?? []);
            $evidence = is_string($row['evidence'] ?? null) ? json_decode($row['evidence'], true) : ($row['evidence'] ?? []);

            $record = $this->record("consent:pending:{$row['id']}", Craft::t('lock', 'Unconfirmed consent from a form on {date}', [
                'date' => substr((string)$row['dateCreated'], 0, 10),
            ]), [
                'Email' => $row['email'],
                'Purposes' => implode(', ', array_column(is_array($grants) ? $grants : [], 'purpose')),
                'Form' => $row['form'],
                'Waiting since' => $row['dateCreated'],
                'Link expires' => $row['expiresAt'],
                'Page' => is_array($evidence) ? ($evidence['url'] ?? null) : null,
                'IP address' => is_array($evidence) ? ($evidence['ip'] ?? null) : null,
            ]);
            $record->categories = [DataRecord::CATEGORY_CONTACT, DataRecord::CATEGORY_TECHNICAL];
            $record->erasable = true;
            $record->anonymisable = false;
            $record->basis = Craft::t('lock', 'Held only to send the confirmation email, until it is confirmed or expires.');
            $record->retention = Craft::t('lock', 'Deleted on confirmation, or when the link expires.');
            $record->dateCreated = ($row['dateCreated'] ?? null) !== null ? new DateTime((string)$row['dateCreated']) : null;
            $records[] = $record;
        }

        return $records;
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        if (str_starts_with($this->keyBody($target->key), 'pending:')) {
            if ($target->action === ErasureTarget::ACTION_ERASE) {
                Craft::$app->getDb()->createCommand()->delete(PendingConsentRecord::tableName(), [
                    'id' => (int)substr($this->keyBody($target->key), strlen('pending:')),
                ])->execute();
            }

            return;
        }

        if ($target->action !== ErasureTarget::ACTION_ANONYMISE) {
            return;
        }

        // The hash stays. It is what still ties the proof to a person if that person ever comes
        // back and says they never agreed — without it, an anonymised consent record proves that
        // *somebody* agreed, which is not the same thing.
        //
        // The evidence keeps what was *shown* — the wording, the policy version — and loses what
        // identifies the person who saw it: their IP address, their browser, and the URL they
        // were on, which on a preferences page routinely carries their address or account ID.
        $id = $this->keyId($target->key);
        $evidence = (new Query())->select(['evidence'])->from([ConsentRecord::tableName()])->where(['id' => $id])->scalar();
        $evidence = is_string($evidence) ? json_decode($evidence, true) : $evidence;

        if (is_array($evidence)) {
            foreach (['ip', 'userAgent', 'url'] as $key) {
                if (array_key_exists($key, $evidence)) {
                    $evidence[$key] = null;
                }
            }

            if ($subject->normalisedEmail() !== '') {
                $evidence = Address::replaceDeep($evidence, $subject->normalisedEmail(), '[address]');
            }
        }

        Craft::$app->getDb()->createCommand()->update(ConsentRecord::tableName(), [
            'email' => $subject->pseudonym() . '@' . ($this->settings()->anonymousDomain ?: 'anonymised.invalid'),
            'userId' => null,
            'evidence' => is_array($evidence) && $evidence !== [] ? \craft\helpers\Json::encode($evidence) : null,
        ], ['id' => $id])->execute();
    }

    public function scopes(): array
    {
        $scope = new RetentionScope();
        $scope->key = 'consent:superseded';
        $scope->source = self::handle();
        $scope->label = Craft::t('lock', 'Superseded consent records');
        $scope->description = Craft::t('lock', 'Consent decisions that a later decision on the same purpose has already replaced. The current state of every purpose is kept regardless of age.');
        $scope->measuredFrom = Craft::t('lock', 'when the decision was recorded');
        $scope->erasable = false;
        $scope->suggestedMonths = 72;

        return [$scope];
    }

    public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array
    {
        if ($scope->key !== 'consent:superseded') {
            return [];
        }

        // The newest row per (person, purpose) is the live answer and is never swept, however old
        // it is. Withdrawing consent five years ago is still the reason you must not email them.
        $current = (new Query())
            ->select(['maxId' => 'MAX([[id]])'])
            ->from([ConsentRecord::tableName()])
            ->groupBy(['emailHash', 'purpose'])
            ->column();

        $rows = (new Query())
            ->select(['id', 'email', 'purpose', 'recordedAt'])
            ->from([ConsentRecord::tableName()])
            ->where(['<', 'recordedAt', Db::prepareDateForDb($cutoff)])
            ->andWhere(['not in', 'id', $current ?: [0]])
            ->orderBy(['recordedAt' => SORT_ASC])
            ->limit($limit)
            ->all();

        $records = [];

        foreach ($rows as $row) {
            $record = $this->record("consent:{$row['id']}", Craft::t('lock', 'Superseded “{purpose}” record from {date}', [
                'purpose' => $row['purpose'],
                'date' => substr((string)$row['recordedAt'], 0, 10),
            ]), ['Email' => $row['email']]);
            $record->erasable = false;
            $records[] = $record;
        }

        return $records;
    }
}
