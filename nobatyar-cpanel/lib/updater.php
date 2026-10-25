<?php
declare(strict_types=1);

/**
 * One-click update from the admin panel (like WordPress): the owner uploads the release ZIP, the package is
 * checked and unpacked into private/updates, a preview shows the version and the files that will change,
 * and «نصب به‌روزرسانی» (with the owner's password) does the rest:
 *   1. maintenance mode for visitors (admins keep working; a stale flag expires by itself after 30 minutes),
 *   2. a full database backup (kind «preupdate») and a copy of every file that will be replaced (rollback),
 *   3. files copied one by one (temporary name + rename), never touching config.php, private/, install.lock,
 *      and keeping the host's own .htaccess blocks (cPanel PHP handler),
 *   4. a fresh request (upgrade.php?finish=…) loads the new code and applies the missing database steps,
 *   5. maintenance mode ends. Any error during the copy restores the previous files automatically.
 *
 * Package rules (all checked before anything is written outside private/updates):
 *   - a real ZIP, at most 60 MB, 3,000 entries and 150 MB unpacked; one top folder or none;
 *   - no absolute paths, «..», backslashes, control characters or symbolic links;
 *   - release.json lists every file with its SHA-256; files missing from the list, extra files or a different
 *     hash reject the package (a damaged or altered download is never installed);
 *   - lib/version.php of the package: same application, version not older than the installed one, database
 *     step not lower; config.php and install.lock may not be in a package.
 * release.json proves the files arrived intact, not who made them: install only packages from a trusted source.
 */

const UPDATE_MAX_ZIP = 60 * 1024 * 1024;
const UPDATE_MAX_ENTRIES = 3000;
const UPDATE_MAX_UNPACKED = 150 * 1024 * 1024;
const UPDATE_FLAG_TTL = 1800;

function update_dir(): string
{
    $dir = dirname(__DIR__) . '/private/updates';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new RuntimeException('پوشه private/updates قابل ساخت نیست؛ دسترسی نوشتن پوشه private را بررسی کنید.');
    }
    return $dir;
}

function update_root(): string
{
    return dirname(__DIR__);
}

/** Paths a package never writes: site configuration, private data, installer lock, host-specific files. */
function update_skipped(string $rel): bool
{
    return str_starts_with($rel, 'private/') || in_array($rel, ['config.php', 'install.lock', '.user.ini', 'php.ini', 'error_log'], true);
}

function update_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') {
        return PHP_INT_MAX;
    }
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

/** Largest upload this host accepts (the smaller of upload_max_filesize and post_max_size). */
function update_upload_limit(): int
{
    return min(update_ini_bytes((string) ini_get('upload_max_filesize')), update_ini_bytes((string) ini_get('post_max_size')), UPDATE_MAX_ZIP);
}

