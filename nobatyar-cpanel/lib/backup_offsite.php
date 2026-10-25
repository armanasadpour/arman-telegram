<?php
declare(strict_types=1);

/**
 * 1.0.30 off-server backup: after each daily backup a copy leaves the host, so the data survives losing the host.
 * The copy is a ZIP encrypted with AES-256 (opens in 7-Zip or WinRAR with the owner's password) holding the
 * .sql.gz file, which is restored through «امنیت و پشتیبان» → «بارگذاری فایل پشتیبان».
 * Destinations: e-mail (attachment) or another server over FTP/FTPS. Passwords are stored encrypted.
 */

const BACKUP_OFFSITE_MAIL_MAX = 20 * 1024 * 1024;

function backup_offsite_modes(): array
{
    return ['off' => 'خاموش', 'email' => 'ارسال به ایمیل', 'ftp' => 'ارسال به سرور دیگر (FTP)'];
}

function backup_offsite_settings(): array
{
    $last = json_decode(setting('backup_offsite_last', ''), true);
    return [
        'mode' => setting('backup_offsite_mode', 'off'),
        'email' => setting('backup_offsite_email', ''),
        'ftp_host' => setting('backup_offsite_ftp_host', ''),
        'ftp_port' => (int) setting('backup_offsite_ftp_port', '21'),
        'ftp_user' => setting('backup_offsite_ftp_user', ''),
        'ftp_dir' => setting('backup_offsite_ftp_dir', ''),
        'ftp_tls' => setting('backup_offsite_ftp_tls', '1') === '1',
        'has_zip_password' => secret_exists('backup_zip_password'),
        'has_ftp_password' => secret_exists('backup_ftp_password'),
        'last' => is_array($last) ? $last : null,
    ];
}

/** Owner only (permission «admins»). Empty password fields keep the stored passwords. */
function backup_offsite_save(array $d): string
{
    admin_require('admins');
    $mode = (string) ($d['mode'] ?? 'off');
    if (!isset(backup_offsite_modes()[$mode])) {
        throw new AuthError('روش ارسال نامعتبر است.');
    }
    $zip = (string) ($d['zip_password'] ?? '');
    if ($zip !== '' && mb_strlen($zip) < 10) {
        throw new AuthError('رمز فایل پشتیبان باید حداقل ۱۰ نویسه باشد.');
    }
    if ($mode !== 'off' && $zip === '' && !secret_exists('backup_zip_password')) {
        throw new AuthError('برای ارسال، رمز فایل پشتیبان را تعیین کنید؛ فایل بدون رمز از سرور خارج نمی‌شود.');
    }
    $values = ['backup_offsite_mode' => $mode];
    $email = trim((string) ($d['email'] ?? ''));
    if ($mode === 'email' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email))) {
        throw new AuthError('نشانی ایمیل معتبر وارد کنید.');
    }
    $values['backup_offsite_email'] = $email;
    $host = strtolower(trim((string) ($d['ftp_host'] ?? '')));
    $port = (int) ($d['ftp_port'] ?? 21);
    $user = trim((string) ($d['ftp_user'] ?? ''));
    $dir = trim((string) ($d['ftp_dir'] ?? ''), " /");
    if ($mode === 'ftp') {
        if (!preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
            throw new AuthError('نشانی سرور FTP را بدون ftp:// وارد کنید؛ مثل backup.example.com');
        }
        if ($port < 1 || $port > 65535 || $user === '' || mb_strlen($user) > 120) {
            throw new AuthError('درگاه و نام کاربری FTP را درست وارد کنید.');
        }
        if (($dir !== '' && !preg_match('~^[A-Za-z0-9_./-]{1,200}$~', $dir)) || str_contains($dir, '..')) {
            throw new AuthError('پوشه مقصد فقط حروف انگلیسی، عدد، خط تیره و / داشته باشد.');
        }
        if (($d['ftp_password'] ?? '') === '' && !secret_exists('backup_ftp_password')) {
            throw new AuthError('رمز FTP را وارد کنید.');
        }
    }
    $values += [
        'backup_offsite_ftp_host' => $host,
        'backup_offsite_ftp_port' => (string) $port,
        'backup_offsite_ftp_user' => $user,
        'backup_offsite_ftp_dir' => $dir,
        'backup_offsite_ftp_tls' => !empty($d['ftp_tls']) ? '1' : '0',
    ];
    settings_save($values);
    if ($zip !== '') {
        secret_set('backup_zip_password', $zip);
    }
    if (($d['ftp_password'] ?? '') !== '') {
        secret_set('backup_ftp_password', (string) $d['ftp_password']);
    }
    audit('backup.offsite_settings', null, $mode);
    return 'تنظیمات پشتیبان خارج از سرور ذخیره شد.' . ($mode !== 'off' ? ' با «ارسال آزمایشی» از درستی آن مطمئن شوید.' : '');
}

/** Packs one backup into an AES-256 encrypted ZIP in the private folder; the caller deletes it. */
function backup_offsite_package(string $name): string
{
    $source = backup_path($name);
    $password = secret_get('backup_zip_password');
    if ($password === '') {
        throw new RuntimeException('رمز فایل پشتیبان تعیین نشده است.');
    }
    $zipPath = backup_dir() . '/offsite-' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('ساخت فایل فشرده ممکن نشد.');
    }
    $readme = "پشتیبان " . site_name() . "\r\n\r\nاین فایل با رمزی که در «امنیت و پشتیبان» تعیین کرده‌اید باز می‌شود (7-Zip یا WinRAR).\r\n" .
        "برای بازیابی، فایل .sql.gz داخل آن را در «امنیت و پشتیبان» → «بارگذاری فایل پشتیبان» بارگذاری کنید.\r\n";
    $zip->addFile($source, $name);
    $zip->addFromString('README-fa.txt', $readme);
    foreach ([$name, 'README-fa.txt'] as $entry) {
        $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password);
    }
    if (!$zip->close()) {
        @unlink($zipPath);
        throw new RuntimeException('ساخت فایل فشرده رمزدار ممکن نشد.');
    }
    @chmod($zipPath, 0600);
    return $zipPath;
}

