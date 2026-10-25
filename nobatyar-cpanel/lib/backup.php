<?php
declare(strict_types=1);

/**
 * Database backup and restore without SSH or mysqldump.
 *
 * Format: gzip-compressed SQL, one statement per line. The first line identifies the file:
 *   -- nobatyar-backup 1 schema=<N> app=<version> created=<ISO date>
 * Values are quoted with PDO::quote (MySQL escapes newlines), so a line is always one whole statement.
 * Files live in private/backups (closed to the web). Uploaded media and private/app.key are files, not
 * database rows: they are listed in the guide and must be copied with cPanel File Manager.
 */

const BACKUP_FORMAT = 1;

function backup_dir(): string
{
    $dir = dirname(__DIR__) . '/private/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new RuntimeException('پوشه private/backups قابل ساخت نیست؛ دسترسی نوشتن پوشه private را بررسی کنید.');
    }
    return $dir;
}

function backup_name_valid(string $name): bool
{
    return (bool) preg_match('/^nobatyar-[0-9]{8}-[0-9]{6}-[a-z]+-[a-f0-9]{8}\.sql\.gz$/', $name);
}

function backup_path(string $name): string
{
    if (!backup_name_valid($name)) {
        throw new RuntimeException('نام فایل پشتیبان نامعتبر است.');
    }
    $path = backup_dir() . '/' . $name;
    if (!is_file($path)) {
        throw new RuntimeException('فایل پشتیبان پیدا نشد.');
    }
    return $path;
}

/** Writes a full backup and returns its file name. $kind: manual, auto, prerestore. */
function backup_create(string $kind = 'manual'): string
{
    if (!function_exists('gzopen')) {
        throw new RuntimeException('افزونه zlib برای پشتیبان فشرده لازم است.');
    }
    @set_time_limit(300);
    $kind = preg_replace('/[^a-z]/', '', $kind) ?: 'manual';
    $name = 'nobatyar-' . date('Ymd-His') . '-' . $kind . '-' . bin2hex(random_bytes(4)) . '.sql.gz';
    $tmp = backup_dir() . '/.' . $name . '.part';
    $gz = gzopen($tmp, 'wb6');
    if (!$gz) {
        throw new RuntimeException('فایل پشتیبان قابل نوشتن نیست.');
    }
    $pdo = db();
    try {
        gzwrite($gz, '-- nobatyar-backup ' . BACKUP_FORMAT . ' schema=' . SCHEMA_VERSION . ' app=' . APP_VERSION . ' created=' . date('c') . "\n");
        gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`')->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . preg_replace('/\s*\n\s*/', ' ', $create) . ";\n");
            $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`', PDO::FETCH_NUM);
            $batch = [];
            while ($row = $stmt->fetch()) {
                $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) >= 200) {
                    gzwrite($gz, 'INSERT INTO `' . $table . '` VALUES ' . implode(',', $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, 'INSERT INTO `' . $table . '` VALUES ' . implode(',', $batch) . ";\n");
            }
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- end\n");
    } finally {
        gzclose($gz);
    }
    rename($tmp, backup_dir() . '/' . $name);
    @chmod(backup_dir() . '/' . $name, 0600);
    backup_prune();
    if (isset($_SESSION['admin_id'])) {
        audit('backup.create', null, $name);
    }
    return $name;
}

/** Keeps the newest N automatic/manual backups; pre-restore copies are kept separately (newest 3). */
function backup_prune(): void
{
    $keep = setting_int('backup_keep', 7, 2, 60);
    $all = backup_list();
    // Safety copies taken before a restore or an update are kept separately (newest 3).
    $regular = array_values(array_filter($all, fn($b) => !in_array($b['kind'], ['prerestore', 'preupdate'], true)));
    $safety = array_values(array_filter($all, fn($b) => in_array($b['kind'], ['prerestore', 'preupdate'], true)));
    foreach ([[$regular, $keep], [$safety, 3]] as [$rows, $limit]) {
        foreach (array_slice($rows, $limit) as $b) {
            @unlink(backup_dir() . '/' . $b['name']);
        }
    }
}

function backup_list(): array
{
    $rows = [];
    foreach (glob(backup_dir() . '/nobatyar-*.sql.gz') ?: [] as $path) {
        $name = basename($path);
        if (!backup_name_valid($name)) {
            continue;
        }
        $rows[] = ['name' => $name, 'size' => filesize($path), 'time' => filemtime($path), 'kind' => explode('-', $name)[3], 'header' => backup_header($path)];
    }
    usort($rows, fn($a, $b) => [$b['time'], $b['name']] <=> [$a['time'], $a['name']]);
    return $rows;
}