function update_size_label(int $bytes): string
{
    return $bytes >= 1048576 ? fa_number(rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.')) . ' مگابایت' : fa_number((string) max(1, intdiv($bytes, 1024))) . ' کیلوبایت';
}

/** Host checks shown on the update page; a red item blocks the update. */
function update_checks(): array
{
    $root = update_root();
    return [
        ['label' => 'افزونه ZipArchive برای باز کردن بسته', 'ok' => class_exists('ZipArchive')],
        ['label' => 'دسترسی نوشتن پوشه اصلی برنامه', 'ok' => is_writable($root) && is_writable($root . '/lib') && is_writable($root . '/assets')],
        ['label' => 'دسترسی نوشتن پوشه private', 'ok' => is_writable($root . '/private')],
        ['label' => 'حداکثر حجم بارگذاری هاست: ' . update_size_label(update_upload_limit()), 'ok' => update_upload_limit() >= 8 * 1024 * 1024],
        ['label' => 'فضای آزاد دیسک', 'ok' => (@disk_free_space($root) ?: PHP_INT_MAX) > 200 * 1024 * 1024],
    ];
}

function update_ready(): bool
{
    foreach (array_slice(update_checks(), 0, 3) as $c) {
        if (!$c['ok']) {
            return false;
        }
    }
    return true;
}

/** Deletes a folder created by the updater (only inside private/updates). */
function update_rmtree(string $dir): void
{
    $base = realpath(update_dir());
    $real = realpath($dir);
    if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($real);
}

function update_safe_path(string $p): bool
{
    return $p !== '' &&
        !str_contains($p, '\\') &&
        !str_starts_with($p, '/') &&
        !preg_match('/[\x00-\x1f\x7f]|^[a-zA-Z]:/', $p) &&
        !in_array('..', explode('/', $p), true) &&
        !in_array('.', explode('/', $p), true) &&
        !str_contains($p, '//');
}

/** Version facts read from the text of lib/version.php (never executed). */
function update_parse_version(string $php): ?array
{
    if (!preg_match("/const APP_VERSION = '([0-9][0-9A-Za-z.\-]{0,30})';/", $php, $v) ||
        !preg_match("/const APP_VERSION_FA = '([^'\\\\]{1,80})';/u", $php, $fa) ||
        !preg_match('/const SCHEMA_VERSION = ([0-9]{1,4});/', $php, $s)) {
        return null;
    }
    return ['version' => $v[1], 'version_fa' => $fa[1], 'schema' => (int) $s[1]];
}

/**
 * Checks an uploaded package and unpacks it into a new folder under private/updates.
 * Returns the pending update (also stored in the setting «update_pending»).
 */
function update_receive(array $upload): array
{
    if (!class_exists('ZipArchive')) {
        throw new AuthError('افزونه ZipArchive روی هاست فعال نیست؛ از روش دستی (File Manager و upgrade.php) استفاده کنید.');
    }
    $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new AuthError('حجم فایل از حداکثر بارگذاری هاست (' . update_size_label(update_upload_limit()) . ') بیشتر است. مقدار upload_max_filesize و post_max_size را در «Select PHP Version» یا «MultiPHP INI Editor» سی‌پنل بیشتر کنید.');
    }
    if ($err === UPLOAD_ERR_NO_FILE) {
        throw new AuthError('فایل ZIP به‌روزرسانی را انتخاب کنید.');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? '')) && PHP_SAPI !== 'cli') {
        throw new AuthError('بارگذاری فایل کامل نشد؛ دوباره امتحان کنید.');
    }
    if (!preg_match('/\.zip$/i', (string) ($upload['name'] ?? '')) || (int) $upload['size'] > UPDATE_MAX_ZIP) {
        throw new AuthError('فقط فایل ZIP نسخه نوبت‌یار (حداکثر ۶۰ مگابایت) پذیرفته می‌شود.');
    }
    update_cancel();
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $zipPath = update_dir() . '/package-' . $id . '.zip';
    if (!(PHP_SAPI === 'cli' ? copy($upload['tmp_name'], $zipPath) : move_uploaded_file($upload['tmp_name'], $zipPath))) {
        throw new RuntimeException('فایل در پوشه private/updates ذخیره نشد.');
    }
    $stage = update_dir() . '/stage-' . $id;
    try {
        $info = update_unpack($zipPath, $stage);
    } catch (Throwable $e) {
        update_rmtree($stage);
        @unlink($zipPath);
        audit('update.rejected', null, mb_substr($e->getMessage(), 0, 180));
        throw $e;
    }
    @unlink($zipPath);
    $pending = $info + ['id' => $id, 'uploaded_at' => date('Y-m-d H:i:s'), 'file_name' => mb_substr(basename((string) $upload['name']), 0, 120)];
    setting_set('update_pending', json_encode($pending, JSON_UNESCAPED_UNICODE));
    audit('update.upload', null, $pending['version']);
    return $pending;
}