/** Sends one backup to the configured destination; records and returns the outcome. */
function backup_offsite_send(string $name): string
{
    $cfg = backup_offsite_settings();
    if ($cfg['mode'] === 'off') {
        return '';
    }
    $zipPath = null;
    try {
        $zipPath = backup_offsite_package($name);
        $file = preg_replace('/\.sql\.gz$/', '.zip', $name);
        $cfg['mode'] === 'email' ? backup_offsite_mail($zipPath, $file, $cfg) : backup_offsite_ftp($zipPath, $file, $cfg);
        $message = 'نسخه رمزدار به ' . ($cfg['mode'] === 'email' ? 'ایمیل ' . $cfg['email'] : 'سرور ' . $cfg['ftp_host']) . ' ارسال شد.';
        $ok = true;
    } catch (Throwable $e) {
        $message = 'ارسال پشتیبان خارج از سرور ناموفق بود: ' . error_message($e);
        $ok = false;
        app_log('error', 'backup_offsite', ['mode' => $cfg['mode'], 'error' => $e->getMessage()]);
        admin_event('daily', 'پشتیبان خارج از سرور ارسال نشد', mb_substr($message, 0, 150), 'offsite:' . date('Y-m-d'), 'admin.php?tab=security');
    } finally {
        if ($zipPath) {
            @unlink($zipPath);
        }
    }
    setting_set('backup_offsite_last', json_encode(['at' => date('Y-m-d H:i:s'), 'ok' => $ok, 'message' => $message, 'file' => $name], JSON_UNESCAPED_UNICODE));
    return $message;
}

/** «ارسال آزمایشی»: the newest backup (a new one is made when none exists). */
function backup_offsite_test(): string
{
    admin_require('admins');
    if (backup_offsite_settings()['mode'] === 'off') {
        throw new AuthError('ابتدا روش ارسال را انتخاب و ذخیره کنید.');
    }
    throttle('backup_offsite_test', 5, 3600);
    $latest = backup_latest();
    $name = $latest ? $latest['name'] : backup_create('manual');
    $result = backup_offsite_send($name);
    audit('backup.offsite_test', null, $name);
    $last = backup_offsite_settings()['last'];
    if (!$last || !$last['ok']) {
        throw new AuthError($result);
    }
    return $result;
}

function backup_offsite_mail(string $zipPath, string $file, array $cfg): void
{
    if (filesize($zipPath) > BACKUP_OFFSITE_MAIL_MAX) {
        throw new RuntimeException('حجم پشتیبان برای ایمیل زیاد است؛ روش FTP را انتخاب کنید.');
    }
    $host = parse_url(app_base_url(), PHP_URL_HOST) ?: 'localhost';
    $boundary = 'nby' . bin2hex(random_bytes(12));
    $subject = '=?UTF-8?B?' . base64_encode('پشتیبان ' . site_name() . ' — ' . jdate(date('Y-m-d'))) . '?=';
    $text = "پشتیبان رمزدار دیتابیس پیوست است ($file).\r\nرمز همان رمزی است که در «امنیت و پشتیبان» تعیین کرده‌اید؛ آن را در جای امن نگه دارید.\r\n";
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) .
        "--$boundary\r\nContent-Type: application/zip; name=\"$file\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$file\"\r\n\r\n" .
        chunk_split(base64_encode((string) file_get_contents($zipPath))) . "--$boundary--\r\n";
    $headers = "From: backup@$host\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"";
    if (!mail($cfg['email'], $subject, $body, $headers)) {
        throw new RuntimeException('سرویس ایمیل هاست پیام را نپذیرفت.');
    }
}

function backup_offsite_ftp(string $zipPath, string $file, array $cfg): void
{
    if (!function_exists('ftp_connect')) {
        throw new RuntimeException('افزونه FTP در PHP هاست فعال نیست.');
    }
    if ($cfg['ftp_tls'] && !function_exists('ftp_ssl_connect')) {
        throw new RuntimeException('اتصال امن FTPS در PHP هاست در دسترس نیست.');
    }
    $conn = $cfg['ftp_tls'] ? @ftp_ssl_connect($cfg['ftp_host'], $cfg['ftp_port'], 20) : @ftp_connect($cfg['ftp_host'], $cfg['ftp_port'], 20);
    if (!$conn) {
        throw new RuntimeException('اتصال به سرور ' . $cfg['ftp_host'] . ' برقرار نشد.');
    }
    try {
        if (!@ftp_login($conn, $cfg['ftp_user'], secret_get('backup_ftp_password'))) {
            throw new RuntimeException('نام کاربری یا رمز FTP پذیرفته نشد.');
        }
        ftp_pasv($conn, true);
        if ($cfg['ftp_dir'] !== '' && !@ftp_chdir($conn, $cfg['ftp_dir'])) {
            throw new RuntimeException('پوشه «' . $cfg['ftp_dir'] . '» روی سرور مقصد پیدا نشد.');
        }
        if (!@ftp_put($conn, $file, $zipPath, FTP_BINARY)) {
            throw new RuntimeException('بارگذاری فایل روی سرور مقصد انجام نشد.');
        }
    } finally {
        ftp_close($conn);
    }
}
