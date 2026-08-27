<?php

/**
 * Unit-suite bootstrap.
 *
 * No Craft. The unit suite covers only the arithmetic that has no business touching a database —
 * schedule occurrences, retention cutoffs, deadline counting and the plan fingerprint. Everything
 * that needs real elements is in `tests/integration/checks.php`, because a fixture that fakes an
 * element query is a test of the fake.
 */

require __DIR__ . '/../vendor/autoload.php';
