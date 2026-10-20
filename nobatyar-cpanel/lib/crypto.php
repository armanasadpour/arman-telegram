<?php
declare(strict_types=1);

/**
 * Encryption of secrets at rest (SMS API key, gateway credentials).
 * The 32-byte key lives in private/app.key (mode 0600), never in the database or in source,
 * so a database dump alone does not reveal credentials.
 * Format: "s1:" + base64(nonce|ciphertext) with libsodium, or "o1:" + base64(iv|tag|ciphertext) with
 * OpenSSL AES-256-GCM on hosts without sodium. Both formats are always readable.
 */

function app_key_path(): string
{
    return dirname(__DIR__) . '/private/app.key';
}

function app_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $path = app_key_path();
    if (!is_file($path)) {
        ensure_app_key();
    }
    $raw = base64_decode(trim((string) file_get_contents($path)), true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException('کلید رمزنگاری سامانه (private/app.key) معتبر نیست.');
    }
    return $key = $raw;
}

/** Creates private/app.key once. Never overwrites an existing key: that would make stored secrets unreadable. */
function ensure_app_key(): void
{
    $path = app_key_path();
    if (is_file($path)) {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new RuntimeException('پوشه private قابل ساخت نیست.');
    }
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        if (is_file($path)) {
            return; // created concurrently
        }
        throw new RuntimeException('پوشه private برای ساخت کلید رمزنگاری قابل نوشتن نیست.');
    }
    fwrite($handle, base64_encode(random_bytes(32)));
    fclose($handle);
    @chmod($path, 0600);
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $key = app_key();
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('رمزنگاری اطلاعات ممکن نیست.');
    }
    return 'o1:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    $key = app_key();
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false) {
        throw new RuntimeException('اطلاعات رمزشده خراب است.');
    }
    if (str_starts_with($stored, 's1:') && function_exists('sodium_crypto_secretbox_open')) {
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );
    } elseif (str_starts_with($stored, 'o1:')) {
        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
        );
    } else {
        $plain = false;
    }
    if ($plain === false) {
        throw new RuntimeException('رمزگشایی اطلاعات ممکن نیست؛ فایل private/app.key تغییر کرده است.');
    }
    return $plain;
}

/** Secrets are kept in the settings table under "secret." keys, always encrypted. */
function secret_get(string $name): string
{
    $value = setting('secret.' . $name, '');
    return $value === '' ? '' : decrypt_secret($value);
}

function secret_set(string $name, string $plain): void
{
    setting_set('secret.' . $name, encrypt_secret($plain));
}

function secret_exists(string $name): bool
{
    return setting('secret.' . $name, '') !== '';
}

/** Shows only the tail of a secret so an admin can recognise which key is stored. */
function secret_hint(string $name): string
{
    $plain = secret_get($name);
    if ($plain === '') {
        return 'ثبت نشده';
    }
    return 'ثبت‌شده • انتهای کلید: ' . substr($plain, -4);
}