/** The checks listed in the file header, then extraction of the listed files only. */
function update_unpack(string $zipPath, string $stage): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
        throw new AuthError('این فایل ZIP معتبر نیست یا ناقص بارگذاری شده است.');
    }
    try {
        if ($zip->numFiles < 1 || $zip->numFiles > UPDATE_MAX_ENTRIES) {
            throw new AuthError('تعداد فایل‌های بسته غیرعادی است.');
        }
        $entries = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = (string) $st['name'];
            if (!update_safe_path(rtrim($name, '/'))) {
                throw new AuthError('بسته شامل مسیر غیرمجاز است: ' . mb_substr($name, 0, 80));
            }
            $zip->getExternalAttributesIndex($i, $os, $attr);
            if ($os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new AuthError('بسته شامل پیوند نمادین (symlink) است و پذیرفته نمی‌شود.');
            }
            $total += (int) $st['size'];
            if ($total > UPDATE_MAX_UNPACKED) {
                throw new AuthError('حجم باز‌شده بسته بیش از حد مجاز است.');
            }
            if (!str_ends_with($name, '/')) {
                $entries[$name] = $i;
            }
        }
        // One top folder (nobatyar-cpanel/) or files at the root of the ZIP.
        $prefix = '';
        if (!isset($entries['release.json'])) {
            $tops = array_unique(array_map(fn($n) => explode('/', $n, 2)[0], array_keys($entries)));
            if (count($tops) !== 1 || !isset($entries[$tops[0] . '/release.json'])) {
                throw new AuthError('این فایل بسته به‌روزرسانی نوبت‌یار نیست (release.json پیدا نشد). فایل ZIP دریافتی نسخه را بدون تغییر بارگذاری کنید.');
            }
            $prefix = $tops[0] . '/';
        }
        $manifest = json_decode((string) $zip->getFromIndex($entries[$prefix . 'release.json']), true);
        if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'nobatyar' || !is_array($manifest['files'] ?? null) || !$manifest['files']) {
            throw new AuthError('فایل release.json بسته معتبر نیست.');
        }
        $files = $manifest['files'];
        foreach ($entries as $name => $_) {
            if (!str_starts_with($name, $prefix)) {
                throw new AuthError('ساختار پوشه‌های بسته معتبر نیست.');
            }
            $rel = substr($name, strlen($prefix));
            if ($rel !== 'release.json' && !isset($files[$rel])) {
                throw new AuthError('فایلی خارج از فهرست release.json در بسته هست: ' . mb_substr($rel, 0, 80));
            }
        }
        foreach ($files as $rel => $hash) {
            if (!is_string($rel) || !update_safe_path($rel) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
                throw new AuthError('فهرست فایل‌های بسته معتبر نیست.');
            }
            if (in_array($rel, ['config.php', 'install.lock'], true)) {
                throw new AuthError('بسته شامل ' . $rel . ' است؛ بسته رسمی هرگز تنظیمات سایت را ندارد.');
            }
            if (!isset($entries[$prefix . $rel])) {
                throw new AuthError('بسته ناقص است؛ این فایل وجود ندارد: ' . mb_substr($rel, 0, 80));
            }
        }
        foreach (['lib/version.php', 'lib/migrator.php', 'core.php', 'upgrade.php', 'admin.php', 'lib/updater.php'] as $must) {
            if (!isset($files[$must])) {
                throw new AuthError('بسته کامل نیست (' . $must . ' ندارد).');
            }
        }
        $ver = update_parse_version((string) $zip->getFromIndex($entries[$prefix . 'lib/version.php']));
        if (!$ver) {
            throw new AuthError('نسخه بسته قابل تشخیص نیست.');
        }
        if (version_compare($ver['version'], APP_VERSION, '<')) {
            throw new AuthError('نسخه بسته (' . $ver['version'] . ') از نسخه نصب‌شده (' . APP_VERSION . ') قدیمی‌تر است. بازگشت به نسخه قبل فقط با «بازگرداندن نسخه قبلی» همین صفحه ممکن است.');
        }
        if ($ver['schema'] < schema_version(db())) {
            throw new AuthError('مرحله دیتابیس بسته از دیتابیس فعلی پایین‌تر است؛ این بسته با داده‌های فعلی سازگار نیست.');
        }
        if (!@mkdir($stage, 0700, true)) {
            throw new RuntimeException('پوشه موقت بسته ساخته نشد.');
        }
        foreach ($files as $rel => $hash) {
            $target = $stage . '/' . $rel;
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0700, true)) {
                throw new RuntimeException('پوشه موقت ساخته نشد.');
            }
            $in = $zip->getStream($prefix . $rel);
            $out = $in ? fopen($target, 'wb') : false;
            if (!$in || !$out) {
                throw new RuntimeException('خواندن فایل از بسته ممکن نشد: ' . $rel);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            if (!hash_equals($hash, hash_file('sha256', $target))) {
                throw new AuthError('فایل ' . mb_substr($rel, 0, 80) . ' با release.json یکسان نیست؛ بسته آسیب دیده یا تغییر کرده است.');
            }
        }
        $notes = '';
        if (isset($files['CHANGELOG.md'])) {
            $log = (string) file_get_contents($stage . '/CHANGELOG.md');
            $parts = preg_split('/\n# /u', "\n" . ltrim($log), 3) ?: [];
            $notes = mb_substr(trim((string) ($parts[1] ?? '')), 0, 6000);
        }
        return $ver + ['files' => count($files), 'notes' => $notes, 'built' => is_string($manifest['built'] ?? null) ? mb_substr($manifest['built'], 0, 40) : ''];
    } finally {
        $zip->close();
    }
}

