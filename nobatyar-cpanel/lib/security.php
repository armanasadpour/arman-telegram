<?php
declare(strict_types=1);

/**
 * Response hardening, private log files and the host security checklist.
 *
 * - Every page gets a strict Content-Security-Policy: scripts only from this site (no inline script),
 *   styles from this site plus style attributes (CSS variables on bars), images from this site, data:
 *   and HTTPS (map tiles), forms only to this site or HTTPS (bank gateways).
 * - Errors and security events go to private/logs/<channel>-YYYY-MM.log as JSON lines. Values under
 *   sensitive keys are replaced before writing; the visitor only sees a generic message and a reference.
 */

function security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: no-store');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(self), payment=()');
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; " .
            "img-src 'self' data: https:; font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; " .
            "frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self' https:",
    );
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** Keys whose values never reach a log file. */
function log_redact(array $context): array
{
    foreach ($context as $k => $v) {
        if (is_array($v)) {
            $context[$k] = log_redact($v);
        } elseif (preg_match('/pass|token|secret|key|otp|code|card|pan|cvv|pin|csrf|auth|cookie|session|mobile|phone/i', (string) $k)) {
            $context[$k] = '[redacted]';
        }
    }
    return $context;
}

function log_dir(): string
{
    return dirname(__DIR__) . '/private/logs';
}

/** Appends one JSON line to private/logs/<channel>-YYYY-MM.log. Never throws. */
function app_log(string $channel, string $message, array $context = []): string
{
    $ref = strtoupper(bin2hex(random_bytes(4)));
    try {
        $dir = log_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $line = json_encode(
            [
                'at' => date('c'),
                'ref' => $ref,
                'message' => mb_substr($message, 0, 1000),
                'ip' => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
                'uri' => PHP_SAPI === 'cli' ? implode(' ', $_SERVER['argv'] ?? []) : mb_substr(strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '', 0, 200),
                'context' => log_redact($context),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        $file = $dir . '/' . preg_replace('/[^a-z]/', '', $channel) . '-' . date('Y-m') . '.log';
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        @chmod($file, 0600);
    } catch (Throwable $e) {
        error_log('nobatyar log failure: ' . $e->getMessage());
    }
    return $ref;
}

/** Latest lines of a log channel for the admin security page (newest first). */
function log_tail(string $channel, int $limit = 30): array
{
    $files = glob(log_dir() . '/' . preg_replace('/[^a-z]/', '', $channel) . '-*.log') ?: [];
    rsort($files);
    $rows = [];
    foreach ($files as $file) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
            if (count($rows) >= $limit) {
                return $rows;
            }
        }
    }
    return $rows;
}

/** Uncaught errors: logged with a reference; the visitor gets a plain page without details. */
function install_error_handlers(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (app_environment() !== 'development') {
        ini_set('display_errors', '0');
    }
    set_exception_handler(function (Throwable $e) {
        $ref = app_log('error', get_class($e) . ': ' . $e->getMessage(), ['file' => basename($e->getFile()) . ':' . $e->getLine()]);
        if (!headers_sent()) {
            http_response_code($e instanceof AuthError ? $e->status : 500);
            header('Content-Type: text/html; charset=utf-8');
        }
        $public = $e instanceof AuthError ? $e->getMessage() : 'در پردازش درخواست خطایی رخ داد. لطفاً چند لحظه بعد دوباره امتحان کنید.';
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>خطا</title><link rel="stylesheet" href="assets/style.css"></head><body class="error-page"><main class="narrow"><div class="card"><h1>متأسفیم</h1><p>' .
            esc($public) .
            '</p><p class="muted">کد پیگیری خطا: <bdi dir="ltr">' .
            esc($ref) .
            '</bdi></p><p><a class="button" href="index.php">بازگشت به صفحه اصلی</a></p></div></main></body></html>';
    });
    set_error_handler(function (int $no, string $msg, string $file, int $line) {
        if (!(error_reporting() & $no)) {
            return false;
        }
        app_log('error', $msg, ['file' => basename($file) . ':' . $line, 'level' => $no]);
        return app_environment() !== 'development';
    });
}

/** Security event: audit row (for the admin activity log) plus a line in the security log file. */
function security_event(string $action, string $detail = '', ?int $entity = null): void
{
    app_log('security', $action, ['detail' => $detail]);
    try {
        audit($action, $entity, $detail);
    } catch (Throwable $e) {
        // The audit table may not exist yet during installation.
    }
}

/**
 * Host checklist for the "security and backup" admin page. Each row: [label, status ok|warn|fail, detail].
 * Checks are read-only and never print secret values.
 */
