<?php
// PM-SINGLE-FILE-INSTALLER (version __PM_VERSION__)
// Upload this ONE file into public_html (or the document root of a subdomain) and open
//   https://your-domain/install.php
// It unpacks the application next to the web root, then continues with the web installer.
// Written in PHP 5.3-compatible syntax so it can explain problems even on an old PHP version.

error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(300);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

define('PM_PAYLOAD_SHA256', '__PM_SHA256__');
define('PM_MAX_AGE', 7200); // the upload must be used within 2 hours

function pm_h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function pm_page($title, $html, $status)
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8', true, $status);
    }
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>نصب</title><style>'
        . 'body{font-family:IRANSans,Tahoma,sans-serif;background:#f4f6f9;color:#111827;margin:0;line-height:1.9}'
        . '.c{max-width:640px;margin:32px auto;padding:0 16px}.p{background:#fff;border-radius:14px;padding:20px;margin:12px 0}'
        . 'h1{font-size:21px}.ok{color:#0f7b45}.bad{color:#b42318}.warn{background:#fff6db;color:#7a5200;padding:10px;border-radius:10px}'
        . 'button{font:inherit;background:#2563eb;color:#fff;border:0;border-radius:10px;padding:12px 18px;width:100%;font-size:17px;cursor:pointer}'
        . 'code{direction:ltr;unicode-bidi:isolate;background:#eef1f5;padding:1px 6px;border-radius:6px}li{margin:4px 0}'
        . '</style></head><body><div class="c"><h1>' . pm_h($title) . '</h1>' . $html . '</div></body></html>';
    exit;
}

function pm_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) return true;
    return isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
}

$host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST']) : '';

// ---------------------------------------------------------------- 1. HTTPS
if (!pm_https()) {
    pm_page('ابتدا SSL را فعال کنید',
        '<div class="p"><p>این صفحه بدون رمزنگاری (http) باز شده است. نصب فقط روی <b>https</b> انجام می‌شود.</p>'
        . '<p>اگر SSL دامنه فعال است، روی این لینک بزنید:<br><a href="https://' . pm_h($host) . '/install.php">https://' . pm_h($host) . '/install.php</a></p>'
        . '<p>اگر آن لینک خطای امنیتی داد: در cPanel بخش <b>SSL/TLS Status</b> را باز کنید و دکمه <b>Run AutoSSL</b> را بزنید، چند دقیقه صبر کنید و دوباره امتحان کنید.</p></div>', 200);
}

// ---------------------------------------------------------------- 2. PHP version and extensions
$problems = array();
if (PHP_VERSION_ID < 80200) {
    $problems[] = 'نسخه PHP این دامنه <b>' . pm_h(PHP_VERSION) . '</b> است ولی حداقل <b>8.2</b> لازم است.<br>'
        . 'cPanel ← <b>MultiPHP Manager</b> (یا <b>Select PHP Version</b>) ← برای دامنه <code>' . pm_h($host) . '</code> نسخه <b>8.2</b> یا بالاتر را انتخاب و Apply کنید.';
}
$missing = array();
foreach (array('pdo_mysql', 'openssl', 'sodium', 'mbstring', 'json', 'curl', 'ctype', 'hash', 'zlib') as $ext) {
    if (!extension_loaded($ext)) $missing[] = $ext;
}
if ($missing) {
    $problems[] = 'این افزونه‌های PHP خاموش هستند: <code>' . pm_h(implode(', ', $missing)) . '</code><br>'
        . 'cPanel ← <b>Select PHP Version</b> ← تب <b>Extensions</b> ← تیک همین‌ها را بزنید.';
}
if ($problems) {
    pm_page('سرور هنوز آماده نیست', '<div class="p"><ol><li>' . implode('</li><li>', $problems) . '</li></ol><p>بعد از درست کردن، همین صفحه را دوباره باز کنید (Refresh).</p></div>', 200);
}

