---
title: Consent
slug: consent
order: 40
summary: A ledger about people rather than browsers, and what Article 7(1) needs you to be able to show.
---

# Consent

## What this is, and what a cookie banner is

A cookie banner records that **a browser** agreed to analytics. That is right for an anonymous
visitor and useless the moment somebody writes in and asks what you hold on them.

Lock's ledger records that **a person** agreed to something: which purpose, when, through what,
on what wording, from what page. That is what Article 7(1) means by being able to *demonstrate*
consent, and it is what a subject access request has to be able to answer.

The two are complementary. [Toss](https://github.com/justinholtweb/craft-toss) shows the banner
and holds the tags back until it is answered; Lock keeps the identified record and answers for it
afterwards. Where Toss's rows carry a user ID, Lock discloses them too — and says plainly in the
disclosure that decisions made while signed out cannot be attributed to anybody, rather than
quietly omitting them.

## Asking

```twig
{% if craft.lock.allows(currentUser.email, 'marketing') %}
    {# send the newsletter, fire the tag, sync the CRM #}
{% endif %}
```

It answers **false** for an address nobody has ever recorded a decision for. Silence is not
consent — Article 4(11) requires a "clear affirmative action" — and a helper that defaulted to
true would turn every unknown visitor into a lawful marketing target.

## Recording

A preferences page for signed-in members can post straight to Lock:

```twig
<form method="post">
    {{ csrfInput() }}
    {{ actionInput('lock/portal/consent') }}
    {{ redirectInput('account/preferences') }}
    <input type="hidden" name="wording" value="Email me about new products and offers.">

    {% set state = craft.lock.consent() %}
    {% for key, label in craft.lock.purposes() %}
        <label>
            <input type="checkbox" name="purposes[]" value="{{ key }}"{{ state[key] ? ' checked' }}>
            {{ label }}
        </label>
    {% endfor %}

    <button>Save</button>
</form>
```

**Signed in**, the decision is recorded against the account's own address, whatever address was
posted, and both grants and withdrawals count. That is the only case where Lock knows whose
decision it is.

**Signed out**, the endpoint takes withdrawals only, and says the same thing whatever happened:

```twig
<form method="post">
    {{ csrfInput() }}
    {{ actionInput('lock/portal/consent') }}
    {{ redirectInput('unsubscribed') }}
    <input type="email" name="email" required>
    {# no purposes[] posted: every purpose is withdrawn #}
    <button>Unsubscribe me</button>
</form>
```

- A **grant** from an anonymous form is ignored. Anybody could otherwise sign anybody up to
  marketing, and the ledger would hold a "consent" the person never gave — the opposite of what
  Article 7(1) asks you to be able to demonstrate. Ask people to sign in, or collect it through a
  [Formie or Freeform form](#formie-and-freeform), where Lock confirms it by email first.
- A **withdrawal** is honoured, because Article 7(3) requires withdrawing to be as easy as giving,
  and an unsubscribe form cannot demand a login. The worst a forged withdrawal can do is stop the
  site emailing somebody. It is marked `"verified": false` in the evidence, so the ledger never
  claims more than it knows.
- The response never includes the address's consent state, and is the same whether anything was
  recorded, nothing needed changing, the address is unknown, or the caller was rate limited.
  "Here is what this address agreed to" for any address you type would be a lookup service.

Both are rate limited per address and per IP. `policyVersion`, if posted, is kept to its first 64
characters.

Only a **change** is written. Re-recording the same answer on every page view would make the
ledger unreadable and bury the decisions that matter.

`wording` is worth filling in. When somebody disputes it in two years, the useful record is not
"granted 2026-08-27" but the sentence they were shown when they granted it.

## Formie and Freeform

Most consent on a Craft site is a ticked box on a Formie or Freeform form. Map the box to a purpose
in *Settings → Consent → Consent from Formie and Freeform* (or `formConsents` in
`config/lock.php`, see [Configuration](configuration.md#consent)): the plugin, the form handle, the
checkbox or agree field, the purpose, and whether ticking it **grants** or **withdraws**. Lock
finds the address in the form's first email field unless you name one, and keeps the field's own
description or label as the wording unless you write the wording yourself.

When a submission arrives with the box ticked:

| Who | Grant | Withdraw |
|---|---|---|
| Signed in, and the form's address is their account's | Recorded at once, marked verified | Recorded at once |
| Anybody else | **Waits for an emailed confirmation** | Recorded at once, marked `"verified": false` |

A waiting grant is not consent yet, and it is not in the ledger. Lock stores it, emails the
address a link, and records the grant only when that link's button is pressed — marked
`"verified": true`, `"confirmedBy": "email"`, with the time, the wording, the page, the form and
the submission ID. That is the "confirm by email" Article 7(1) needs for a box anybody could have
ticked for anybody.

The link works the way a request's verification link does:

- **Opening it changes nothing.** It shows a page with a *Yes, I agree* button. Mail scanners open
  every link in an email, and a link that confirmed on its own would be confirmed by a robot.
- **It works once**, and stops working after `verificationTtl` hours (48). An unconfirmed
  confirmation is deleted by garbage collection after that.
- **The code is 256 random bits**, stored only as its hash and looked up by an exact match.
- The page sends `no-store` and `no-referrer`. Override it with site templates at
  `lock/confirm.twig` (which must post `code` and `action=lock/portal/confirm` with a CSRF token)
  and `lock/confirmed.twig`.

The email is sent even when *Email the subject* is off, because without it the consent can never
be recorded. Sending it uses the intake form's rate limit: `intakeRateLimit` per address and per
IP, `intakeGlobalLimit` across the site.

Nothing Lock does changes what the form answers. The visitor sees Formie's or Freeform's own
success message whether a confirmation went out, nothing needed changing, or the rate limit
stopped it, so the form cannot be used to find out what an address has agreed to. A ticked
purpose the address already allows sends no email. An **unticked** box is no decision at all:
somebody sending a contact form without ticking the newsletter box has not withdrawn from
anything. Submissions the form plugin marks as spam, unfinished multi-page submissions, and
submissions made in the control panel or from the console are ignored.

The integration is in Lite. It needs Formie or Freeform installed and enabled, and does nothing
when neither is. A site with its own form handler can call the same entry point:

```php
use justinholtweb\lock\Plugin as Lock;

Lock::getInstance()->formConsent->capture('my-form', 'newsletter', $email, [
    ['purpose' => 'marketing', 'ticked' => true, 'text' => 'Email me the newsletter.'],
]);
```

Waiting confirmations are counted on the Consent screen, appear in a subject's disclosure, and are
deleted outright by an erasure: anonymising a request to email somebody would leave nothing worth
keeping.

## The ledger

Withdrawal is a new row, never an update to the old one. The state of a purpose is its newest row.
Overwriting would leave a ledger that cannot say what was true last March — and "were you allowed
to email them in March" is exactly the question that gets asked.

That also means the counts on the Consent screen are counted from the newest row per person per
purpose. Somebody who agreed, withdrew and agreed again is one person who allows it, not three.

## Ageing

Consent does not expire in law. Consent given four years ago to wording nobody kept is very hard
to rely on, so Lock marks records past a re-ask period — two years by default — as stale and shows
you how many there are. Nothing is revoked automatically; it is a prompt to ask again.

`consent:superseded` is the retention scope for the ledger. It anonymises decisions that a later
decision has already replaced, and never touches the current answer for a purpose whatever its
age.

Anonymising a consent record — by that rule or by an erasure — replaces the address with the
subject's pseudonym and clears the IP address, browser and page from the evidence. The purpose,
the decision, the date, the wording and the policy version stay, with the keyed hash that ties
them to the person if they ever come back and dispute it.

## Purposes

Defined in *Settings → Consent*, each with a key, a label, a description and a lawful basis. Four
ship as a starting point: marketing, analytics, personalisation and sharing with partners.

**Renaming a key orphans its history**, because records are keyed by it. Change labels freely;
change keys deliberately.
