<?php
declare(strict_types=1);

/**
 * 1.0.0 security audit checks (CLI): static rules over the source tree, log redaction, backup format
 * validation, multi-statement SQL refusal and secret storage. Uses the disposable nobatyar_test_http
 * database only for read-only checks and a rolled-back write.
 */

if (PHP_SAPI !== 'cli' || getenv('NOBATYAR_TEST') !== '1') {
    exit("Set NOBATYAR_TEST=1 to run disposable tests.\n");
}
require __DIR__ . '/../core.php';
if (config()['database'] !== 'nobatyar_test_http') {
    exit(9);
}
$root = dirname(__DIR__);
$checks = 0;
function check($ok, string $name): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $name);
    }
    $checks++;
    echo "PASS $name\n";
}
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$php = [];
foreach ($files as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    if (str_ends_with($rel, '.php') && !str_starts_with($rel, 'tests/') && !str_starts_with($rel, 'private/') && $rel !== 'config.php') {
        $php[$rel] = file_get_contents($f->getPathname());
    }
}
check(count($php) > 80, 'source tree scanned (' . count($php) . ' files)');

// CSRF: every entry page that reads POST verifies the token (bank callbacks are verified with the bank).
$entry = array_filter($php, fn($k) => !str_contains($k, '/'), ARRAY_FILTER_USE_KEY);
$missing = [];
foreach ($entry as $name => $src) {
    if (preg_match('/is_post\(\)|\$_POST/', $src) && !str_contains($src, 'verify_csrf') && !in_array($name, ['pay-return.php', 'payment-callback.php', 'api.php'], true)) {
        $missing[] = $name;
    }
}
check(!$missing, 'every form handler verifies the CSRF token ' . implode(',', $missing));
check(str_contains($php['api.php'], "hash_equals(\$_SESSION['csrf']"), 'API checks the CSRF header on writes');

// XSS: views never print request input directly; no inline script or event-handler attributes.
$raw = [];
foreach ($php as $name => $src) {
    if (preg_match_all('/<\?=\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/', $src, $m)) {
        $raw[] = $name;
    }
    if (preg_match('/<script(?![^>]*\b(src=|type="application\/ld\+json"))[^>]*>/i', $src) || preg_match('/\son(click|load|error|submit|change|input)\s*=\s*["\']/i', $src)) {
        $raw[] = $name . ' (inline script)';
    }
}
check(!$raw, 'no raw request output and no inline script in PHP sources ' . implode(',', $raw));
foreach (glob($root . '/*.html') as $html) {
    check(!preg_match('/<script(?![^>]*\bsrc=)/i', file_get_contents($html)), basename($html) . ' has no inline script');
}

// SQL: user values reach queries only as parameters.
$concat = [];
foreach ($php as $name => $src) {
    if (preg_match_all('/query\(\s*["\'][^"\']*["\']\s*\.\s*\$_(GET|POST|REQUEST)/', $src) || preg_match('/query\("[^"]*\{?\$_(GET|POST)/', $src)) {
        $concat[] = $name;
    }
}
check(!$concat, 'no request value concatenated into SQL ' . implode(',', $concat));
check(str_contains($php['lib/support.php'], 'PDO::ATTR_EMULATE_PREPARES => false') && str_contains($php['lib/support.php'], 'MYSQL_ATTR_MULTI_STATEMENTS => false'), 'native prepares and single-statement connection');
$threw = false;
try {
    db()->query('SELECT 1; SELECT 2');
} catch (PDOException $e) {
    $threw = true;
}
check($threw, 'a second chained statement is refused by the connection');

// Uploads: every image upload is re-encoded with GD; nothing is moved into a public folder.
foreach (['lib/specialties.php', 'lib/doctor_actions.php', 'lib/centers.php'] as $file) {
    check(str_contains($php[$file], 'getimagesize') && preg_match('/image(png|jpeg)\(/', $php[$file]), "$file re-encodes uploaded images");
}
$moves = array_keys(array_filter($php, fn($s) => str_contains($s, 'move_uploaded_file(')));
sort($moves);
check($moves === ['lib/backup.php', 'lib/updater.php'], 'only backups and update packages are moved as uploaded');
check(str_contains($php['lib/updater.php'], "/private/updates'") && str_contains($php['lib/updater.php'], 'hash_equals($hash'), 'update packages stay in private/updates and every file hash is checked');