// ---------------------------------------------------------------- 3. Location checks
$here = realpath(__DIR__);
$docroot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
if ($docroot === false || $here !== $docroot) {
    pm_page('فایل در پوشه درست نیست',
        '<div class="p"><p>فایل <code>install.php</code> باید <b>مستقیماً داخل</b> <code>' . pm_h($docroot ? $docroot : 'public_html') . '</code> باشد، نه داخل یک پوشه دیگر.</p>'
        . '<p>الان در این مسیر است: <code>' . pm_h($here) . '</code></p><p>در File Manager آن را جابه‌جا کنید و دوباره باز کنید.</p></div>', 200);
}
if (filemtime(__FILE__) < time() - PM_MAX_AGE) {
    pm_page('فایل نصب قدیمی است',
        '<div class="p"><p>برای امنیت، فایل نصب فقط تا ۲ ساعت پس از آپلود کار می‌کند.</p>'
        . '<p>فایل <code>install.php</code> را در File Manager حذف کنید، دوباره آپلود کنید و همین صفحه را باز کنید.</p></div>', 200);
}
$home = dirname($here);
$target = (is_writable($home) && !is_link($home)) ? $home . '/private-messenger' : $here . '/private-messenger';
if (is_file($target . '/storage/installed.lock')) {
    @unlink(__FILE__);
    pm_page('قبلاً نصب شده است', '<div class="p"><p>برنامه قبلاً روی این هاست نصب شده است و فایل نصب حذف شد.</p>'
        . '<p>آدرس‌های ورود در فایل <code>' . pm_h($target) . '/config/.env</code> (مقدارهای APP_PATH و ADMIN_PATH) هستند.</p></div>', 200);
}
if (!is_writable($here)) {
    pm_page('پوشه قابل نوشتن نیست', '<div class="p"><p>پوشه <code>' . pm_h($here) . '</code> قابل نوشتن نیست. در File Manager روی آن راست‌کلیک ← Change Permissions ← 755.</p></div>', 200);
}

// ---------------------------------------------------------------- 4. Claim: the first browser that starts owns this installation
$claimFile = $here . '/.pm-install-claim';
$cookieName = '__Host-pmclaim';
$cookie = isset($_COOKIE[$cookieName]) ? (string)$_COOKIE[$cookieName] : '';
$claimed = is_file($claimFile);
if ($claimed && !($cookie !== '' && hash_equals(trim((string)file_get_contents($claimFile)), hash('sha256', $cookie)))) {
    pm_page('نصب در مرورگر دیگری شروع شده است',
        '<div class="p"><p>نصب از یک مرورگر دیگر شروع شده است. اگر خودتان بودید و می‌خواهید از اول شروع کنید، در File Manager فایل <code>.pm-install-claim</code> را '
        . '(با فعال کردن Show Hidden Files) حذف کنید و این صفحه را دوباره باز کنید.</p></div>', 200);
}

// Other website content in this web root?
$foreign = array();
foreach (scandir($here) as $f) {
    if (in_array($f, array('.', '..', 'install.php', '.htaccess', '.pm-install-claim', 'cgi-bin', '.well-known', 'index.html', 'default.html',
        'favicon.ico', 'error_log', '.user.ini', 'php.ini', 'private-messenger', 'index.php'), true)) continue;
    $foreign[] = $f;
}
if (is_file($here . '/index.php') && strpos((string)file_get_contents($here . '/index.php'), 'pmCandidates') === false) {
    $foreign[] = 'index.php';
}

if (!isset($_POST['start'])) {
    $warn = '';
    if ($foreign) {
        $warn = '<div class="warn"><b>توجه:</b> در این پوشه فایل‌های سایت دیگری هست (' . pm_h(implode('، ', array_slice($foreign, 0, 6)))
            . (count($foreign) > 6 ? '، …' : '') . '). با نصب، آن سایت از کار می‌افتد. بهتر است یک <b>ساب‌دامین جدید</b> بسازید (cPanel ← Domains) و فایل را آنجا آپلود کنید.'
            . '<br><label><input type="checkbox" name="confirm_foreign" value="1" required> می‌دانم و می‌خواهم همین‌جا نصب شود</label></div>';
    }
    pm_page('نصب برنامه',
        '<div class="p"><p class="ok">✔ PHP ' . pm_h(PHP_VERSION) . ' — ✔ HTTPS — ✔ افزونه‌ها</p>'
        . '<p>با زدن دکمه زیر، فایل‌های برنامه در این مسیر قرار می‌گیرند:<br><code>' . pm_h($target) . '</code></p>'
        . '<form method="post">' . $warn . '<input type="hidden" name="start" value="1"><button>شروع نصب</button></form></div>', 200);
}
if ($foreign && empty($_POST['confirm_foreign'])) {
    pm_page('تأیید لازم است', '<div class="p"><p>لطفاً به صفحه قبل برگردید و گزینه تأیید را بزنید.</p></div>', 200);
}

