#!/bin/bash
#
# Control-panel smoke test.
#
#   docker exec ddev-plugin-testing-web bash /var/www/craft-lock/tests/integration/cp-smoke.sh
#
# Signs in and fetches every screen Lock adds, then fetches the two front-end portal pages signed
# out. It catches the class of failure no PHP check can: a Twig error in a template that is only
# ever rendered by a real request. Run over localhost inside the container with a Host header, so
# it does not depend on ddev-router being up.
set -u
BASE="http://localhost"
JAR=/tmp/lock-cp-cookies.txt
rm -f "$JAR"

# Wrapped in a function and retried on the way through, because a container restart mid-run drops
# the session and turns every remaining page into a 302 to the login screen — which looks exactly
# like a broken plugin and is not one.
login() {
  rm -f "$JAR"
  local page csrf
  page=$(curl -s -c "$JAR" -b "$JAR" -H "Host: plugin-testing.ddev.site" "$BASE/admin/login")

  # Craft puts the token in a JS blob on some responses and in a hidden input on others; take
  # whichever is there rather than assuming.
  csrf=$(echo "$page" | grep -o 'csrfTokenValue":"[^"]*' | head -1 | cut -d'"' -f3)
  [ -z "$csrf" ] && csrf=$(echo "$page" | grep -o 'name="CRAFT_CSRF_TOKEN" value="[^"]*' | head -1 | cut -d'"' -f4)
  [ -z "$csrf" ] && { echo "  (no CSRF token on the login page — is the site up?)" >&2; return 1; }
  curl -s -c "$JAR" -b "$JAR" -H "Host: plugin-testing.ddev.site" -H "Accept: application/json" -H "X-Requested-With: XMLHttpRequest" \
    -d "loginName=admin&password=claudepassword&CRAFT_CSRF_TOKEN=$csrf&action=users/login" "$BASE/index.php" \
    | grep -q '"modelName":"user"'
}

# A failed sign-in skips the control-panel half rather than ending the run. The portal and intake
# checks below need no session at all — they are the half a member of the public uses — and they
# are worth having even on a site whose admin credentials have moved on.
CP=1
login && echo "login: ok" || { echo "login failed — skipping the control panel screens"; CP=0; }

# The Pro screens answer 403 on Lite by design, so the expectation has to follow the edition
# rather than be hard-coded — otherwise this either fails on Lite or passes vacuously on Pro.
if php /var/www/html/craft lock/report/status --json 2>/dev/null | grep -q '"pro": true'; then PRO=1; else PRO=0; fi
echo "edition: $([ "$PRO" = 1 ] && echo pro || echo lite)"

PRO_PATHS=" register register/new register/report retention retention/history "

fail=0

if [ "$CP" = "1" ]; then
for path in "" "requests" "requests/new" "consent" "activity" "register" "register/new" "register/report" "retention" "retention/history" "holds" "settings" "settings/collectors" "settings/retention"; do
  url="$BASE/admin/lock${path:+/$path}"
  code=$(curl -s -o /tmp/lock-page.html -w '%{http_code}' -b "$JAR" -H "Host: plugin-testing.ddev.site" "$url")

  if [ "$code" = "302" ]; then
    login && code=$(curl -s -o /tmp/lock-page.html -w '%{http_code}' -b "$JAR" -H "Host: plugin-testing.ddev.site" "$url")
  fi

  size=$(wc -c < /tmp/lock-page.html)

  if [ "$PRO" = "0" ] && [[ "$PRO_PATHS" == *" $path "* ]]; then
    if [ "$code" = "403" ]; then
      echo "  ✓ /admin/lock/$path  refused on Lite, as it should be"
    else
      echo "  ✗ /admin/lock/$path  HTTP $code — a Pro screen answered on Lite"
      fail=1
    fi
    continue
  fi

  if [ "$code" = "200" ] && [ "$size" -gt 2000 ]; then
    echo "  ✓ /admin/lock${path:+/$path}  ($size bytes)"
  else
    echo "  ✗ /admin/lock${path:+/$path}  HTTP $code, $size bytes"
    grep -o '<h1[^>]*>[^<]*' /tmp/lock-page.html | head -2
    grep -oE 'exception[^<]{0,200}' /tmp/lock-page.html | head -2
    fail=1
  fi
