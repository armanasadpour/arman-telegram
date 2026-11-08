<?php
declare(strict_types=1);

namespace PM\Support;

/** RFC 6238 TOTP (HMAC-SHA1, 30 s, 6 digits) — compatible with common authenticator apps. */
final class Totp
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secretB32, int $step, int $digits = 6): string
    {
        $key = self::base32Decode($secretB32);
        $msg = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $h = hash_hmac('sha1', $msg, $key, true);
        $o = ord($h[19]) & 0x0F;
        $bin = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string)($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Returns the matched time step (for replay protection) or null.
     * Accepts ±1 step of clock drift and rejects steps <= $lastUsedStep.
     */
    public static function verify(string $secretB32, string $code, int $time, ?int $lastUsedStep = null): ?int
    {
        $code = preg_replace('/\D/', '', Fa::toLatinDigits($code)) ?? '';
        if (strlen($code) !== 6) {
            return null;
        }
        $step = intdiv($time, 30);
        foreach ([$step - 1, $step, $step + 1] as $s) {
            if ($lastUsedStep !== null && $s <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::code($secretB32, $s), $code)) {
                return $s;
            }
        }
        return null;
    }

    public static function uri(string $secretB32, string $label, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
            . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
