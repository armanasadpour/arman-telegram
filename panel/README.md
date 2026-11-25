# پنل مدیریت و فروش VPN (IBSng)

Laravel 13 / PHP 8.3 / MySQL-MariaDB — قابل نصب روی cPanel بدون SSH.

- تحویل نهایی و فهرست مستندات: [`../docs/final/README.md`](../docs/final/README.md)
- نصب، به‌روزرسانی و پشتیبان‌گیری: [`../docs/final/install-and-upgrade.md`](../docs/final/install-and-upgrade.md)

## توسعه
```bash
composer install
cp .env.example .env && php artisan key:generate   # DB_CONNECTION=sqlite for local work
php artisan migrate && php artisan permissions:sync
php artisan panel:create-admin admin
mkdir -p storage/app && touch storage/app/installed.lock
php artisan serve
php artisan test                                     # full suite (SQLite); MariaDB: set DB_* env
deploy/build-release.sh                              # -> ../dist/vpn-panel-<version>.zip
```
