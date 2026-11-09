<?php
declare(strict_types=1);

/**
 * End-to-end test against a real web server (Apache + .htaccess + HTTPS).
 *   php tests/Integration/e2e.php https://pm.test /srv/pmhost/private-messenger DBNAME DBUSER DBPASS
 * The target must be a FRESH, not-installed deployment with an EMPTY database.
 */
[$_, $BASE, $ROOT, $DBN, $DBU, $DBP] = $argv + array_fill(0, 6, '');

require __DIR__ . '/e2e-lib.php';

$anon = new Browser($BASE);
echo "\n# Before install\n";
check('GET / is 404', $anon->get('/')['status'] === 404);
check('GET /install/ shows token page', $anon->get('/install/')['status'] === 200 && str_contains($anon->last['body'], 'install-token.txt'));
check('wrong install token rejected', str_contains($anon->post('/install/', ['token' => 'wrong'])['body'], 'کد نصب نادرست است'));
$token = trim((string)file_get_contents("$ROOT/storage/install-token.txt"));
$anon->post('/install/', ['token' => $token]);
check('correct install token accepted', $anon->last['status'] === 303);
$form = $anon->get('/install/');
check('host checks shown, no blockers', str_contains($form['body'], 'سرور شما آماده است') && str_contains($form['body'], 'جزئیات بررسی سرور'));
$weak = $anon->post('/install/', ['db_mode' => 'manual', 'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => $DBN, 'db_user' => $DBU, 'db_pass' => $DBP,
    'admin_username' => 'boss', 'admin_display' => 'مدیرکل', 'admin_password' => 'short', 'admin_password2' => 'short']);
check('weak admin password rejected', str_contains($weak['body'], 'حداقل'));
$ADMIN_PW = 'Very-Strong admin pass 2026';
$done = $anon->post('/install/', ['db_mode' => 'manual', 'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => $DBN, 'db_user' => $DBU, 'db_pass' => $DBP,
    'admin_username' => 'boss', 'admin_display' => 'مدیرکل', 'admin_password' => $ADMIN_PW, 'admin_password2' => $ADMIN_PW]);
check('install completes', $done['status'] === 200 && str_contains($done['body'], 'نصب کامل شد'), substr(strip_tags($done['body']), 0, 300));
preg_match_all('#https://[^/]+/([A-Za-z0-9]{16})/#', $done['body'], $m);
$uniq = array_values(array_unique($m[1])); [$ADM, $APP] = [$uniq[0] ?? "", $uniq[1] ?? ""]; // done page lists admin first, then app
check('two different 16-char secret paths', strlen($APP) === 16 && strlen($ADM) === 16 && $APP !== $ADM);
check('install token file deleted', !is_file("$ROOT/storage/install-token.txt"));
check('.env is mode 600', (fileperms("$ROOT/config/.env") & 0777) === 0600);

echo "\n# Stealth\n";
$probe = new Browser($BASE);
$ref = $probe->get('/')['body'];
foreach (['/', '/install/', '/install', '/index.php', '/robots.txt', '/favicon.ico', '/admin/', '/login', '/' . strtolower($APP) . '/', '/' . substr($APP, 0, 15) . '/',
          '/assets/style.css', '/private-messenger/config/.env', '/' . $APP . 'x/', '/' . $APP . '/nope', '/' . $ADM . '/users'] as $p) {
    $r = $probe->get($p);
    $isAdminProtected = $p === '/' . $ADM . '/users';
    if ($isAdminProtected) {
        check("unauthenticated $p redirects to anonymous login", $r['status'] === 303);
        continue;
    }
    check("$p → 404 identical", $r['status'] === 404 && $r['body'] === $ref && ($r['hdrs']['x-robots-tag'][0] ?? '') !== '' && $r['cookies'] === [], "status {$r['status']}");
}
$login = $probe->get("/$APP/");
check('secret path shows login (200)', $login['status'] === 200);
$text = strip_tags($login['body']);
check('login page has only username + password', str_contains($login['body'], 'name="username"') && str_contains($login['body'], 'name="password"')
    && substr_count($login['body'], '<input') === 3 /* +csrf hidden */);
