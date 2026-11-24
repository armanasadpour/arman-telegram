<?php

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Settings;
use Illuminate\Contracts\Console\Kernel;

/**
 * Persian web installer (no SSH needed).
 *
 * Intentionally written without PHP 8.x-only syntax so that it can run on an older
 * PHP version and tell the owner to switch to PHP 8.3 instead of failing with a fatal error.
 *
 * Flow: requirements -> form (site, database, super admin) -> test DB -> write .env ->
 * boot Laravel -> migrate -> permissions -> super admin -> settings -> lock file.
 * Once storage/app/installed.lock exists this script refuses to run.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

define('BASE', dirname(__DIR__));
define('LOCK', BASE.'/storage/app/installed.lock');
define('MIN_PHP', '8.3.0');

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

if (is_file(LOCK)) {
    http_response_code(403);
    page('نصب انجام شده است', '<p>سامانه قبلاً نصب شده و نصب‌کننده قفل است. برای امنیت بیشتر فایل <code>public/install.php</code> را حذف کنید.</p><p><a class="btn" href="'.h(baseUrl()).'">ورود به پنل</a></p>');
    exit;
}

session_name('vpnpanel_install');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => isHttps()]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$checks = requirements();
$blocking = array_filter($checks, function ($c) {
    return $c['required'] && ! $c['ok'];
});
$errors = [];
$input = [
    'app_url' => rtrim(baseUrl(), '/'),
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
    'site_name' => 'پنل فروش VPN', 'admin_name' => '', 'admin_username' => 'admin',
    'timezone' => 'Asia/Tehran',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (! hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'نشست منقضی شده است؛ صفحه را دوباره بارگذاری کنید.';
    } elseif ($blocking) {
        $errors[] = 'ابتدا پیش‌نیازهای قرمز را برطرف کنید.';
    } else {
        foreach ($input as $k => $v) {
            if (isset($_POST[$k])) {
                $input[$k] = trim((string) $_POST[$k]);
            }
        }
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $adminPass = (string) ($_POST['admin_pass'] ?? '');
        $errors = validate($input, $adminPass, (string) ($_POST['admin_pass2'] ?? ''));
        if (! $errors) {
            $result = install($input, $dbPass, $adminPass);
            if ($result === true) {
                $_SESSION = [];
                session_destroy();
                $selfDeleted = @unlink(__FILE__);
                page('نصب با موفقیت انجام شد', successHtml($input, $selfDeleted));
                exit;
            }
            $errors[] = $result;
        }
    }
}

page('نصب پنل', formHtml($checks, $blocking, $errors, $input));

// ====================================================================== steps

function requirements()
{
    $c = [];
    $c[] = check('نسخه PHP (حداقل '.MIN_PHP.')', version_compare(PHP_VERSION, MIN_PHP, '>='), 'نسخه فعلی: '.PHP_VERSION.(version_compare(PHP_VERSION, MIN_PHP, '>=') ? '' : ' — از cPanel ← MultiPHP Manager (یا Select PHP Version) نسخه 8.3 را برای این دامنه انتخاب کنید.'));
    foreach (['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'dom', 'xml', 'ctype', 'json', 'fileinfo', 'filter', 'session', 'curl'] as $ext) {
        $c[] = check('افزونه '.$ext, extension_loaded($ext), extension_loaded($ext) ? 'فعال' : 'غیرفعال — از Select PHP Version ← Extensions فعال کنید');
    }
    foreach (['bcmath', 'intl', 'zip', 'sodium'] as $ext) {
        $c[] = check('افزونه '.$ext.' (توصیه‌شده)', extension_loaded($ext), extension_loaded($ext) ? 'فعال' : 'غیرفعال — برای فازهای بعد لازم می‌شود', false);
    }
    $c[] = check('پوشه vendor (وابستگی‌ها)', is_file(BASE.'/vendor/autoload.php'), is_file(BASE.'/vendor/autoload.php') ? 'موجود' : 'فایل ZIP ناقص استخراج شده است');
    foreach (['storage', 'storage/app', 'storage/framework', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
        $p = BASE.'/'.$dir;
        if (! is_dir($p)) {
            @mkdir($p, 0755, true);
        }
        $c[] = check('قابل نوشتن: '.$dir, is_dir($p) && is_writable($p), is_writable($p) ? 'بله' : 'دسترسی پوشه را 755 کنید');
    }
    $envWritable = is_file(BASE.'/.env') ? is_writable(BASE.'/.env') : is_writable(BASE);
    $c[] = check('امکان ساخت فایل .env', $envWritable, $envWritable ? 'بله' : 'پوشه اصلی برنامه قابل نوشتن نیست');
    $c[] = check('HTTPS', isHttps(), isHttps() ? 'فعال' : 'توصیه می‌شود ابتدا SSL (AutoSSL) فعال و نصب از آدرس https انجام شود', false);

    return $c;
}

function check($label, $ok, $detail, $required = true)
{
    return ['label' => $label, 'ok' => (bool) $ok, 'detail' => $detail, 'required' => $required];
}

function validate($in, $pass, $pass2)
{
    $e = [];
    if (! filter_var($in['app_url'], FILTER_VALIDATE_URL) || ! preg_match('#^https?://#', $in['app_url'])) {
        $e[] = 'آدرس سامانه معتبر نیست.';
    }
    foreach (['db_host' => 'میزبان دیتابیس', 'db_name' => 'نام دیتابیس', 'db_user' => 'نام کاربری دیتابیس', 'admin_name' => 'نام مدیرکل', 'site_name' => 'نام سامانه'] as $k => $label) {
        if ($in[$k] === '') {
            $e[] = $label.' الزامی است.';
        }
    }
    if (! ctype_digit($in['db_port'])) {
        $e[] = 'پورت دیتابیس باید عدد باشد.';
    }
    if (! preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $in['admin_username'])) {
        $e[] = 'نام کاربری مدیرکل باید ۳ تا ۶۰ کاراکتر از حروف انگلیسی، عدد، نقطه، خط تیره یا زیرخط باشد.';
    }
    if (mb_strlen($pass) < 10 || ! preg_match('/[A-Za-z]/', $pass) || ! preg_match('/[0-9]/', $pass)) {
        $e[] = 'رمز مدیرکل باید حداقل ۱۰ کاراکتر و شامل حرف و عدد باشد.';
    }
    if ($pass !== $pass2) {
        $e[] = 'تکرار رمز مدیرکل مطابقت ندارد.';
    }
    if (! in_array($in['timezone'], timezone_identifiers_list(), true)) {
        $e[] = 'منطقه زمانی معتبر نیست.';
    }

    return $e;
}

/** @return true|string */
function install($in, $dbPass, $adminPass)
{
    // 1. Database connection and emptiness check.
    try {
        $dsn = 'mysql:host='.$in['db_host'].';port='.(int) $in['db_port'].';dbname='.$in['db_name'].';charset=utf8mb4';
        $pdo = new PDO($dsn, $in['db_user'], $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        $version = $pdo->query('select version()')->fetchColumn();
        $tables = $pdo->query('show tables')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return 'اتصال به دیتابیس برقرار نشد. نام دیتابیس، نام کاربری، رمز و دسترسی کاربر به دیتابیس (Add User To Database ← ALL PRIVILEGES) را بررسی کنید. خطا: '.dbError($e, $dbPass);
    }
    if (! dbVersionOk($version)) {
        return 'نسخه دیتابیس ('.h($version).') قدیمی است. حداقل MySQL 5.7 یا MariaDB 10.3 لازم است.';
    }
    $ours = array_intersect($tables, ['users', 'roles', 'audit_logs', 'settings']);
    if ($ours && in_array('users', $tables, true)) {
        $count = (int) $pdo->query('select count(*) from users')->fetchColumn();
        if ($count > 0) {
            return 'این دیتابیس از قبل داده سامانه را دارد. برای نصب تازه یک دیتابیس خالی بسازید؛ برای جلوگیری از پاک شدن داده‌ها، نصب‌کننده روی دیتابیس پر نصب نمی‌کند.';
        }
    } elseif ($tables && ! $ours) {
        return 'این دیتابیس خالی نیست ('.count($tables).' جدول دیگر دارد). لطفاً یک دیتابیس خالی و اختصاصی برای پنل بسازید.';
    }

    // 2. Write .env (APP_KEY and cron key are random; production mode, debug off).
    $env = [
        'APP_NAME' => $in['site_name'],
        'APP_ENV' => 'production',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_DEBUG' => 'false',
        'APP_URL' => rtrim($in['app_url'], '/'),
        'APP_LOCALE' => 'fa',
        'APP_FALLBACK_LOCALE' => 'fa',
        'APP_DISPLAY_TIMEZONE' => $in['timezone'],
        'LOG_CHANNEL' => 'daily',
        'LOG_LEVEL' => 'warning',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $in['db_host'],
        'DB_PORT' => $in['db_port'],
        'DB_DATABASE' => $in['db_name'],
        'DB_USERNAME' => $in['db_user'],
        'DB_PASSWORD' => $dbPass,
        'SESSION_DRIVER' => 'database',
        'SESSION_LIFETIME' => '120',
        'SESSION_ENCRYPT' => 'true',
        'SESSION_SECURE_COOKIE' => strpos($in['app_url'], 'https://') === 0 ? 'true' : 'false',
        'CACHE_STORE' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'FILESYSTEM_DISK' => 'local',
        'BCRYPT_ROUNDS' => '12',
        'PANEL_CRON_KEY' => bin2hex(random_bytes(24)),
    ];
    $lines = [];
    foreach ($env as $k => $v) {
        $lines[] = $k.'='.envValue($v);
    }
    if (@file_put_contents(BASE.'/.env', implode("\n", $lines)."\n", LOCK_EX) === false) {
        return 'نوشتن فایل .env ممکن نشد. دسترسی نوشتن پوشه اصلی برنامه را بررسی کنید.';
    }
    @chmod(BASE.'/.env', 0600);
    foreach (glob(BASE.'/bootstrap/cache/*.php') ?: [] as $f) {
        if (basename($f) !== 'packages.php' && basename($f) !== 'services.php') {
            @unlink($f); // stale config/route caches from a previous attempt
        }
    }

    // 3. Boot Laravel and run migrations + seed the super admin.
    try {
        require BASE.'/vendor/autoload.php';
        $app = require BASE.'/bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();

        if ($kernel->call('migrate', ['--force' => true]) !== 0) {
            return 'اجرای Migrationها ناموفق بود: '.h(trim($kernel->output()));
        }
        $kernel->call('permissions:sync');

        $db = $app->make('db');
        $db->transaction(function () use ($app, $in, $adminPass) {
            $user = User::query()->create([
                'name' => $in['admin_name'],
                'username' => $in['admin_username'],
                'password' => $adminPass,
                'is_active' => true,
            ]);
            $user->forceFill(['password_changed_at' => now()])->save();
            $superId = Role::query()->where('name', config('permissions.super_role'))->value('id');
            $user->roles()->attach($superId);

            $settings = $app->make(Settings::class);
            $settings->set('general.site_name', $in['site_name']);
            $settings->set('general.display_timezone', $in['timezone']);

            $app->make(AuditLogger::class)->log(
                'system.installed', $user,
                'نصب سامانه نسخه '.config('panel.version').' و ساخت مدیرکل «'.$user->username.'»',
                null, null, $user, 'installer'
            );
        });
    } catch (Throwable $e) {
        error_log('[installer] '.get_class($e).': '.$e->getMessage());

        return 'خطا در راه‌اندازی برنامه: '.h(dbError($e, $dbPass)).' (جزئیات در لاگ خطای PHP هاست)';
    }

    // 4. Lock.
    if (@file_put_contents(LOCK, json_encode(['installed_at' => gmdate('c'), 'version' => config('panel.version')]), LOCK_EX) === false) {
        return 'نصب انجام شد اما فایل قفل نوشته نشد. پوشه storage/app را قابل نوشتن کنید و صفحه را دوباره ارسال کنید.';
    }

    return true;
}

// ====================================================================== helpers

function envValue($v)
{
    $v = (string) $v;
    if ($v === '' || preg_match('/^[A-Za-z0-9_.:\/+=@-]+$/', $v)) {
        return $v;
    }

    return '"'.str_replace(['\\', '"', '$', "\n"], ['\\\\', '\\"', '\\$', ''], $v).'"';
}

function dbVersionOk($v)
{
    if (stripos($v, 'mariadb') !== false) {
        return version_compare(preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $v), '10.3.0', '>=');
    }

    return version_compare(preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $v), '5.7.0', '>=');
}

