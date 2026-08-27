# Installation

```bash
composer require justinholtweb/craft-lock
php craft plugin/install lock
```

Craft 5.3 or later, PHP 8.2 or later. No other dependencies — Lock talks to Commerce, Formie,
Freeform, Comments and Toss when they are there, and records them as *not searched* when they are
not.

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
statutory deadline while there is still time to do something about it.

If the site has no cron, *Settings → Retention rules → Triggered by → Control panel traffic* fires
the retention half from control-panel page loads. It only ever queues a job; nothing is deleted
inside a page request.

## Permissions

| Permission | Lets somebody |
|---|---|
| See requests, the ledger and the register | read everything, change nothing |
| Work requests | assemble, export, correspond, close |
| Anonymise and delete personal data | actually carry out an erasure |
| Place and lift legal holds | stop a deletion, or unstop it |
| Edit the record of processing activities | maintain the register |
| Run retention rules | run a purge by hand |

Erasure is deliberately separate from working a request. Somebody in support should be able to
answer *what have you got on me* without being one click from *and now delete all of it*.

## Verifying the install

```bash
php craft lock/report/status
```

Prints the edition, open and overdue requests, consent counts, which sources are available, and
how many kinds of data have no retention rule pointed at them.