check('login page reveals no app name', !preg_match('/پیام|messenger|private|chat|گفتگو|E2EE|رمزنگاری/iu', $login['body']), $text);
check('login title is neutral', str_contains($login['body'], '<title>ورود</title>'));
check('noindex header + meta on login', str_contains($login['hdrs']['x-robots-tag'][0] ?? '', 'noindex') && str_contains($login['body'], 'name="robots" content="noindex'));
check('CSP + HSTS + no-referrer on login', str_contains($login['hdrs']['content-security-policy'][0] ?? '', "default-src 'self'")
    && isset($login['hdrs']['strict-transport-security']) && ($login['hdrs']['referrer-policy'][0] ?? '') === 'no-referrer');
check('no X-Powered-By', !isset($login['hdrs']['x-powered-by']));
check('stylesheet only under secret path', $probe->get("/$APP/assets/style.css")['status'] === 200);
check('admin login page also anonymous', !preg_match('/پیام|messenger|مدیریت|admin/iu', strip_tags($probe->get("/$ADM/")['body'])));

echo "\n# Admin\n";
$admin = new Browser($BASE);
$admin->post("/$ADM/login", ['username' => 'boss', 'password' => 'wrong-password-123']);
check('admin wrong password → generic error', str_contains($admin->last['body'], 'اطلاعات ورود نادرست است'));
$admin->post("/$ADM/login", ['username' => 'boss', 'password' => $ADMIN_PW]);
check('admin login ok', $admin->last['status'] === 303 && isset($admin->jar['__Host-asid']));
$c = implode(' | ', $admin->last['cookies']);
check('admin cookie: __Host-, Secure, HttpOnly, SameSite=Strict', str_contains($c, '__Host-asid=') && str_contains($c, 'Secure') && str_contains($c, 'HttpOnly') && str_contains($c, 'SameSite=Strict'));
check('dashboard renders', str_contains($admin->follow()['body'], 'داشبورد') && str_contains($admin->last['body'], 'هیچ امکانی برای خواندن پیام‌ها'));
$r = $admin->req('POST', "/$ADM/users/new", ['username' => 'x1', 'display_name' => 'x', '_csrf' => 'forged']);
check('admin POST with forged CSRF → 400', $r['status'] === 400);
$r = $admin->req('POST', "/$ADM/users/new", ['username' => 'x1', 'display_name' => 'x', '_csrf' => $admin->jar['__Host-csrf']], ['Origin' => 'https://evil.example']);
check('admin POST from foreign Origin → 400', $r['status'] === 400);
$created = $admin->post("/$ADM/users/new", ['username' => 'ali', 'display_name' => 'علی رضایی', 'phone' => '09121234567', 'activate' => '1']);
$aliTemp = secret($created['body']);
check('user created, temp password shown once', strlen($aliTemp) === 16, $aliTemp);
preg_match('#/users/(\d+)/edit#', $created['body'], $mm); $aliId = (int)($mm[1] ?? 0);
$admin->post("/$ADM/users/new", ['username' => 'pending1', 'display_name' => 'در انتظار']);
$pendTemp = secret($admin->last['body']);
check('duplicate username rejected', str_contains($admin->post("/$ADM/users/new", ['username' => 'ALI', 'display_name' => 'x'])['body'], 'قبلاً استفاده شده'));
check('users list shows both', str_contains($admin->get("/$ADM/users")['body'], 'علی رضایی') && str_contains($admin->last['body'], 'در انتظار'));

