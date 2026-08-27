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

A preferences form can post straight to Lock:

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

A signed-in visitor may only record consent for themselves. Without that rule the endpoint is a
way to record that somebody *else* agreed to marketing.

Only a **change** is written. Re-recording the same answer on every page view would make the
ledger unreadable and bury the decisions that matter.

`wording` is worth filling in. When somebody disputes it in two years, the useful record is not
"granted 2026-08-27" but the sentence they were shown when they granted it.

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

## Purposes

Defined in *Settings → Consent*, each with a key, a label, a description and a lawful basis. Four
ship as a starting point: marketing, analytics, personalisation and sharing with partners.

**Renaming a key orphans its history**, because records are keyed by it. Change labels freely;
change keys deliberately.
