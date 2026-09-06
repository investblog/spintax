#!/usr/bin/env bash
# PHPCBF auto-fix via the wp-env tests-cli container. See lint-php.sh
# for why this lives in a script instead of inline in package.json.
set -euo pipefail

npx wp-env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/spintax && /tmp/vendor/bin/phpcbf --standard=phpcs.xml.dist src/ uninstall.php spintax.php'
