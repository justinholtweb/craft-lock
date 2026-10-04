<?php

namespace justinholtweb\lock\helpers;

use craft\db\Query;
use justinholtweb\lock\records\RequestRecord;

/**
 * A name for a subject on a screen that is not allowed to know their address.
 *
 * The ledgers key people by a keyed hash. Showing that hash in full is noise; showing the address
 * would mean storing it. A request reference is what staff actually search by, so that comes
 * first, and a short prefix of the hash is the fallback — stable, distinct per person, and useless
 * to anybody without the key.
 */
final class SubjectLabel
{
    /** @var array<int, string|null> */
    private static array $references = [];

    public static function for(?int $requestId, ?string $subjectHash): ?string
    {
        if ($requestId !== null) {
            if (!array_key_exists($requestId, self::$references)) {
                $reference = (new Query())
                    ->select(['reference'])
                    ->from([RequestRecord::tableName()])
                    ->where(['id' => $requestId])
                    ->scalar();

                self::$references[$requestId] = is_string($reference) ? $reference : null;
            }

            if (self::$references[$requestId] !== null) {
                return self::$references[$requestId];
            }
        }

        if ($subjectHash === null || $subjectHash === '') {
            return null;
        }

        return '#' . substr($subjectHash, 0, 10);
    }
}