function update_pending(): ?array
{
    $p = json_decode(setting('update_pending', ''), true);
    if (!is_array($p) || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', (string) ($p['id'] ?? '')) || !is_dir(update_dir() . '/stage-' . $p['id'])) {
        return null;
    }
    return $p;
}

function update_cancel(): void
{
    foreach (glob(update_dir() . '/stage-*') ?: [] as $d) {
        update_rmtree($d);
    }
    foreach (glob(update_dir() . '/package-*.zip') ?: [] as $f) {
        @unlink($f);
    }
    setting_set('update_pending', '');
}

/** Files of a staged package against the live site: new, changed, unchanged (skipped paths excluded). */
function update_plan(array $pending): array
{
    $stage = update_dir() . '/stage-' . $pending['id'];
    $plan = ['new' => [], 'changed' => [], 'same' => 0, 'skipped' => []];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($stage) + 1));
        if (update_skipped($rel)) {
            $plan['skipped'][] = $rel;
            continue;
        }
        $live = update_root() . '/' . $rel;
        if (!is_file($live)) {
            $plan['new'][] = $rel;
        } elseif (!hash_equals(hash_file('sha256', $live), hash_file('sha256', $f->getPathname()))) {
            $plan['changed'][] = $rel;
        } else {
            $plan['same']++;
        }
    }
    sort($plan['new']);
    sort($plan['changed']);
    return $plan;
}

/** Keeps the host's own blocks (cPanel PHP handler, php ini directives) when .htaccess is replaced. */
function update_merge_htaccess(string $old, string $new): string
{
    preg_match_all('/^#\s*(?:php -- )?BEGIN cPanel[^\n]*\n.*?^#\s*(?:php -- )?END cPanel[^\n]*$/ims', $old, $m);
    $keep = array_filter($m[0], fn($b) => !str_contains($new, trim($b)));
    return $keep ? rtrim($new) . "\n\n" . implode("\n\n", $keep) . "\n" : $new;
}

function update_flag(): string
{
    return update_root() . '/private/updating.flag';
}

function update_verify_owner_password(string $password): void
{
    admin_require('admins');
    if (!admin_password_confirm($password)) {
        audit('update.denied');
        throw new AuthError('رمز عبور شما اشتباه است.');
    }
}

