<?php

use App\Services\Settings;
use App\Support\Jalali;

if (! function_exists('jdate')) {
    /** Jalali date in display timezone with Persian digits. */
    function jdate(?DateTimeInterface $date, string $format = 'Y/m/d H:i'): string
    {
        return Jalali::format($date, $format);
    }
}

if (! function_exists('fa')) {
    /** Persian digits; numbers get thousands separators. */
    function fa(string|int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return is_int($value) || is_float($value) ? Jalali::number($value) : Jalali::digits($value);
    }
}

if (! function_exists('fa_ago')) {
    function fa_ago(?DateTimeInterface $date): string
    {
        return Jalali::ago($date);
    }
}

if (! function_exists('fa_bytes')) {
    /** 1536 -> "۱٫۵ کیلوبایت" (binary units, Persian digits). */
    function fa_bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return Jalali::digits(rtrim(rtrim(number_format($v, $i >= 3 ? 2 : 1, '٫', ''), '0'), '٫')).' '.$units[$i];
    }
}

if (! function_exists('fa_duration')) {
    /** Seconds -> "۳۰ روز" / "۵ ساعت". */
    function fa_duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        if ($seconds % 86400 === 0 || $seconds >= 86400 * 3) {
            return Jalali::digits((string) round($seconds / 86400, 1)).' روز';
        }

        return Jalali::digits((string) round($seconds / 3600, 1)).' ساعت';
    }
}

if (! function_exists('fa_money')) {
    /** Rial amount shown in the configured display currency (default تومان). */
    function fa_money(?int $rial): string
    {
        if ($rial === null) {
            return '—';
        }
        $unit = app(Settings::class)->get('pricing.display_currency', 'toman');

        return $unit === 'rial' ? Jalali::number($rial).' ریال' : Jalali::number(intdiv($rial, 10)).' تومان';
    }
}