function security_checks(): array
{
    $root = dirname(__DIR__);
    $rows = [];
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $rows[] = ['HTTPS', $https ? 'ok' : 'fail', $https ? 'اتصال رمزنگاری‌شده است.' : 'گواهی SSL را در cPanel (AutoSSL) فعال کنید؛ ورود، پرداخت و اعلان بدون HTTPS امن نیست.'];
    $rows[] = ['محیط اجرا', app_environment() === 'production' ? 'ok' : 'warn', app_environment() === 'production' ? 'حالت عملیاتی.' : 'حالت توسعه فعال است؛ روی سایت اصلی در config.php مقدار environment را production بگذارید.'];
    $display = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) || ini_get('display_errors') === 'stderr';
    $rows[] = ['نمایش خطای PHP', $display && app_environment() === 'production' ? 'warn' : 'ok', $display ? 'display_errors روشن است؛ سامانه آن را در زمان اجرا خاموش می‌کند ولی بهتر است در MultiPHP INI Editor هم خاموش شود.' : 'خطاها به بازدیدکننده نمایش داده نمی‌شود.'];
    $rows[] = ['نسخه PHP', PHP_VERSION_ID >= 80200 ? 'ok' : 'warn', 'نسخه ' . PHP_VERSION . (PHP_VERSION_ID >= 80200 ? '' : ' — پشتیبانی امنیتی این نسخه رو به پایان است؛ ۸.۲ یا بالاتر را انتخاب کنید.')];
    $perm = @fileperms($root . '/config.php');
    $worldReadable = $perm !== false && ($perm & 0004);
    $rows[] = ['دسترسی config.php', $worldReadable ? 'warn' : 'ok', $worldReadable ? 'فایل برای همه قابل خواندن است؛ در File Manager دسترسی را ۶۰۰ یا ۶۴۰ کنید.' : 'فقط مالک حساب هاست می‌تواند آن را بخواند.'];
    $key = $root . '/private/app.key';
    $rows[] = ['کلید رمزنگاری', is_file($key) ? 'ok' : 'fail', is_file($key) ? 'موجود است؛ آن را جدا از پشتیبان دیتابیس نگه دارید.' : 'فایل private/app.key پیدا نشد.'];
    foreach (['private' => 'پوشه private', 'tests' => 'پوشه tests', 'migrations' => 'پوشه migrations'] as $dir => $label) {
        $protected = is_file($root . '/' . $dir . '/.htaccess') && str_contains((string) file_get_contents($root . '/' . $dir . '/.htaccess'), 'denied');
        $rows[] = [$label . ' بسته به روی وب', $protected ? 'ok' : 'fail', $protected ? 'دسترسی مستقیم مسدود است.' : 'فایل .htaccess این پوشه را از بسته نصب دوباره کپی کنید.'];
    }
    $rows[] = ['نصب‌کننده', is_file($root . '/config.php') ? 'ok' : 'warn', 'پس از نصب، install.php هیچ کاری انجام نمی‌دهد و پاسخ ۴۰۳ می‌دهد.'];
    $leftovers = array_values(array_filter(['phpinfo.php', 'info.php', 'test.php', 'adminer.php', 'config.php.bak', 'backup.sql'], fn($f) => is_file($root . '/' . $f)));
    $rows[] = ['فایل‌های اضافی', $leftovers ? 'fail' : 'ok', $leftovers ? 'این فایل‌ها را حذف کنید: ' . implode('، ', $leftovers) : 'فایل خطرناکی در ریشه سایت نیست.'];
    $lastBackup = backup_latest();
    $age = $lastBackup ? (int) floor((time() - $lastBackup['time']) / 86400) : null;
    $rows[] = ['پشتیبان دیتابیس', $age === null ? 'warn' : ($age > 7 ? 'warn' : 'ok'), $age === null ? 'هنوز پشتیبانی ساخته نشده است.' : 'آخرین پشتیبان ' . fa_number($age) . ' روز پیش ساخته شده است.'];
    $cron = setting('cron_last_run', '');
    $cronOk = $cron !== '' && strtotime($cron) > time() - 3600;
    $rows[] = ['Cron', $cronOk ? 'ok' : 'warn', $cronOk ? 'در یک ساعت گذشته اجرا شده است.' : 'Cron در یک ساعت گذشته اجرا نشده است؛ بخش «زمان‌بندی» را ببینید.'];
    $owners = (int) query("SELECT COUNT(*) FROM admins WHERE role='owner' AND active=1")->fetchColumn();
    $rows[] = ['حساب‌های مالک', $owners <= 2 ? 'ok' : 'warn', 'تعداد مالک فعال: ' . fa_number($owners) . ($owners > 2 ? ' — برای همکاران نقش محدود بسازید.' : '')];
    return $rows;
}
