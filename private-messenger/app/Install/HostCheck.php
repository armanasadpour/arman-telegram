<?php
declare(strict_types=1);

namespace PM\Install;

use PM\Http\Request;

/** Real checks of the running host. Results are shown to the installer and stored for the admin panel. */
final class HostCheck
{
    public const REQUIRED_EXT = ['pdo_mysql', 'openssl', 'sodium', 'mbstring', 'json', 'curl', 'ctype', 'hash'];

    /** @return list<array{name:string, value:string, status:string, note:string}> */
    public static function run(string $root, Request $req): array
    {
        $c = [];
        $add = static function (string $name, string $value, string $status, string $note = '') use (&$c): void {
            $c[] = compact('name', 'value', 'status', 'note');
        };
        $add('نسخه PHP', PHP_VERSION, PHP_VERSION_ID >= 80200 ? 'ok' : 'fail', 'حداقل 8.2');
        foreach (self::REQUIRED_EXT as $ext) {
            $add('افزونه ' . $ext, extension_loaded($ext) ? 'موجود' : 'ناموجود', extension_loaded($ext) ? 'ok' : 'fail', 'الزامی');
        }
        $add('افزونه intl', extension_loaded('intl') ? 'موجود' : 'ناموجود', extension_loaded('intl') ? 'ok' : 'warn', 'نمایش تاریخ شمسی');
        $add('Argon2id', defined('PASSWORD_ARGON2ID') ? 'موجود' : 'ناموجود', defined('PASSWORD_ARGON2ID') ? 'ok' : 'warn', 'در نبود آن bcrypt استفاده می‌شود');
        $add('HTTPS', $req->isHttps() ? 'بله' : 'خیر', $req->isHttps() ? 'ok' : 'fail', 'الزامی');
        foreach (['config', 'storage', 'storage/logs', 'storage/tmp', 'storage/attachments'] as $d) {
            $w = is_dir($root . '/' . $d) && is_writable($root . '/' . $d);
            $add('قابل نوشتن: ' . $d, $w ? 'بله' : 'خیر', $w ? 'ok' : 'fail');
        }
        $docroot = realpath((string)($req->server['DOCUMENT_ROOT'] ?? '')) ?: '';
        $rootReal = realpath($root) ?: $root;
        $inside = $docroot !== '' && str_starts_with($rootReal . '/', rtrim($docroot, '/') . '/');
        $add('محل برنامه نسبت به پوشه وب', $inside ? 'داخل docroot' : 'خارج از docroot', $inside ? 'warn' : 'ok',
            $inside ? 'حالت C: همه درخواست‌ها به index.php هدایت می‌شوند و پوشه‌ها .htaccess مسدودکننده دارند، اما قرار دادن برنامه خارج از public_html توصیه اکید است' : '');
        $add('upload_max_filesize', (string)ini_get('upload_max_filesize'), 'info', 'آپلود چندبخشی (فاز ۴) این سقف را دور می‌زند');
        $add('post_max_size', (string)ini_get('post_max_size'), 'info');
        $add('memory_limit', (string)ini_get('memory_limit'), 'info');
        $add('max_execution_time', (string)ini_get('max_execution_time'), 'info');
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        $canExec = function_exists('exec') && !in_array('exec', $disabled, true);
        $add('اجرای فرمان (exec)', $canExec ? 'مجاز' : 'غیرمجاز', 'info', 'برای عملکرد برنامه لازم نیست');
        $ff = $canExec ? trim((string)@exec('command -v ffmpeg 2>/dev/null')) : '';
        $add('FFmpeg', $ff !== '' ? $ff : 'یافت نشد / قابل بررسی نیست', 'info', 'برای محتوای E2EE استفاده نمی‌شود؛ سرور فقط داده رمزنگاری‌شده دارد');
        $free = @disk_free_space($root);
        $add('فضای آزاد پارتیشن', $free !== false ? round($free / 1073741824, 1) . ' GB' : 'نامعلوم', 'warn', 'این فضای پارتیشن است، نه سهمیه حساب cPanel شما');
        $add('Cron', 'از داخل PHP قابل تشخیص نیست', 'warn', 'پس از نصب، دستور Cron نمایش‌داده‌شده را در cPanel اضافه کنید');
        return $c;
    }

    public static function blocking(array $checks): bool
    {
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') {
                return true;
            }
        }
        return false;
    }
}
