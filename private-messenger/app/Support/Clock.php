<?php
declare(strict_types=1);

namespace PM\Support;

final class Clock
{
    private static ?\DateTimeImmutable $frozen = null;

    public static function now(): \DateTimeImmutable
    {
        return self::$frozen ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Format for DATETIME(3) columns. */
    public static function db(?\DateTimeImmutable $t = null): string
    {
        return ($t ?? self::now())->format('Y-m-d H:i:s.v');
    }

    public static function dbIn(string $modify): string
    {
        return self::db(self::now()->modify($modify));
    }

    public static function freeze(?\DateTimeImmutable $t): void
    {
        self::$frozen = $t;
    }
}
