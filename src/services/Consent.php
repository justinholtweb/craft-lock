<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateInterval;
use DateTime;
use justinholtweb\lock\models\ConsentEntry;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use justinholtweb\lock\records\ConsentRecord;

/**
 * The consent ledger — consent tied to a *person*, not to a browser.
 *
 * This is the half a cookie banner cannot do. A banner records that a browser agreed to
 * analytics; it has no idea who that was, which is correct for an anonymous visitor and useless
 * the moment somebody writes in and asks what you hold on them. Lock's ledger is keyed to an email
 * address, so "what have you got on me" has an answer that includes "your consent to marketing,
 * given on this date, on this wording, from this page".
 *
 * **Withdrawal is an insert, never an update.** The state of a purpose is the newest row for it.
 * Overwriting the old row would leave a ledger that cannot show what was true last March — and
 * "were you allowed to email them in March" is exactly the question that gets asked.
 */
class Consent extends Component
{
    /**
     * Records a decision.
     *
     * `$evidence` is the demonstrable part required by Article 7(1) — the wording shown, the page,
     * the address, the policy version. Anything the site can honestly say it presented.
     */
    public function record(
        string $email,
        string $purpose,
        string $state = ConsentEntry::STATE_GRANTED,
        string $source = ConsentEntry::SOURCE_FORM,
        array $evidence = [],
        ?int $userId = null,
        ?int $siteId = null,
        ?string $policyVersion = null,
    ): ?ConsentEntry {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $entry = new ConsentEntry();
        $entry->email = mb_strtolower(trim($email));
        $entry->purpose = trim($purpose);
        $entry->state = $state;
        $entry->source = $source;
        $entry->evidence = $evidence;
        $entry->policyVersion = $policyVersion;
        $entry->siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $entry->recordedAt = new DateTime();

        if ($settings->consentExpiryMonths > 0 && $state === ConsentEntry::STATE_GRANTED) {
            $entry->expiresAt = (clone $entry->recordedAt)->add(new DateInterval("P{$settings->consentExpiryMonths}M"));
        }

        $subject = new Subject(email: $entry->email);
        $subject->resolve();
        $entry->userId = $userId ?? $subject->userId;

        if (!$entry->validate()) {
            return null;
        }

        $record = new ConsentRecord();
        $record->email = $entry->email;
        $record->emailHash = $subject->emailHash();
        $record->userId = $entry->userId;
        $record->purpose = $entry->purpose;
        $record->state = $entry->state;
        $record->source = $entry->source;
        $record->policyVersion = $entry->policyVersion;
        $record->evidence = $entry->evidence === [] ? null : $entry->evidence;
        $record->siteId = $entry->siteId;
        $record->recordedAt = Db::prepareDateForDb($entry->recordedAt);
        $record->expiresAt = $entry->expiresAt !== null ? Db::prepareDateForDb($entry->expiresAt) : null;

        if (!$record->save(false)) {
            return null;
        }

        $entry->id = $record->id;
        $entry->uid = $record->uid;

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_CONSENT,
            "consent.$state",
            Craft::t('lock', '“{purpose}” {state} through {source}.', [
                'purpose' => $entry->purpose,
                'state' => $state,
                'source' => $source,
            ]),
            $subject,
            null,
            ['purpose' => $entry->purpose, 'policyVersion' => $policyVersion],
        );

