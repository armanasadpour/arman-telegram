<?php

namespace Tests\Unit;

use App\Support\Jalali;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JalaliTest extends TestCase
{
    public static function knownDates(): array
    {
        return [
            'nowruz 1404' => [[2025, 3, 21], [1404, 1, 1]],
            'nowruz 1403 (leap year before)' => [[2024, 3, 20], [1403, 1, 1]],
            'last day of leap 1403' => [[2025, 3, 20], [1403, 12, 30]],
            'last day of 1404' => [[2026, 3, 20], [1404, 12, 29]],
            'y2k' => [[2000, 1, 1], [1378, 10, 11]],
            'today (project)' => [[2026, 11, 24], [1405, 9, 3]],
        ];
    }

    #[DataProvider('knownDates')]
    public function test_known_conversions(array $g, array $j): void
    {
        $this->assertSame($j, Jalali::fromGregorian(...$g));
        $this->assertSame($g, Jalali::toGregorian(...$j));
    }

    public function test_round_trip_over_60_years(): void
    {
        $d = new DateTimeImmutable('1980-01-01', new DateTimeZone('UTC'));
        for ($i = 0; $i < 22000; $i++) {
            $day = $d->modify("+$i days");
            $g = [(int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j')];
            $this->assertSame($g, Jalali::toGregorian(...Jalali::fromGregorian(...$g)));
        }
    }

    public function test_format_uses_display_timezone_and_persian_digits(): void
    {
        // 2026-11-24 21:00 UTC is already 2026-11-25 00:30 in Tehran -> 1405/09/04.
        $utc = new DateTimeImmutable('2026-11-24 21:00:00', new DateTimeZone('UTC'));
        $this->assertSame('۱۴۰۵/۰۹/۰۴ ۰۰:۳۰', Jalali::format($utc, 'Y/m/d H:i', 'Asia/Tehran'));
        $this->assertSame('۳ آذر ۱۴۰۵', Jalali::format($utc, 'j F Y', 'UTC'));
    }

    public function test_digits_and_numbers(): void
    {
        $this->assertSame('۱۲۳', Jalali::digits('123'));
        $this->assertSame('123', Jalali::toLatinDigits('۱۲۳'));
        $this->assertSame('123', Jalali::toLatinDigits('١٢٣'));
        $this->assertSame('۱٬۲۳۴٬۵۶۷', Jalali::number(1234567));
    }
}
