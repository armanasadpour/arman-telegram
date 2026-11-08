<?php
declare(strict_types=1);

namespace PM\Support;

/**
 * Server-side secrets only (session tokens, OTP hashing, encrypting the SMS API key at rest).
 * This class is never used for message content: message content is E2EE on user devices.
 */
final class Crypto
{
    public static function sha256(string $data): string
    {
        return hash('sha256', $data, true);
    }

    public static function hmac(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }

    public static function seal(string $key, string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key);
    }

    public static function open(string $key, string $sealed): ?string
    {
        if (strlen($sealed) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $nonce = substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $out = sodium_crypto_secretbox_open(substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        return $out === false ? null : $out;
    }
}
