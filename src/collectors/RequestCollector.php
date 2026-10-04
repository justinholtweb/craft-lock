<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\db\Query;
use DateTime;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\Request;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\records\ActivityRecord;
use justinholtweb\lock\records\RequestRecord;

/**
 * Previous requests this person has made.
 *
 * Discloses Lock's own request table, for the same reason the consent ledger is disclosed: it is
 * personal data, and an exemption for the tool's own storage would be a hole in every disclosure
 * the tool produces.
 *
 * Never erased, and only anonymised once closed. A supervisory authority asking "did you answer
 * that request, and when" has to be answerable a year later; a request record deleted on request
 * takes the only evidence of compliance with it.
 */
class RequestCollector extends BaseCollector
{
    public static function handle(): string
    {
        return 'request';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Previous requests');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Data protection requests this person has made before, and how they were answered.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $rows = (new Query())
            ->select(['id', 'reference', 'type', 'status', 'name', 'email', 'message', 'outcome', 'receivedAt', 'closedAt'])
            ->from([RequestRecord::tableName()])
            ->where(['email' => $subject->normalisedEmail()])
            ->orderBy(['receivedAt' => SORT_DESC])
            ->limit(200)
            ->all();

        $records = [];

        foreach ($rows as $row) {
            $record = $this->record("request:{$row['id']}", Craft::t('lock', '{reference} — {type}', [
                'reference' => $row['reference'],
                'type' => $row['type'],
            ]), [
                'Reference' => $row['reference'],
                'Kind of request' => $row['type'],
                'Received' => $row['receivedAt'],
                'Status' => $row['status'],
                'What you asked' => $row['message'],
                'How it was answered' => $row['outcome'],
                'Closed' => $row['closedAt'],
            ]);
            $record->categories = [DataRecord::CATEGORY_ACCOUNT];
            $record->dateCreated = ($row['receivedAt'] ?? null) !== null ? new DateTime((string)$row['receivedAt']) : null;
            $record->erasable = false;
            $record->basis = Craft::t('lock', 'Required to show that the request was received and answered.');
            $record->cpUrl = \craft\helpers\UrlHelper::cpUrl("lock/requests/{$row['id']}");

            // An open request must not be anonymised out from under the person handling it — the
            // address is how the answer gets back to the subject.
            if (in_array($row['status'], [Request::STATUS_UNVERIFIED, Request::STATUS_OPEN, Request::STATUS_ASSEMBLED, Request::STATUS_AWAITING], true)) {
                $record->anonymisable = false;
                $record->retainReason = Craft::t('lock', 'This request is still open. It is anonymised once it has been answered.');
            }

            $records[] = $record;
        }

        $ledger = $this->ledger($subject);

        if ($ledger !== null) {
            $records[] = $ledger;
        }

        return $records;
    }

    /**
     * Lock's own activity ledger, as it concerns this person.
     *
     * Keyed by hash, so it is found by hash. It is disclosed but never changed: the ledger is the
     * accountability record Article 5(2) asks for, and it carries no address to remove.
     *
     * Only offered once something besides Lock looking has happened. Assembling a dossier and
     * previewing an erasure each write a line, so counting those would make the record appear
     * between a preview and the run it approved — and change the plan's fingerprint under it.
     */
    private function ledger(Subject $subject): ?DataRecord
    {
        if ($subject->normalisedEmail() === '') {
            return null;
        }

        $hash = $subject->emailHash();
        $ignored = ['dossier.assembled', 'erasure.previewed'];

        $substantive = (new Query())
            ->from([ActivityRecord::tableName()])
            ->where(['subjectHash' => $hash])
            ->andWhere(['not in', 'action', $ignored])
            ->exists();

        if (!$substantive) {
            return null;
        }

        $rows = (new Query())
            ->select(['action', 'summary', 'dateCreated'])
            ->from([ActivityRecord::tableName()])
            ->where(['subjectHash' => $hash])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(500)
            ->all();

        $lines = array_map(
            static fn(array $row) => sprintf('%s  %s  %s', substr((string)$row['dateCreated'], 0, 16), $row['action'], (string)$row['summary']),
            $rows,
        );

        $record = $this->record('ledger:activity', Craft::t('lock', 'Our record of handling your data ({n} entries)', ['n' => count($rows)]), [
            'Entries' => $lines,
        ]);
        $record->categories = [DataRecord::CATEGORY_TECHNICAL];
        $record->erasable = false;
        $record->anonymisable = false;
        $record->basis = Craft::t('lock', 'Required to show how personal data was handled — Article 5(2).');
        $record->retainReason = Craft::t('lock', 'This is the log of what was done with your data, kept to show it was handled lawfully. It holds no email address, only a one-way key.');

        return $record;
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        if ($target->action !== ErasureTarget::ACTION_ANONYMISE) {
            return;
        }

        Craft::$app->getDb()->createCommand()->update(RequestRecord::tableName(), [
            'email' => $subject->pseudonym() . '@' . ($this->settings()->anonymousDomain ?: 'anonymised.invalid'),
            'name' => $this->settings()->anonymousName,
            'userId' => null,
            'tokenHash' => null,
            'context' => null,
        ], ['id' => $this->keyId($target->key)])->execute();
    }
}