echo "\n# User password login\n";
$u1 = new Browser($BASE);
$u1->post("/$APP/login", ['username' => 'ali', 'password' => 'not-the-password']);
check('wrong password → generic message', str_contains($u1->last['body'], 'نام کاربری یا رمز عبور نادرست است'));
$u1->post("/$APP/login", ['username' => 'nobody', 'password' => 'whatever-password']);
check('unknown user → same generic message', str_contains($u1->last['body'], 'نام کاربری یا رمز عبور نادرست است'));
$r = $u1->req('POST', "/$APP/login", ['username' => 'ali', 'password' => $aliTemp]);
check('login without CSRF token → rejected', $r['status'] === 400 && !isset($u1->jar['__Host-sid']));
$u1->post("/$APP/login", ['username' => 'ali', 'password' => $aliTemp]);
check('temp password login → forced to /password', $u1->last['status'] === 303 && str_ends_with($u1->last['hdrs']['location'][0], '/password'));
$c = implode(' | ', $u1->last['cookies']);
check('session cookie: __Host-sid, Secure, HttpOnly, SameSite=Lax', str_contains($c, '__Host-sid=') && str_contains($c, 'HttpOnly') && str_contains($c, 'SameSite=Lax'));
$u1->get("/$APP/");
check('home blocked until password changed', $u1->last['status'] === 303);
$u1->get("/$APP/sessions");
check('other pages blocked too', $u1->last['status'] === 303);
$u1->post("/$APP/password", ['current' => $aliTemp, 'new' => 'short', 'confirm' => 'short']);
check('weak new password rejected', str_contains($u1->last['body'], 'حداقل'));
$ALI_PW = 'Ali new strong password';
$u1->post("/$APP/password", ['current' => $aliTemp, 'new' => $ALI_PW, 'confirm' => $ALI_PW]);
check('password changed → home', $u1->last['status'] === 303 && str_contains($u1->follow()['body'], 'سلام، علی رضایی'));
$api = $u1->get("/$APP/api/v1/me");
check('API /me works', $api['status'] === 200 && json_decode($api['body'], true)['username'] === 'ali');
check('API without session → 401', (new Browser($BASE))->get("/$APP/api/v1/me")['status'] === 401);

$u2 = new Browser($BASE);
$u2->post("/$APP/login", ['username' => 'ali', 'password' => $ALI_PW]);
check('second device login', $u2->last['status'] === 303 && str_contains($u2->follow()['body'], 'سلام'));
check('sessions page lists 2 sessions', substr_count($u1->get("/$APP/sessions")['body'], '<tr>') === 3);
$u1->post("/$APP/sessions/revoke-others", []);
check('revoke other sessions → device 2 logged out', str_contains($u2->get("/$APP/")['body'], 'name="password"'));

$p = new Browser($BASE);
$p->post("/$APP/login", ['username' => 'pending1', 'password' => $pendTemp]);
check('pending (not activated) account cannot log in', str_contains($p->last['body'], 'حساب شما فعال نیست') && !isset($p->jar['__Host-sid']));

echo "\n# Admin actions on user\n";
$admin->post("/$ADM/users/$aliId/status", ['status' => 'suspended']);
check('suspend → user session revoked immediately', str_contains($u1->get("/$APP/")['body'], 'name="password"'));
$u1->post("/$APP/login", ['username' => 'ali', 'password' => $ALI_PW]);
check('suspended user cannot log in', str_contains($u1->last['body'], 'حساب شما فعال نیست'));
$admin->post("/$ADM/users/$aliId/status", ['status' => 'active']);
$u1->post("/$APP/login", ['username' => 'ali', 'password' => $ALI_PW]);
check('re-activated user can log in', $u1->last['status'] === 303);
$det = $admin->get("/$ADM/users/$aliId")['body'];
check('admin user page shows session + audit trail', str_contains($det, 'خروج اجباری') && str_contains($det, 'user.status'));
check('admin user page has no content access', !preg_match('/ciphertext|envelope|خواندن پیام/u', $det));
$admin->post("/$ADM/users/$aliId/retention", ['permanent' => '1']);
check('super admin toggles permanent retention (audited)', str_contains($admin->follow()['body'], 'فعال</b>') && str_contains($admin->last['body'], 'user.retention.enable'));

