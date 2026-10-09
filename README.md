# Lock

GDPR and DSAR governance for Craft CMS.

The Craft plugin store has cookie banners. It has nothing behind them. Lock is the part that makes
a privacy policy true: a door for subject access requests, an assembly of **everything the site
actually holds about a person** across every source it has, the choice between anonymising and
erasing, a ledger of consent and processing you can put in front of a regulator, and retention
rules that delete on a timer instead of on a good intention.

[Toss](https://github.com/justinholtweb/craft-toss) and
[Jack](https://github.com/justinholtweb/craft-jack) write the policy. This is the plugin that
makes it true.

## Requirements

Craft CMS 5.3.0 or later, PHP 8.2 or later.

## Installation

```bash
composer require justinholtweb/craft-lock
php craft plugin/install lock
```

## The idea

**Everything a data subject can ask for is one question with two answers.** *What have you got on
me?* and *now get rid of it.* Both need the same thing first: a complete and honest list of every
place this site holds personal data about one person. Get that list wrong and the disclosure is
incomplete, the erasure is partial, and neither failure announces itself.

So Lock is built around one object per source of personal data — a **collector** — and each one
does all three jobs: find it, decide what should happen to it, and do that.

```
                       ┌──────────────┐
   ada@example.com ──► │  collectors  │ ──► the dossier      "what have you got on me"
                       │  (14 of them)│ ──► the plan   ──►   "now get rid of it"
                       └──────────────┘
```

The alternative — a finder here and an eraser there — drifts. The disclosure lists a table the
eraser has never heard of; the eraser clears a column the disclosure never showed anyone. Both are
the kind of failure a site only discovers when a regulator asks.

### Three things that are load-bearing

**The plan is decided before anything happens, and execution reads it.** A preview lists specific
records with a decided action each. Pressing the button runs *that list* — it never goes back and
searches again. Re-searching would be shorter code and it is wrong twice over: rows created
between the preview and the button would be deleted without anybody having seen them, and rows
the preview listed might be gone. An approval that does not bind is not an approval. The plan is
fingerprinted, the fingerprint is posted back, and a mismatch stops the run.

**Anonymise is not a softer erase.** A completed order is a tax record — six years in the UK, ten
in Germany — and Article 17(3)(b) is explicit that erasure does not override a legal obligation to
keep something. But the *order* is what the obligation covers, not the customer's name inside it.
So Lock empties the order and keeps it: the money, the tax, the line items, the country and the
date all stay, and everything that says who does not. The pseudonym is derived from the address,
so two anonymised rows in different tables still line up as the same (now unidentifiable) person —
and each person in a bulk sweep gets their own, because ten thousand orders sharing one pseudonym
are ten thousand orders that are linkable again.

**Retention is erasure with a different way of choosing records.** Instead of "everything belonging
to this person", "everything in this scope older than this date" — and from there it is the same
plan, the same preview, the same executor, the same ledger. A nightly automated deletion is held
to exactly the guarantees a hand-run erasure gets, rather than being a second, less careful
deletion path.

## What it does

### Subject access request intake

A front-end form, a route for the confirmation link, and a page where somebody can check on a
request they already made with their reference and their address.

```twig
{% if craft.lock.intakeEnabled() %}
    <form method="post" action="{{ craft.lock.actionUrl() }}">
        {{ csrfInput() }}
        {{ redirectInput('privacy/thanks') }}

        <label>Your email address <input type="email" name="email" required></label>
        <label>Your name <input type="text" name="name"></label>

        <select name="type">
            {% for value, label in craft.lock.requestTypes() %}
                <option value="{{ value }}">{{ label }}</option>
            {% endfor %}
        </select>

        <label>Anything else we should know <textarea name="message"></textarea></label>

        {# A honeypot. Named for something a browser will autofill and a person will never see. #}
        <input type="text" name="confirmEmail" tabindex="-1" autocomplete="off" style="display:none">

        <button>Send it</button>
    </form>
{% endif %}
```

Submitting creates a row and emails **the address that was typed**. Nothing is disclosed and
nothing is deleted until somebody follows that link and presses the button on the page it opens.
Opening the link changes nothing, because corporate mail scanners open every link in every message;
only the button confirms. That step exists because an unverified erasure request is a
way to delete somebody else's account by typing their address into a form. The person whose
address it is finds out either way, which is the point.

The response is identical whether the address is known, unknown, or rate-limited. A form that says
"no account found" is an account enumeration oracle wearing a compliance hat.

### Everything we hold on this person

Fourteen sources, out of the box:

| | |
|---|---|
| **User account** | the account, custom fields, groups, photo, preferences |
| **Addresses** | the address book, including Commerce's |
| **Commerce** | orders, carts, subscriptions — **matched by email, so guest orders are found** |
| **Formie** | submissions with the address anywhere in the answers |
| **Freeform** | the same, across whatever columns the forms happen to have |
| **Comments** | Verbb Comments, signed in or as a guest |
| **Entries** | entries they wrote, and entries with their address in a field |
| **Uploaded files** | assets attributed to them |
| **Sign-in records** | sessions, sign-in and failed-sign-in history, 2FA, passkeys |
| **Other tables** | anything you name in settings — modules, old imports, a mailing list |
| **Toss** | cookie decisions and policy acceptances |
| **Consent records** | Lock's own ledger |
| **Previous requests** | Lock's own request table |
| **Log files** | `storage/logs`, searched line by line |

The last four disclose Lock's own storage. A privacy tool that exempts its own tables from the
disclosure it produces is not one.

Assembly produces a ZIP containing the same disclosure four ways — a readable HTML page, a CSV, a
JSON file for a portability request, and a covering note in plain English saying **where it
looked, what it found, and what it could not read**. All four are rendered from one array, so
they cannot come to describe different holdings.

A source that fails is recorded on the dossier and named in the covering note. Nine sources out of
ten with no mention of the tenth is the failure that turns a handled request into a complaint, and
it is invisible unless the tool goes out of its way to make it visible.

Add your own:

```php
use justinholtweb\lock\services\Collectors;
use justinholtweb\lock\events\RegisterCollectorsEvent;

Event::on(Collectors::class, Collectors::EVENT_REGISTER_COLLECTORS, function(RegisterCollectorsEvent $e) {
    $e->collectors[] = MyLoyaltySchemeCollector::class;
});
```

### Anonymise or erase

Every record says which of the two it supports, and why. Some say neither, and say why:

- A **completed order** cannot be deleted — it is a financial record — so it is emptied instead.
- A **published entry** the person wrote is neither. It is site content with a URL; deleting it
  is an editorial decision the plugin is not entitled to make. The byline follows the account, so
  anonymising the account takes their name off it.
- A **log file** is disclosed and never rewritten. A log is only useful because it has not been
  edited. The remedy Lock offers is the correct one: a retention rule that deletes whole log files.
- A **consent record** is never deleted, only anonymised. Article 7(1) says you must be able to
  demonstrate consent; delete the proof and every email you ever sent becomes unlawful in
  retrospect.
- An **administrator account** is skipped, with a note saying to do it deliberately from Users.

Anonymising an account is four things at once, because any three of them leave a way back: the
identifiers are overwritten, the photo is deleted, the custom fields are emptied, and the account
is locked out with its sessions dropped.

When it is done, Lock writes a certificate holding no address — a keyed hash, the pseudonym, and
the counts — and a **suppression entry**, so the same person cannot be silently re-imported from
next week's CSV. An erasure a re-import undoes was never an erasure.

```twig
{% if craft.lock.isSuppressed(row.email) %}
    {# skip: this address was erased #}
{% endif %}
```

### Consent and processing

Consent tied to a **person**, not a browser. A cookie banner records that a browser agreed to
analytics; it has no idea who that was — correct for an anonymous visitor, and useless the moment
somebody writes in and asks what you hold on them.

```twig
{% if craft.lock.allows(user.email, 'marketing') %}
    {# … #}
{% endif %}
```

It answers **false** for an address nobody has ever recorded a decision for. Silence is not
consent; Article 4(11) is explicit about that.

The front-end preferences form records a grant only for somebody who is signed in, and only
against their own account's address. A signed-out visitor can withdraw but never grant, because
a grant anyone can type in for anyone else is not evidence of anything.

**Formie and Freeform forms feed the ledger too.** Map a checkbox or agree field to a purpose and
a ticked box becomes a decision. A grant from somebody signed out waits while Lock emails the
address a confirmation link, and reaches the ledger only when the person at that address presses
its button. Withdrawals apply at once. See [Consent](docs/consent.md#formie-and-freeform).

Withdrawal is a new row, never an update. Overwriting would leave a ledger that cannot answer
"were you allowed to email them in March", which is exactly the question that gets asked.

Alongside it, an append-only **activity ledger**: every read, export, anonymisation, deletion,
consent change and automated purge, with who and when. There is no edit button on that screen. An
audit trail administrators can quietly tidy is a diary.

### Retention that runs

Rules read as sentences — *anonymise completed orders more than 7 years old* — and each carries
the reason for its period, because a retention schedule with no justification is a number somebody
guessed. Rules live in project config: a rule that deletes personal data on a timer is a change to
what the site does, so it arrives by deploy, reviewed, like every other one.

The first real run of a new rule reports instead of deleting. Subjects with an open request or a
legal hold are skipped, per record — purging the data somebody has just asked for a copy of would
answer their request honestly and emptily.

```bash
php craft lock/retention/due          # for cron
php craft lock/requests/deadlines     # reminders, and expiring unconfirmed requests
php craft lock/requests/tidy          # deletes built dossiers past their window
```

A built dossier is the most concentrated personal data on the site — one file with everything
about one person in it — so they expire.

### The Article 30 register

The record of processing activities, kept **in** the site so it stays true, and cross-checked
against what the site demonstrably holds. `lock/report/gaps` exits non-zero when the register
stops describing reality, which is the only mechanism that keeps one honest for longer than a
quarter:

```bash
php craft lock/report/gaps
  - “Form submissions (Formie)” holds personal data and is not named in any register entry.
  - Analytics: Legitimate interests claimed with no balancing test written down.
  - The retention rule “old carts” deletes data on a timer with no justification written down.
```

The printable report puts the register and the rules Lock actually enforces on one page. A
register that states one retention period while the site enforces another is the discrepancy an
inspection finds.

### Legal holds

A hold beats a retention rule and beats an erasure request. "We deleted it under our automated
retention policy" is not a defence to a preservation order, and Article 17(3)(e) lets you keep
what you need for legal claims. Holds can be per person or site-wide, with an expiry and a reason
written for somebody reading it in two years.

## Editions

**Everything needed to answer a request lawfully is in Lite** — intake, assembly, export,
anonymise, erase, the consent ledger, the activity ledger, legal holds, the suppression list.

**Pro** adds the recurring and the paperwork: retention rules and their scheduling, the Article 30
register and its report, and deadline reminders. A site on Lite can comply; it does the repeating
parts by hand.

Lite is free and it is not a trial. Pro is $149 with a $119/year renewal.

| | Lite | Pro |
| --- | --- | --- |
| Price | **Free** | **$149** one-off, $119/year renewal |
| Request intake, verification, status lookup | ✓ | ✓ |
| The dossier, across all fourteen sources | ✓ | ✓ |
| Export, anonymise, erase | ✓ | ✓ |
| The consent ledger | ✓ | ✓ |
| Consent from Formie and Freeform, confirmed by email | ✓ | ✓ |
| The activity ledger | ✓ | ✓ |
| Legal holds and the suppression list | ✓ | ✓ |
| Retention rules and their scheduling | — | ✓ |
| The Article 30 register and its report | — | ✓ |
| Deadline reminders | — | ✓ |


## Command line

```
lock/requests/list         open requests, soonest deadline first
lock/requests/deadlines    send reminders, expire unconfirmed requests   ← cron, daily
lock/requests/tidy         delete expired dossiers, trim the ledger
lock/erase/preview         what would happen to this person, and why
lock/erase/run             carry it out (needs --force and the preview's --fingerprint)
lock/erase/assemble        build a dossier from the shell
lock/erase/check           has this address been erased?
lock/retention/due         run every rule that is owed a run             ← cron, daily
lock/retention/preview     what one rule would do
lock/retention/status      rules, and the data no rule covers
lock/report/gaps           what the register does not account for        ← CI
lock/report/register       the Article 30 record as JSON
lock/report/status         where the site stands
```

## What this plugin is not

It is not legal advice, and it does not decide anything for you. It will not tell you whether your
legitimate interests balancing test is any good — only that you have not written one down. The
retention periods are yours to choose; Lock's job is to make sure the site enforces the ones you
chose, and to be honest with you when it cannot.

It also does not pretend the hard parts are solved. Toss records cookie consent against an
anonymous browser token, so Lock can only tie those decisions to somebody who was signed in, and
it says so in the disclosure rather than quietly leaving them out. A suppression list is a
membership test by construction — anyone holding your security key who guesses an address can
confirm it was erased. Both are inherent to the job, and both are documented rather than papered
over.

## Documentation

- [Installation](docs/installation.md)
- [Answering a request](docs/requests.md)
- [Anonymise and erase](docs/erasure.md)
- [Retention](docs/retention.md)
- [Consent](docs/consent.md)
- [Configuration](docs/configuration.md)
- [Extending](docs/extending.md)

## License

The [Craft License](LICENSE.md).
