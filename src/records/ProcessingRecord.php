<?php

namespace justinholtweb\lock\records;

use craft\db\ActiveRecord;

/**
 * One Article 30 register entry.
 *
 * @property int $id
 * @property string $name
 * @property string|null $purpose
 * @property string $basis
 * @property string|null $balancing
 * @property array|null $dataCategories
 * @property array|null $subjectCategories
 * @property array|null $recipients
 * @property string|null $transfers
 * @property string|null $retention
 * @property string|null $safeguards
 * @property array|null $systems
 * @property string|null $owner
 * @property bool $enabled
 * @property int $sortOrder
 * @property string|null $reviewedAt
 * @property string $dateCreated
 * @property string $uid
 */
class ProcessingRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%lock_processing}}';
    }
}