echo "\n# Lockout\n";
$lk = new Browser($BASE);
for ($i = 0; $i < 10; $i++) { $lk->post("/$APP/login", ['username' => 'ali', 'password' => "wrong-$i-password"]); }
$lk->post("/$APP/login", ['username' => 'ali', 'password' => $ALI_PW]);
check('account locked after 10 failures (correct password refused)', str_contains($lk->last['body'], 'نادرست') && !isset($lk->jar['__Host-sid']));
check('admin sees lock and can unlock', str_contains($admin->get("/$ADM/users/$aliId")['body'], 'برداشتن قفل ورود'));
$admin->post("/$ADM/users/$aliId/unlock", []);
$lk->post("/$APP/login", ['username' => 'ali', 'password' => $ALI_PW]);
check('login works after unlock', $lk->last['status'] === 303);

echo "\n# OTP mode\n";
$set = fn (array $over) => $admin->post("/$ADM/settings", $over + ['auth_method' => 'password', 'session_days' => '30', 'otp_ttl' => '120', 'otp_attempts' => '5',
    'login_ip' => '200', 'login_fail' => '10', 'login_lock' => '15', 'sms_provider' => 'log', 'sms_template' => '', 'sms_param_name' => 'CODE',
    'sms_generic_method' => 'POST', 'sms_generic_url' => '', 'sms_generic_body' => '', 'sms_generic_auth_header' => '', 'otp_message' => 'کد: {code}',
    'internal_name' => 'پیام‌رسان']);
$r = $set(['auth_method' => 'otp', 'sms_provider' => 'none']);
check('OTP mode refused without SMS provider', str_contains($r['body'], 'ابتدا ارائه‌دهنده پیامک'));
$set(['auth_method' => 'otp']);
check('switched to OTP (test log provider) + dashboard warns', str_contains($admin->get("/$ADM/")['body'], 'فقط تست'));
$o = new Browser($BASE);
$lp = $o->get("/$APP/")['body'];
check('login page now asks only for phone', str_contains($lp, 'name="phone"') && !str_contains($lp, 'name="password"'));
$o->post("/$APP/otp/request", ['phone' => '۰۹۱۲۱۲۳۴۵۶۷']);
check('OTP requested (Persian digits accepted) → /otp', $o->last['status'] === 303 && isset($o->jar['__Host-lc']));
$code = lastOtp($ROOT);
check('OTP form shows masked phone', str_contains($o->follow()['body'], '+98912') && !str_contains($o->last['body'], '1234567'));
$o->post("/$APP/otp/verify", ['code' => $code === '000000' ? '111111' : '000000']);
check('wrong code → error, can retry', str_contains($o->last['body'], 'کد وارد‌شده نادرست است'));
$o->post("/$APP/otp/verify", ['code' => $code]);
check('correct code → logged in', $o->last['status'] === 303 && str_contains($o->follow()['body'], 'سلام'));
$x = new Browser($BASE);
$lines = count(file("$ROOT/storage/logs/sms-test.log"));
$x->post("/$APP/otp/request", ['phone' => '09129999999']);
check('unknown number → same redirect, no SMS sent', $x->last['status'] === 303 && isset($x->jar['__Host-lc']) && count(file("$ROOT/storage/logs/sms-test.log")) === $lines);
$x->post("/$APP/otp/verify", ['code' => '123456']);
check('unknown number cannot log in', !isset($x->jar['__Host-sid']));
$o2 = new Browser($BASE);
$o2->post("/$APP/otp/request", ['phone' => '09121234567']);
check('resend cooldown enforced', str_contains($o2->last['body'], 'کمی بعد'));

