# Configuration

Everything is in *Lock → Settings*, across three screens, and everything goes to project config.
A `config/lock.php` overrides any of it in the usual Craft way:

```php
<?php

return [
    'organisationName' => 'Acme Ltd',
    'contactEmail' => '$PRIVACY_EMAIL',
    'responseDays' => 30,
    'defaultMode' => 'anonymise',
];
```

## Who answers

| Setting | Default | |
|---|---|---|
| `organisationName` | *(empty)* | the controller's name, on correspondence and the register |
| `contactName` | *(empty)* | the person who answers |
| `contactEmail` | *(empty)* | falls back to the system email address; takes an env var |
| `policyUrl` | *(empty)* | linked from subject emails |
| `staffRecipients` | *(empty)* | falls back to `contactEmail` |

## Deadlines

| Setting | Default | |
|---|---|---|
| `responseDays` | `30` | Article 12(3) says "one month"; 30 days is the safe reading |
| `extensionDays` | `60` | available once, and only if the subject is told why |
| `reminderDays` | `[7, 2]` | days remaining at which staff are nudged |
| `clockStartsOnReceipt` | `true` | off means the clock starts at confirmation — permitted, and a clock a subject can leave stopped |

## Intake

| Setting | Default | |
|---|---|---|
| `intakeEnabled` | `true` | |
| `intakeTypes` | all six | which request types the public form may submit |
| `requireVerification` | `true` | **leave this on** — see below |
| `verificationTtl` | `48` | hours a confirmation link lives |
| `intakeRateLimit` | `5` | per hour, per address *and* per IP; 0 disables |
| `trustSignedInSubjects` | `true` | skips confirmation for somebody asking about their own signed-in address |

Turning `requireVerification` off makes the intake form a way to delete somebody else's account by
typing their address into it. There is a legitimate case — an intranet where every visitor is
authenticated — and there is no other one.

## Where to look

| Setting | Default | |
|---|---|---|
| `disabledCollectors` | `[]` | handles to skip; anything not listed is searched |
| `scanLogs` | `true` | |
| `customTables` | `[]` | see below |

`customTables` is the one that makes a disclosure complete rather than merely thorough. Every real
site has a table somebody wrote:

```php
'customTables' => [
    [
        'table' => 'oldsite_newsletter',
        'label' => 'Newsletter list',
        'emailColumn' => 'email_address',
        'userColumn' => '',
        'dateColumn' => 'subscribed_at',
        'mode' => 'erase',        // erase | anonymise | read
    ],
],
```

Names are validated as plain identifiers when saved **and again** when used. One validation on the
way in is a policy; validating again at the point the string reaches a query is what makes it
true. A table with a `dateColumn` and a mode other than `read` also becomes a retention scope.

## Anonymise and erase

| Setting | Default | |
|---|---|---|
| `defaultMode` | `anonymise` | |
| `anonymousName` | `Anonymised` | |
| `anonymousDomain` | `anonymised.invalid` | RFC 2606 reserves `.invalid` so it can never be delivered to |
| `suppressAfterErasure` | `true` | keeps a keyed hash so the address cannot be re-imported |
| `holdBlocksRetention` | `true` | an open request or a hold stops automated purging |
| `backupBeforeErasure` | `true` | skipped with a warning where the host forbids backups |

## Disclosures

| Setting | Default | |
|---|---|---|
| `dossierTtl` | `14` | days a built archive stays downloadable |
| `protectDossier` | `true` | AES-256, with a password shown once and never stored |

A built dossier is the most concentrated personal data on the site — one file with everything
about one person in it. `lock/requests/tidy` deletes the expired ones; put it on cron.

## Consent

| Setting | Default | |
|---|---|---|
| `consentPurposes` | four | key, label, description, basis — **renaming a key orphans its history** |
| `consentExpiryMonths` | `24` | after which a record is flagged stale; 0 never expires |
| `adoptToss` | `true` | reads Toss's consent and acceptance rows into disclosures |

## Retention

| Setting | Default | |
|---|---|---|
| `retentionRules` | `[]` | see [Retention](retention.md) |
| `scheduleEnabled` | `false` | |
| `scheduleTrigger` | `cron` | or `web`, which fires from control-panel traffic |
| `scheduleFrequency` | `daily` | |
| `scheduleTime` | `03:00` | 24-hour, site time zone |
| `retentionDryRunFirst` | `true` | a new rule reports before it deletes |

## Notifications and logging

| Setting | Default | |
|---|---|---|
| `notifySubject` | `true` | |
| `notifyStaff` | `true` | |
| `logLevel` | `info` | writes `storage/logs/lock.log`, kept 90 days |
| `activityRetentionDays` | `0` | 0 keeps the ledger forever |

Lock keeps its own log. A tool whose job is deleting personal data needs a trail that survives the
database it was deleting from; the ledger in the database is the convenient copy, not the
authoritative one.

`activityRetentionDays` defaults to keeping everything. The ledger is the evidence that the plugin
did what it says, and evidence that expires before the limitation period is not evidence.
