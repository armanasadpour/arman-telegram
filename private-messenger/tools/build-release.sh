#!/bin/bash
# Builds the installable ZIP for cPanel:  tools/build-release.sh [version]
# Output: dist/private-messenger-<version>.zip  (extracts to a top-level "private-messenger/" folder)
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="${1:-$(date -u +%Y%m%d)}"
OUT="$PWD/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$OUT" "$STAGE/private-messenger"
for p in app config database public docs README.md .gitignore; do cp -a "$p" "$STAGE/private-messenger/"; done
mkdir -p "$STAGE/private-messenger/storage"/{logs,tmp,attachments}
cp storage/.htaccess "$STAGE/private-messenger/storage/"
touch "$STAGE/private-messenger/storage"/{logs,tmp,attachments}/.gitkeep
rm -f "$STAGE/private-messenger/config/.env" "$STAGE/private-messenger/storage/installed.lock" "$STAGE/private-messenger/storage/install-token.txt"
find "$STAGE" -name '.DS_Store' -delete
echo "$VERSION" > "$STAGE/private-messenger/VERSION"
(cd "$STAGE" && find private-messenger -type f -name '*.php' -exec php -l {} \; | grep -v '^No syntax' || true)
rm -f "$OUT/private-messenger-$VERSION.zip"
(cd "$STAGE" && zip -qr -X "$OUT/private-messenger-$VERSION.zip" private-messenger)
(cd "$OUT" && sha256sum "private-messenger-$VERSION.zip" > "private-messenger-$VERSION.zip.sha256")
echo "built: dist/private-messenger-$VERSION.zip"
cat "$OUT/private-messenger-$VERSION.zip.sha256"
