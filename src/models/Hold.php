<?php

namespace justinholtweb\lock\models;

use craft\base\Model;
use DateTime;

/**
 * A reason not to delete somebody's data yet.
 *
 * Litigation, a tax investigation, an open insurance claim, a dispute. A hold beats a retention
 * rule and beats an erasure request, and it has to, because "we deleted it under our automated
 * retention policy" is not a defence to a preservation order.
 */
class Hold extends Model
{
    public ?int $id = null;
    public ?string $uid = null;

    /** @var string Empty means a site-wide hold: nothing at all is purged while it stands. */
    public string $email = '';

    public ?int $userId = null;

    public string $reason = '';

    public ?DateTime $expiresAt = null;
    public ?int $createdBy = null;
    public ?DateTime $dateCreated = null;

    public function isSiteWide(): bool
    {
        return trim($this->email) === '' && $this->userId === null;
    }

    public function isActive(?DateTime $now = null): bool
    {
        if ($this->expiresAt === null) {
            return true;
        }

        return $this->expiresAt > ($now ?? new DateTime());
    }

    protected function defineRules(): array
    {
        return [
            [['reason'], 'required'],
            [['email'], 'email', 'skipOnEmpty' => true],
        ];
    }
}
