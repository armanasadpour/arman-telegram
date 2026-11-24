#!/usr/bin/env bash
# Builds the cPanel-ready ZIP: production dependencies included, no dev files, no secrets.
# Usage: deploy/build-release.sh   -> ../dist/vpn-panel-<version>.zip
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(php -r 'echo (require "config/panel.php")["version"];' 2>/dev/null || true)
if [ -z "$VERSION" ]; then VERSION=$(grep -oP "'version' => '\K[^']+" config/panel.php); fi
OUT="$(cd .. && pwd)/dist"
STAGE=$(mktemp -d)
APP="$STAGE/vpn-panel"
mkdir -p "$APP" "$OUT"

rsync -a --exclude-from=- ./ "$APP/" <<'EXC'
.env
.env.*
.git
.phpunit.cache
.phpunit.result.cache
node_modules
vendor
tests
storage/logs/*
storage/framework/cache/data/*
storage/framework/sessions/*
storage/framework/views/*
storage/app/installed.lock
bootstrap/cache/*.php
database/database.sqlite
public/fonts/IRANSans*
deploy/build-release.sh
EXC
cp .env.example "$APP/.env.example"

( cd "$APP" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts -q \
  && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize -q )
# Package discovery normally runs as a composer script; run it here so bootstrap/cache is correct.
( cd "$APP" && printf 'APP_KEY=base64:%s\nAPP_ENV=production\n' "$(head -c32 /dev/urandom | base64)" > .env \
  && php artisan package:discover -q && rm -f .env )

# Strip VCS metadata, tests and docs from dependencies (not needed at runtime).
find "$APP/vendor" -depth -type d \( -name .git -o -name .github -o -name tests -o -name Tests -o -name docs -o -name .agents -o -name .amp \) -prune -exec rm -rf {} +
find "$APP/vendor" -type f \( -name 'phpunit.xml*' -o -name '.gitattributes' -o -name '.gitignore' -o -name '.editorconfig' -o -name 'CHANGELOG*' \) -delete
rm -f "$APP/phpunit.xml"

cp deploy/root.htaccess "$APP/.htaccess"
# Keep empty runtime folders in the archive.
for d in storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
  mkdir -p "$APP/$d"; [ -e "$APP/$d/.gitignore" ] || printf '*\n!.gitignore\n' > "$APP/$d/.gitignore"
done
find "$APP" -type d -exec chmod 755 {} +
find "$APP" -type f -exec chmod 644 {} +

ZIP="$OUT/vpn-panel-$VERSION.zip"
rm -f "$ZIP"
( cd "$APP" && zip -qr -9 "$ZIP" . -x '*.DS_Store' )
( cd "$OUT" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256" )
rm -rf "$STAGE"
echo "$ZIP ($(du -h "$ZIP" | cut -f1))"
