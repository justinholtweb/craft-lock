<?php

namespace justinholtweb\lock\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;

/**
 * One data subject request.
 *
 * The model carries the deadline arithmetic because every screen, every email and every reminder
 * needs the same answer to "how long have we got", and three implementations of that would
 * eventually disagree by a day — which, for the only number a regulator actually checks, is the
 * one place a disagreement is expensive.
 */
class Request extends Model
{
    // Article 15 through 21, in the order a form would sensibly offer them.
    public const TYPE_ACCESS = 'access';
    public const TYPE_PORTABILITY = 'portability';
    public const TYPE_RECTIFICATION = 'rectification';
    public const TYPE_ERASURE = 'erasure';
    public const TYPE_RESTRICTION = 'restriction';
    public const TYPE_OBJECTION = 'objection';

    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_OPEN = 'open';
    public const STATUS_ASSEMBLED = 'assembled';
    public const STATUS_AWAITING = 'awaiting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_EXPIRED = 'expired';

    public const SOURCE_WEB = 'web';
    public const SOURCE_CP = 'cp';
    public const SOURCE_EMAIL = 'email';
    public const SOURCE_CONSOLE = 'console';

    public ?int $id = null;
    public ?string $uid = null;

    /** @var string The human reference, e.g. `DSAR-2026-0042`. What the subject quotes back at you. */
    public string $reference = '';

    public string $type = self::TYPE_ACCESS;
    public string $status = self::STATUS_UNVERIFIED;
    public string $source = self::SOURCE_WEB;

    public string $email = '';
    public ?string $name = null;
    public ?int $userId = null;

    /** @var string|null What the subject actually asked for, in their words. */
    public ?string $message = null;

    /** @var string|null Internal notes. Never sent to the subject. */
    public ?string $note = null;

    /** @var string|null Why a request was refused or restricted. Article 12(4) requires a reason. */
    public ?string $outcome = null;

    public ?int $assigneeId = null;

    public ?DateTime $receivedAt = null;
    public ?DateTime $verifiedAt = null;
    public ?DateTime $dueAt = null;
    public ?DateTime $extendedAt = null;
    public ?DateTime $closedAt = null;

    /** @var string|null Relative path of the last built dossier, under the plugin's storage folder. */
    public ?string $dossierPath = null;

    public ?DateTime $dossierBuiltAt = null;

    /** @var array<string, mixed> Where the request came from: IP, user agent, site, form. */
    public array $context = [];

    /** @var int Which reminders have already gone out, so a nudge is not sent twice. */
    public int $remindersSent = 0;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;

    /** @var string|null Only ever populated in memory, immediately after intake, to build the link. */
    public ?string $plainToken = null;

    public static function types(): array
    {
        return [
            self::TYPE_ACCESS => Craft::t('lock', 'Access — a copy of everything held'),
            self::TYPE_PORTABILITY => Craft::t('lock', 'Portability — a machine-readable copy'),
            self::TYPE_RECTIFICATION => Craft::t('lock', 'Rectification — correct something wrong'),
            self::TYPE_ERASURE => Craft::t('lock', 'Erasure — delete it'),
            self::TYPE_RESTRICTION => Craft::t('lock', 'Restriction — stop processing it'),
            self::TYPE_OBJECTION => Craft::t('lock', 'Objection — stop a particular use'),
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_UNVERIFIED => Craft::t('lock', 'Awaiting verification'),
            self::STATUS_OPEN => Craft::t('lock', 'Open'),
            self::STATUS_ASSEMBLED => Craft::t('lock', 'Assembled'),
            self::STATUS_AWAITING => Craft::t('lock', 'Waiting on the subject'),
            self::STATUS_COMPLETED => Craft::t('lock', 'Completed'),
            self::STATUS_REFUSED => Craft::t('lock', 'Refused'),
            self::STATUS_WITHDRAWN => Craft::t('lock', 'Withdrawn'),
            self::STATUS_EXPIRED => Craft::t('lock', 'Expired unverified'),
        ];
    }

