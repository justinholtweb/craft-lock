<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use Throwable;

/**
 * The processing and administration ledger.
 *
 * Two ideas, one table. **Processing** — every time somebody's data was read, assembled, exported,
 * anonymised or deleted through Lock. **Administration** — every time the rules themselves changed.
 * Article 5(2) calls the combination accountability, and it is the difference between a site that
 * complies and a site that can show it complied.
 *
 * Append-only. There is no update method here and no delete method except the retention sweep of
 * the ledger itself, which is off by default: an audit trail with an edit button is a diary.
 */
class Activity extends Component
{
    /**
     * Writes a line. Never throws — a ledger write that can break the operation it is recording is
     * a ledger that gets removed from the hot path six months later.
     */
    public function log(
        string $category,
        string $action,
        ?string $summary = null,
        ?Subject $subject = null,
        ?int $requestId = null,
        array $data = [],
    ): void {
        try {
            $record = new ActivityRecord();
            $record->category = $category;
            $record->action = $action;
            $record->summary = $summary;
            $record->subjectEmail = $subject?->normalisedEmail();
            $record->subjectHash = $subject !== null && $subject->normalisedEmail() !== '' ? $subject->emailHash() : null;
            $record->requestId = $requestId;
            $record->data = $data === [] ? null : $data;
            $record->userId = Craft::$app->getUser()->getId();
            $record->ip = $this->ip();
            $record->save(false);
        } catch (Throwable $e) {
            Craft::error('Could not write to the Lock activity ledger: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    /**
     * The client address, or null on the console.
     *
     * Not taken from `X-Forwarded-For` unless Craft has been told which proxies to trust — an
     * unvalidated forwarded header is a value the client chose, and a ledger of addresses the
     * subject picked is worse than no addresses at all.
     */
    private function ip(): ?string
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return null;
        }

        /** @var \craft\web\Request $request */
        $ip = $request->getUserIP();

        return is_string($ip) ? substr($ip, 0, 45) : null;
    }

    /**
     * @return ActivityRecord[]
     */
    public function recent(int $limit = 100, ?string $category = null, ?string $subjectHash = null): array
    {
        $query = ActivityRecord::find()->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])->limit($limit);

        if ($category !== null) {
            $query->andWhere(['category' => $category]);
        }

        if ($subjectHash !== null) {
            $query->andWhere(['subjectHash' => $subjectHash]);
        }

        return $query->all();
    }

    public function countSince(DateTime $since, ?string $category = null): int
    {
        $query = (new Query())
            ->from([ActivityRecord::tableName()])
            ->where(['>=', 'dateCreated', Db::prepareDateForDb($since)]);

        if ($category !== null) {
            $query->andWhere(['category' => $category]);
        }

        return (int)$query->count();
    }

    /**
     * @return array<string, int> action => count, for the overview.
     */
    public function tally(DateTime $since): array
    {
        $rows = (new Query())
            ->select(['category', 'n' => 'COUNT(*)'])
            ->from([ActivityRecord::tableName()])
            ->where(['>=', 'dateCreated', Db::prepareDateForDb($since)])
            ->groupBy(['category'])
            ->all();

        $tally = [];

        foreach ($rows as $row) {
            // `COUNT(*)` comes back as a string from PDO on both drivers. Cast, or the overview's
            // arithmetic quietly concatenates.
            $tally[(string)$row['category']] = (int)$row['n'];
        }

        return $tally;
    }

    /** Everything the ledger holds about one person, for their own disclosure. */
    public function forSubject(Subject $subject, int $limit = 500): array
    {
        return ActivityRecord::find()
            ->where(['subjectHash' => $subject->emailHash()])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Trims the ledger. Returns how many rows went.
     *
     * Only ever called when `activityRetentionDays` has deliberately been set to something other
     * than zero.
     */
    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-$days days");

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(ActivityRecord::tableName(), ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->execute();
    }
}
