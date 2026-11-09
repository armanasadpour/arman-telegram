<?php
declare(strict_types=1);

namespace PM\Support;

/** Base64 as used by vodozemac (standard alphabet, no padding); decoding also accepts padding. */
final class B64
{
    public static function enc(string $raw): string
    {
        return rtrim(base64_encode($raw), '=');
    }

    /** @return string|null raw bytes, or null when invalid or not exactly $len bytes (when given) */
    public static function dec(mixed $s, ?int $len = null, int $max = 1048576): ?string
    {
        if (!is_string($s) || $s === '' || strlen($s) > $max || !preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $s)) {
            return null;
        }
        $raw = base64_decode($s, true);
        if ($raw === false || ($len !== null && strlen($raw) !== $len)) {
            return null;
        }
        return $raw;
    }
}