/** Writes one file through a temporary name in the same folder, then renames it over the old one. */
function update_put(string $from, string $to, ?string $content = null): void
{
    $dir = dirname($to);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException('پوشه ' . basename($dir) . ' ساخته نشد.');
    }
    $tmp = $dir . '/.nobatyar-' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = $content !== null ? file_put_contents($tmp, $content) !== false : @copy($from, $tmp);
    if (!$ok || !@rename($tmp, $to)) {
        @unlink($tmp);
        throw new RuntimeException('نوشتن فایل ممکن نشد: ' . substr($to, strlen(update_root()) + 1));
    }
    @chmod($to, 0644);
}

/**
 * Installs the staged package. Returns the one-time token for upgrade.php?finish=… which runs the new code's
 * database steps in a fresh request. On a copy error the replaced files are put back and the error is thrown.
 */
function update_apply(string $password): string
{
    update_verify_owner_password($password);
    $pending = update_pending();
    if (!$pending) {
        throw new AuthError('بسته‌ای برای نصب آماده نیست؛ ابتدا فایل ZIP را بارگذاری کنید.');
    }
    if (!update_ready()) {
        throw new AuthError('پیش‌نیازهای هاست برای به‌روزرسانی کامل نیست (موارد قرمز همین صفحه).');
    }
    $lock = fopen(update_dir() . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new AuthError('به‌روزرسانی دیگری در حال اجراست.');
    }
    @set_time_limit(600);
    @ignore_user_abort(true);
    $root = update_root();
    $stage = update_dir() . '/stage-' . $pending['id'];
    $plan = update_plan($pending);
    $targets = array_merge($plan['changed'], $plan['new']);
    // Everything that will be written must be writable before the first change.
    $blocked = [];
    foreach ($targets as $rel) {
        $live = $root . '/' . $rel;
        $dir = dirname($live);
        while (!is_dir($dir)) {
            $dir = dirname($dir);
        }
        if ((is_file($live) && !is_writable($live)) || !is_writable($dir)) {
            $blocked[] = $rel;
        }
    }
    if ($blocked) {
        throw new AuthError('این فایل‌ها قابل نوشتن نیستند (دسترسی را در File Manager روی ۶۴۴ و پوشه‌ها را ۷۵۵ بگذارید): ' . implode('، ', array_slice($blocked, 0, 8)));
    }
    $backup = backup_create('preupdate');
    $rollback = update_dir() . '/rollback-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    if (!@mkdir($rollback . '/files', 0700, true)) {
        throw new RuntimeException('پوشه نسخه قبلی ساخته نشد.');
    }
    foreach ($plan['changed'] as $rel) {
        if (!is_dir(dirname($rollback . '/files/' . $rel))) {
            mkdir(dirname($rollback . '/files/' . $rel), 0700, true);
        }
        if (!copy($root . '/' . $rel, $rollback . '/files/' . $rel)) {
            throw new RuntimeException('نسخه قبلی فایل ' . $rel . ' ذخیره نشد؛ به‌روزرسانی انجام نشد.');
        }
    }
    file_put_contents($rollback . '/rollback.json', json_encode([
        'from' => APP_VERSION,
        'to' => $pending['version'],
        'changed' => $plan['changed'],
        'created' => $plan['new'],
        'backup' => $backup,
        'at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    touch(update_flag());
    $written = [];
    try {
        // Application code last, so a half-finished copy never pairs a new core with old libraries for long.
        usort($targets, fn($a, $b) => [in_array($a, ['core.php', 'lib/version.php'], true), $a] <=> [in_array($b, ['core.php', 'lib/version.php'], true), $b]);
        foreach ($targets as $rel) {
            $content = null;
            if ($rel === '.htaccess' && is_file($root . '/.htaccess')) {
                $content = update_merge_htaccess((string) file_get_contents($root . '/.htaccess'), (string) file_get_contents($stage . '/.htaccess'));
            }
            update_put($stage . '/' . $rel, $root . '/' . $rel, $content);
            $written[] = $rel;
        }
    } catch (Throwable $e) {
        update_restore_files($rollback, $written);
        @unlink(update_flag());
        audit('update.failed', null, $pending['version'] . ': ' . mb_substr($e->getMessage(), 0, 150));
        throw new RuntimeException('به‌روزرسانی انجام نشد و فایل‌های قبلی برگردانده شد: ' . $e->getMessage());
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    update_prune_rollbacks();
    audit('update.apply', null, APP_VERSION . ' → ' . $pending['version']);
    $token = bin2hex(random_bytes(16));
    $_SESSION['update_finish'] = ['token' => $token, 'to' => $pending['version'], 'backup' => $backup, 'files' => count($targets)];
    return $token;
}

/** Puts back the saved copies and deletes files that were new (only $only when given). */
function update_restore_files(string $rollback, ?array $only = null): void
{
    $meta = json_decode((string) @file_get_contents($rollback . '/rollback.json'), true) ?: ['changed' => [], 'created' => []];
    $root = update_root();
    foreach ($meta['changed'] as $rel) {
        if (($only === null || in_array($rel, $only, true)) && is_file($rollback . '/files/' . $rel)) {
            update_put($rollback . '/files/' . $rel, $root . '/' . $rel);
        }
    }
    foreach ($meta['created'] as $rel) {
        if (($only === null || in_array($rel, $only, true)) && update_safe_path($rel) && !update_skipped($rel)) {
            @unlink($root . '/' . $rel);
        }
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

/** Second half, in a fresh request with the new code: database steps, end of maintenance, clean-up. */
function update_finish(string $token): array
{
    $f = $_SESSION['update_finish'] ?? null;
    if (!is_array($f) || !hash_equals((string) $f['token'], $token) || !admin_can('admins')) {
        throw new AuthError('پیوند تکمیل به‌روزرسانی نامعتبر یا منقضی است.');
    }
    unset($_SESSION['update_finish']);
    try {
        $ran = run_migrations(db());
    } finally {
        @unlink(update_flag());
    }
    update_cancel();
    audit('update.finish', null, APP_VERSION . ($ran ? ' steps ' . implode(',', $ran) : ''));
    return $f + ['ran' => $ran];
}

function update_rollbacks(): array
{
    $out = [];
    foreach (glob(update_dir() . '/rollback-*', GLOB_ONLYDIR) ?: [] as $d) {
        $m = json_decode((string) @file_get_contents($d . '/rollback.json'), true);
        if (is_array($m)) {
            $out[] = $m + ['id' => basename($d)];
        }
    }
    usort($out, fn($a, $b) => strcmp($b['id'], $a['id']));
    return $out;
}

function update_prune_rollbacks(): void
{
    foreach (array_slice(update_rollbacks(), 2) as $r) {
        update_rmtree(update_dir() . '/' . $r['id']);
    }
}

/** Restores the files replaced by the latest update (the database stays; every migration is additive). */
function update_rollback(string $password): array
{
    update_verify_owner_password($password);
    $last = update_rollbacks()[0] ?? null;
    if (!$last) {
        throw new AuthError('نسخه قبلی برای بازگرداندن وجود ندارد.');
    }
    touch(update_flag());
    try {
        update_restore_files(update_dir() . '/' . $last['id']);
    } finally {
        @unlink(update_flag());
    }
    update_rmtree(update_dir() . '/' . $last['id']);
    audit('update.rollback', null, $last['to'] . ' → ' . $last['from']);
    return $last;
}

function update_history(): array
{
    return query("SELECT l.created_at,l.action,l.detail,a.email FROM audit_log l LEFT JOIN admins a ON a.id=l.actor_admin_id WHERE l.action IN ('update.apply','update.finish','update.rollback','update.failed','update.rejected') ORDER BY l.id DESC LIMIT 10")->fetchAll();
}
