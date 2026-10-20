<?php
declare(strict_types=1);

/**
 * Web Push in plain PHP (OpenSSL only, no Composer):
 *   - payload encryption: RFC 8291 (aes128gcm content coding, RFC 8188), verified against the RFC test vector;
 *   - authentication: VAPID, RFC 8292 (ES256 JWT signed with the installation key).
 * Only a short JSON with title, body and link is sent; medical details stay inside the panel.
 */

function b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    $out = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    if ($out === false) {
        throw new AuthError('کلید رمزنگاری نامعتبر است.');
    }
    return $out;
}

/** PEM of a P-256 public key from its 65-byte uncompressed point. */
function p256_public_pem(string $point): string
{
    if (strlen($point) !== 65 || $point[0] !== "\x04") {
        throw new AuthError('کلید عمومی P-256 نامعتبر است.');
    }
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** PEM of a P-256 private key from the 32-byte scalar and its public point (SEC 1 / RFC 5915). */
function p256_private_pem(string $d, string $point): string
{
    $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/** [private PEM, 65-byte public point] of a fresh P-256 key. */
function p256_generate(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$key) {
        throw new AuthError('ساخت کلید رمزنگاری روی این هاست ممکن نیست.', 503);
    }
    $ec = openssl_pkey_get_details($key)['ec'];
    $point = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    openssl_pkey_export($key, $pem);
    return [$pem, $point];
}

/**
 * RFC 8291 encryption. $asPrivatePem/$asPublic/$salt are injectable only for the RFC test vector;
 * production calls generate a fresh ephemeral key and salt for every message.
 */
function webpush_encrypt(string $payload, string $uaPublic, string $authSecret, ?string $asPrivatePem = null, ?string $asPublic = null, ?string $salt = null): string
{
    if (strlen($authSecret) !== 16) {
        throw new AuthError('کلید احراز اشتراک اعلان نامعتبر است.');
    }
    if ($asPrivatePem === null) {
        [$asPrivatePem, $asPublic] = p256_generate();
    }
    $salt ??= random_bytes(16);
    $shared = openssl_pkey_derive(openssl_pkey_get_public(p256_public_pem($uaPublic)), openssl_pkey_get_private($asPrivatePem), 32);
    if ($shared === false || strlen($shared) !== 32) {
        throw new AuthError('توافق کلید اعلان انجام نشد.', 503);
    }
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) {
        throw new AuthError('رمزنگاری اعلان ممکن نیست.', 503);
    }
    return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
}

/** Converts an OpenSSL DER ECDSA signature into the 64-byte r||s form JWS requires. */
function ecdsa_der_to_raw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f;
    }
    $parts = '';
    for ($i = 0; $i < 2; $i++) {
        if ($der[$pos] !== "\x02") {
            throw new AuthError('امضای VAPID نامعتبر است.');
        }
        $len = ord($der[$pos + 1]);
        $int = ltrim(substr($der, $pos + 2, $len), "\0");
        $parts .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $parts;
}

function vapid_keys(): ?array
{
    $public = setting('push_vapid_public', '');
    $private = secret_get('push_vapid_private');
    if ($public === '' || $private === '') {
        return null;
    }
    return ['public' => $public, 'private' => $private];
}

function vapid_subject(): string
{
    $mail = setting('push_contact', '');
    if ($mail === '') {
        $mail = (string) query('SELECT email FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    }
    return 'mailto:' . ($mail !== '' ? $mail : 'admin@localhost');
}

/** Authorization header value for a push service endpoint. */
function vapid_authorization(string $endpoint, array $keys, int $ttl = 43200): string
{
    $parts = parse_url($endpoint);
    if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        throw new AuthError('نشانی سرویس اعلان باید HTTPS باشد.');
    }
    $aud = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $input = b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' .
        b64url_encode(json_encode(['aud' => $aud, 'exp' => time() + min($ttl, 86400), 'sub' => vapid_subject()], JSON_UNESCAPED_SLASHES));
    $public = b64url_decode($keys['public']);
    $pem = p256_private_pem(b64url_decode($keys['private']), $public);
    if (!openssl_sign($input, $der, $pem, OPENSSL_ALGO_SHA256)) {
        throw new AuthError('امضای VAPID ممکن نیست.', 503);
    }
    return 'vapid t=' . $input . '.' . b64url_encode(ecdsa_der_to_raw($der)) . ', k=' . $keys['public'];
}

/**
 * Sends one encrypted message. Returns the HTTP status. $transport (CLI tests only):
 * fn(string $endpoint, array $headers, string $body): int
 */
function webpush_send(array $sub, string $payload, ?callable $transport = null): int
{
    $keys = vapid_keys();
    if (!$keys) {
        throw new AuthError('کلیدهای اعلان مرورگر ساخته نشده‌اند.', 503);
    }
    $body = webpush_encrypt($payload, b64url_decode($sub['p256dh']), b64url_decode($sub['auth']));
    $headers = [
        'Authorization: ' . vapid_authorization($sub['endpoint'], $keys),
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'TTL: ' . setting_int('push_ttl_seconds', 86400, 60, 2419200),
        'Urgency: normal',
    ];
    if ($transport) {
        if (PHP_SAPI !== 'cli') {
            throw new AuthError('انتقال آزمایشی فقط در CLI مجاز است.', 403);
        }
        return (int) $transport($sub['endpoint'], $headers, $body);
    }
    if (!extension_loaded('curl')) {
        throw new AuthError('افزونه cURL روی هاست فعال نیست.', 503);
    }
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $http;
}
