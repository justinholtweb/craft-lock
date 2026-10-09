<?php

namespace justinholtweb\lock\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use justinholtweb\lock\helpers\TableGuard;

/**
 * Lock's plugin settings.
 *
 * Nothing here is marked `required`. A `required` rule on any one setting makes
 * `savePluginSettings()` fail wholesale, so on a fresh install *no* setting can be saved until
 * that one is filled in — values are validated for correctness only when they're present.
 *
 * The defaults lean the way the law does. A request is acknowledged, a deadline is counted from
 * the day it arrived, erasure means *anonymise* rather than delete, and nothing is purged
 * automatically until somebody writes a rule and switches it on. An operator who wants it
 * harsher has to say so.
 */
class Settings extends Model
{
    // Erasure modes. The distinction is the whole plugin: `erase` destroys the row, `anonymise`
    // keeps it and destroys the person in it.
    public const MODE_ANONYMISE = 'anonymise';
    public const MODE_ERASE = 'erase';

    public const TRIGGER_CRON = 'cron';
    public const TRIGGER_WEB = 'web';

    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';

    // ---------------------------------------------------------------------
    // Who answers
    // ---------------------------------------------------------------------

    /** @var string The controller's name, as it appears on correspondence and on the Article 30 register. */
    public string $organisationName = '';

    /** @var string Data protection officer or responsible person. Printed on the register and on subject emails. */
    public string $contactName = '';

    /** @var string Where a subject can reach a human. Falls back to the system email address. */
    public string $contactEmail = '';

    /** @var string Where the site's privacy policy lives. Included in acknowledgement emails. */
    public string $policyUrl = '';

    // ---------------------------------------------------------------------
    // Deadlines
    // ---------------------------------------------------------------------

    /**
     * @var int Calendar days to answer a request. GDPR Article 12(3) says "one month"; 30 days is
     *          the safe reading of it, and is what most supervisory authorities expect to see.
     */
    public int $responseDays = 30;

    /** @var int Extra days available under Article 12(3) when a request is complex. Must be claimed, not assumed. */
    public int $extensionDays = 60;

    /**
     * @var int[] Days before the deadline to nudge staff. Two nudges beats one: the first is for
     *            "start it", the second is for "it is now late tomorrow".
     */
    public array $reminderDays = [7, 2];

    /**
     * @var bool Whether the clock starts when the request arrives or when the subject verifies
     *           their identity. Recital 64 permits waiting for verification; the safer default
     *           is that it does not stop the clock.
     */
    public bool $clockStartsOnReceipt = true;

    // ---------------------------------------------------------------------
    // Intake
    // ---------------------------------------------------------------------

    /** @var bool Whether the front-end intake endpoint accepts requests at all. */
    public bool $intakeEnabled = true;

    /** @var string[] Which request types the public form is allowed to submit. */
    public array $intakeTypes = [
        Request::TYPE_ACCESS,
        Request::TYPE_ERASURE,
        Request::TYPE_RECTIFICATION,
        Request::TYPE_PORTABILITY,
        Request::TYPE_RESTRICTION,
        Request::TYPE_OBJECTION,
    ];

    /**
     * @var bool Whether an emailed link must be followed before the request is worked on.
     *
     * On by default, and it is not paranoia: an unverified erasure request is a way to delete
     * somebody else's account by typing their address into a form.
     */
    public bool $requireVerification = true;

    /** @var int Hours a verification link stays good for. */
    public int $verificationTtl = 48;

    /** @var int Max intake submissions per IP, and per address, per hour. 0 disables the limit. */
    public int $intakeRateLimit = 5;

    /**
     * @var int Max intake submissions per hour across the whole site, from everyone. 0 disables it.
     *
     * The backstop for a distributed script: per-IP and per-address limits do nothing against a
     * thousand IPs each naming a different address, and every submission sends an email. Set in
     * `config/lock.php`; there is no control in the settings screen.
     */
    public int $intakeGlobalLimit = 200;

    /** @var bool Whether a signed-in user submitting for their own address skips verification. */
    public bool $trustSignedInSubjects = true;

