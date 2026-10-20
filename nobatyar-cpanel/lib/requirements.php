<?php
declare(strict_types=1);

/** Host checks shown by the installer and the upgrader (cPanel without SSH). */
function system_requirements(): array
{
    $root = dirname(__DIR__);
    $checks = [
        ['PHP نسخه ۸.۱ یا بالاتر', PHP_VERSION_ID >= 80100, 'نسخه فعلی: ' . PHP_VERSION, true],
    ];
    foreach (
        [
            'pdo_mysql' => 'اتصال به MySQL',
            'mbstring' => 'پردازش متن فارسی',
            'intl' => 'تقویم شمسی',
            'curl' => 'اتصال به کاوه‌نگار و درگاه‌ها',
            'gd' => 'پردازش تصاویر',
            'openssl' => 'رمزنگاری و امضای اعلان‌ها',
            'json' => 'API',
        ]
        as $ext => $purpose
    ) {
        $checks[] = ['افزونه ' . $ext, extension_loaded($ext), $purpose, true];
    }
    $checks[] = [
        'افزونه sodium (اختیاری)',
        extension_loaded('sodium'),
        extension_loaded('sodium') ? 'رمزنگاری با libsodium' : 'در نبود آن از OpenSSL استفاده می‌شود',
        false,
    ];
    $checks[] = ['افزونه zip (اختیاری)', class_exists('ZipArchive'), 'خروجی اکسل؛ در نبود آن CSV ساخته می‌شود', false];
    $checks[] = ['افزونه zlib (اختیاری)', function_exists('gzopen'), 'پشتیبان فشرده دیتابیس از پنل مدیر', false];
    $checks[] = ['پوشه private قابل نوشتن', is_writable($root . '/private'), 'کلید رمزنگاری، تصاویر و پشتیبان‌ها', true];
    $checks[] = [
        'HTTPS',
        !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'برای ورود امن، درگاه پرداخت و اعلان PWA ضروری است',
        false,
    ];
    return $checks;
}

function requirements_met(): bool
{
    foreach (system_requirements() as [, $ok, , $required]) {
        if ($required && !$ok) {
            return false;
        }
    }
    return true;
}

function render_requirements(): void
{
    echo '<ul class="checklist">';
    foreach (system_requirements() as [$label, $ok, $detail, $required]) {
        echo '<li class="' .
            ($ok ? 'ok' : ($required ? 'fail' : 'warn')) .
            '">' .
            icon($ok ? 'check' : ($required ? 'x' : 'alert')) .
            '<span><strong>' .
            esc($label) .
            '</strong><small>' .
            esc($detail) .
            '</small></span></li>';
    }
    echo '</ul>';
}
