---
title: Anonymise and erase
slug: erasure
order: 30
summary: Why the two are different decisions, what each collector allows, and how a plan is approved and run.
---

# Anonymise and erase

## The distinction

**Erase** deletes the record. **Anonymise** keeps the record and destroys the person in it.

Most of the time the second is the right answer, and not because it is gentler. A completed order
is a financial record — six years in the UK, ten in Germany — and Article 17(3)(b) says the right
to erasure does not override a legal obligation to keep something. But the *order* is what the
obligation covers, not the customer's name inside it. Delete the order and you have destroyed
accounting evidence. Leave it intact and you have not erased anybody. Empty it and both problems
go away: the money, the tax, the line items, the country and the date stay; everything that says
who does not.

The default treatment is anonymise. You can ask for deletion, and each source will do it where it
can and tell you where it cannot.

## What each source will and will not do

| Source | Anonymise | Delete | Why |
|---|:-:|:-:|---|
| User account | ✓ | ✓ | administrators are skipped — remove one deliberately from Users |
| Addresses | ✓ | ✓ | anonymising keeps country and region, which is what a VAT return needs |
| Commerce order (completed) | ✓ | ✗ | a financial record |
| Commerce cart | ✗ | ✓ | not a financial record |
| Subscription (live) | ✗ | ✗ | a running contract — cancel it first |
| Form submissions | ✓ | ✓ | |
| Comments | ✓ | ✓ | anonymising keeps the text and removes the commenter |
| Entry the person wrote | ✗ | ✗ | site content with a URL; the byline follows the account |
| Entry mentioning them | ✓ | ✗ | the address is replaced in place |
| Uploaded files | ✓ | ✗ | the attribution is personal data; the file is usually a page |
| Sessions, passkeys, 2FA | ✗ | ✓ | |
| Consent records | ✓ | ✗ | Article 7(1) — you must keep the proof |
| Previous requests | ✓ | ✗ | evidence that you answered; anonymised once closed |
| Log files | ✗ | ✗ | see below |

### Logs

A log file is disclosed and never rewritten. Its value is that it has not been edited; cutting one
line out of the middle destroys that, and leaves a file which is now evidence of nothing.

The lawful answer to personal data in logs is storage limitation, and Lock offers it: a retention
rule on the `logs:files` scope deletes whole log files once they are older than the period you
have chosen.

## Anonymising an account

Four things at once, because any three of them leave a way back:

1. Username, email and name are overwritten with a pseudonym.
2. The profile photo is deleted.
3. Custom fields are emptied.
4. The password is replaced with a random one, the sessions are dropped, and the account is
   suspended.

An "anonymised" account that still accepts the old password is still that person's account.

## Pseudonyms

The replacement is derived from the address with a keyed hash, so it is **stable**: the same
person anonymises to the same token in every table, and rows that used to join still join. It is
also **not reversible** to an address by anyone who does not already have both the address and
your security key.

In a bulk retention sweep each record is pseudonymised from **its own** address, not the
sweeper's. Give ten thousand anonymised orders one shared pseudonym and they are provably the same
customer again, which undoes the anonymisation you just performed.

The replacement address uses a `.invalid` domain. RFC 2606 reserves it precisely so it can never be
delivered to or registered by anybody.

## The plan

Nothing is deleted from a search. Lock builds a **plan** — a specific list of specific records,
each with a decided action — and the run reads that list.

The reason is not caution for its own sake. If execution re-ran the search, rows created between
the preview and the button would be deleted without anybody having seen them, and rows the preview
listed might have gone — so the report of what happened would describe a different set from the
one that was approved. The plan carries a fingerprint; if it no longer matches, the run stops and
asks you to preview again. There is no way to run without one: the control panel refuses an
erasure that was not previewed, and the console needs `--fingerprint` alongside `--force`.

Nothing is planned or run for a request that has not been confirmed by the person who made it.

## Afterwards

Lock writes a certificate: a keyed hash of the address, the pseudonym, what was done and when. It
holds no address, because a table of "people we deleted" that lists their email addresses has not
deleted anybody.

The same goes for everything else Lock writes about the run. The run ledger keeps the plan as
source, key, action and reason per record, against the keyed hash — not the labels, which are
built from the data ("Order #1042 for ada@…"). The activity ledger records people by hash only,
and takes an address out of any summary that names one. Error messages are scrubbed before they
are stored or logged, because a database error quotes its SQL with the bound values in it. Consent
records keep the wording and the policy version and lose the IP address, browser and page.
Closing the erasure request then anonymises the request and deletes its dossier — see
[Requests](requests.md).

It also writes a **suppression entry**, so the same address can be blocked from coming back:

```twig
{% if craft.lock.isSuppressed(row.email) %}{% continue %}{% endif %}
```

```bash
php craft lock/erase/check --email=ada@example.com
```

An erasure that next week's CSV import undoes was never an erasure. That is why Lock keeps a hash
of an address it was told to forget — you need it in order to honour the forgetting, which
Article 17 contemplates.

## From the command line

```bash
php craft lock/erase/preview --email=ada@example.com               # changes nothing; prints the fingerprint
php craft lock/erase/preview --email=ada@example.com --mode=erase
php craft lock/erase/run     --email=ada@example.com                # a dry run
php craft lock/erase/run     --email=ada@example.com --force --fingerprint=<from preview>
```

`--force` without `--fingerprint` exits with a usage error, and a fingerprint that no longer
matches exits non-zero without changing anything. Pass the same `--mode` to both commands — the
mode is part of the plan.

## What stops it

An open request of another kind, or a legal hold. Both come back as a sentence rather than a
refusal — an operator told "blocked" goes looking; an operator told "held for the Fenwick claim
until 30 June" already knows.
