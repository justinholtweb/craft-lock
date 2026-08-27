# Answering a request

## What the law actually asks

One month, from the day the request arrives. Two more months on top if the request is genuinely
complex — but you must claim that within the first month, and tell the subject why. A refusal has
to say why, and has to tell the person they can complain to a supervisory authority.

A late answer is itself a breach of Article 12(3), separately from whatever the request was about.
That is why the deadline is on the nav badge, in the list, on the detail screen, and in a nightly
email, and why the clock starts by itself.

## The route in

**From the site.** Somebody fills in the form. Lock creates the request, sets the deadline, and
emails the address that was typed. Nothing else happens until they follow the link.

That link is what makes the whole thing safe. The email says, in as many words: *if this was not
you, do nothing at all — nothing about you has been looked at, changed or deleted.* Somebody
whose address was typed into a form by a stranger finds out at that moment.

A signed-in person asking about the address they are signed in with skips the confirmation.
Emailing them a link to prove they are the account they are currently authenticated as is theatre.

**By hand.** Requests arrive by post, by phone and in an ordinary support email. *Requests →
Record a request* takes those. It skips confirmation — you have already satisfied yourself who
asked — and starts the clock immediately.

## Working it

The detail screen is in the order of the job.

**Assemble.** One button. Lock searches every source and writes an archive: a readable page, a
CSV, a JSON file, and a covering note. If the archive is encrypted — it is by default — the
password is shown to you **once** and never stored. A password kept next to the file it protects
protects nothing. Lost it? Assemble again; it takes seconds.

Read the covering note before you send it. If a source could not be read, the note says so, and
you should decide whether to fix it and re-run rather than sending an incomplete disclosure that
does not look incomplete.

**Decide.** For an erasure request, *Show me what would happen* lists every record and what it
would get: deleted, anonymised, or kept with a reason. Read the kept ones — they are the part you
may need to explain. Then carry out that plan. If the data has moved since the preview, the run
refuses rather than acting on a list nobody looked at.

**Close it.** Completed, refused, or withdrawn. A refusal needs its reason: Article 12(4) requires
it, and the email Lock sends on a refusal also tells the person they can complain to their
supervisory authority and go to court. Neither of those needs your permission.

## The extension

*Need longer?* claims the Article 12(3) extension. It asks for a reason and sends that reason to
the subject, because an extension the subject is not told about is not an extension. It can be
claimed once. There is no second one.

## What the subject sees

- **On submitting:** the same sentence whether or not you hold anything about them. Anything else
  is an oracle: a form that says "no account found" tells a stranger whether an address is
  registered.
- **On confirming:** their reference, what they asked for, and the date they will hear by.
- **Any time after:** `/lock/status`, with their reference *and* their address. Both, because a
  reference is sequential by design — it is meant to be quoted in correspondence.
- **On completion:** what was decided, in your words.

Every one of those pages can be overridden. A site template at `lock/verify.twig` or
`lock/status.twig` wins over Lock's built-in one. The built-ins are deliberately plain and
self-contained so that the link in a verification email never 404s on a fresh install.

## Reminders

`php craft lock/requests/deadlines`, nightly. It emails staff when a request crosses a reminder
threshold — seven days out and two by default — and expires requests whose confirmation link ran
out.

Expired requests are not deleted. "Somebody asked and never confirmed it was them" is worth being
able to show, particularly if the address belonged to somebody else.
