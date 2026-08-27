<?php

namespace justinholtweb\lock\records;

use craft\db\ActiveRecord;

/**
 * The certificate that an erasure happened, and the suppression entry that keeps it true.
 *
 * Holds no address. That is not squeamishness: a table of "people we deleted" that lists their
 * email addresses has not deleted anybody. The keyed hash is enough to answer "has this address
 * been erased?" when a CSV import asks, and not enough to enumerate who.
 *
 * @property int $id
 * @property string $emailHash
 * @property string $pseudonym
 * @property string $mode
 * @property int|null $requestId
 * @property string|null $reference
 * @property array|null $scope
 * @property int $erased
 * @property int $anonymised
 * @property bool $suppressed
 * @property string $completedAt
 * @property int|null $userId
 * @property string $dateCreated
 * @property string $uid
 */
class ErasureRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%lock_erasures}}';
    }
}
