<?php

namespace App\Services\Mikrotik;

use RuntimeException;

/** WireGuard keys: Curve25519 (X25519) via ext-sodium, base64 like `wg genkey`/`wg pubkey`. */
final class WgKeys
{
    /** @return array{private:string, public:string} */
    public static function pair(): array
    {
        self::need();
        $sk = random_bytes(32);
        $sk[0] = chr(ord($sk[0]) & 248);
        $sk[31] = chr((ord($sk[31]) & 127) | 64);

        return ['private' => base64_encode($sk), 'public' => self::publicFrom(base64_encode($sk))];
    }

    public static function publicFrom(string $privateB64): string
    {
        self::need();
        $sk = base64_decode($privateB64, true);
        if ($sk === false || strlen($sk) !== 32) {
            throw new RuntimeException('کلید خصوصی WireGuard نامعتبر است.');
        }

        return base64_encode(sodium_crypto_scalarmult_base($sk));
    }

    public static function preshared(): string
    {
        return base64_encode(random_bytes(32));
    }

    public static function valid(string $b64): bool
    {
        $raw = base64_decode($b64, true);

        return $raw !== false && strlen($raw) === 32;
    }

    private static function need(): void
    {
        if (! function_exists('sodium_crypto_scalarmult_base')) {
            throw new RuntimeException('افزونه sodium در PHP هاست فعال نیست (برای ساخت کلید WireGuard لازم است). از cPanel → Select PHP Version آن را فعال کنید.');
        }
    }
}
