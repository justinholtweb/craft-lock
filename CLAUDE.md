# Lock — Craft CMS 5 Plugin

## Project Overview

Lock does GDPR and DSAR governance: subject access request intake, "everything we hold on this
person" assembled across every source the site has, anonymise vs. erase, a consent and
processing-activity ledger, and retention rules that purge automatically. Distributed as
`justinholtweb/craft-lock`. **Free Lite, Pro $149** ($119/year renewal).

The pitch it was built to: *Craft's store has cookie banners and nothing else.* Toss and Jack
write the policy; this is the plugin that makes it true.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step and no runtime dependencies. Eight tables. Optional integrations with Commerce,
  Formie, Freeform, Verbb Comments and Toss, all guarded by `isAvailable()`.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\lock`
- Package: `justinholtweb/craft-lock`
- Handle: `lock`

### One collector per source, doing all three jobs

`collectors/CollectorInterface` — **find it, plan what happens to it, do that** — plus retention,
which is the same machinery pointed at a different question. Fourteen ship. The moment finding and
erasing live in separate classes they start to disagree: the disclosure lists a table the eraser
has never heard of, or the eraser clears a column the disclosure never showed anyone. Both are
failures a site only discovers when a regulator asks.

`BaseCollector` owns timing, error capture and the default planning rules, so subclasses implement
`find()` and `apply()` and nothing else. `collect()` is `final` — a collector that threw would
abandon the other thirteen sources, so the base catches and records the problem on the bundle,
which makes the dossier report itself as **partial**. That honesty is the feature: nine sources
out of ten with no mention of the tenth is what turns a handled request into a complaint.

### The plan is materialised, and execution reads it

`services/Erasure` builds an `ErasurePlan` of specific records with a decided action each. `run()`
walks that list and never re-searches. Re-searching would be shorter and is wrong twice: rows
created between preview and button would be deleted unseen, and rows the preview listed might be
gone, so the report would describe a different set from the one approved. The plan carries a
`fingerprint()` (source|key|action, hashed); the CP posts it back and a mismatch throws.

The fingerprint deliberately excludes labels — a renamed order is the same deletion.

### Retention is erasure with a different way of choosing records

`services/Retention::preview()` asks a collector for `stale()` records and hands them to
`Erasure::planFor()`. Same plan, same executor, same ledger, so an automated nightly purge is held
to every guarantee a hand-run erasure gets.

`ErasureTarget::$subjectEmail` exists for this: a sweep has a different person on every row, and
each has to get **their own** pseudonym. Ten thousand orders sharing one pseudonym are ten
thousand orders that are linkable again.

### Anonymise is not a softer erase

Each record declares `erasable` / `anonymisable` / `retainReason`, and the collector that owns the
data gets the last word. Completed Commerce orders are `erasable = false` and keep country and
region when anonymised — the parts a VAT return is built from. Logs are neither, with the reason
attached, and the remedy is a retention rule on whole files. Consent records are never deleted
(Article 7(1): you must be able to demonstrate consent).

### Storage decisions

- **Retention rules → project config.** A rule that deletes personal data on a timer is a change
  to what the site does; it should arrive by deploy. Cost: it cannot be added on production with
  admin changes off. Intended.
- **The Article 30 register → the database.** A living document a DPO maintains and prints;
  nothing in it changes behaviour.
- **`lock_erasures` holds no address.** A table of "people we deleted" listing their addresses has
  not deleted anybody. Keyed hash only — enough for a membership test, not for enumeration.
- **User foreign keys are `SET NULL`, never `CASCADE`.** Deleting a person must not delete the
  evidence that their request was handled. The one cascade is request → timeline.
- **Lock's own tables hold the keyed hash, never the address.** `lock_activity`, `lock_runs` and
  the stored plan carry `subjectHash` and source|key|action only — no labels, which name people.
  An erasure request is itself anonymised when it is closed as completed, and its dossier files
  deleted. `checks.php` greps every `lock_*` table and `storage/lock` for the address afterwards.
- **The public consent endpoint grants only for a signed-in user, against their own address.**
  Signed out, it can withdraw (marked unverified) and answers identically whatever happened.
- **`lock_activity` has no FK to the request**, so the line saying a request was deleted outlives
  the row it is about.

## Traps found while building this

- **`token` is Craft's own query-string parameter.** `?token=…` on the verification link gets
  intercepted before routing and answers `400 Invalid token`, so every confirmation email
  dead-ends. The route is `lock/verify/<code>`.
- **`yii\base\Event` has no `isValid`.** Reading it throws `UnknownPropertyException`, so a
  cancelable event must extend `craft\events\CancelableEvent`. A veto hook written against a plain
  event fatals the first time anything listens.
- **`['like', $col, $value, false]` disables the automatic `%` wrapping as well as the escaping.**
  `Reference::next()` matched only the literal prefix, found nothing, and made every reference
  `DSAR-2026-0001` — a duplicate-key error on the second request of the year. Write the `%`.
- **PHP 8 identifiers swallow curly quotes.** `"rule “$rule->key” ran"` parses the property as
  `key”` and throws `UnknownPropertyException`. Braces, always: `{$rule->key}`.
- **Craft 5's `tokens` table has no `userId`.** A token cannot be attributed to anybody, so it is
  not in the disclosure — claiming otherwise would be worse than omitting it. `sessions`,
  `authenticator` and `webauthn` do carry one and are.
- **`elements_sites.content` is keyed by field *layout element* UID**, and Craft's `Json::encode`
  escapes slashes and quotes — so a `LIKE` over it needs the JSON-escaped needle too, or it misses
  exactly the values worth escaping. `helpers/ContentScan` handles both.
- **Formie keeps submission values in its own `formie_submissions.content`**, not in
  `elements_sites`, so the entry scan does not reach them. Freeform gives every form field its own
  column, so its collector reads the schema at query time.
- **Every Craft element is `Traversable`.** `helpers/Readable` treats only `is_array()` as a
  container and resolves element queries explicitly; anything else walks an element into its
  attribute values.
- **Craft does not copy route parameters into the query string.** `getParam('code')` never saw the
  `<code>` from `lock/verify/<code>`, so every verification link answered "expired". Take it as an
  action argument.
- **Opening the verification link changes nothing; the button on that page does.** Corporate mail
  scanners (Safe Links, Mimecast) fetch every link, and a GET that verifies lets anybody's request
  be confirmed by a robot.
- **Craft 5 has no `Users::getUserByEmail()`**, and `getUserByUsernameOrEmail()` matches usernames
  too. `Subject` uses `User::find()->email(Db::escapeParam($email))->status(null)`.
- **A console `beforeAction()` returning false exits 0**, so a Lite cron looked successful. Gate
  inside each action and return `ExitCode::UNAVAILABLE`.
- **Dry-run-first counts dry runs.** It used to count only real runs, so every run of a new rule
  was forced dry for ever and nothing was ever purged.
- **`LIKE '%addr%'` is a pre-filter, never the decision.** It finds `alex@corp.com` for
  `lex@corp.co`. `helpers/Address` decides matches and does the rewriting, with one bounded pattern
  for both stored forms.
- Adding `P60D` to a timezone-aware `DateTime` across a DST boundary moves the timestamp by 60
  days ± an hour. Compare deadlines in **days**, never in seconds.

See `[[craft-plugin-gotchas]]` in the shared memory for the family-wide ones — the typed-int `''`
TypeError, lightswitches posting strings, the editable table's blank trailing row, never marking a
setting `required` — all handled in `Settings::setAttributes()`.

## Testing

No local PHP on this Mac. Everything runs in the plugin-testing container.

```sh
cd ~/Sites/plugin-testing
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-lock/tests/integration/checks.php   # 150 checks
docker exec ddev-plugin-testing-web bash /var/www/craft-lock/tests/integration/cp-smoke.sh                  # 16 screens
docker exec -w /var/www/craft-lock ddev-plugin-testing-web php vendor/bin/phpunit                           # 39 tests
docker exec -w /var/www/craft-lock ddev-plugin-testing-web php vendor/bin/ecs check
./lint.sh
```

Use `docker exec`, not `ddev exec` — see `[[plugin-testing-harness]]`.

`checks.php` is idempotent and self-cleaning: settings are captured and restored, fixtures live
under `lock-test.invalid` and are removed at the end. It also cleans up after `cp-smoke.sh`, which
posts a real request through the front-end form.

The unit suite covers only arithmetic that has no business touching a database — schedule
occurrences, retention cutoffs, the fingerprint. Everything else is an integration check, because
a fixture that fakes an element query is a test of the fake.

## Coding conventions

- `Craft::t('lock', '…')` for user-facing strings
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template
- Never mark plugin settings `required`
- The activity ledger has no update or delete path, and the Activity screen has no buttons. An
  audit trail administrators can quietly tidy is a diary.
- Every response the public intake gives is identical — success, unknown address, rate limit,
  honeypot. Any difference is an account enumeration oracle.
