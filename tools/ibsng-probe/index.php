<?php
/**
 * IBSng connection probe — web + CLI entry point.
 *
 * Web:  https://your-domain/ibsng-probe/?token=ACCESS_TOKEN
 * CLI:  php index.php [--config=/path/config.php] [--write] [--json]
 */

declare(strict_types=1);

require __DIR__ . '/src/XmlRpcClient.php';
require __DIR__ . '/src/MikrotikRestClient.php';
require __DIR__ . '/src/Probe.php';

use IbsProbe\Probe;

$isCli = PHP_SAPI === 'cli';

// ---------------------------------------------------------------- load config
$configPath = __DIR__ . '/config.php';
if ($isCli) {
    foreach ($argv as $a) {
        if (str_starts_with($a, '--config=')) {
            $configPath = substr($a, 9);
        }
    }
}
if (!is_file($configPath)) {
    respondFatal($isCli, 'فایل config.php یافت نشد. config.sample.php را کپی و تکمیل کنید.');
}
$cfg = require $configPath;
if (!is_array($cfg) || empty($cfg['host']) || empty($cfg['admin_username']) || !isset($cfg['admin_password'])) {
    respondFatal($isCli, 'config.php ناقص است (host / admin_username / admin_password).');
}

// ---------------------------------------------------------------- CLI
if ($isCli) {
    $probe = new Probe($cfg);
    $probe->runAll(in_array('--write', $argv, true));
    $results = $probe->results();
    if (in_array('--json', $argv, true)) {
        echo json_encode(buildReport($cfg, $results), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    } else {
        foreach ($results as $r) {
            printf("[%-4s] %-28s %s\n", strtoupper($r['status']), $r['id'], $r['detail']);
        }
    }
    exit(hasFailure($results) ? 1 : 0);
}

// ---------------------------------------------------------------- Web: hardening
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");
header('Cache-Control: no-store');

$token = (string) ($cfg['access_token'] ?? '');
if (strlen($token) < 24 || str_starts_with($token, 'CHANGE_ME')) {
    respondFatal(false, 'access_token در config.php تنظیم نشده یا کوتاه‌تر از ۲۴ کاراکتر است.');
}
if (str_starts_with((string) $cfg['admin_password'], 'CHANGE_ME')) {
    respondFatal(false, 'رمز مدیر IBSng در config.php تنظیم نشده است.');
}
$given = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
if (!hash_equals($token, $given)) {
    if ($given !== '') {
        http_response_code(403);
    }
    $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>ورود به ابزار تست</title></head>'
        . '<body style="font-family:IRANSans,Tahoma,sans-serif;background:#f6f7f9;margin:0;padding:16px">'
        . '<form method="post" style="max-width:420px;margin:40px auto;background:#fff;border:1px solid #e4e7ec;border-radius:12px;padding:16px">'
        . '<h1 style="font-size:1.1rem;margin-top:0">ابزار تست اتصال IBSng</h1>'
        . ($given !== '' ? '<p style="color:#c4320a">توکن اشتباه است. دقیقاً همان مقدار access_token داخل config.php را وارد کنید.</p>' : '')
        . '<label>توکن دسترسی (access_token):<br><input name="token" type="password" autocomplete="off" required '
        . 'style="width:100%;box-sizing:border-box;padding:8px;margin:8px 0;border:1px solid #ccc;border-radius:8px;direction:ltr"></label>'
        . '<button style="background:#2f5bea;color:#fff;border:0;border-radius:8px;padding:10px 18px;font:inherit">ورود</button>'
        . '</form></body></html>';
    unset($h);
    exit;
}

$ran = false;
$results = [];
$wantWrite = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $writeTicked = ($_POST['write'] ?? '') === '1';
    $wantWrite = $writeTicked && isConfirmWord((string) ($_POST['confirm'] ?? ''));
    @set_time_limit(120);
    $probe = new Probe($cfg);
    if ($writeTicked && !$wantWrite) {
        $probe->note('write.not_confirmed', 'تست نوشتن', 'skip', 'تیک زده شد اما کلمه تأیید درست وارد نشد؛ تست نوشتن اجرا نشد.');
    } elseif (!$writeTicked && !empty($cfg['allow_write_test'])) {
        $probe->note('write.not_selected', 'تست نوشتن', 'skip', 'تیک «اجرای تست نوشتن» زده نشده بود.');
    }
    $probe->runAll($wantWrite);
    $results = $probe->results();
    $ran = true;
}

