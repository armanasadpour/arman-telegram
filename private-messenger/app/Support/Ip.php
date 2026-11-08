<?php
declare(strict_types=1);

namespace PM\Support;

final class Ip
{
    public static function toBinary(string $ip): string
    {
        $b = @inet_pton($ip);
        return $b === false ? "\0\0\0\0" : $b;
    }

    public static function fromBinary(?string $bin): string
    {
        if ($bin === null || $bin === '') {
            return '';
        }
        $s = @inet_ntop($bin);
        return $s === false ? '' : $s;
    }

    /** Truncated prefix for display (/24 for IPv4, /48 for IPv6). */
    public static function prefix(string $ip): string
    {
        $b = @inet_pton($ip);
        if ($b === false) {
            return "\0\0\0\0";
        }
        if (strlen($b) === 4) {
            return substr($b, 0, 3) . "\0";
        }
        return substr($b, 0, 6) . str_repeat("\0", 10);
    }

    public static function displayPrefix(?string $bin): string
    {
        $ip = self::fromBinary($bin);
        if ($ip === '') {
            return '—';
        }
        if (str_contains($ip, '.')) {
            $p = explode('.', $ip);
            return $p[0] . '.' . $p[1] . '.' . $p[2] . '.x';
        }
        return $ip . '/48';
    }
}
