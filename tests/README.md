# Testing Lock

There is no PHP on this Mac; everything runs in the plugin-testing container.

```sh
cd ~/Sites/plugin-testing

# 121 checks — the whole plugin against a real database
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-lock/tests/integration/checks.php

# 16 screens, plus the front-end intake end to end over HTTP
docker exec ddev-plugin-testing-web bash /var/www/craft-lock/tests/integration/cp-smoke.sh

# 29 unit tests
docker exec -w /var/www/craft-lock ddev-plugin-testing-web php vendor/bin/phpunit

docker exec -w /var/www/craft-lock ddev-plugin-testing-web php vendor/bin/ecs check
cd ~/Sites/craft-lock && ./lint.sh
```

Use `docker exec`, not `ddev exec`: the harness's web container takes longer to pass its health
check than ddev waits, and the resulting restart loop kills a long script mid-run with "MySQL
server has gone away".

## What is where, and why

**`checks.php`** is the real suite. It exercises settings against the shapes the control panel
actually posts, the collectors against real elements and real tables, the plan-and-execute
invariant, and an anonymisation verified by reading the rows back rather than by trusting a return
value.

It is idempotent and self-cleaning: settings are captured up front and restored at the end, every
fixture lives under `lock-test.invalid`, and the teardown runs first as well as last so a failed
run does not poison the next one. Running it against a configured site neither reconfigures it nor
leaves test people in it. It also cleans up after `cp-smoke.sh`.

The content-scan checks need a plain-text field somewhere on the site. Rather than push a new
field into a contended project config, the script finds one that already exists — and prints a
skip notice when there is none, instead of passing silently.

**`cp-smoke.sh`** catches the class of failure no PHP check can: a Twig error in a template that
is only ever rendered by a real request. It signs in, fetches every screen, then fetches the two
portal pages and posts a real request through the intake form **signed out**, which is the path
that has to work on a site whose owner has never opened the control panel.

It follows the plugin's edition rather than hard-coding it: on Lite the Pro screens are expected
to answer 403, and a 200 there is a failure. A sign-in that fails skips the control-panel half and
still runs the public half, because the harness is shared and its admin credentials move.

**`tests/unit`** covers only arithmetic that has no business touching a database — schedule
occurrences, retention cutoffs, the plan fingerprint. Everything else is an integration check,
because a fixture that fakes an element query is a test of the fake.

## Switching edition

```php
Craft::$app->getPlugins()->switchEdition('lock', 'pro');
Craft::$app->getProjectConfig()->flush();   // both halves — see [[craft-plugin-gotchas]]
```

Worth doing both ways: the Pro screens have to be reachable on Pro and refused on Lite, and only
one of those is the default.
