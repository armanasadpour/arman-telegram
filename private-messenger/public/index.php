<?php
// The only PHP file inside the web root. Every request is routed here by .htaccess.
// Keep this file compatible with old PHP versions so problems are reported, not shown as a blank page.
$pmCandidates = array(
    dirname(__DIR__),                          // layout A: docroot = <app>/public
    dirname(__DIR__) . '/private-messenger',   // layout B: public_html next to ~/private-messenger
    __DIR__ . '/private-messenger',            // layout C: extracted inside public_html (works, but not recommended)
);
foreach ($pmCandidates as $pmRoot) {
    if (is_file($pmRoot . '/app/bootstrap.php')) {
        require $pmRoot . '/app/preflight.php';
        if (!pm_preflight($pmRoot)) {
            return;
        }
        require $pmRoot . '/app/bootstrap.php';
        $pmKernel = new PM\Http\Kernel(PM\App::boot($pmRoot));
        $pmKernel->handle(PM\Http\Request::fromGlobals())->send();
        $pmKernel->terminate();
        return;
    }
}
header('Content-Type: text/html; charset=utf-8', true, 500);
header('X-Robots-Tag: noindex, nofollow');
echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex"><title>نصب</title>'
    . '<body style="font-family:Tahoma,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;line-height:1.9">'
    . '<h1 style="font-size:20px">پوشه برنامه پیدا نشد</h1>'
    . '<p>فایل index.php در این مسیر قرار دارد:<br><code dir="ltr">' . htmlspecialchars(__DIR__, ENT_QUOTES, 'UTF-8') . '</code></p>'
    . '<p>پوشه برنامه (شامل app، config، storage) باید در یکی از این مسیرها باشد:</p><ul dir="ltr">'
    . '<li><code>' . htmlspecialchars(dirname(__DIR__) . '/private-messenger', ENT_QUOTES, 'UTF-8') . '</code> (recommended)</li>'
    . '<li><code>' . htmlspecialchars(__DIR__ . '/private-messenger', ENT_QUOTES, 'UTF-8') . '</code></li></ul>'
    . '<p>اگر ZIP را در جای دیگری استخراج کرده‌اید، پوشه private-messenger را با File Manager به مسیر اول منتقل کنید. '
    . 'دقت کنید پس از Extract یک پوشه اضافه (مثلاً private-messenger-0.2.1) ساخته نشده باشد.</p></body></html>';
