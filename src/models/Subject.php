<?php

namespace justinholtweb\lock\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\Db;

/**
 * A person, as far as this site is concerned.
 *
 * A subject is an **email address first and a user account second**. Most of the personal data on
 * a typical Craft site — guest orders, form submissions, comments, mailing-list rows — belongs to
 * somebody who never made an account, and a data-protection tool that can only see registered
 * users answers the easy half of every request and quietly loses the rest.
 */
class Subject extends Model
{
    public function __construct(
        public string $email = '',
        public ?string $name = null,
        public ?int $userId = null,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /** Addresses are matched case-insensitively; nobody's mailbox is case sensitive in practice. */
    public function normalisedEmail(): string
    {
        return mb_strtolower(trim($this->email));
    }

    /**
     * A stable, one-way handle for the address.
     *
     * Used for the suppression list and to key an erasure certificate, so that Lock can still
     * answer "was this person erased?" without keeping the address it was told to forget.
     *
     * It is a keyed hash, not a plain one — an unkeyed SHA-256 of an email address is trivially
     * reversed with a wordlist, and a suppression list is not a place to be casual. It is still
     * a membership test by construction: anyone who can guess the address and holds the key can
     * confirm it. That is inherent to the job and is documented rather than pretended away.
     */
    public function emailHash(): string
    {
        return hash_hmac('sha256', $this->normalisedEmail(), Craft::$app->getConfig()->getGeneral()->securityKey);
    }

    /**
     * The name this subject is given once anonymised — deterministic, so a row in one table and
     * a row in another still line up as the same (now unidentifiable) person afterwards.
     */
    public function pseudonym(): string
    {
        return 'anon-' . substr($this->emailHash(), 0, 12);
    }

    public function getUser(): ?User
    {
        if ($this->userId !== null) {
            return Craft::$app->getUsers()->getUserById($this->userId);
        }

        if ($this->email === '') {
            return null;
        }

        // By email only. `getUserByUsernameOrEmail()` would also match a *username* equal to the
        // address typed — so a person who registered the username "ada@example.com" would be
        // handed somebody else's subject request, and erased for it.
        // Escaped, because a query param treats `*`, `,` and a leading `not ` as syntax.
        return User::find()
            ->email(Db::escapeParam($this->normalisedEmail()))
            ->status(null)
            ->one();
    }

    /** Fills in whichever of the two identifiers was missing. */
    public function resolve(): self
    {
        $user = $this->getUser();

        if ($user !== null) {
            $this->userId ??= $user->id;
            $this->name ??= $user->fullName ?: $user->username;

            if ($this->email === '') {
                $this->email = (string)$user->email;
            }
        }

        return $this;
    }

    public function label(): string
    {
        return $this->name !== null && $this->name !== '' ? "$this->name <$this->email>" : $this->email;
    }

    protected function defineRules(): array
    {
        return [
            [['email'], 'required'],
            [['email'], 'email'],
        ];
    }
}