    // ---------------------------------------------------------------------
    // Assembly and delivery
    // ---------------------------------------------------------------------

    /** @var string[] Handles of collectors that are switched off. Everything not listed runs. */
    public array $disabledCollectors = [];

    /**
     * @var string[] Handles of custom entry fields to scan for a subject's email address, on top
     *               of the author relationship. Empty means "scan every plain-text field", which
     *               is correct but slow on a large site.
     */
    public array $entryFieldHandles = [];

    /** @var bool Whether to search Craft's log files for the subject's address. */
    public bool $scanLogs = true;

    /**
     * @var array<int, array<string, string>> Extra tables to search, for modules and plugins Lock
     *              has never heard of. Each row: `table`, `emailColumn`, `userColumn`, `label`,
     *              `dateColumn`, `mode` (anonymise|erase|read).
     */
    public array $customTables = [];

    /** @var int Days a built dossier stays downloadable before it is deleted. */
    public int $dossierTtl = 14;

    /** @var bool Whether the dossier ZIP is encrypted with a password shown once to the operator. */
    public bool $protectDossier = true;

    // ---------------------------------------------------------------------
    // Erasure
    // ---------------------------------------------------------------------

    /** @var string What "erase me" means by default. See the class constants. */
    public string $defaultMode = self::MODE_ANONYMISE;

    /** @var string Display name written over a subject's name when anonymising. */
    public string $anonymousName = 'Anonymised';

    /**
     * @var string Domain used to build the replacement address. `.invalid` is reserved by RFC 2606
     *             precisely so it can never be delivered to or registered by anyone.
     */
    public string $anonymousDomain = 'anonymised.invalid';

    /**
     * @var bool Whether to keep a hash of the erased address so the same person cannot be silently
     *           re-imported from a CSV next week. Storing a hash of an address you were told to
     *           forget is expressly contemplated by Article 17 — you need it to *honour* the
     *           erasure.
     */
    public bool $suppressAfterErasure = true;

    /** @var bool Whether an open request or a legal hold blocks automated purging for that subject. */
    public bool $holdBlocksRetention = true;

    /** @var bool Whether every erasure takes a database backup first. */
    public bool $backupBeforeErasure = true;

    // ---------------------------------------------------------------------
    // Consent
    // ---------------------------------------------------------------------

    /**
     * @var array<int, array<string, string>> The purposes consent can be given for. Each row:
     *              `key`, `label`, `description`, `basis`. Shipped with the four everyone needs.
     */
    public array $consentPurposes = [
        ['key' => 'marketing', 'label' => 'Marketing email', 'description' => 'Newsletters and promotional email.', 'basis' => 'consent'],
        ['key' => 'analytics', 'label' => 'Analytics', 'description' => 'Measuring how the site is used.', 'basis' => 'consent'],
        ['key' => 'profiling', 'label' => 'Personalisation', 'description' => 'Tailoring content to the visitor.', 'basis' => 'consent'],
        ['key' => 'sharing', 'label' => 'Sharing with partners', 'description' => 'Passing data to third parties.', 'basis' => 'consent'],
    ];

    /** @var bool Whether to mirror Toss's cookie-consent and policy-acceptance rows into Lock's ledger. */
    public bool $adoptToss = true;

    /** @var int Months after which a consent record is treated as stale and needs re-asking. 0 never expires. */
    public int $consentExpiryMonths = 24;

    /**
     * @var array<int, array<string, string>> Formie and Freeform fields that capture consent. Each
     *              row: `plugin` (`formie` or `freeform`), `form` and `field` handles, an optional
     *              `emailField` handle (the form's first email field when blank), the `purpose`
     *              key, `action` (`grant` or `withdraw`) and optional `wording` kept as evidence.
     *              A ticked grant from a signed-out visitor waits for an emailed confirmation
     *              before it reaches the ledger. See {@see \justinholtweb\lock\services\FormConsent}.
     */
    public array $formConsents = [];