function backup_latest(): ?array
{
    try {
        return backup_list()[0] ?? null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Parsed first line, or null when the file is not a Nobatyar backup. */
function backup_header(string $path): ?array
{
    $gz = @gzopen($path, 'rb');
    if (!$gz) {
        return null;
    }
    $line = (string) gzgets($gz, 512);
    gzclose($gz);
    if (!preg_match('/^-- nobatyar-backup ([0-9]+) schema=([0-9]+) app=(\S+) created=(\S+)/', $line, $m)) {
        return null;
    }
    return ['format' => (int) $m[1], 'schema' => (int) $m[2], 'app' => $m[3], 'created' => $m[4]];
}

function backup_download(string $name): never
{
    $path = backup_path($name);
    audit('backup.download', null, $name);
    header_remove('Cache-Control');
    header('Cache-Control: private, no-store');
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit();
}

function backup_delete(string $name): void
{
    unlink(backup_path($name));
    audit('backup.delete', null, $name);
}

/** Stores an uploaded backup in private/backups after checking it is a Nobatyar backup. */
function backup_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('فایل پشتیبان دریافت نشد. فایل‌های بزرگ را با File Manager در پوشه private/backups بگذارید.');
    }
    $header = backup_header($file['tmp_name']);
    if (!$header) {
        throw new RuntimeException('این فایل پشتیبان نوبت‌یار نیست.');
    }
    $name = 'nobatyar-' . date('Ymd-His') . '-uploaded-' . bin2hex(random_bytes(4)) . '.sql.gz';
    if (!move_uploaded_file($file['tmp_name'], backup_dir() . '/' . $name)) {
        throw new RuntimeException('ذخیره فایل پشتیبان ممکن نیست.');
    }
    @chmod(backup_dir() . '/' . $name, 0600);
    audit('backup.upload', null, $name);
    return $name;
}

/**
 * Replaces the whole database with a backup of the same schema version. The administrator re-enters
 * the password; a safety backup of the current data is written first. All sessions end afterwards
 * because the users and admins tables are replaced too.
 */
function backup_restore(string $name, string $password, string $confirm): string
{
    $me = admin_current();
    if (!$me || $me['role'] !== 'owner' || !admin_password_confirm($password)) {
        security_event('backup.restore_denied', $name);
        throw new RuntimeException('رمز عبور مالک سامانه درست نیست.');
    }
    if (trim($confirm) !== 'بازیابی') {
        throw new RuntimeException('برای تأیید، کلمه «بازیابی» را تایپ کنید.');
    }
    $path = backup_path($name);
    $header = backup_header($path);
    if (!$header || $header['format'] !== BACKUP_FORMAT) {
        throw new RuntimeException('قالب فایل پشتیبان پشتیبانی نمی‌شود.');
    }
    if ($header['schema'] !== SCHEMA_VERSION) {
        throw new RuntimeException('این پشتیبان مربوط به نسخه دیگری از دیتابیس (مرحله ' . fa_number($header['schema']) . ') است؛ ابتدا همان نسخه برنامه را نصب کنید، بازیابی کنید و سپس ارتقا دهید.');
    }
    // Validate the whole file before touching data: only the statement kinds the exporter writes.
    $gz = gzopen($path, 'rb');
    $lines = 0;
    while (($line = gzgets($gz)) !== false) {
        $line = rtrim($line, "\n");
        if ($line === '' || str_starts_with($line, '-- ')) {
            continue;
        }
        if (!preg_match('/^(SET NAMES utf8mb4|SET FOREIGN_KEY_CHECKS=[01]|DROP TABLE IF EXISTS `[a-z0-9_]+`|CREATE TABLE `[a-z0-9_]+` .*|INSERT INTO `[a-z0-9_]+` VALUES .*);$/s', $line)) {
            gzclose($gz);
            throw new RuntimeException('فایل پشتیبان دستکاری شده یا ناقص است (خط ' . fa_number($lines + 1) . ').');
        }
        $lines++;
    }
    gzclose($gz);
    if (!str_contains((string) backup_tail($path), '-- end')) {
        throw new RuntimeException('فایل پشتیبان کامل نیست.');
    }
    @set_time_limit(600);
    $safety = backup_create('prerestore');
    $pdo = db();
    $gz = gzopen($path, 'rb');
    try {
        while (($line = gzgets($gz)) !== false) {
            $line = rtrim($line, "\n");
            if ($line !== '' && !str_starts_with($line, '-- ')) {
                $pdo->exec(substr($line, 0, -1));
            }
        }
    } finally {
        gzclose($gz);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    // Every account signs in again; the restored admins table decides who can.
    query('UPDATE users SET session_version=session_version+1');
    query('UPDATE admins SET session_version=session_version+1');
    app_log('security', 'backup.restore', ['file' => $name, 'safety' => $safety]);
    query('INSERT INTO audit_log(actor_admin_id,action,detail,ip) VALUES(?,?,?,?)', [(int) $me['id'], 'backup.restore', $name, client_ip()]);
    return $safety;
}

function backup_tail(string $path): string
{
    $gz = gzopen($path, 'rb');
    $last = '';
    while (($line = gzgets($gz)) !== false) {
        $last = $line;
    }
    gzclose($gz);
    return $last;
}

/** Cron task: one automatic backup per day when enabled in the admin panel. */
function backup_auto(): string
{
    if (setting('backup_auto', '0') !== '1') {
        return 'پشتیبان خودکار غیرفعال است.';
    }
    $latest = array_values(array_filter(backup_list(), fn($b) => $b['kind'] === 'auto'))[0] ?? null;
    if ($latest && $latest['time'] > time() - 86000) {
        return 'پشتیبان امروز قبلاً ساخته شده است.';
    }
    $name = backup_create('auto');
    // 1.0.30: an encrypted copy also leaves the host when «پشتیبان خارج از سرور» is on.
    return trim('پشتیبان ساخته شد: ' . $name . ' ' . backup_offsite_send($name));
}
