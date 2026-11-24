# پنل مدیریت و فروش VPN (IBSng)

Laravel 13 / PHP 8.3 / MySQL-MariaDB — قابل نصب روی cPanel بدون SSH.

- راهنمای نصب: [`../docs/phase-2/install-guide.md`](../docs/phase-2/install-guide.md)
- گزارش فاز ۲: [`../docs/phase-2/README.md`](../docs/phase-2/README.md)

## توسعه
```bash
composer install
cp .env.example .env && php artisan key:generate   # DB_CONNECTION=sqlite for local work
php artisan migrate && php artisan permissions:sync
php artisan panel:create-admin admin
mkdir -p storage/app && touch storage/app/installed.lock
php artisan serve
php artisan test                                     # 42 tests
deploy/build-release.sh                              # -> ../dist/vpn-panel-<version>.zip
```
