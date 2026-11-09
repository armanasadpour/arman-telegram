<?php
declare(strict_types=1);

/**
 * Tests the one-file installer flow on a real web server:
 *   php tests/Integration/single-file-install.php https://pm.test /srv/pmhost/public_html /srv/pmhost/private-messenger
 * Requires: dist/install.php uploaded as <docroot>/install.php, mock cPanel API (mock-cpanel.php) reachable by the
 * web server via PM_CPANEL_API_BASE.
 */
[$_, $BASE, $DOCROOT, $APPROOT] = $argv;
require __DIR__ . '/e2e-lib.php';

$b = new Browser($BASE);
echo "\n# Upload-one-file installer\n";
$r = $b->get('/install.php');
check('install.php: welcome page with checks', $r['status'] === 200 && str_contains($r['body'], 'شروع نصب') && str_contains($r['body'], '✔ PHP'));
$http = (new Browser(str_replace('https://', 'http://', $BASE)))->get('/install.php');
check('install.php over http: explains SSL', str_contains($http['body'], 'ابتدا SSL را فعال کنید'));
$r = $b->req('POST', '/install.php', ['start' => '1']);
check('start → app unpacked, redirect to /install/', $r['status'] === 303 && str_ends_with($r['hdrs']['location'][0], '/install/'), "status {$r['status']}");
check('app unpacked outside web root', is_file("$APPROOT/app/bootstrap.php") && !is_dir("$DOCROOT/private-messenger"));
$ht = (string)file_get_contents("$DOCROOT/.htaccess");
check('.htaccess: our block added AND cPanel PHP handler block kept', str_contains($ht, '# BEGIN app') && str_contains($ht, 'cPanel-generated handler'));
check('second browser cannot reach install.php anymore (404)', (new Browser($BASE))->get('/install.php')['status'] === 404);
$f = $b->get('/install/');
check('web installer opens directly (no token step)', str_contains($f['body'], 'سرور شما آماده است') && !str_contains($f['body'], 'install-token.txt'));
check('cPanel username auto-detected', str_contains($f['body'], 'name="cpanel_user"'));
$ADMIN_PW = 'Goat-proof admin pass 1';
$r = $b->post('/install/', ['cpanel_user' => 'pmuser', 'cpanel_pass' => 'wrong', 'admin_username' => 'admin', 'admin_password' => $ADMIN_PW, 'admin_password2' => $ADMIN_PW], '/install/');
check('wrong cPanel password → clear message', str_contains($r['body'], 'رمز cPanel پذیرفته نشد'));
$r = $b->post('/install/', ['cpanel_user' => 'pmuser', 'cpanel_pass' => 'cp-pass-123', 'admin_username' => 'admin', 'admin_password' => $ADMIN_PW, 'admin_password2' => $ADMIN_PW], '/install/');
check('install with only cPanel password + admin password', str_contains($r['body'], 'نصب کامل شد'), substr(strip_tags($r['body']), 0, 300));
check('cron registered automatically via cPanel API', str_contains($r['body'], 'Cron به‌صورت خودکار') && str_contains((string)@file_get_contents("/tmp/pm-mock-crontab.txt"), '*/15 * * * * /usr/local/bin/php'));
preg_match_all('#https://[^/]+/([A-Za-z0-9]{16})/#', $r['body'], $m);
$ADM = $m[1][0] ?? '';
$env = (string)file_get_contents("$APPROOT/config/.env");
check('database + user were created automatically (prefixed)', (bool)preg_match('/DB_NAME="pmuser_pm[a-z0-9]{4}"/', $env));
check('install.php and claim file deleted', !is_file("$DOCROOT/install.php") && !is_file("$DOCROOT/.pm-install-claim"));
check('cPanel password not stored anywhere', !str_contains($env, 'cp-pass-123') && !str_contains((string)shell_exec('grep -rl cp-pass-123 ' . escapeshellarg($APPROOT) . ' 2>/dev/null'), 'private-messenger'));
check('site root is now 404', (new Browser($BASE))->get('/')['status'] === 404);
$a = new Browser($BASE);
$a->post("/$ADM/login", ['username' => 'admin', 'password' => $ADMIN_PW]);
check('admin can log in with the chosen password', $a->last['status'] === 303 && isset($a->jar['__Host-asid']));
check('pseudo-cron: maintenance ran after a request (no cron needed)', (bool)preg_match('/maintenance/', $a->get("/$ADM/system")['body']));
finish();
