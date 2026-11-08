<?php
/**
 * Private Messenger — Host capability probe (Phase 1 research tool)
 *
 * Measures what a cPanel / shared host REALLY supports. Nothing is assumed.
 *
 * SECURITY:
 *   1. Set PROBE_TOKEN below to a long random string (>= 24 chars) before uploading.
 *   2. Open https://your-domain/probe.php?token=YOUR_TOKEN
 *   3. Save the JSON report, then press "delete probe" (or delete the file manually).
 *   The script refuses to run with the default token and over plain HTTP (unless ?allow_http=1
 *   is given, which is only reported, never recommended).
 */

declare(strict_types=1);

const PROBE_TOKEN = 'CHANGE_ME';
const PROBE_VERSION = '1.0.0';
const TEST_DIR_NAME = 'pm-probe-tmp';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

// ---------------------------------------------------------------- guard
$token = (string)($_GET['token'] ?? '');
if (PROBE_TOKEN === 'CHANGE_ME' || strlen(PROBE_TOKEN) < 24) {
    http_response_code(503);
    exit('Probe disabled: edit PROBE_TOKEN in probe.php (>= 24 random characters).');
}
if (!hash_equals(PROBE_TOKEN, $token)) {
    http_response_code(404);
    exit;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

if (!is_https() && !isset($_GET['allow_http'])) {
    http_response_code(403);
    exit('Probe requires HTTPS. (Report only: add &allow_http=1 to run anyway; HTTPS will be marked as failed.)');
}

$action = (string)($_GET['action'] ?? 'report');
$docroot = rtrim((string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__)), DIRECTORY_SEPARATOR);
$testDir = __DIR__ . DIRECTORY_SEPARATOR . TEST_DIR_NAME;

// ---------------------------------------------------------------- helpers
function ini_bytes(string $key): ?int
{
    $v = ini_get($key);
    if ($v === false || $v === '') {
        return null;
    }
    $v = trim($v);
    $unit = strtolower(substr($v, -1));
    $num = (float)$v;
    switch ($unit) {
        case 'g': $num *= 1024;
        // no break
        case 'm': $num *= 1024;
        // no break
        case 'k': $num *= 1024;
    }
    return (int)$num;
}