if (($_POST['format'] ?? '') === 'json' && $ran) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="ibsng-probe-report.json"');
    echo json_encode(buildReport($cfg, $results), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$labels = ['pass' => 'موفق', 'fail' => 'ناموفق', 'warn' => 'هشدار', 'skip' => 'رد شد', 'info' => 'اطلاعات'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>اثبات اتصال IBSng</title>
<style>
:root{--bg:#f6f7f9;--card:#fff;--text:#1d2330;--muted:#667085;--border:#e4e7ec;--pass:#12805c;--fail:#c4320a;--warn:#b54708;--info:#1d4ed8;--skip:#667085;--accent:#2f5bea}
@media (prefers-color-scheme:dark){:root{--bg:#0f1218;--card:#171b23;--text:#e6e8ec;--muted:#98a2b3;--border:#2a303c;--pass:#3ccf91;--fail:#ff7a59;--warn:#fdb022;--info:#7aa2ff;--skip:#98a2b3;--accent:#7aa2ff}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:IRANSans,Vazirmatn,Tahoma,sans-serif;line-height:1.8}
main{max-width:960px;margin:0 auto;padding:16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px}
h1{font-size:1.3rem;margin:0 0 4px}p.muted{color:var(--muted);margin:0}
form .row{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:12px}
button{background:var(--accent);color:#fff;border:0;border-radius:8px;padding:10px 18px;font:inherit;cursor:pointer}
button.secondary{background:transparent;color:var(--accent);border:1px solid var(--accent)}
input[type=text]{border:1px solid var(--border);border-radius:8px;padding:8px;font:inherit;background:var(--bg);color:var(--text)}
table{width:100%;border-collapse:collapse;font-size:.92rem}
th,td{border-bottom:1px solid var(--border);padding:8px;text-align:right;vertical-align:top}
.st{font-weight:700;white-space:nowrap}.st-pass{color:var(--pass)}.st-fail{color:var(--fail)}.st-warn{color:var(--warn)}.st-info{color:var(--info)}.st-skip{color:var(--skip)}
details pre{direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-word;font-size:.8rem;background:var(--bg);padding:8px;border-radius:8px;max-height:320px;overflow:auto}
.warnbox{border-inline-start:4px solid var(--warn);padding:8px 12px;background:var(--bg);border-radius:6px}
.table-wrap{overflow-x:auto}
td{overflow-wrap:anywhere}
@media (max-width:640px){thead{display:none}table,tbody,tr,td{display:block;width:100%}tr{border-bottom:1px solid var(--border);padding:8px 0}td{border:0;padding:2px 0}}
</style>
</head>
<body>
<main>
  <div class="card">
    <h1>اثبات اتصال به IBSng A1.24 (فاز ۱)</h1>
    <p class="muted">سرور هدف: <bdi><?= $e($cfg['host']) ?>:<?= $e($cfg['port'] ?? 1235) ?></bdi> — مدیر: <bdi><?= $e($cfg['admin_username']) ?></bdi></p>
    <form method="post">
      <input type="hidden" name="token" value="<?= $e($given) ?>">
      <?php if (!empty($cfg['allow_write_test'])): ?>
      <div class="warnbox" style="margin-top:12px">
        <label><input type="checkbox" name="write" value="1"> اجرای تست نوشتن کنترل‌شده (ساخت یک کاربر <b>قفل‌شده</b> موقت در گروه «<?= $e($cfg['write_test_group'] ?? '') ?>» و حذف فوری آن)</label><br>
        برای تأیید کلمه <b>تایید</b> را بنویسید: <input type="text" name="confirm" autocomplete="off" size="8">
      </div>
      <?php endif; ?>
      <div class="row">
        <button type="submit" name="format" value="html">اجرای تست‌ها</button>
        <button type="submit" name="format" value="json" class="secondary">اجرا و دریافت گزارش JSON</button>
      </div>
    </form>
  </div>

  <?php if ($ran): ?>
  <div class="card">
    <h2 style="font-size:1.1rem;margin-top:0">نتیجه — <?= $e(date('Y-m-d H:i:s')) ?><?= $wantWrite ? ' (با تست نوشتن)' : ' (فقط خواندنی)' ?></h2>
    <div class="table-wrap">
    <table>
      <thead><tr><th>وضعیت</th><th>بررسی</th><th>جزئیات</th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td class="st st-<?= $e($r['status']) ?>"><?= $e($labels[$r['status']] ?? $r['status']) ?></td>
          <td><?= $e($r['title']) ?><br><small class="muted"><bdi><?= $e($r['id']) ?></bdi></small></td>
          <td><?= $e($r['detail']) ?>
            <?php if (isset($r['data']) || isset($r['meta'])): ?>
              <details><summary>داده خام</summary><pre><?= $e(json_encode(['meta' => $r['meta'] ?? null, 'data' => $r['data'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>
  <p class="muted">پس از پایان تست، پوشه ibsng-probe را از هاست حذف کنید.</p>
</main>
</body>
</html>
<?php

// ---------------------------------------------------------------- helpers

/**
 * @param array<string,mixed> $cfg
 * @param list<array<string,mixed>> $results
 * @return array<string,mixed>
 */
function buildReport(array $cfg, array $results): array
{
    return [
        'tool' => 'ibsng-probe/1.0',
        'generated_at' => date(DATE_ATOM),
        'target' => ['host' => $cfg['host'], 'port' => (int) ($cfg['port'] ?? 1235), 'admin_username' => $cfg['admin_username']],
        'php' => PHP_VERSION,
        'summary' => array_count_values(array_column($results, 'status')),
        'results' => $results,
    ];
}

/** Accept تایید / تأیید / Arabic yeh, with stray spaces or ZWNJ. */
function isConfirmWord(string $s): bool
{
    $s = str_replace(["\u{200C}", "\u{200F}", "\u{200E}", ' ', 'ي', 'ى', 'أ', 'ئ'], ['', '', '', '', 'ی', 'ی', 'ا', 'ی'], trim($s));
    return in_array($s, ['تایید', 'تاييد', 'confirm', 'CONFIRM'], true);
}

/** @param list<array<string,mixed>> $results */
function hasFailure(array $results): bool
{
    foreach ($results as $r) {
        if ($r['status'] === 'fail') {
            return true;
        }
    }
    return false;
}

function respondFatal(bool $isCli, string $msg): never
{
    if ($isCli) {
        fwrite(STDERR, $msg . "\n");
        exit(2);
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>IBSng probe</title><body style="font-family:Tahoma,sans-serif;padding:16px">'
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</body></html>';
    exit;
}
