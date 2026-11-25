<?php

namespace App\Services\Mikrotik;

/** Parsing of RouterOS text values. */
final class RosValues
{
    /** "1w2d3h4m5s", "3h", "00:01:02", "1d02:03:04", "5s500ms" → seconds; null when empty/unknown. */
    public static function seconds(?string $v): ?int
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(?:(\d+)d)?(\d+):(\d{2}):(\d{2})$/', $v, $m)) {
            return (int) $m[1] * 86400 + (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) $m[4];
        }
        if (! preg_match_all('/(\d+)(ms|w|d|h|m|s)/', $v, $all, PREG_SET_ORDER)) {
            return null;
        }
        $mult = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1, 'ms' => 0];
        $t = 0;
        foreach ($all as $p) {
            $t += (int) $p[1] * $mult[$p[2]];
        }

        return $t;
    }

    public static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    public static function bool(mixed $v): bool
    {
        return in_array($v, ['true', 'yes', true], true);
    }
}
