#!/usr/bin/env bash
#
# Verify that all version references are in sync.
#
# Usage:
#   ./scripts/version-check.sh
#   npm run version:check
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

HEADER=$(grep " \* Version:" "$ROOT/plugin/spintax.php" | sed 's/.*Version:[[:space:]]*//')
CONST=$(grep "SPINTAX_VERSION" "$ROOT/plugin/spintax.php" | sed "s/.*'\\([^']*\\)'.*/\\1/")
README=$(grep "Stable tag:" "$ROOT/plugin/readme.txt" | sed 's/.*Stable tag:[[:space:]]*//')

echo "Header:  $HEADER"
echo "Const:   $CONST"
echo "Readme:  $README"

if [ "$HEADER" = "$CONST" ] && [ "$HEADER" = "$README" ]; then
  echo "✓ All match: $HEADER"
else
  echo "✗ MISMATCH — run: npm run version:set -- <version>"
  exit 1
fi
