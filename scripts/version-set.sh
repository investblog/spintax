#!/usr/bin/env bash
#
# Set the plugin version in all required locations.
#
# Usage:
#   ./scripts/version-set.sh 1.2.3
#   npm run version:set -- 1.2.3
#
# Updates:
#   1. plugin/spintax.php   — Plugin header "Version:" field
#   2. plugin/spintax.php   — SPINTAX_VERSION constant
#   3. plugin/readme.txt    — Stable tag
#
set -euo pipefail

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  echo "Usage: $0 <version>"
  echo "Example: $0 1.2.3"
  exit 1
fi

# Validate semver-ish format.
if ! echo "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?$'; then
  echo "Error: Version must be in format X.Y.Z or X.Y.Z-suffix"
  exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# 1. Plugin header.
sed -i "s/\( \* Version:\s*\).*/\1$VERSION/" "$ROOT/plugin/spintax.php"

# 2. PHP constant.
sed -i "s/define( 'SPINTAX_VERSION', '.*' )/define( 'SPINTAX_VERSION', '$VERSION' )/" "$ROOT/plugin/spintax.php"

# 3. readme.txt Stable tag.
sed -i "s/Stable tag:.*/Stable tag: $VERSION/" "$ROOT/plugin/readme.txt"

echo "✓ Version set to $VERSION in:"
echo "  plugin/spintax.php (header + constant)"
echo "  plugin/readme.txt (Stable tag)"
echo ""
echo "Next steps:"
echo "  git add plugin/spintax.php plugin/readme.txt"
echo "  git commit -m \"Release $VERSION\""
echo "  git tag v$VERSION"
echo "  git push && git push --tags"
