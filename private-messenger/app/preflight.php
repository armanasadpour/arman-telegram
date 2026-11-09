<?php
// Server pre-flight check. Deliberately written in PHP 5.3-compatible syntax so that it can
// show a readable message even on hosts running an old PHP version (instead of a blank page).
// Before installation it explains the problem in detail; after installation it stays generic.

function pm_preflight_problems($root)
{
    $p = array();
    if (PHP_VERSION_ID < 80200) {
        $p[] = 'نسخه PHP این دامنه ' . PHP_VERSION . ' است؛ حداقل 8.2 لازم است. در cPanel بخش «Select PHP Version» یا «MultiPHP Manager» نسخه 8.2 یا بالاتر را برای همین دامنه انتخاب کنید.';
    }
    $missing = array();
    foreach (array('pdo_mysql', 'openssl', 'sodium', 'mbstring', 'json', 'curl', 'ctype', 'hash') as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    if ($missing) {
        $p[] = 'افزونه‌های PHP زیر فعال نیستند: ' . implode('، ', $missing) . ' — در cPanel بخش «Select PHP Version» ← تب Extensions تیک آن‌ها را بزنید.';
    }
    if (!is_file($root . '/storage/installed.lock')) {
        foreach (array('storage', 'storage/logs', 'config') as $d) {
            if (!is_dir($root . '/' . $d) || !is_writable($root . '/' . $d)) {
                $p[] = 'پوشه ' . $d . ' قابل نوشتن نیست. در File Manager روی پوشه راست‌کلیک ← Change Permissions ← 755.';
            }
        }
    }
    return $p;
}

function pm_preflight_page($title, $items)
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8', true, 500);
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
    }
    $li = '';
    foreach ($items as $i) {
        $li .= '<li>' . htmlspecialchars($i, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>نصب</title></head>'
        . '<body style="font-family:Tahoma,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;line-height:1.9">'
        . '<h1 style="font-size:20px">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><ul>' . $li . '</ul></body></html>';
}

/** Returns true when the request may continue. */
function pm_preflight($root)
{
    $problems = pm_preflight_problems($root);
    if (!$problems) {
        return true;
    }
    if (is_file($root . '/storage/installed.lock')) {
        error_log('[pm] preflight: ' . implode(' | ', $problems));
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8', true, 500);
        }
        echo 'Server configuration error.';
        return false;
    }
    pm_preflight_page('پیش از نصب این موارد باید برطرف شوند', $problems);
    return false;
}
