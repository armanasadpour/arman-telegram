<?php
declare(strict_types=1);

namespace PM\Support;

final class Fa
{
    public static function toLatinDigits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    public static function digits(string|int $s): string
    {
        return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /** Gregorian UTC DATETIME string -> Persian (Jalali) date/time in Asia/Tehran, Persian digits. */
    public static function dateTime(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        if (class_exists(\IntlDateFormatter::class)) {
            $f = new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT,
                'Asia/Tehran', \IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd HH:mm');
            $s = $f->format($dt);
            if (is_string($s)) {
                return $s;
            }
        }
        return self::digits($dt->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i'));
    }
}