function dbError($e, $secret)
{
    $msg = $e->getMessage();
    if ($secret !== '') {
        $msg = str_replace($secret, '***', $msg);
    }

    return mb_substr($msg, 0, 300);
}

function isHttps()
{
    return (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/** Base URL of the panel, without "/public" and without "/install.php". */
function baseUrl()
{
    $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
    $path = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
    $path = preg_replace('#/install\.php$#', '', (string) $path);
    $path = preg_replace('#/public$#', '', $path);

    return (isHttps() ? 'https' : 'http').'://'.$host.$path.'/';
}

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function formHtml($checks, $blocking, $errors, $in)
{
    $rows = '';
    foreach ($checks as $c) {
        $cls = $c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'warn');
        $txt = $c['ok'] ? 'درست' : ($c['required'] ? 'لازم' : 'توصیه');
        $rows .= '<tr><td>'.h($c['label']).'</td><td><span class="b '.$cls.'">'.$txt.'</span></td><td>'.h($c['detail']).'</td></tr>';
    }
    $err = '';
    if ($errors) {
        $err = '<div class="alert">'.implode('<br>', array_map('h', $errors)).'</div>';
    }
    $tz = '';
    foreach (['Asia/Tehran', 'UTC', 'Asia/Dubai', 'Europe/Istanbul'] as $z) {
        $tz .= '<option value="'.h($z).'"'.($in['timezone'] === $z ? ' selected' : '').'>'.h($z).'</option>';
    }
    $disabled = $blocking ? ' disabled' : '';

    return '<h2>۱. بررسی پیش‌نیازها</h2><div class="tw"><table><tbody>'.$rows.'</tbody></table></div>'
        .($blocking ? '<div class="alert">موارد «لازم» را برطرف کنید و صفحه را دوباره بارگذاری کنید.</div>' : '')
        .$err
        .'<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'
        .'<h2>۲. سامانه</h2>'
        .field('app_url', 'آدرس پنل', $in['app_url'], 'url', 'آدرسی که پنل با آن باز می‌شود (بدون install.php).', true)
        .field('site_name', 'نام سامانه', $in['site_name'])
        .'<div class="f"><label for="timezone">منطقه زمانی نمایش</label><select id="timezone" name="timezone">'.$tz.'</select></div>'
        .'<h2>۳. دیتابیس MySQL / MariaDB</h2><p class="m">در cPanel ← MySQL Databases یک دیتابیس و یک کاربر بسازید و کاربر را با ALL PRIVILEGES به دیتابیس اضافه کنید. نام‌ها معمولاً با پیشوند حساب cPanel شروع می‌شوند.</p>'
        .field('db_host', 'میزبان', $in['db_host'], 'text', 'معمولاً localhost', true)
        .field('db_port', 'پورت', $in['db_port'], 'text', '', true)
        .field('db_name', 'نام دیتابیس', $in['db_name'], 'text', '', true)
        .field('db_user', 'نام کاربری دیتابیس', $in['db_user'], 'text', '', true)
        .'<div class="f"><label for="db_pass">رمز دیتابیس</label><input id="db_pass" name="db_pass" type="password" class="ltr"></div>'
        .'<h2>۴. مدیرکل</h2>'
        .field('admin_name', 'نام و نام خانوادگی', $in['admin_name'])
        .field('admin_username', 'نام کاربری', $in['admin_username'], 'text', 'حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط', true)
        .'<div class="f"><label for="admin_pass">رمز عبور</label><input id="admin_pass" name="admin_pass" type="password" class="ltr" autocomplete="new-password" required><div class="m">حداقل ۱۰ کاراکتر، شامل حرف و عدد.</div></div>'
        .'<div class="f"><label for="admin_pass2">تکرار رمز عبور</label><input id="admin_pass2" name="admin_pass2" type="password" class="ltr" autocomplete="new-password" required></div>'
        .'<button class="btn" type="submit"'.$disabled.'>نصب</button></form>';
}

function field($name, $label, $value, $type = 'text', $help = '', $ltr = false)
{
    return '<div class="f"><label for="'.$name.'">'.h($label).'</label><input id="'.$name.'" name="'.$name.'" type="'.$type.'" value="'.h($value).'"'.($ltr ? ' class="ltr"' : '').' required>'
        .($help !== '' ? '<div class="m">'.h($help).'</div>' : '').'</div>';
}

function successHtml($in, $selfDeleted)
{
    $php = PHP_BINDIR.'/php';

    return '<p>پنل نصب شد و نصب‌کننده قفل شد.</p>'
        .($selfDeleted ? '<p class="m">فایل install.php به‌طور خودکار حذف شد.</p>' : '<div class="alert">فایل <code>public/install.php</code> را از File Manager حذف کنید (نصب‌کننده قفل است، اما حذف آن امن‌تر است).</div>')
        .'<h2>قدم بعدی: Cron Job</h2><p class="m">در cPanel ← Cron Jobs یک کار «Once Per Minute» با این دستور بسازید:</p>'
        .'<pre>'.h($php.' '.BASE.'/artisan schedule:run >/dev/null 2>&1').'</pre>'
        .'<p><a class="btn" href="'.h(rtrim($in['app_url'], '/').'/login').'">ورود به پنل</a></p>';
}

function page($title, $body)
{
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        .'<title>'.h($title).'</title><style>'
        .':root{--bg:#f5f6f8;--c:#fff;--t:#1b2130;--m:#667085;--b:#e3e6eb;--p:#2f5bea;--ok:#12805c;--bad:#c4320a;--warn:#b54708}'
        .'@media (prefers-color-scheme:dark){:root{--bg:#0f1218;--c:#171b23;--t:#e6e8ec;--m:#98a2b3;--b:#2a303c;--p:#7aa2ff;--ok:#3ccf91;--bad:#ff7a59;--warn:#fdb022}}'
        .'*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--t);font-family:IRANSans,Vazirmatn,Tahoma,sans-serif;line-height:1.8}'
        .'main{max-width:760px;margin:0 auto;padding:16px}.card{background:var(--c);border:1px solid var(--b);border-radius:12px;padding:16px}'
        .'h1{font-size:1.3rem}h2{font-size:1.05rem;margin-top:24px}.m{color:var(--m);font-size:.85rem}'
        .'.tw{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:.9rem}td{padding:6px;border-bottom:1px solid var(--b);vertical-align:top}'
        .'.b{padding:1px 8px;border-radius:99px;font-size:.78rem;white-space:nowrap}.ok{color:var(--ok)}.bad{color:var(--bad);font-weight:700}.warn{color:var(--warn)}'
        .'.f{margin-bottom:12px}label{display:block;font-weight:500;margin-bottom:4px}input,select{width:100%;min-height:42px;padding:8px 12px;border:1px solid var(--b);border-radius:8px;background:var(--c);color:var(--t);font:inherit}'
        .'.ltr{direction:ltr;text-align:left}.btn{display:inline-block;background:var(--p);color:#fff;border:0;border-radius:8px;padding:10px 20px;font:inherit;cursor:pointer;text-decoration:none}.btn[disabled]{opacity:.5}'
        .'.alert{border:1px solid var(--bad);color:var(--bad);border-radius:8px;padding:10px;margin:12px 0}pre{direction:ltr;text-align:left;background:var(--bg);padding:10px;border-radius:8px;overflow:auto;white-space:pre-wrap;word-break:break-all}'
        .'</style></head><body><main><div class="card"><h1>'.h($title).'</h1>'.$body.'</div></main></body></html>';
}
