<?php

namespace justinholtweb\lock\models;

use Craft;
use craft\base\Model;
use DateTime;

/**
 * One consent decision, with the evidence that it was made.
 *
 * Article 7(1): the controller must be able to *demonstrate* consent. A boolean column on the
 * user record cannot demonstrate anything — it cannot say when, on what wording, from where, or
 * whether the person was shown a pre-ticked box. So a consent entry keeps the circumstances, and
 * withdrawal is a new entry rather than an update, because an audit trail you can overwrite is
 * not one.
 */
class ConsentEntry extends Model
{
    public const STATE_GRANTED = 'granted';
    public const STATE_WITHDRAWN = 'withdrawn';
    public const STATE_REFUSED = 'refused';

    public const SOURCE_BANNER = 'banner';
    public const SOURCE_FORM = 'form';
    public const SOURCE_REGISTRATION = 'registration';
    public const SOURCE_CHECKOUT = 'checkout';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_CP = 'cp';
    public const SOURCE_API = 'api';

    public ?int $id = null;
    public ?string $uid = null;

    public string $email = '';
    public ?int $userId = null;

    /** @var string The purpose key, from {@see Settings::$consentPurposes}. */
    public string $purpose = '';

    public string $state = self::STATE_GRANTED;
    public string $source = self::SOURCE_FORM;

    /** @var string|null Version of the policy or wording in force when it was given. */
    public ?string $policyVersion = null;

    /**
     * @var array<string, mixed> The circumstances: IP, user agent, page URL, the exact text shown,
     *              the form's element ID. This is the demonstrable part.
     */
    public array $evidence = [];

    public ?int $siteId = null;

    public ?DateTime $recordedAt = null;
    public ?DateTime $expiresAt = null;

    public ?DateTime $dateCreated = null;

    public function isActive(?DateTime $now = null): bool
    {
        if ($this->state !== self::STATE_GRANTED) {
            return false;
        }

        if ($this->expiresAt === null) {
            return true;
        }

        return $this->expiresAt > ($now ?? new DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone())));
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            self::STATE_GRANTED => Craft::t('lock', 'Granted'),
            self::STATE_WITHDRAWN => Craft::t('lock', 'Withdrawn'),
            default => Craft::t('lock', 'Refused'),
        };
    }

    protected function defineRules(): array
    {
        return [
            [['email', 'purpose'], 'required'],
            [['email'], 'email'],
            [['state'], 'in', 'range' => [self::STATE_GRANTED, self::STATE_WITHDRAWN, self::STATE_REFUSED]],
        ];
    }
}
