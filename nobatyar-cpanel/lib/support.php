<?php
declare(strict_types=1);

/** Configuration, database access, escaping, CSRF, settings and error translation. */

function config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $file = dirname(__DIR__) . '/config.php';
    if (!is_file($file)) {
        if (PHP_SAPI === 'cli') {
            throw new RuntimeException('config.php not found; install the application first.');
        }
        header('Location: install.php');
        exit();
    }
    return $config = require $file;
}

/** "production" unless config.php explicitly says "development". Only development allows SMS test mode. */
function app_environment(): string
{
    // Before installation there is no config.php: treat the site as production (no redirect from here).
    if (!is_file(dirname(__DIR__) . '/config.php')) {
        return 'production';
    }
    return (config()['environment'] ?? 'production') === 'development' ? 'development' : 'production';
}

function db(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        $c = config();
        $pdo = new PDO(
            'mysql:host=' . $c['host'] . ';dbname=' . $c['database'] . ';charset=utf8mb4',
            $c['username'],
            $c['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // One statement per call: a string that slipped into SQL can never chain a second one.
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ],
        );
        $pdo->exec("SET time_zone='+03:30'");
    }
    return $pdo;
}

function query(string $sql, array $params = []): PDOStatement
{
    $s = db()->prepare($sql);
    $s->execute($params);
    return $s;
}

/** Builds "?,?,?" for an IN() list. */
function placeholders(array $values): string
{
    return implode(',', array_fill(0, max(1, count($values)), '?'));
}

function db_transaction(callable $callback)
{
    if (db()->inTransaction()) {
        return $callback();
    }
    db()->beginTransaction();
    try {
        $r = $callback();
        db()->commit();
        return $r;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

function esc($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    if (
        !isset($_SESSION['csrf']) ||
        !is_string($_POST['csrf'] ?? null) ||
        !hash_equals($_SESSION['csrf'], $_POST['csrf'])
    ) {
        // rc.18: a page form whose token is out of date (another tab signed in or out, or the page was open
        // for hours) goes back to the same page with a message instead of a bare error page.
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $parts = $ref !== '' ? parse_url($ref) : false;
        $sameSite = $parts && isset($parts['host'], $parts['path']) && strcasecmp($parts['host'], (string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) === 0;
        $json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), 'api.php');
        if ($sameSite && !$json && session_status() === PHP_SESSION_ACTIVE) {
            flash('مهلت این فرم تمام شده بود و ثبت نشد. لطفاً دوباره ثبت کنید.');
            redirect(basename($parts['path']) . (isset($parts['query']) ? '?' . $parts['query'] : ''));
        }
        http_response_code(419);
        exit('درخواست نامعتبر است. صفحه را تازه کنید.');
    }
}

function setting(string $key, string $default = ''): string
{
    $v = query('SELECT value FROM settings WHERE `key`=?', [$key])->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function setting_int(string $key, int $default, int $min, int $max): int
{
    $value = (int) setting($key, (string) $default);
    return max($min, min($max, $value));
}

function setting_set(string $key, string $value): void
{
    query('INSERT INTO settings (`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [
        $key,
        $value,
    ]);
}

function settings_save(array $values): void
{
    db_transaction(function () use ($values) {
        foreach ($values as $key => $value) {
            setting_set((string) $key, (string) $value);
        }
    });
}

/**
 * Signed-in administrator check: 12-hour sessions; after the 0.12 upgrade the account must be active and
 * its session version must match (disabling, a role change or a password reset signs it out everywhere).
 */
function admin(): bool
{
    if (!isset($_SESSION['admin_id']) || time() - (int) ($_SESSION['admin_login_at'] ?? 0) >= 43200) {
        return false;
    }
    if (!schema_is_current()) {
        return (bool) query('SELECT id FROM admins WHERE id=?', [(int) $_SESSION['admin_id']])->fetch();
    }
    return (bool) query('SELECT id FROM admins WHERE id=? AND active=1 AND session_version=?', [
        (int) $_SESSION['admin_id'],
        (int) ($_SESSION['admin_version'] ?? 1),
    ])->fetch();
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit();
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function error_message(Throwable $e): string
{
    if ($e instanceof PDOException) {
        error_log((string) $e);
        return $e->getCode() === '23000'
            ? 'این اطلاعات تکراری است یا به رکورد دیگری وابسته است.'
            : 'خطای پایگاه داده؛ گزارش سرور را بررسی کنید.';
    }
    if ($e instanceof RuntimeException) {
        return $e->getMessage();
    }
    error_log((string) $e);
    return 'در پردازش درخواست خطایی رخ داد.';
}

/** Pages stop with a friendly message until the administrator runs the upgrader. */
function require_schema(): void
{
    if (schema_is_current()) {
        return;
    }
    http_response_code(503);
    layout_start('ارتقای لازم');
    echo '<div class="card"><h1>نسخه جدید نصب شده است</h1><p>برای ادامه، مدیر سامانه باید <a href="upgrade.php">ارتقای پایگاه داده به نسخه ' .
        APP_VERSION_FA .
        '</a> را اجرا کند. اطلاعات قبلی حفظ می‌شود.</p></div>';
    layout_end();
    exit();
}

/** Backwards-compatible name used by pages written before 0.7.0. */
function require_phase2(): void
{
    require_schema();
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Adds keys to config.php (used by upgrades for values introduced after installation). */
function config_add(array $values): void
{
    $file = dirname(__DIR__) . '/config.php';
    $current = require $file;
    $merged = $values + $current;
    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, '<?php return ' . var_export($merged, true) . ';', LOCK_EX) === false) {
        throw new RuntimeException('config.php قابل به‌روزرسانی نیست؛ دسترسی نوشتن پوشه را بررسی کنید.');
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('config.php قابل به‌روزرسانی نیست.');
    }
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($file, true);
    }
}

/** Absolute URL of the installation folder with a trailing slash, e.g. https://example.com/nobat/ */
function app_base_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = preg_replace('/[^a-zA-Z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = PHP_SAPI === 'cli' ? '' : rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/.');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

/**
 * While an update copies files (private/updating.flag, at most 30 minutes old), visitors get a short
 * «در حال به‌روزرسانی» page; signed-in administrators, admin.php and upgrade.php keep working.
 */
function maintenance_gate(): void
{
    $flag = dirname(__DIR__) . '/private/updating.flag';
    if (PHP_SAPI === 'cli' || !is_file($flag) || filemtime($flag) < time() - 1800) {
        return;
    }
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['admin.php', 'upgrade.php'], true) || !empty($_SESSION['admin_id'])) {
        return;
    }
    http_response_code(503);
    header('Retry-After: 120');
    header('Cache-Control: no-store');
    if ($script === 'api.php') {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'error' => 'سامانه در حال به‌روزرسانی است؛ چند دقیقه دیگر دوباره امتحان کنید.'], JSON_UNESCAPED_UNICODE));
    }
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>در حال به‌روزرسانی</title><link rel="stylesheet" href="assets/style.css"></head><body><main class="maintenance"><h1>در حال به‌روزرسانی</h1><p>نوبت‌یار چند لحظه برای نصب نسخه جدید در دسترس نیست. نوبت‌ها و اطلاعات شما محفوظ است؛ چند دقیقه دیگر صفحه را تازه کنید.</p></main></body></html>');
}
