<?php
declare(strict_types=1);

namespace PM\Push;

/**
 * Minimal, dependency-free Web Push sender:
 *  - VAPID (RFC 8292): ES256 JWT signed with the server's P-256 key.
 *  - Message encryption (RFC 8291, "aes128gcm" of RFC 8188) so push services cannot read payloads.
 * Payloads never contain message content: only an event type and a conversation id.
 */
final class WebPush
{
    /** Push services of the major browsers. Endpoints elsewhere are refused (no SSRF to internal hosts). */
    public const ALLOWED_HOST_SUFFIXES = ['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    private const SPKI_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function __construct(private readonly string $vapidPrivatePem, private readonly string $vapidPublicRaw, private readonly string $subject,
                                private readonly array $extraHosts = [], private readonly ?string $caInfo = null, private readonly string $proxy = '')
    {
    }

    public static function available(): bool
    {
        return function_exists('openssl_pkey_derive') && function_exists('curl_init') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
    }

    /** @return array{0:string,1:string} [private key PEM, public key raw (65 bytes, uncompressed point)] */
    public static function generateVapidKeys(): array
    {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($k === false) {
            throw new \RuntimeException('EC key generation failed');
        }
        openssl_pkey_export($k, $pem);
        return [$pem, self::rawPublic($k)];
    }

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    public function endpointAllowed(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (($p['scheme'] ?? '') !== 'https' || !isset($p['host']) || isset($p['user']) || isset($p['port']) && $p['port'] !== 443 && !in_array(strtolower($p['host']), $this->extraHosts, true)) {
            return false;
        }
        $host = strtolower($p['host']);
        if (in_array($host, $this->extraHosts, true)) {
            return true;
        }
        foreach (self::ALLOWED_HOST_SUFFIXES as $s) {
            if ($host === $s || str_ends_with($host, '.' . $s)) {
                return true;
            }
        }
        return false;
    }

    /** VAPID authorization header value for one push service origin. */
    public function vapidHeader(string $endpoint, int $now): string
    {
        $p = parse_url($endpoint);
        $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $data = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
            . self::b64u(json_encode(['aud' => $aud, 'exp' => $now + 12 * 3600, 'sub' => $this->subject], JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($data, $der, $this->vapidPrivatePem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID signing failed');
        }
        return 'vapid t=' . $data . '.' . self::b64u(self::derToRaw($der)) . ', k=' . self::b64u($this->vapidPublicRaw);
    }

    /**
     * RFC 8291 encryption. $asPrivatePem/$salt are only injected by tests.
     * @return string the aes128gcm body (header || ciphertext)
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $asPrivatePem = null, ?string $salt = null): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('bad subscription keys');
        }
        $as = $asPrivatePem !== null ? openssl_pkey_get_private($asPrivatePem) : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $asPublic = self::rawPublic($as);
        $ua = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::SPKI_P256_PREFIX) . $uaPublic), 64, "\n") . "-----END PUBLIC KEY-----\n");
        if ($ua === false) {
            throw new \InvalidArgumentException('bad subscription public key');
        }
        $ecdh = openssl_pkey_derive($ua, $as, 32);
        if ($ecdh === false) {
            throw new \RuntimeException('ECDH failed');
        }
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $salt ??= random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ct === false) {
            throw new \RuntimeException('encryption failed');
        }
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $ct . $tag;
    }

    /**
     * Sends to many subscriptions in parallel (curl_multi), bounded to a few seconds in total.
     * @param list<array{id:int, endpoint:string, p256dh:string, auth:string, payload:string, ttl:int, urgency:string}> $jobs
     * @return array<int, array{0:int, 1:string}> subscription id => [HTTP status (0 = network error, -1 = bad keys), error text]
     */
    public function sendMany(array $jobs): array
    {
        $mh = curl_multi_init();
        $handles = [];
        $now = time();
        foreach ($jobs as $j) {
            if (!$this->endpointAllowed($j['endpoint'])) {
                continue;
            }
            try {
                $body = self::encrypt($j['payload'], $j['p256dh'], $j['auth']);
                $auth = $this->vapidHeader($j['endpoint'], $now);
            } catch (\Throwable) {
                $handles[$j['id']] = null;
                continue;
            }
            $ch = curl_init($j['endpoint']);
            $opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: ' . $j['ttl'],
                    'Urgency: ' . $j['urgency'], 'Authorization: ' . $auth]];
            if ($this->proxy !== '') {
                $opts[CURLOPT_PROXY] = $this->proxy; // e.g. a server abroad when the host cannot reach the push services
            }
            if ($this->extraHosts !== []) {
                $opts[CURLOPT_NOPROXY] = implode(',', $this->extraHosts); // self-hosted push services are reached directly
            }
            if ($this->caInfo !== null && is_file($this->caInfo)) {
                $opts[CURLOPT_CAINFO] = $this->caInfo;
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$j['id']] = $ch;
        }
        $deadline = microtime(true) + 8;
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running && $st === CURLM_OK && microtime(true) < $deadline);
        $out = [];
        foreach ($handles as $id => $ch) {
            if ($ch === null) {
                $out[$id] = [-1, 'invalid subscription keys']; // malformed subscription keys
                continue;
            }
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = $code === 0 ? (curl_error($ch) ?: 'no response (timeout)') : ($code >= 300 ? mb_substr(trim(preg_replace('/\s+/', ' ', (string)curl_multi_getcontent($ch))), 0, 250) : '');
            $out[$id] = [$code, $err];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    /** Can this server open an HTTPS connection to the push services? (diagnostics; any HTTP answer = reachable) */
    public function reachability(): array
    {
        $out = [];
        foreach (['fcm.googleapis.com' => 'گوگل (Chrome، Edge، اندروید)', 'updates.push.services.mozilla.com' => 'موزیلا (Firefox)', 'web.push.apple.com' => 'اپل (Safari، آیفون)'] as $host => $label) {
            $ch = curl_init('https://' . $host . '/');
            $o = [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
            if ($this->proxy !== '') {
                $o[CURLOPT_PROXY] = $this->proxy;
            }
            curl_setopt_array($ch, $o);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $out[] = ['host' => $host, 'label' => $label, 'ok' => $code > 0, 'code' => $code, 'error' => $code > 0 ? '' : curl_error($ch)];
            curl_close($ch);
        }
        return $out;
    }

    private static function rawPublic(\OpenSSLAsymmetricKey $k): string
    {
        $d = openssl_pkey_get_details($k);
        $x = str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        return "\x04" . $x . $y;
    }

    /** ECDSA DER (SEQUENCE{INTEGER r, INTEGER s}) -> raw r||s (64 bytes), as JWS requires. */
    private static function derToRaw(string $der): string
    {
        $pos = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }
}
