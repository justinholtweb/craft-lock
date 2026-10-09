# Release Notes for Lock

## 5.1.0 - 2026-10-09
### Added

- **Consent from Formie and Freeform forms, confirmed by email.** Map a form's checkbox or agree
  field to a purpose under *Settings → Consent* (`formConsents`), as a grant or a withdrawal. A
  grant from somebody signed in, for their own address, is recorded at once. A grant from anybody
  else waits: Lock emails the address a link, and records the grant, marked verified, only when
  the button on that link's page is pressed. The link works once, expires after
  `verificationTtl` hours, is stored only as a hash, and opening it changes nothing, so a mail
  scanner can't confirm anybody. Withdrawals apply at once, as before. The form's own response is
  the same whatever Lock did, and the confirmation email shares the intake form's rate limit. In
  Lite.
- Waiting confirmations are counted on the Consent screen, disclosed in a subject's dossier,
  deleted by an erasure, and deleted by garbage collection once their link expires.
- `formConsent->capture()`, for sites with their own form handler.

### Changed

- The portal pages carry `<meta name="referrer" content="no-referrer">`, so the code in a
  verification or confirmation link can't leak through the `Referer` header even where a server
  sets its own `Referrer-Policy`.
- The rate limit moved from the portal controller to `helpers\RateLimit`.
  `PortalController::rateKeyForEmail()` still works.

## 5.0.0 - 2026-10-03

Initial release.

Version 5.0.0 rather than 1.0.0: the whole `justinholtweb` family numbers by the Craft major it
targets, so a glance at a version says which Craft it runs on.

### Added

- **Subject access request intake.** A front-end form that answers identically whatever happened,
  an emailed confirmation link that opens a confirm button rather than acting on its own, a status
  page a subject can check with their reference, and a deadline that starts counting on its own.
  Rate limited per address, per IP and site-wide.
- **Dossier assembly** across fourteen sources — accounts, addresses, Commerce orders and carts
  (matched by email, so guest orders are found), Formie, Freeform, Comments, entries by authorship
  and by content, uploaded files, sign-in records, arbitrary tables you name, Toss's consent rows,
  and Lock's own consent, request and log data.
- **Export** as one archive containing the same disclosure as HTML, CSV and JSON, with a covering
  note in plain English saying where the search looked and what it could not read.
- **Anonymise or erase**, decided per record by the source that owns it, with a materialised plan
  the operator approves and a fingerprint, required in the control panel and on the console, that
  stops the run if the data moved in between. Answering an erasure request anonymises the request
  and deletes its dossier, and Lock's own ledgers keep a keyed hash rather than the address.
- **Erasure certificates and a suppression list**, so an erased address cannot be quietly
  re-imported.
- **A consent ledger** keyed to people rather than browsers, with the evidence Article 7(1) asks
  for, and withdrawal recorded as a new entry rather than an overwrite. Signed-in members record
  their own decisions; signed-out visitors can withdraw but never grant.
- **An append-only activity ledger** of every read, export, erasure, consent change and purge.
- **Legal holds**, per person or site-wide, that outrank both retention and erasure.
- **Retention rules** (Pro) that run on a schedule, sharing the plan-and-execute path with a
  hand-run erasure, skipping anybody with an open request or a hold, and reporting on their first
  run before they delete on the second.
- **The Article 30 register** (Pro), cross-checked against what the site demonstrably holds, with a
  printable report and a `lock/report/gaps` command for CI.
- Console commands for every part of it, and a `craft.lock` Twig variable for the front end.