    // ---------------------------------------------------------------------
    // Retention (Pro)
    // ---------------------------------------------------------------------

    /**
     * @var array<int, array<string, mixed>> Retention rules, as data. Kept in project config
     *              rather than a table on purpose: a rule that deletes data on a timer is policy,
     *              and policy should arrive on production by deploy, reviewed, like any other
     *              change — not be typed into a live control panel at 5pm on a Friday.
     */
    public array $retentionRules = [];

    /** @var bool Whether due retention rules run on their own. */
    public bool $scheduleEnabled = false;

    /** @var string `cron` (recommended) or `web`, which fires from control-panel traffic. */
    public string $scheduleTrigger = self::TRIGGER_CRON;

    /** @var string How often the scheduler looks for due rules. */
    public string $scheduleFrequency = self::FREQUENCY_DAILY;

    /** @var string Time of day the scheduled run is due, `HH:MM`, in the system time zone. */
    public string $scheduleTime = '03:00';

    /**
     * @var bool Whether a scheduled retention run only ever reports. On by default: the first
     *           thing a new rule should do is tell you what it *would* have deleted.
     */
    public bool $retentionDryRunFirst = true;

    // ---------------------------------------------------------------------
    // Notifications and logging
    // ---------------------------------------------------------------------

    /** @var bool Email the subject on receipt, on verification, and on completion. */
    public bool $notifySubject = true;

    /** @var bool Email staff when a request arrives and when a deadline approaches. */
    public bool $notifyStaff = true;

    /** @var string[] Who "staff" means. Falls back to `contactEmail`, then the system address. */
    public array $staffRecipients = [];

    /** @var string Monolog level for `storage/logs/lock.log`. */
    public string $logLevel = 'info';

    /**
     * @var int Days to keep the activity ledger. 0 keeps it forever, which is the default: the
     *          ledger is the evidence that the plugin did what it says, and evidence that expires
     *          before the limitation period is not evidence.
     */
    public int $activityRetentionDays = 0;

    // ---------------------------------------------------------------------

