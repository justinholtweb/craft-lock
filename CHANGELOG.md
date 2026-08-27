# Release Notes for Lock

## 5.0.0 — 2026-08-27

Initial release.

Version 5.0.0 rather than 1.0.0: the whole `justinholtweb` family numbers by the Craft major it
targets, so a glance at a version says which Craft it runs on.

### Added

- **Subject access request intake.** A front-end form, an emailed confirmation link, a status page
  a subject can check with their reference, and a deadline that starts counting on its own.
- **Dossier assembly** across fourteen sources — accounts, addresses, Commerce orders and carts
  (matched by email, so guest orders are found), Formie, Freeform, Comments, entries by authorship
  and by content, uploaded files, sign-in records, arbitrary tables you name, Toss's consent rows,
  and Lock's own consent, request and log data.
- **Export** as one archive containing the same disclosure as HTML, CSV and JSON, with a covering
  note in plain English saying where the search looked and what it could not read.
- **Anonymise or erase**, decided per record by the source that owns it, with a materialised plan
  the operator approves and a fingerprint that stops the run if the data moved in between.
- **Erasure certificates and a suppression list**, so an erased address cannot be quietly
  re-imported.
- **A consent ledger** keyed to people rather than browsers, with the evidence Article 7(1) asks
  for, and withdrawal recorded as a new entry rather than an overwrite.
- **An append-only activity ledger** of every read, export, erasure, consent change and purge.
- **Legal holds**, per person or site-wide, that outrank both retention and erasure.
- **Retention rules** (Pro) that run on a schedule, sharing the plan-and-execute path with a
  hand-run erasure, and skipping anybody with an open request or a hold.
- **The Article 30 register** (Pro), cross-checked against what the site demonstrably holds, with a
  printable report and a `lock/report/gaps` command for CI.
- Console commands for every part of it, and a `craft.lock` Twig variable for the front end.
