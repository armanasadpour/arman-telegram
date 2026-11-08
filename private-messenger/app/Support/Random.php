<?php
declare(strict_types=1);

namespace PM\Support;

final class Random
{
    private const BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    // No 0/O/1/l/I to avoid confusion when a password is read aloud or copied by hand.
    private const READABLE = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';

    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function string(int $length, string $alphabet = self::BASE62): string
    {
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    public static function readablePassword(int $length = 16): string
    {
        return self::string($length, self::READABLE);
    }

    public static function digits(int $length): string
    {
        return self::string($length, '0123456789');
    }
}
