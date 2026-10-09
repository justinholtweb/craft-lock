<?php

namespace justinholtweb\lock\records;

use craft\db\ActiveRecord;

/**
 * Consent ticked on a form by somebody who has not yet shown that the address is theirs.
 *
 * Not part of the ledger. It becomes ledger rows when the emailed link is confirmed, and is
 * deleted then, or when the link expires, or when the person is erased.
 *
 * @property int $id
 * @property string $email
 * @property string $emailHash
 * @property array|null $grants
 * @property array|null $evidence
 * @property string $source
 * @property string|null $form
 * @property int|null $siteId
 * @property string $tokenHash
 * @property string $expiresAt
 * @property string $dateCreated
 * @property string $uid
 */
class PendingConsentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%lock_pendingconsents}}';
    }
}
