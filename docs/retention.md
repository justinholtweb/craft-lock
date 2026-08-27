# Retention

*Pro.*

Storage limitation — Article 5(1)(e) — is the principle nobody implements, because implementing it
means writing down how long you keep things and then actually doing it. A rule is both halves at
once: the period is the policy, the scheduled run is the compliance.

## Writing one

*Settings → Retention rules*. Each rule is a sentence:

> **Anonymise** *completed orders* more than **7 years** old.

- **Applies to** is a *scope* — a named sweep the source itself defines, because only Commerce
  knows which of an order's several dates is the one that matters.
- **Then** is delete, anonymise, or report only.
- **Because** is the justification. It goes on the Article 30 register, and a retention period with
  no reason behind it is a number somebody guessed.
- **Max per run** bounds the first run on a large table. If it is reached, the preview says so, and
  the rest is picked up on later runs.

## Why they live in project config

A rule that deletes personal data on a timer is a change to what the site *does*. It should arrive
on production by deploy, reviewed, like any other change — not be typed into a live control panel
at five o'clock on a Friday.

The cost is real and worth stating: on a production site with `allowAdminChanges` off, a rule
cannot be added there. That is the intended answer, not an oversight.

## Scopes that ship

| Scope | Measured from | Delete | Anonymise |
|---|---|:-:|:-:|
| `user:dormant` | last sign-in, or registration | ✓ | ✓ |
| `commerce:carts` | last touched | ✓ | ✗ |
| `commerce:orders` | order date | ✗ | ✓ |
| `formie:submissions` | submission date | ✓ | ✓ |
| `formie:spam` | submission date | ✓ | ✗ |
| `freeform:submissions` | submission date | ✓ | ✓ |
| `comments:old` | date posted | ✗ | ✓ |
| `session:stale` | last use | ✓ | ✗ |
| `consent:superseded` | when recorded | ✗ | ✓ |
| `logs:files` | last written to | ✓ | ✗ |
| `custom:<table>` | the date column you named | as configured | as configured |

Administrators are never in `user:dormant`. The newest consent decision for each person and
purpose is never in `consent:superseded`, however old it is — withdrawing consent five years ago
is still the reason you must not email them.

*Lock → Retention* also lists the scopes **nothing points at**. That list is the useful output of
the screen: it says which kinds of personal data currently have no expiry at all.

## What it will not do

**It will not touch somebody with an open request.** Purging the data a person has just asked for
a copy of would answer their request honestly and emptily. Nor anybody under a legal hold. Both
are checked per record and reported as skips with the reason attached, so a run that touched
nothing tells you *why* it touched nothing.

**Its first real run reports instead of deleting.** The first thing a new rule that deletes things
should do is tell you what it would have deleted. Run it again to apply it. (Switchable, but on by
default.)

## Running them

```bash
php craft lock/retention/due                 # everything owed a run — put this on cron
php craft lock/retention/due --dry-run
php craft lock/retention/preview --rule=carts
php craft lock/retention/run --rule=carts --force
php craft lock/retention/status              # rules, last runs, and what nothing covers
```

Due-ness is occurrence-based, not "has a day passed". A 3am purge stays at 3am rather than
drifting an hour later every night until it lands in the working day — so running the command
every five minutes and running it once a night produce the same deletions.

Sites without cron can switch the trigger to control-panel traffic. It only ever queues a job: a
visitor's page load must not be the thing that deletes ten thousand rows.

## Reading what happened

*Retention → History* keeps, for every run, the plan it was given and the outcome it produced,
side by side. "Exactly what the preview said" is a claim; keeping both halves is what makes it
checkable.