// Claim (first POST) — then this browser alone continues.
if (!$claimed) {
    $cookie = bin2hex(openssl_random_pseudo_bytes(32));
    file_put_contents($claimFile, hash('sha256', $cookie));
    setcookie($cookieName, $cookie, 0, '/', '', true, true);
}

// ---------------------------------------------------------------- 5. Unpack
$fh = fopen(__FILE__, 'rb');
fseek($fh, __COMPILER_HALT_OFFSET__);
$raw = stream_get_contents($fh);
fclose($fh);
if (hash('sha256', $raw) !== PM_PAYLOAD_SHA256) {
    pm_page('فایل نصب ناقص آپلود شده است', '<div class="p"><p>فایل <code>install.php</code> کامل آپلود نشده یا خراب شده است. آن را حذف و دوباره آپلود کنید (با File Manager، نه ویرایشگر متن).</p></div>', 200);
}
$files = json_decode(gzinflate($raw), true);
if (!is_array($files)) {
    pm_page('خطا در باز کردن فایل‌ها', '<div class="p"><p>محتوای فایل نصب قابل خواندن نیست. دوباره آپلود کنید.</p></div>', 200);
}
foreach ($files as $rel => $b64) {
    if (strpos($rel, '..') !== false || $rel === '' || $rel[0] === '/') continue;
    $dest = $target . '/' . $rel;
    if (substr($rel, -6) === '/.keep') { if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true); continue; }
    if ($rel === 'config/.env' || $rel === 'storage/installed.lock') continue;
    if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) {
        pm_page('خطا در ساخت پوشه', '<div class="p"><p>ساخت پوشه <code>' . pm_h(dirname($dest)) . '</code> ممکن نشد. فضای دیسک یا مجوزها را بررسی کنید.</p></div>', 200);
    }
    if (file_put_contents($dest, base64_decode($b64)) === false) {
        pm_page('خطا در نوشتن فایل', '<div class="p"><p>نوشتن <code>' . pm_h($dest) . '</code> ممکن نشد. احتمالاً فضای دیسک تمام شده است.</p></div>', 200);
    }
    @chmod($dest, 0644);
}

// ---------------------------------------------------------------- 6. Web root entry point and rewrite rules
if (is_file($here . '/index.php') && strpos((string)file_get_contents($here . '/index.php'), 'pmCandidates') === false) {
    @rename($here . '/index.php', $here . '/index.php.before-install-' . date('Ymd-His'));
}
copy($target . '/public/index.php', $here . '/index.php');
// Merge into an existing .htaccess: cPanel stores the PHP-version handler there and it must be kept.
$block = "# BEGIN app\n" . trim(preg_replace('/^#.*$/m', '', (string)file_get_contents($target . '/public/.htaccess'))) . "\n# END app\n";
$block = preg_replace("/\n{2,}/", "\n", $block);
$existing = is_file($here . '/.htaccess') ? (string)file_get_contents($here . '/.htaccess') : '';
$existing = preg_replace('/# BEGIN app\n.*?# END app\n?/s', '', $existing);
if ($existing !== '' && !is_file($here . '/.htaccess.before-install')) {
    @copy($here . '/.htaccess', $here . '/.htaccess.before-install');
}
file_put_contents($here . '/.htaccess', $block . ($existing !== '' ? "\n" . ltrim($existing) : ''));

// ---------------------------------------------------------------- 7. Hand over to the web installer (skips the token step)
@mkdir($target . '/storage/logs', 0755, true);
$token = bin2hex(openssl_random_pseudo_bytes(16));
file_put_contents($target . '/storage/install-token.txt', $token . "\n");
@chmod($target . '/storage/install-token.txt', 0600);
setcookie('__Host-inst', hash('sha256', $token), 0, '/', '', true, true);
header('Location: https://' . $host . '/install/', true, 303);
exit;
__halt_compiler();