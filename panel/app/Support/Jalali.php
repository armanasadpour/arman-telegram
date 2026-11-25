<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Gregorian <-> Jalali (Solar Hijri) conversion and Persian formatting.
 * Algorithm: the widely used jdf/Borkowski-based arithmetic, valid for years 1..3177 AP.
 * No dependency on ext-intl (not guaranteed on shared hosts).
 */
final class Jalali
{
    private const FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    /** @return array{0:int,1:int,2:int} [jy, jm, jd] */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /** @return array{0:int,1:int,2:int} [gy, gm, gd] */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0);
        $sal = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 1; $gm <= 12 && $gd > $sal[$gm]; $gm++) {
            $gd -= $sal[$gm];
        }

        return [$gy, $gm, $gd];
    }

    public static function digits(string|int|float $value): string
    {
        return str_replace(range(0, 9), self::FA_DIGITS, (string) $value);
    }

    public static function toLatinDigits(string $value): string
    {
        return str_replace(
            array_merge(self::FA_DIGITS, ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩']),
            array_merge(range(0, 9), range(0, 9)),
            $value
        );
    }

    /**
     * Format a moment in Jalali, in the display timezone, with Persian digits.
     * $format tokens: Y year, m month(2), n month, d day(2), j day, F month name, H, i, s.
     */
    public static function format(?DateTimeInterface $date, string $format = 'Y/m/d H:i', ?string $tz = null): string
    {
        if ($date === null) {
            return '—';
        }
        $local = \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone($tz ?? config('app.display_timezone', 'Asia/Tehran')));
        [$jy, $jm, $jd] = self::fromGregorian((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
        $map = [
            'Y' => (string) $jy,
            'm' => str_pad((string) $jm, 2, '0', STR_PAD_LEFT),
            'n' => (string) $jm,
            'd' => str_pad((string) $jd, 2, '0', STR_PAD_LEFT),
            'j' => (string) $jd,
            'F' => self::MONTHS[$jm - 1],
            'H' => $local->format('H'),
            'i' => $local->format('i'),
            's' => $local->format('s'),
        ];

        return self::digits(strtr($format, $map));
    }

    /** "۳ دقیقه پیش" style relative time. */
    public static function ago(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '—';
        }
        $diff = time() - $date->getTimestamp();
        $future = $diff < 0;
        $diff = abs($diff);
        $units = [[86400 * 365, 'سال'], [86400 * 30, 'ماه'], [86400, 'روز'], [3600, 'ساعت'], [60, 'دقیقه']];
        foreach ($units as [$sec, $label]) {
            if ($diff >= $sec) {
                return self::digits((string) intdiv($diff, $sec)).' '.$label.($future ? ' دیگر' : ' پیش');
            }
        }

        return 'همین الان';
    }

    public static function number(int|float $n, int $decimals = 0): string
    {
        return self::digits(number_format($n, $decimals, '.', '٬'));
    }

    /** "1405/09/10" (Persian or Latin digits) -> start of that day in the display time zone, or null. */
    public static function parseDate(?string $value): ?CarbonImmutable
    {
        if (! preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', trim(self::toLatinDigits((string) $value)), $m)) {
            return null;
        }
        [$gy, $gm, $gd] = self::toGregorian((int) $m[1], (int) $m[2], (int) $m[3]);
        if (! checkdate($gm, $gd, $gy) || (int) $m[2] < 1 || (int) $m[2] > 12 || (int) $m[3] < 1 || (int) $m[3] > 31) {
            return null;
        }

        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, config('app.display_timezone', 'Asia/Tehran'));
    }
}