echo "\n# Password + OTP (two-step) mode\n";
$set(['auth_method' => 'password_otp']);
sleep(61); // OTP resend cooldown for the same number
$t = new Browser($BASE);
$t->post("/$APP/login", ['username' => 'ali', 'password' => $ALI_PW]);
check('password step → /otp, no session yet', $t->last['status'] === 303 && str_ends_with($t->last['hdrs']['location'][0], '/otp') && !isset($t->jar['__Host-sid']));
$t->post("/$APP/otp/verify", ['code' => lastOtp($ROOT)]);
check('second factor → logged in', $t->last['status'] === 303 && isset($t->jar['__Host-sid']));
$set(['auth_method' => 'password', 'sms_provider' => 'none']);
check('back to password mode', str_contains((new Browser($BASE))->get("/$APP/")['body'], 'name="password"'));

echo "\n# Roles and admin TOTP\n";
$admin->post("/$ADM/admins", ['username' => 'watcher', 'display_name' => 'ناظر', 'password' => 'Watcher strong pass 1', 'role_id' => '3']);
$w = new Browser($BASE);
$w->post("/$ADM/login", ['username' => 'watcher', 'password' => 'Watcher strong pass 1']);
check('observer role: can view users', $w->follow()['status'] === 200 && $w->get("/$ADM/users")['status'] === 200);
check('observer role: cannot create users (403)', $w->get("/$ADM/users/new")['status'] === 403);
check('observer role: cannot manage admins (403)', $w->get("/$ADM/admins")['status'] === 403);
check('observer role: cannot change retention (403)', $w->post("/$ADM/users/$aliId/retention", ['permanent' => '0'])['status'] === 403);
$acc = $admin->post("/$ADM/account/totp/start", []);
$sec = str_replace(' ', '', secret($acc['body']));
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$admin->post("/$ADM/account/totp/confirm", ['code' => PM\Support\Totp::code($sec, intdiv(time(), 30) - 1)]);
check('TOTP enabled for super admin', str_contains($admin->follow()['body'], 'فعال'));
$n = new Browser($BASE);
$n->post("/$ADM/login", ['username' => 'boss', 'password' => $ADMIN_PW]);
check('admin login now asks for TOTP', str_contains($n->last['body'], 'name="totp"') && !isset($n->jar['__Host-asid']));
$n->post("/$ADM/login", ['username' => 'boss', 'password' => $ADMIN_PW, 'totp' => PM\Support\Totp::code($sec, intdiv(time(), 30))]);
check('admin login with TOTP ok', isset($n->jar['__Host-asid']));
check('audit chain verified in panel', str_contains($admin->get("/$ADM/audit")['body'], 'زنجیره هش سالم است'));

echo "\n# Cron + database plaintext scan\n";
exec('sudo -u www-data php ' . escapeshellarg("$ROOT/app/Cli/cron.php") . ' maintenance 2>&1', $out, $rc);
check('cron maintenance runs (as web user)', $rc === 0, implode("\n", $out));
check('cron run visible in admin system page', str_contains($admin->get("/$ADM/system")['body'], 'maintenance'));
$dump = shell_exec('mysqldump --skip-extended-insert -u' . escapeshellarg($DBU) . ' -p' . escapeshellarg($DBP) . ' -h127.0.0.1 ' . escapeshellarg($DBN) . ' 2>/dev/null');
check('DB dump obtained', strlen((string)$dump) > 1000);
foreach (['admin password' => $ADMIN_PW, 'user temp password' => $aliTemp, 'user password' => $ALI_PW, 'TOTP secret' => $sec, 'session cookie' => $u1->jar['__Host-sid']] as $what => $needle) {
    check("no plaintext $what in database", !str_contains((string)$dump, $needle));
}
check('no OTP codes from log in database', !array_filter(array_map(fn ($l) => explode("\t", $l)[3] ?? null, file("$ROOT/storage/logs/sms-test.log")),
    fn ($c) => $c && preg_match("/'$c'/", (string)$dump)));
check('error log has no PHP warnings', !preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', (string)@file_get_contents('/var/log/apache2/pm-error.log')));

finish();