// Folders closed to the web; configuration and dumps denied at the root.
foreach (['private', 'tests', 'lib', 'views', 'migrations'] as $dir) {
    check(str_contains((string) @file_get_contents("$root/$dir/.htaccess"), 'Require all denied'), "$dir/.htaccess denies web access");
}
$ht = file_get_contents("$root/.htaccess");
check(str_contains($ht, 'config') && str_contains($ht, 'sql|gz') && str_contains($ht, 'Options -Indexes'), 'root .htaccess denies config, dumps and listings');

// Secrets are not in the source and are encrypted at rest.
$leaks = array_keys(array_filter($php, fn($s) => preg_match('/[0-9A-Za-z]{40,}=*\s*[\'"]\s*;/', $s) && preg_match('/api[_-]?key|merchant|secret/i', $s) && preg_match('/(apikey|api_key|merchant_id)\s*=>?\s*[\'"][0-9A-Za-z]{20,}/i', $s)));
check(!$leaks, 'no hard-coded API key or merchant id ' . implode(',', $leaks));
db()->beginTransaction();
try {
    secret_set('kavenegar_api_key', 'TEST-KEY-1234567890-ABCDEFGHIJ');
    $stored = (string) query("SELECT value FROM settings WHERE `key`='secret.kavenegar_api_key'")->fetchColumn();
    check($stored !== '' && !str_contains($stored, 'TEST-KEY') && secret_get('kavenegar_api_key') === 'TEST-KEY-1234567890-ABCDEFGHIJ', 'API keys stored encrypted and readable only with app.key');
    check(!str_contains(secret_hint('kavenegar_api_key'), '1234567890'), 'admin pages show only a hint of the key');
} finally {
    db()->rollBack();
}

// Logs never contain sensitive values.
$ref = app_log('security', 'redaction test', ['password' => 'p@ss', 'otp_code' => '123456', 'mobile' => '09120000000', 'card_pan' => '6037', 'nested' => ['api_key' => 'K'], 'gateway' => 'zarinpal']);
$line = '';
foreach (glob(log_dir() . '/security-*.log') as $file) {
    foreach (file($file) as $l) {
        if (str_contains($l, $ref)) {
            $line = $l;
        }
    }
}
check($line !== '' && !preg_match('/p@ss|123456|09120000000|6037"|"K"/', $line) && str_contains($line, 'zarinpal'), 'log lines redact passwords, codes, numbers and keys');
check(log_tail('security', 5)[0]['ref'] === $ref, 'latest security log lines readable for the admin page');
check(substr(sprintf('%o', fileperms(log_dir())), -3) === '700', 'log folder readable only by the account');

// Backup format guard.
$_SESSION['admin_id'] = (int) query("SELECT id FROM admins WHERE role='owner' ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION['admin_login_at'] = time();
$_SESSION['admin_version'] = (int) query('SELECT session_version FROM admins WHERE id=?', [$_SESSION['admin_id']])->fetchColumn();
$name = backup_create('manual');
$path = backup_path($name);
$header = backup_header($path);
check($header && $header['schema'] === SCHEMA_VERSION && $header['app'] === APP_VERSION, 'backup header records schema and version');
check(substr(sprintf('%o', fileperms($path)), -3) === '600', 'backup file readable only by the account');
foreach (['../config.php', 'nobatyar-x.sql.gz', 'nobatyar-20260101-000000-manual-zzzzzzzz.sql.gz'] as $bad) {
    $refused = false;
    try {
        backup_path($bad);
    } catch (RuntimeException $e) {
        $refused = true;
    }
    check($refused, 'backup path refused: ' . $bad);
}
$refused = '';
try {
    backup_restore($name, 'not-the-password', 'بازیابی');
} catch (RuntimeException $e) {
    $refused = $e->getMessage();
}
check(str_contains($refused, 'رمز عبور'), 'restore without the owner password refused');
backup_delete($name);
check(!is_file($path), 'backup deleted');

// Service worker and offline page carry the release version; no page is precached.
$sw = file_get_contents("$root/sw.js");
check(str_contains($sw, "const VERSION = '" . APP_VERSION . "';"), 'service worker version is the release version');
check(!preg_match("/^  '[^']*\.php/m", $sw) && str_contains($sw, "req.method !== 'GET'"), 'service worker never precaches pages and ignores non-GET');
check(str_contains(file_get_contents("$root/offline.html"), 'style.css?v=' . APP_VERSION), 'offline page uses the release stylesheet');

// Headers set for every response.
$src = $php['lib/security.php'];
foreach (["script-src 'self'", "object-src 'none'", "frame-ancestors 'self'", 'Strict-Transport-Security', 'Cache-Control: no-store'] as $needle) {
    check(str_contains($src, $needle), 'security header present: ' . $needle);
}
echo "SUCCESS $checks security checks\n";