    /**
     * Control-panel forms post strings, checkboxes post `'0'`/`'1'`, and an editable table posts
     * a blank trailing row. A typed `int` property assigned `''` is a `TypeError` thrown from
     * inside Yii, well away from anything that names the setting — so every shape the CP can post
     * is normalised here, before assignment.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        foreach (['responseDays', 'extensionDays', 'verificationTtl', 'intakeRateLimit', 'intakeGlobalLimit', 'dossierTtl', 'consentExpiryMonths', 'activityRetentionDays'] as $key) {
            if (isset($values[$key]) && $values[$key] === '') {
                $values[$key] = $this->$key;
            }
        }

        foreach (['reminderDays', 'intakeTypes', 'disabledCollectors', 'entryFieldHandles', 'staffRecipients'] as $key) {
            if (isset($values[$key]) && is_string($values[$key])) {
                $values[$key] = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $values[$key]) ?: [])));
            }
        }

        foreach (['customTables', 'consentPurposes', 'retentionRules', 'formConsents'] as $key) {
            if (isset($values[$key]) && is_array($values[$key])) {
                $values[$key] = $this->stripBlankRows($values[$key]);
            }
        }

        if (isset($values['reminderDays']) && is_array($values['reminderDays'])) {
            $values['reminderDays'] = array_values(array_filter(array_map('intval', $values['reminderDays'])));
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * Craft's editable-table macro always posts one empty row at the bottom, and a row whose
     * every cell is blank is that row, not a setting.
     *
     * @param array<int|string, mixed> $rows
     * @return array<int, mixed>
     */
    private function stripBlankRows(array $rows): array
    {
        $kept = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $value) {
                if (is_string($value) ? trim($value) !== '' : !empty($value)) {
                    $kept[] = $row;
                    break;
                }
            }
        }

        return $kept;
    }

    protected function defineRules(): array
    {
        return [
            [['responseDays', 'extensionDays'], 'integer', 'min' => 1, 'max' => 3650],
            [['verificationTtl'], 'integer', 'min' => 1, 'max' => 8760],
            [['intakeRateLimit', 'intakeGlobalLimit', 'dossierTtl', 'consentExpiryMonths', 'activityRetentionDays'], 'integer', 'min' => 0],
            [['defaultMode'], 'in', 'range' => [self::MODE_ANONYMISE, self::MODE_ERASE]],
            [['scheduleTrigger'], 'in', 'range' => [self::TRIGGER_CRON, self::TRIGGER_WEB]],
            [['scheduleFrequency'], 'in', 'range' => [self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY]],
            [['scheduleTime'], 'match', 'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/', 'message' => Craft::t('lock', 'Use a 24-hour time such as 03:00.')],
            [['contactEmail'], 'email', 'skipOnEmpty' => true],
            [['policyUrl'], 'url', 'skipOnEmpty' => true, 'defaultScheme' => 'https'],
            [['anonymousDomain'], 'match', 'pattern' => '/^[a-z0-9.-]+$/i', 'skipOnEmpty' => true],
            [['anonymousName'], 'string', 'max' => 100],
            [['staffRecipients'], 'validateRecipients', 'skipOnEmpty' => false],
            [['customTables'], 'validateCustomTables', 'skipOnEmpty' => false],
            [['consentPurposes'], 'validatePurposes', 'skipOnEmpty' => false],
            [['formConsents'], 'validateFormConsents', 'skipOnEmpty' => false],
        ];
    }

    /**
     * Yii skips an inline validator when the attribute is empty, so every rule that has to fire
     * on an empty value carries `skipOnEmpty => false` — otherwise the rule that rejects a bad
     * empty shape is exactly the rule that never runs.
     */
    public function validateRecipients(string $attribute): void
    {
        foreach ($this->staffRecipients as $address) {
            $resolved = App::parseEnv($address);

            if ($resolved !== null && $resolved !== '' && !filter_var($resolved, FILTER_VALIDATE_EMAIL)) {
                $this->addError($attribute, Craft::t('lock', '“{value}” is not an email address.', ['value' => $address]));
            }
        }
    }

    public function validateCustomTables(string $attribute): void
    {
        foreach ($this->customTables as $i => $row) {
            $table = trim((string)($row['table'] ?? ''));

            if ($table === '') {
                $this->addError($attribute, Craft::t('lock', 'Row {n}: a table name is needed.', ['n' => $i + 1]));
                continue;
            }

            // Identifiers reach a query builder. Anything that is not a plain identifier is
            // rejected here rather than escaped later, because "escaped later" is how this
            // becomes an injection.
            foreach (['table', 'emailColumn', 'userColumn', 'dateColumn'] as $key) {
                $value = trim((string)($row[$key] ?? ''));

                if ($value !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
                    $this->addError($attribute, Craft::t('lock', 'Row {n}: “{value}” is not a valid table or column name.', ['n' => $i + 1, 'value' => $value]));
                }
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) && TableGuard::isProtected($table)) {
                $this->addError($attribute, Craft::t('lock', 'Row {n}: “{table}” belongs to Craft or to Lock itself. Lock already searches those, and a custom-table rule pointed at one could delete or rewrite rows the site depends on.', ['n' => $i + 1, 'table' => $table]));
            }

            if (trim((string)($row['emailColumn'] ?? '')) === '' && trim((string)($row['userColumn'] ?? '')) === '') {
                $this->addError($attribute, Craft::t('lock', 'Row {n}: give an email column, a user ID column, or both — otherwise there is no way to find the subject in it.', ['n' => $i + 1]));
            }
        }
    }

    public function validatePurposes(string $attribute): void
    {
        $seen = [];

        foreach ($this->consentPurposes as $i => $row) {
            $key = trim((string)($row['key'] ?? ''));

            if ($key === '' || !preg_match('/^[a-z0-9_-]+$/i', $key)) {
                $this->addError($attribute, Craft::t('lock', 'Row {n}: a purpose needs a key made of letters, numbers, hyphens or underscores.', ['n' => $i + 1]));
                continue;
            }

            if (isset($seen[$key])) {
                $this->addError($attribute, Craft::t('lock', 'Row {n}: the purpose “{key}” is listed twice. Consent records are keyed by it.', ['n' => $i + 1, 'key' => $key]));
            }

            $seen[$key] = true;
        }
    }

    public function validateFormConsents(string $attribute): void
    {
        $purposes = $this->purposeOptions();

        foreach ($this->formConsents as $i => $row) {
            $n = $i + 1;
            $plugin = trim((string)($row['plugin'] ?? ''));

            if (!in_array($plugin, ['formie', 'freeform'], true)) {
                $this->addError($attribute, Craft::t('lock', 'Form row {n}: choose Formie or Freeform.', ['n' => $n]));
            }

            foreach (['form' => true, 'field' => true, 'emailField' => false] as $key => $needed) {
                $value = trim((string)($row[$key] ?? ''));

                if ($value === '' && $needed) {
                    $this->addError($attribute, Craft::t('lock', 'Form row {n}: a form handle and a field handle are both needed.', ['n' => $n]));
                    break;
                }

                if ($value !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $value)) {
                    $this->addError($attribute, Craft::t('lock', 'Form row {n}: “{value}” is not a handle.', ['n' => $n, 'value' => $value]));
                }
            }

            $purpose = trim((string)($row['purpose'] ?? ''));

            if (!isset($purposes[$purpose])) {
                $this->addError($attribute, Craft::t('lock', 'Form row {n}: “{purpose}” is not one of the purposes above.', ['n' => $n, 'purpose' => $purpose]));
            }

            if (!in_array((string)($row['action'] ?? 'grant'), ['grant', 'withdraw', ''], true)) {
                $this->addError($attribute, Craft::t('lock', 'Form row {n}: a ticked box either grants or withdraws.', ['n' => $n]));
            }
        }
    }

    /**
     * The consent mappings for one form, cleaned.
     *
     * @return array<int, array{field: string, emailField: string, purpose: string, action: string, wording: string}>
     */
    public function formConsentsFor(string $plugin, string $form): array
    {
        $rows = [];

        foreach ($this->formConsents as $row) {
            if (trim((string)($row['plugin'] ?? '')) !== $plugin || trim((string)($row['form'] ?? '')) !== $form) {
                continue;
            }

            $field = trim((string)($row['field'] ?? ''));
            $purpose = trim((string)($row['purpose'] ?? ''));

            if ($field === '' || $purpose === '') {
                continue;
            }

            $rows[] = [
                'field' => $field,
                'emailField' => trim((string)($row['emailField'] ?? '')),
                'purpose' => $purpose,
                'action' => (string)($row['action'] ?? '') === 'withdraw' ? 'withdraw' : 'grant',
                'wording' => trim((string)($row['wording'] ?? '')),
            ];
        }

        return $rows;
    }

    /** Where subject correspondence comes from and goes back to. */
    public function resolvedContactEmail(): string
    {
        $configured = App::parseEnv($this->contactEmail);

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string)App::parseEnv(Craft::$app->getProjectConfig()->get('email.fromEmail') ?? '');
    }

    /** @return string[] */
    public function resolvedStaffRecipients(): array
    {
        $addresses = [];

        foreach ($this->staffRecipients as $address) {
            $resolved = App::parseEnv($address);

            if (is_string($resolved) && $resolved !== '') {
                $addresses[] = $resolved;
            }
        }

        if ($addresses === []) {
            $fallback = $this->resolvedContactEmail();

            if ($fallback !== '') {
                $addresses[] = $fallback;
            }
        }

        return array_values(array_unique($addresses));
    }

    /** @return array<string, string> Purpose key => label, for form building. */
    public function purposeOptions(): array
    {
        $options = [];

        foreach ($this->consentPurposes as $purpose) {
            $key = trim((string)($purpose['key'] ?? ''));

            if ($key !== '') {
                $options[$key] = (string)($purpose['label'] ?? $key);
            }
        }

        return $options;
    }
}
