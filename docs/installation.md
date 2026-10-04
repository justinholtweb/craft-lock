---
title: Installation
slug: installation
order: 10
summary: Requirements, install, editions, and what Lock talks to when it is there.
---

# Installation

```bash
composer require justinholtweb/craft-lock
php craft plugin/install lock
```

Craft 5.3 or later, PHP 8.2 or later. No other dependencies — Lock talks to Commerce, Formie,
Freeform, Comments and Toss when they are there, and records them as *not searched* when they are
not.

## Editions

**Everything needed to answer a request lawfully is in Lite**, and Lite is free — it is not a
trial and it does not expire. Pro is the recurring half: rules that delete on a timer, the register
a DPO maintains, and the reminders that stop a statutory deadline passing unnoticed.

| | Lite | Pro |
| --- | --- | --- |
| Price | **Free** | **$149** one-off, $119/year renewal |
| Request intake, verification, status lookup | ✓ | ✓ |
| The dossier, across all fourteen sources | ✓ | ✓ |
| Export, anonymise, erase | ✓ | ✓ |
| The consent ledger | ✓ | ✓ |
| The activity ledger | ✓ | ✓ |
| Legal holds and the suppression list | ✓ | ✓ |
| Retention rules and their scheduling | — | ✓ |
| The Article 30 register and its report | — | ✓ |
| Deadline reminders | — | ✓ |

A site on Lite can comply in full. It does the repeating parts by hand.


## The first fifteen minutes

**1. Say who you are.** *Lock → Settings*: the organisation's name, the person who answers, a
contact address, and the URL of your privacy policy. All four go on correspondence with data
subjects and on the Article 30 report.

**2. Put the form on the site.** The intake form is Twig you write, posting to
`craft.lock.actionUrl()`. There is a copy-and-paste starting point in the
[README](../README.md#subject-access-request-intake). Link it from your privacy policy — Article
12(2) asks you to *facilitate* the exercise of these rights, and a form nobody can find does not.

**3. Check where Lock will look.** *Settings → Where to look* lists every source, whether it is
available, and what it searches. Anything absent says so. If the site has a table Lock cannot know
about — an old import, a mailing list, a module's enquiry log — add it at the bottom of that
screen, and say whether Lock may delete from it, anonymise in it, or only read it.

**4. Run a search on yourself.**

```bash
php craft lock/erase/assemble --email=you@example.com
```

That is the moment most sites discover what they are holding.

**5. Put two commands on cron.**

```
0 3 * * *  cd /path/to/site && php craft lock/requests/deadlines
15 3 * * * cd /path/to/site && php craft lock/retention/due
```

The first is the one that matters. Nothing else on the site will tell you it is about to miss a
statutory deadline while there is still time to do something about it. On Lite it still expires
dead confirmation links but sends no reminders, and `lock/retention/due` exits non-zero — leave it
off a Lite site's crontab.

If the site has no cron, *Settings → Retention rules → Triggered by → Control panel traffic* fires
the retention half from control-panel page loads. It only ever queues a job; nothing is deleted
inside a page request.

## Permissions

| Permission | Lets somebody |
|---|---|
| See requests, the ledger and the register | read everything, change nothing |
| Work requests | assemble, export (including the whole consent ledger), correspond, close |
| Anonymise and delete personal data | actually carry out an erasure |
| Place and lift legal holds | stop a deletion, or unstop it |
| Edit the record of processing activities | maintain the register |
| Run retention rules | run a purge by hand |

Without the first one, Lock is not in the navigation at all. Deleting a request outright needs an
administrator, and works even when admin changes are switched off.

Erasure is deliberately separate from working a request. Somebody in support should be able to
answer *what have you got on me* without being one click from *and now delete all of it*.

## Verifying the install

```bash
php craft lock/report/status
```

Prints the edition, open and overdue requests, consent counts, which sources are available, and
how many kinds of data have no retention rule pointed at them.
