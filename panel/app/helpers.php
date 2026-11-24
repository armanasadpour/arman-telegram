<?php

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
