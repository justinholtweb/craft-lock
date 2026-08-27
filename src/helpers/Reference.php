<?php

namespace justinholtweb\lock\helpers;

use craft\db\Query;
use justinholtweb\lock\records\RequestRecord;

/**
 * Human-quotable references for requests: `DSAR-2026-0042`.
 *
 * Sequential within the year rather than global, because the number that matters in
 * correspondence is "the forty-second request this year", and a reference that restarts each
 * January is also one that quietly tells you the volume — a number a controller is expected to
 * know.
 */
final class Reference
{
    public static function next(string $prefix = 'DSAR', ?int $year = null): string
    {
        $year ??= (int)date('Y');

        // Counted from the highest existing reference for the year, not from a row count: a
        // deleted request must not hand its number to the next one, because the old number is
        // already in somebody's inbox.
        //
        // The `%` is written out because the `false` disables Yii's escaping *and* its automatic
        // wrapping — a `like` with neither matches only the literal prefix, which matches nothing,
        // which makes every reference the first one and every second request a duplicate-key error.
        $latest = (new Query())
            ->select(['reference'])
            ->from([RequestRecord::tableName()])
            ->where(['like', 'reference', "$prefix-$year-%", false])
            ->orderBy(['reference' => SORT_DESC])
            ->scalar();

        $sequence = 1;

        if (is_string($latest) && preg_match('/-(\d+)$/', $latest, $matches)) {
            $sequence = (int)$matches[1] + 1;
        }

        // Two requests submitted in the same instant would otherwise both take the same number and
        // one would fail on the unique index. Walking forward costs a query only in the rare case
        // where it happens.
        while (self::exists($reference = sprintf('%s-%d-%04d', $prefix, $year, $sequence))) {
            $sequence++;
        }

        return $reference;
    }

    private static function exists(string $reference): bool
    {
        return (new Query())
            ->from([RequestRecord::tableName()])
            ->where(['reference' => $reference])
            ->exists();
    }
}
