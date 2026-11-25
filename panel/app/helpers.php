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

if (! function_exists('jdate_local')) {
    /** A "Y-m-d H:i" string already in Iran local time (e.g. from the IBSng server) shown as Jalali. */
    function jdate_local(?string $value, string $format = 'Y/m/d H:i'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            $tz = config('app.display_timezone', 'Asia/Tehran');

            return Jalali::format(new DateTimeImmutable($value, new DateTimeZone($tz)), $format, $tz);
        } catch (Throwable) {
            return Jalali::digits($value);
        }
    }
}

if (! function_exists('fa_value')) {
    /** Any stored value for display: ISO / SQL date strings become Jalali, arrays JSON. */
    function fa_value(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($v)) {
            return $v ? 'بله' : 'خیر';
        }
        $s = (string) $v;
        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $s)) {
            try {
                return jdate(new DateTimeImmutable($s, new DateTimeZone('UTC')), strlen($s) === 10 ? 'Y/m/d' : 'Y/m/d H:i');
            } catch (Throwable) {
            }
        }

        return $s;
    }
}
