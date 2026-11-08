<?php
declare(strict_types=1);

namespace PM\Support;

/** ULID: 48-bit millisecond time + 80 random bits, Crockford base32 (26 chars). */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $ms = null): string
    {
        $ms ??= (int)floor(microtime(true) * 1000);
        $time = '';
        for ($i = 9; $i >= 0; $i--) {
            $time = self::ALPHABET[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }
        $rand = '';
        $bytes = random_bytes(10);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $rand .= self::ALPHABET[bindec($chunk)];
        }
        return $time . $rand;
    }

    public static function isValid(string $s): bool
    {
        return (bool)preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $s);
    }
}