        return $entry;
    }

    public function grant(string $email, string $purpose, array $evidence = [], string $source = ConsentEntry::SOURCE_FORM): ?ConsentEntry
    {
        return $this->record($email, $purpose, ConsentEntry::STATE_GRANTED, $source, $evidence);
    }

    public function withdraw(string $email, string $purpose, array $evidence = [], string $source = ConsentEntry::SOURCE_FORM): ?ConsentEntry
    {
        return $this->record($email, $purpose, ConsentEntry::STATE_WITHDRAWN, $source, $evidence);
    }

    /**
     * Whether this person may currently be processed for this purpose.
     *
     * The question every mailing send and every tag should be asking. Defaults to **no** — a
     * purpose nobody has ever answered for is not consented to, and treating silence as agreement
     * is the specific thing Article 4(11) rules out.
     */
    public function allows(string $email, string $purpose): bool
    {
        $latest = $this->latest($email, $purpose);

        return $latest !== null && $latest->isActive();
    }

    public function latest(string $email, string $purpose): ?ConsentEntry
    {
        $subject = new Subject(email: $email);

        $record = ConsentRecord::find()
            ->where(['emailHash' => $subject->emailHash(), 'purpose' => trim($purpose)])
            ->orderBy(['recordedAt' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        return $record instanceof ConsentRecord ? $this->toModel($record) : null;
    }

    /**
     * The current answer for every configured purpose.
     *
     * @return array<string, bool>
     */
    public function state(string $email): array
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $state = [];

        foreach (array_keys($settings->purposeOptions()) as $purpose) {
            $state[$purpose] = $this->allows($email, $purpose);
        }

        return $state;
    }

    /**
     * Every decision this person has ever made, newest first.
     *
     * @return ConsentEntry[]
     */
    public function history(string $email, int $limit = 200): array
    {
        $subject = new Subject(email: $email);

        return array_map(
            fn(ConsentRecord $r) => $this->toModel($r),
            ConsentRecord::find()
                ->where(['or', ['emailHash' => $subject->emailHash()], ['email' => $subject->normalisedEmail()]])
                ->orderBy(['recordedAt' => SORT_DESC, 'id' => SORT_DESC])
                ->limit($limit)
                ->all(),
        );
    }

    /**
     * @param array<string, mixed> $criteria
     * @return ConsentEntry[]
     */
    public function find(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $query = ConsentRecord::find();

        if (isset($criteria['purpose']) && $criteria['purpose'] !== '') {
            $query->andWhere(['purpose' => $criteria['purpose']]);
        }

        if (isset($criteria['state']) && $criteria['state'] !== '') {
            $query->andWhere(['state' => $criteria['state']]);
        }

        if (isset($criteria['search']) && trim((string)$criteria['search']) !== '') {
            $query->andWhere(['like', 'email', trim((string)$criteria['search'])]);
        }

        return array_map(
            fn(ConsentRecord $r) => $this->toModel($r),
            $query->orderBy(['recordedAt' => SORT_DESC, 'id' => SORT_DESC])->limit($limit)->offset($offset)->all(),
        );
    }

    /**
     * How many people currently allow each purpose.
     *
     * Counted from the newest row per person per purpose, not from a count of granted rows —
     * somebody who agreed, withdrew, and agreed again would otherwise be counted three times, two
     * of them wrongly.
     *
     * @return array<string, array{granted: int, withdrawn: int}>
     */
    public function tally(): array
    {
        $currentIds = (new Query())
            ->select(['maxId' => 'MAX([[id]])'])
            ->from([ConsentRecord::tableName()])
            ->groupBy(['emailHash', 'purpose'])
            ->column();

        if ($currentIds === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['purpose', 'state', 'n' => 'COUNT(*)'])
            ->from([ConsentRecord::tableName()])
            ->where(['id' => $currentIds])
            ->groupBy(['purpose', 'state'])
            ->all();

        $tally = [];

        foreach ($rows as $row) {
            $purpose = (string)$row['purpose'];
            $tally[$purpose] ??= ['granted' => 0, 'withdrawn' => 0];

            if ($row['state'] === ConsentEntry::STATE_GRANTED) {
                $tally[$purpose]['granted'] = (int)$row['n'];
            } else {
                $tally[$purpose]['withdrawn'] += (int)$row['n'];
            }
        }

        return $tally;
    }

    /**
     * Consent that has aged past the site's re-ask period and is now no better than silence.
     *
     * @return ConsentEntry[]
     */
    public function stale(int $limit = 500): array
    {
        return array_map(
            fn(ConsentRecord $r) => $this->toModel($r),
            ConsentRecord::find()
                ->where(['state' => ConsentEntry::STATE_GRANTED])
                ->andWhere(['not', ['expiresAt' => null]])
                ->andWhere(['<', 'expiresAt', Db::prepareDateForDb(new DateTime())])
                ->orderBy(['expiresAt' => SORT_ASC])
                ->limit($limit)
                ->all(),
        );
    }

    private function toModel(ConsentRecord $record): ConsentEntry
    {
        $entry = new ConsentEntry();
        $entry->id = $record->id;
        $entry->uid = $record->uid;
        $entry->email = $record->email;
        $entry->userId = $record->userId;
        $entry->purpose = $record->purpose;
        $entry->state = $record->state;
        $entry->source = $record->source;
        $entry->policyVersion = $record->policyVersion;
        $entry->evidence = is_array($record->evidence) ? $record->evidence : [];
        $entry->siteId = $record->siteId;
        $entry->recordedAt = DateTimeHelper::toDateTime($record->recordedAt) ?: null;
        $entry->expiresAt = $record->expiresAt !== null ? (DateTimeHelper::toDateTime($record->expiresAt) ?: null) : null;

        return $entry;
    }
}