    /** The article the request is made under, for the acknowledgement and the register. */
    public function article(): string
    {
        return match ($this->type) {
            self::TYPE_ACCESS => 'Article 15',
            self::TYPE_RECTIFICATION => 'Article 16',
            self::TYPE_ERASURE => 'Article 17',
            self::TYPE_RESTRICTION => 'Article 18',
            self::TYPE_PORTABILITY => 'Article 20',
            self::TYPE_OBJECTION => 'Article 21',
            default => '',
        };
    }

    public function typeLabel(): string
    {
        return self::types()[$this->type] ?? $this->type;
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_WEB => Craft::t('lock', 'Website form'),
            self::SOURCE_CP => Craft::t('lock', 'Control panel'),
            self::SOURCE_EMAIL => Craft::t('lock', 'Email'),
            self::SOURCE_CONSOLE => Craft::t('lock', 'Console'),
            default => $this->source,
        };
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_UNVERIFIED,
            self::STATUS_OPEN,
            self::STATUS_ASSEMBLED,
            self::STATUS_AWAITING,
        ], true);
    }

    public function isClosed(): bool
    {
        return !$this->isOpen();
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }

    /**
     * Whether anything may be assembled or erased for this request yet.
     *
     * Not until the person who made it has confirmed it — or staff took it in through the control
     * panel, which counts as confirmation. Before that it is a form somebody filled in with an
     * address, and acting on it would send that address's data towards whoever typed it.
     */
    public function isActionable(): bool
    {
        return $this->isVerified() && !in_array($this->status, [self::STATUS_UNVERIFIED, self::STATUS_EXPIRED], true);
    }

    public function wasExtended(): bool
    {
        return $this->extendedAt !== null;
    }

    /**
     * Whole days left before the deadline. Negative once it is late.
     *
     * Counted from the start of today against the start of the due date, not from "now" — a
     * request due tomorrow morning is due in one day whether it is asked at 9am or at 11pm, and
     * a countdown that says "0 days" all afternoon of the last day is the one people trust least.
     */
    public function daysRemaining(?DateTimeInterface $now = null): ?int
    {
        if ($this->dueAt === null) {
            return null;
        }

        $today = DateTimeHelper::toDateTime($now ?? new DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone())));

        if ($today === false) {
            return null;
        }

        $today = (clone $today)->setTime(0, 0);
        $due = (clone $this->dueAt)->setTime(0, 0);

        return (int)$today->diff($due)->format('%r%a');
    }

    public function isOverdue(?DateTimeInterface $now = null): bool
    {
        $remaining = $this->daysRemaining($now);

        return $this->isOpen() && $remaining !== null && $remaining < 0;
    }

    /** A word for how the deadline is going, for badges and for sorting attention. */
    public function urgency(?DateTimeInterface $now = null): string
    {
        if ($this->isClosed()) {
            return 'closed';
        }

        $remaining = $this->daysRemaining($now);

        if ($remaining === null) {
            return 'none';
        }

        return match (true) {
            $remaining < 0 => 'overdue',
            $remaining <= 3 => 'critical',
            $remaining <= 7 => 'soon',
            default => 'fine',
        };
    }

    /** @return User|null */
    public function getUser(): ?User
    {
        if ($this->userId === null) {
            return null;
        }

        return Craft::$app->getUsers()->getUserById($this->userId);
    }

    /** The subject this request is about, whether or not they have an account. */
    public function getSubject(): Subject
    {
        return new Subject(
            email: $this->email,
            name: $this->name,
            userId: $this->userId,
        );
    }

    protected function defineRules(): array
    {
        return [
            [['email'], 'required'],
            [['email'], 'email'],
            [['type'], 'in', 'range' => array_keys(self::types())],
            [['status'], 'in', 'range' => array_keys(self::statuses())],
            [['name'], 'string', 'max' => 255],
            [['message'], 'string', 'max' => 5000],
        ];
    }
}
