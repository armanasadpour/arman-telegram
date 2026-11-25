#!/usr/bin/env bash
# Small update ZIP: only the panel files changed since an earlier version (no vendor, no fonts).
# Usage: deploy/build-patch.sh 1.2.4   -> ../dist/vpn-panel-update-1.2.4-to-<current>.zip
# Install from the panel (System status → upload update) or extract over an installed <from-version> panel.
# update-manifest.json tells the panel which version the update is for and which files to remove.
set -euo pipefail
cd "$(dirname "$0")/.."
FROM="${1:?usage: build-patch.sh <installed-version>}"
VERSION=$(grep -oP "'version' => '\K[^']+" config/panel.php)
BASE=$(git log --format=%H --grep="^Panel ${FROM//./\\.}:" -n1)
[ -n "$BASE" ] || { echo "no commit found for version $FROM" >&2; exit 1; }
[ -z "$(git status --porcelain -- .)" ] || { echo "commit your changes first" >&2; exit 1; }

if git diff --name-only "$BASE" HEAD -- composer.json composer.lock | grep -q .; then
  echo "composer dependencies changed since $FROM: build the full package (deploy/build-release.sh)" >&2; exit 1
fi

OUT="$(cd .. && pwd)/dist"; mkdir -p "$OUT"
STAGE=$(mktemp -d)
CHANGED=$(git diff --name-only --diff-filter=ACMR "$BASE" HEAD -- . | sed 's#^panel/##' \
  | grep -vE '^(tests/|deploy/build-|\.env|phpunit\.xml|\.github/|README|.*\.md$)' || true)
export DELETED=$(git diff --name-only --diff-filter=D "$BASE" HEAD -- . | sed 's#^panel/##' | grep -vE '^tests/' || true)
[ -n "$CHANGED" ] || { echo "nothing changed since $FROM" >&2; exit 1; }
while IFS= read -r f; do
  mkdir -p "$STAGE/$(dirname "$f")"; cp "$f" "$STAGE/$f"
done <<< "$CHANGED"
php -r '$d = array_values(array_filter(explode("\n", getenv("DELETED")))); echo json_encode(["from" => $argv[1], "to" => $argv[2], "deleted" => $d], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";' "$FROM" "$VERSION" > "$STAGE/update-manifest.json"
find "$STAGE" -type d -exec chmod 755 {} +; find "$STAGE" -type f -exec chmod 644 {} +

ZIP="$OUT/vpn-panel-update-$FROM-to-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr -9 "$ZIP" . )
rm -rf "$STAGE"
echo "$ZIP ($(du -h "$ZIP" | cut -f1), $(echo "$CHANGED" | wc -l) files)"
if [ -n "$DELETED" ]; then echo "files removed since $FROM (harmless if left on the host):"; echo "$DELETED"; fi