function fn_available(string $fn): bool
{
    if (!function_exists($fn)) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array($fn, $disabled, true);
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function run_cmd(string $cmd): ?string
{
    if (fn_available('shell_exec')) {
        $out = @shell_exec($cmd . ' 2>/dev/null');
        return is_string($out) ? trim($out) : null;
    }
    if (fn_available('exec')) {
        $lines = [];
        @exec($cmd . ' 2>/dev/null', $lines);
        return trim(implode("\n", $lines));
    }
    return null;
}

// ---------------------------------------------------------------- actions used by the in-page JS tests
switch ($action) {
    case 'echo_size':
        // Real request-body limit test (raw octet-stream or JSON). Reads php://input.
        $in = fopen('php://input', 'rb');
        $n = 0;
        $ctx = hash_init('sha256');
        if ($in) {
            while (!feof($in)) {
                $buf = fread($in, 1 << 20);
                if ($buf === false || $buf === '') {
                    break;
                }
                $n += strlen($buf);
                hash_update($ctx, $buf);
            }
            fclose($in);
        }
        json_out([
            'received_bytes' => $n,
            'content_length' => (int)($_SERVER['CONTENT_LENGTH'] ?? -1),
            'content_type'   => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
            'sha256'         => hash_final($ctx),
            // PHP 8.x may still deliver raw (non-form) bodies above post_max_size via php://input
            // while emitting a startup warning. The app never relies on that: chunks stay below it.
            'post_max_size_bytes' => ini_bytes('post_max_size'),
        ]);
        // no break
    case 'sleep':
        $s = max(1, min(60, (int)($_GET['s'] ?? 25)));
        $limitOk = fn_available('set_time_limit') ? @set_time_limit($s + 10) : false;
        $t0 = microtime(true);
        sleep($s);
        json_out(['slept' => $s, 'elapsed' => round(microtime(true) - $t0, 2), 'set_time_limit_ok' => $limitOk]);
        // no break
    case 'setup_http_tests':
        // Creates a temp dir with: a .wasm file (MIME test) and a denied subdir (.htaccess test).
        rrmdir($testDir);
        $ok = @mkdir($testDir, 0755) && @mkdir($testDir . '/denied', 0755);
        if ($ok) {
            // Minimal valid WebAssembly module header ("\0asm" + version 1)
            file_put_contents($testDir . '/t.wasm', "\0asm\x01\0\0\0");
            file_put_contents($testDir . '/denied/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            $canary = bin2hex(random_bytes(16));
            file_put_contents($testDir . '/denied/canary.txt', $canary);
            json_out(['ok' => true, 'base' => TEST_DIR_NAME, 'canary' => $canary]);
        }
        json_out(['ok' => false, 'error' => 'cannot create test dir next to probe.php'], 500);
        // no break
    case 'cleanup':
        rrmdir($testDir);
        json_out(['ok' => !is_dir($testDir)]);
        // no break
    case 'selfdelete':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_out(['ok' => false, 'error' => 'POST required'], 405);
        }
        rrmdir($testDir);
        $ok = @unlink(__FILE__);
        json_out(['ok' => $ok, 'message' => $ok ? 'probe deleted' : 'could not delete; remove probe.php manually']);
        // no break
    case 'db':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_out(['ok' => false, 'error' => 'POST required'], 405);
        }
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $res = ['ok' => false];
        if (!extension_loaded('pdo_mysql')) {
            json_out($res + ['error' => 'pdo_mysql missing']);
        }
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string)($body['host'] ?? 'localhost'),
                (int)($body['port'] ?? 3306),
                (string)($body['name'] ?? '')
            );
            $pdo = new PDO($dsn, (string)($body['user'] ?? ''), (string)($body['pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $isMaria = stripos($ver, 'mariadb') !== false;
            preg_match('/^(\d+)\.(\d+)/', $ver, $m);
            $major = (int)($m[1] ?? 0);
            $minor = (int)($m[2] ?? 0);
            $supported = $isMaria ? ($major > 10 || ($major === 10 && $minor >= 6)) : ($major >= 8);
            $pdo->exec('CREATE TEMPORARY TABLE pm_probe_t (id INT PRIMARY KEY, j JSON) ENGINE=InnoDB');
            $pdo->exec("INSERT INTO pm_probe_t VALUES (1, '{\"a\":1}')");
            $pdo->beginTransaction();
            $skip = true;
            try {
                $pdo->query('SELECT id FROM pm_probe_t WHERE id = 1 FOR UPDATE SKIP LOCKED')->fetchAll();
            } catch (Throwable $e) {
                $skip = false;
            }
            $pdo->commit();
            $innodb = (string)$pdo->query("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE='InnoDB'")->fetchColumn();
            $maxPacket = (int)$pdo->query('SELECT @@max_allowed_packet')->fetchColumn();
            json_out([
                'ok' => true,
                'version' => $ver,
                'flavor' => $isMaria ? 'mariadb' : 'mysql',
                'version_supported' => $supported,
                'innodb' => $innodb,
                'skip_locked' => $skip,
                'json_type' => true,
                'max_allowed_packet' => $maxPacket,
                'charset_utf8mb4' => true,
            ]);
        } catch (Throwable $e) {
            // Do not echo credentials; error message only.
            json_out(['ok' => false, 'error' => substr($e->getMessage(), 0, 300)]);
        }
        // no break
    case 'outbound':
        $host = (string)($_GET['host'] ?? '');
        if (!preg_match('/^[a-z0-9.-]{3,253}$/i', $host)) {
            json_out(['ok' => false, 'error' => 'invalid host'], 400);
        }
        if (!function_exists('curl_init')) {
            json_out(['ok' => false, 'error' => 'curl missing']);
        }
        $ch = curl_init('https://' . $host . '/');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        json_out(['ok' => $err === '' && $code > 0, 'http_code' => $code, 'error' => $err]);
}

// ---------------------------------------------------------------- static report
$checks = [];
$add = static function (string $group, string $name, $value, string $status, string $note = '') use (&$checks): void {
    // status: ok | warn | fail | info
    $checks[] = compact('group', 'name', 'value', 'status', 'note');
};

// PHP
$add('php', 'PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'fail', 'حداقل 8.2');
$add('php', 'SAPI', PHP_SAPI, 'info');
$add('php', 'Server software', (string)($_SERVER['SERVER_SOFTWARE'] ?? ''), 'info');
$add('php', 'PHP binary (CLI path guess)', PHP_BINARY, 'info', 'برای دستور Cron؛ مسیر رایج cPanel: /usr/local/bin/php');

$required = ['pdo_mysql', 'openssl', 'sodium', 'mbstring', 'json', 'curl', 'ctype', 'hash'];
$optional = ['zip' => 'استخراج بسته در نصب‌کننده', 'gmp' => 'Web Push (اختیاری)', 'bcmath' => 'Web Push (جایگزین gmp)',
             'intl' => 'تاریخ/اعداد سمت سرور', 'zlib' => 'فشرده‌سازی', 'opcache' => 'کارایی', 'fileinfo' => 'غیرضروری (فایل‌ها رمزنگاری‌شده‌اند)'];
foreach ($required as $ext) {
    $add('ext', $ext, extension_loaded($ext) ? 'loaded' : 'missing', extension_loaded($ext) ? 'ok' : 'fail', 'الزامی');
}
foreach ($optional as $ext => $why) {
    $add('ext', $ext, extension_loaded($ext) ? 'loaded' : 'missing', extension_loaded($ext) ? 'ok' : 'warn', $why);
}

// Limits
$upl = ini_bytes('upload_max_filesize');
$post = ini_bytes('post_max_size');
$mem = ini_bytes('memory_limit');
$add('limits', 'upload_max_filesize', ini_get('upload_max_filesize'), 'info');
$add('limits', 'post_max_size', ini_get('post_max_size'), ($post !== null && $post > 0 && $post < 2 * 1048576) ? 'warn' : 'info', 'سقف هر درخواست؛ با آپلود چندبخشی دور زده می‌شود');
$eff = ($post !== null && $post > 0) ? (int)floor(min($post, $upl ?: $post) * 0.8) : null;
$add('limits', 'suggested chunk size', $eff !== null ? min($eff, 8 * 1048576) : 'unknown', 'info', 'min(post,upload)×0.8، سقف 8MB — با تست واقعی پایین تأیید شود');
$add('limits', 'memory_limit', ini_get('memory_limit'), ($mem !== null && $mem > 0 && $mem < 64 * 1048576) ? 'warn' : 'ok');
$add('limits', 'max_execution_time', ini_get('max_execution_time'), 'info');
$add('limits', 'max_input_time', ini_get('max_input_time'), 'info');
$add('limits', 'set_time_limit available', fn_available('set_time_limit') ? 'yes' : 'no', fn_available('set_time_limit') ? 'ok' : 'warn');
$add('limits', 'open_basedir', (string)ini_get('open_basedir') ?: '(none)', 'info');
$add('limits', 'allow_url_fopen', ini_get('allow_url_fopen') ? 'on' : 'off', 'info');
$add('limits', 'disable_functions', (string)ini_get('disable_functions') ?: '(none)', 'info');
$add('limits', 'opcache.validate_timestamps', (string)ini_get('opcache.validate_timestamps'), 'info', 'اگر 0 باشد، پس از به‌روزرسانی opcache_reset لازم است');

// HTTPS
$add('https', 'HTTPS', is_https() ? 'yes' : 'no', is_https() ? 'ok' : 'fail', 'الزامی');

// Filesystem layout
$parent = dirname($docroot);
$add('fs', 'document root', $docroot, 'info');
$add('fs', 'parent of docroot (proposed app dir)', $parent, 'info');
$parentWritable = is_writable($parent);
$add('fs', 'parent writable (code/storage outside web root)', $parentWritable ? 'yes' : 'no', $parentWritable ? 'ok' : 'fail', 'در غیر این صورت حالت C؛ نصب متوقف می‌شود مگر تست deny موفق باشد');
if ($parentWritable) {
    $canaryFile = $parent . DIRECTORY_SEPARATOR . '.pm-probe-' . bin2hex(random_bytes(6));
    $w = @file_put_contents($canaryFile, 'x') !== false;
    @unlink($canaryFile);
    $add('fs', 'write+delete outside docroot', $w ? 'yes' : 'no', $w ? 'ok' : 'fail');
}
$free = @disk_free_space($parent);
$total = @disk_total_space($parent);
$add('fs', 'partition free space', $free !== false ? round($free / 1073741824, 2) . ' GB' : 'unknown', 'warn', 'این فضای پارتیشن است، نه سهمیه حساب cPanel شما');
$add('fs', 'partition total space', $total !== false ? round($total / 1073741824, 2) . ' GB' : 'unknown', 'info');
$add('fs', 'account quota', 'unknown via PHP', 'warn', 'از cPanel ← Disk Usage / Resource Usage بخوانید یا API Token برای UAPI Quota بدهید');

// Process / binaries
foreach (['exec', 'shell_exec', 'proc_open', 'popen', 'pcntl_fork'] as $fn) {
    $add('proc', $fn, fn_available($fn) ? 'available' : 'disabled', 'info');
}
$ff = run_cmd('command -v ffmpeg');
$add('proc', 'ffmpeg', $ff ?: ($ff === null ? 'cannot check (exec disabled)' : 'not found'), 'info',
    'برای محتوای E2EE استفاده نمی‌شود (سرور فقط ciphertext دارد)');
$cliPhp = run_cmd('command -v php');
$add('proc', 'php CLI in PATH', $cliPhp ?: 'unknown', 'info');
$add('proc', 'stream_socket_server', fn_available('stream_socket_server') ? 'available' : 'disabled', 'info',
    'وجود تابع به معنای امکان اجرای سرور WebSocket دائمی نیست؛ هاست اشتراکی معمولاً پروسه دائمی را می‌کشد');
$add('proc', 'cron', 'not detectable from web', 'warn', 'در cPanel ← Cron Jobs بررسی کنید که حداقل فاصله مجاز چند دقیقه است');

// Crypto sanity (server side only uses it for tokens / signature checks)
if (function_exists('sodium_crypto_sign_keypair')) {
    $kp = sodium_crypto_sign_keypair();
    $sig = sodium_crypto_sign_detached('t', sodium_crypto_sign_secretkey($kp));
    $okSig = sodium_crypto_sign_verify_detached($sig, 't', sodium_crypto_sign_publickey($kp));
    $add('crypto', 'sodium Ed25519 verify', $okSig ? 'ok' : 'failed', $okSig ? 'ok' : 'fail');
}
$add('crypto', 'random_bytes', strlen(random_bytes(32)) === 32 ? 'ok' : 'failed', 'ok');
$add('crypto', 'argon2id password_hash', defined('PASSWORD_ARGON2ID') ? 'available' : 'missing', defined('PASSWORD_ARGON2ID') ? 'ok' : 'warn', 'در نبود، bcrypt');

$report = [
    'probe_version' => PROBE_VERSION,
    'generated_at_utc' => gmdate('c'),
    'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
    'checks' => $checks,
];

if (($_GET['format'] ?? '') === 'json') {
    json_out($report);
}

$tokenQ = htmlspecialchars(rawurlencode($token), ENT_QUOTES);
$self = htmlspecialchars(basename(__FILE__), ENT_QUOTES);
$reportJson = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Host Probe — Private Messenger</title>
<style nonce="<?= $nonce ?>">
 body{font-family:Tahoma,system-ui,sans-serif;margin:0;padding:16px;background:#f6f7f9;color:#1d2330}
 h1{font-size:20px} h2{font-size:16px;margin-top:28px}
 table{width:100%;border-collapse:collapse;background:#fff;font-size:13px}
 td,th{border-bottom:1px solid #e5e7eb;padding:6px 8px;text-align:right;vertical-align:top}
 td.v{direction:ltr;text-align:left;font-family:monospace;word-break:break-all}
 .ok{color:#0a7a3e}.warn{color:#a45c00}.fail{color:#b42318;font-weight:bold}.info{color:#475467}
 button{font:inherit;padding:8px 14px;margin:4px 0 4px 8px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;cursor:pointer}
 button.danger{border-color:#b42318;color:#b42318}
 pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;direction:ltr;text-align:left;font-size:12px;max-height:420px}
 .box{background:#fff;padding:12px;border-radius:8px;margin:8px 0}
 input{font:inherit;padding:6px;margin:2px;direction:ltr}
</style>
</head>
<body>
<h1>بررسی قابلیت‌های هاست — Private Messenger</h1>
<p class="warn">پس از ذخیره گزارش، این فایل را حذف کنید. این صفحه اطلاعات پیکربندی سرور را نمایش می‌دهد.</p>

<h2>۱. بررسی‌های ایستا</h2>
<table id="static"><thead><tr><th>گروه</th><th>مورد</th><th>مقدار</th><th>وضعیت</th><th>توضیح</th></tr></thead><tbody></tbody></table>

<h2>۲. تست‌های واقعی HTTP (از مرورگر شما)</h2>
<div class="box">
 <button id="run-http">اجرای تست‌ها</button>
 <span id="http-status"></span>
 <p class="info">شامل: حداکثر بدنه واقعی قابل ارسال (باینری و JSON — تشخیص ModSecurity/LimitRequestBody)، نوع MIME فایل wasm، مسدودسازی پوشه با .htaccess، و ماندگاری درخواست ۲۵ ثانیه‌ای (برای long-poll).</p>
</div>

<h2>۳. تست دیتابیس (اختیاری)</h2>
<div class="box">
 <input id="db-host" value="localhost" placeholder="host"> <input id="db-port" value="3306" size="5">
 <input id="db-name" placeholder="database"> <input id="db-user" placeholder="user"> <input id="db-pass" type="password" placeholder="password">
 <button id="run-db">تست اتصال</button>
 <p class="info">اطلاعات فقط برای همین تست استفاده و ذخیره نمی‌شود.</p>
</div>

<h2>۴. تست اتصال خروجی (اختیاری)</h2>
<div class="box">
 <input id="out-host" placeholder="مثلاً api.kavenegar.com"> <button id="run-out">تست</button>
</div>

<h2>گزارش JSON</h2>
<pre id="out"></pre>
<button id="copy">کپی گزارش</button>
<button id="del" class="danger">حذف فایل probe از سرور</button>

<script nonce="<?= $nonce ?>">
(() => {
  const report = <?= $reportJson ?>;
  report.http_tests = {}; report.db_test = null; report.outbound = {};
  const base = '<?= $self ?>?token=<?= $tokenQ ?><?= isset($_GET['allow_http']) ? '&allow_http=1' : '' ?>';
  const $ = (id) => document.getElementById(id);
  const render = () => { $('out').textContent = JSON.stringify(report, null, 2); };
  const tbody = document.querySelector('#static tbody');
  for (const c of report.checks) {
    const tr = document.createElement('tr');
    for (const [k, cls] of [['group',''],['name',''],['value','v'],['status',''],['note','']]) {
      const td = document.createElement('td');
      td.textContent = String(c[k]);
      if (cls) td.className = cls;
      if (k === 'status') td.className = c.status;
      tr.appendChild(td);
    }
    tbody.appendChild(tr);
  }
  render();

  const api = async (action, opts = {}, extra = '') => {
    const r = await fetch(`${base}&action=${action}${extra}`, opts);
    let body = null; try { body = await r.json(); } catch (_) {}
    return { status: r.status, body };
  };

  async function bodyLimit(kind) {
    const sizes = [1, 2, 4, 8, 16, 32, 64].map(m => m * 1048576);
    let maxOk = 0, firstFail = null;
    for (const n of sizes) {
      let payload, type;
      if (kind === 'binary') {
        payload = new Uint8Array(n); crypto.getRandomValues(payload.subarray(0, Math.min(n, 65536)));
        type = 'application/octet-stream';
      } else {
        const raw = Math.floor(n * 0.74);
        payload = JSON.stringify({ ciphertext: 'A'.repeat(raw) }); type = 'application/json';
      }
      try {
        const r = await api('echo_size', { method: 'POST', headers: { 'Content-Type': type }, body: payload });
        const len = typeof payload === 'string' ? new TextEncoder().encode(payload).length : payload.length;
        if (r.status === 200 && r.body && r.body.received_bytes === len) { maxOk = n; }
        else { firstFail = { bytes: n, http: r.status, received: r.body && r.body.received_bytes }; break; }
      } catch (e) { firstFail = { bytes: n, error: String(e) }; break; }
    }
    return { max_ok_bytes: maxOk, first_fail: firstFail };
  }

  $('run-http').addEventListener('click', async () => {
    $('run-http').disabled = true;
    const st = (t) => { $('http-status').textContent = t; };
    try {
      st('بدنه باینری...'); report.http_tests.binary_body = await bodyLimit('binary'); render();
      st('بدنه JSON...');  report.http_tests.json_body = await bodyLimit('json'); render();
      st('wasm و htaccess...');
      const s = await api('setup_http_tests');
      if (s.body && s.body.ok) {
        const w = await fetch(`${s.body.base}/t.wasm`, { cache: 'no-store' });
        report.http_tests.wasm_mime = { http: w.status, content_type: w.headers.get('content-type') };
        const d = await fetch(`${s.body.base}/denied/canary.txt`, { cache: 'no-store' });
        const txt = d.ok ? await d.text() : '';
        report.http_tests.htaccess_deny = { http: d.status, leaked: txt.includes(s.body.canary) };
        await api('cleanup');
      } else { report.http_tests.setup_error = s.body; }
      render();
      st('درخواست ۲۵ ثانیه‌ای...');
      const t0 = performance.now();
      try {
        const r = await api('sleep', {}, '&s=25');
        report.http_tests.long_request = { http: r.status, body: r.body, client_ms: Math.round(performance.now() - t0) };
      } catch (e) { report.http_tests.long_request = { error: String(e), client_ms: Math.round(performance.now() - t0) }; }
      st('پایان');
    } catch (e) { st('خطا: ' + e); }
    render(); $('run-http').disabled = false;
  });

  $('run-db').addEventListener('click', async () => {
    const r = await api('db', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ host: $('db-host').value, port: +$('db-port').value, name: $('db-name').value,
                             user: $('db-user').value, pass: $('db-pass').value }) });
    report.db_test = r.body; $('db-pass').value = ''; render();
  });

  $('run-out').addEventListener('click', async () => {
    const h = $('out-host').value.trim();
    const r = await api('outbound', {}, '&host=' + encodeURIComponent(h));
    report.outbound[h] = r.body; render();
  });

  $('copy').addEventListener('click', () => navigator.clipboard.writeText($('out').textContent));
  $('del').addEventListener('click', async () => {
    if (!confirm('فایل probe از سرور حذف شود؟ (ابتدا گزارش را ذخیره کنید)')) return;
    const r = await api('selfdelete', { method: 'POST' });
    alert(r.body ? r.body.message : 'HTTP ' + r.status);
  });
})();
</script>
</body>
</html>
