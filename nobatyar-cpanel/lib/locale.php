<?php
declare(strict_types=1);
function fa_number($value): string
{
    return strtr((string) $value, [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
    ]);
}
function toman($amount): string
{
    return fa_number(number_format((int) $amount, 0, '.', '٬')) . ' تومان';
}
function jalali_formatter(bool $time = false): IntlDateFormatter
{
    if (!extension_loaded('intl')) {
        throw new RuntimeException('افزونه intl را در تنظیمات PHP هاست فعال کنید.');
    }
    $f = new IntlDateFormatter(
        'fa_IR@calendar=persian',
        IntlDateFormatter::NONE,
        IntlDateFormatter::NONE,
        'Asia/Tehran',
        IntlDateFormatter::TRADITIONAL,
        $time ? 'yyyy/MM/dd HH:mm' : 'yyyy/MM/dd',
    );
    $f->setLenient(false);
    return $f;
}
function jdate(?string $value, bool $time = false): string
{
    if (!$value) {
        return '—';
    }
    $d = new DateTimeImmutable($value, new DateTimeZone('Asia/Tehran'));
    return (string) jalali_formatter($time)->format($d);
}
function parse_jdate(string $value, bool $time = false): string
{
    $value = digits(trim($value));
    $pattern = $time
        ? '/^([0-9]{4})\/([0-9]{2})\/([0-9]{2}) ([0-9]{2}):([0-9]{2})$/'
        : '/^([0-9]{4})\/([0-9]{2})\/([0-9]{2})$/';
    if (!preg_match($pattern, $value, $m) || (int) $m[1] < 1300 || (int) $m[1] > 1600) {
        throw new RuntimeException(
            'تاریخ شمسی معتبر مانند ۱۴۰۵/۰۷/۲۱' . ($time ? ' ۱۴:۳۰' : '') . ' وارد کنید.',
        );
    }
    $f = jalali_formatter($time);
    $at = $f->parse($value);
    if ($at === false || digits((string) $f->format($at)) !== $value) {
        throw new RuntimeException('تاریخ یا ساعت شمسی معتبر نیست.');
    }
    return (new DateTimeImmutable('@' . (int) $at))
        ->setTimezone(new DateTimeZone('Asia/Tehran'))
        ->format($time ? 'Y-m-d H:i:s' : 'Y-m-d');
}
function jalali_input(string $label, string $name, ?string $value = null, bool $time = false): void
{
    echo '<label>' .
        esc($label) .
        '<input name="' .
        esc($name) .
        '" value="' .
        esc($value ? jdate($value, $time) : '') .
        '" placeholder="' .
        ($time ? '۱۴۰۵/۰۷/۲۱ ۱۴:۳۰' : '۱۴۰۵/۰۷/۲۱') .
        '" dir="ltr" required maxlength="' .
        ($time ? 16 : 10) .
        '"></label>';
}

/** Parses a Toman amount typed with Persian/Latin digits and thousand separators. */
function parse_toman(string $value): int
{
    $fee = digits(str_replace([',', '٬', ' '], '', trim($value)));
    if (!preg_match('/^[0-9]+$/', $fee) || (int) $fee > 100000000) {
        throw new RuntimeException('مبلغ نامعتبر است.');
    }
    return (int) $fee;
}

/* ---------- Jalali calendar periods ---------- */

/** [year, month, day] of a Gregorian Y-m-d date in the Persian calendar. */
function jalali_parts(string $date): array
{
    return array_map('intval', explode('/', digits(jdate($date))));
}

function jalali_month_start(string $date): string
{
    [$y, $m] = jalali_parts($date);
    return parse_jdate(sprintf('%04d/%02d/01', $y, $m));
}

function jalali_year_start(string $date): string
{
    return parse_jdate(sprintf('%04d/01/01', jalali_parts($date)[0]));
}

function jalali_month_label(string $date): string
{
    static $names = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    [$y, $m] = jalali_parts($date);
    return $names[$m - 1] . ' ' . fa_number($y);
}

/** Last Gregorian day of the Jalali month containing $date. */
function jalali_month_end(string $date): string
{
    [$y, $m] = jalali_parts($date);
    $next = $m === 12 ? sprintf('%04d/01/01', $y + 1) : sprintf('%04d/%02d/01', $y, $m + 1);
    return date('Y-m-d', strtotime(parse_jdate($next) . ' -1 day'));
}
