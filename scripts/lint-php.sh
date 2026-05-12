#!/usr/bin/env bash
# PHPCS via the wp-env tests-cli container.
#
# Sits in its own shell script (not inline in package.json) so the
# Windows `npm` (which spawns cmd.exe) does not have to navigate three
# levels of bash-c quoting around the `--ignore` pattern. That quoting
# turned out to be brittle and silently broke `npm run lint:php` in
# 2.0.2 — phpcs got an empty/garbled args list and exited with a
# parser error instead of running.
set -euo pipefail

npx wp-env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/spintax && /tmp/vendor/bin/phpcs --standard=WordPress --extensions=php --exclude=WordPress.Files.FileName --ignore="*/tests/*,*/vendor/*" src/ uninstall.php spintax.php'