done

fi

# The front-end portal pages have to work with no session at all.
for path in "lock/status" "lock/verify/not-a-real-code"; do
  code=$(curl -s -o /tmp/lock-page.html -w '%{http_code}' -H "Host: plugin-testing.ddev.site" "$BASE/$path")
  size=$(wc -c < /tmp/lock-page.html)
  if [ "$code" = "200" ] && [ "$size" -gt 500 ]; then
    echo "  ✓ /$path  ($size bytes, signed out)"
  else
    echo "  ✗ /$path  HTTP $code, $size bytes"
    fail=1
  fi
done

# The front door, end to end over HTTP. Everything above renders a page; this one actually takes a
# request the way a member of the public would, which is the path that has to work on a site whose
# owner has never opened the control panel.
echo "intake:"
INTAKE_EMAIL="smoke@lock-test.invalid"
rm -f /tmp/lock-intake.txt
# The token comes from any Craft page sharing this cookie jar — the portal pages are deliberately
# plain and do not emit one of their own.
ICSRF=$(curl -s -c /tmp/lock-intake.txt -H "Host: plugin-testing.ddev.site" "$BASE/admin/login" | grep -o 'csrfTokenValue":"[^"]*' | head -1 | cut -d'"' -f3)
BODY=$(curl -s -b /tmp/lock-intake.txt -c /tmp/lock-intake.txt -H "Host: plugin-testing.ddev.site" -H "Accept: application/json" \
  -d "action=lock/portal/submit&email=$INTAKE_EMAIL&type=access&message=Smoke+test&CRAFT_CSRF_TOKEN=$ICSRF" "$BASE/index.php")

if echo "$BODY" | grep -q '"success":true'; then
  echo "  ✓ the form takes a request"
else
  echo "  ✗ the form refused it: ${BODY:0:200}"
  fail=1
fi

# No reference in the answer: it would only be there when the submission landed, which makes its
# presence an oracle of its own.
if echo "$BODY" | grep -q '"reference"'; then
  echo "  ✗ the answer carries a reference, so it differs between outcomes"
  fail=1
else
  echo "  ✓ the answer carries no reference"
fi

# The honeypot must produce the *same* answer, and no request. A different answer is an oracle.
HONEY=$(curl -s -b /tmp/lock-intake.txt -H "Host: plugin-testing.ddev.site" -H "Accept: application/json" \
  -d "action=lock/portal/submit&email=honeypot@lock-test.invalid&type=access&confirmEmail=bot&CRAFT_CSRF_TOKEN=$ICSRF" "$BASE/index.php")

if [ "$(echo "$HONEY" | grep -o '"message":"[^"]*"')" = "$(echo "$BODY" | grep -o '"message":"[^"]*"')" ]; then
  echo "  ✓ a honeypot submission is answered identically"
else
  echo "  ✗ the honeypot answers differently, which tells a script it was caught"
  fail=1
fi

if php /var/www/html/craft lock/requests/list --status=unverified 2>/dev/null | grep -q "$INTAKE_EMAIL"; then
  echo "  ✓ the request is on the desk, awaiting confirmation"
else
  echo "  ✗ nothing reached the requests list"
  fail=1
fi

if php /var/www/html/craft lock/requests/list --status=unverified 2>/dev/null | grep -q "honeypot@"; then
  echo "  ✗ the honeypot submission was recorded"
  fail=1
else
  echo "  ✓ the honeypot submission was not recorded"
fi

echo
echo "Fixtures under lock-test.invalid are cleaned up by tests/integration/checks.php — run it after this."

exit $fail
