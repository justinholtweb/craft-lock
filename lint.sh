#!/bin/bash
# Lints every PHP file in src/ inside the plugin-testing container (no local PHP on this box).
cd /Users/jholt/Sites/plugin-testing || exit 1
docker exec -w /var/www/html ddev-plugin-testing-web bash -c 'find /var/www/craft-lock/src /var/www/craft-lock/tests -name "*.php" -print0 2>/dev/null | xargs -0 -n1 php -l' </dev/null 2>&1 | grep -v "^No syntax errors" | grep -v "^$"
echo "lint done"
