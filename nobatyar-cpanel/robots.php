<?php
declare(strict_types=1);

/** robots.txt (served through .htaccess): panels and private files are not indexed; points to the sitemap. */

define('NOBATYAR_NO_SESSION', true);
require __DIR__ . '/lib/version.php';
require __DIR__ . '/lib/support.php';
header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
foreach (['admin.php', 'panel.php', 'patient.php', 'doctor-panel.php', 'secretary.php', 'center.php', 'checkout.php', 'receipt.php', 'notifications.php', 'support.php', 'login.php', 'api.php', 'install.php', 'upgrade.php', 'cron.php', 'private/', 'tests/'] as $p) {
    echo 'Disallow: /' . ltrim(parse_url(app_base_url(), PHP_URL_PATH) ?: '/', '/') . $p . "\n";
}
echo 'Sitemap: ' . app_base_url() . "sitemap.php\n";
