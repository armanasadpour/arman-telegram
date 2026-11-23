<?php
/**
 * Read-oriented RouterOS v7 REST client (HTTPS only, certificate pinned).
 *
 * Facts from the official MikroTik REST API manual:
 *  - Base URL https://<router>/rest/<menu path>, HTTP Basic auth with a RouterOS user.
 *  - Requires the www-ssl service with a certificate on RouterOS < 7.9 (7.7 = yours).
 *  - Errors come back as JSON {"error":<code>,"message":...,"detail":...}.
 *
 * RouterOS normally uses a self-signed certificate, so instead of CA validation
 * we pin the certificate's public key (SPKI SHA-256, curl "sha256//" format).
 * Credentials are never sent until a pin is configured.
 */

declare(strict_types=1);

namespace IbsProbe;

final class MikrotikError extends \RuntimeException {}

final class MikrotikRestClient
{
    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private string $pin,
        private float $timeout = 10.0,
    ) {
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
            throw new \InvalidArgumentException('Invalid host');
        }
        if (!preg_match('#^sha256//[A-Za-z0-9+/=]{43,44}$#', $pin)) {
            throw new \InvalidArgumentException('cert_pin must look like sha256//BASE64');
        }
        if (!function_exists('curl_init')) {
            throw new MikrotikError('ext-curl is required for the MikroTik REST API');
        }
    }

    /** @return mixed decoded JSON */
    public function get(string $path)
    {
        if (!preg_match('#^[a-z0-9\-/]+$#', $path)) {
            throw new \InvalidArgumentException('Invalid REST path');
        }
        $ch = curl_init('https://' . $this->host . ':' . $this->port . '/rest/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            // Self-signed RouterOS cert: trust comes from the pinned public key, not a CA.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PINNEDPUBLICKEY => $this->pin,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($errno === (defined('CURLE_SSL_PINNEDPUBKEYNOTMATCH') ? CURLE_SSL_PINNEDPUBKEYNOTMATCH : 90)) {
                throw new MikrotikError('Certificate public key does NOT match cert_pin (possible MITM or certificate changed)');
            }
            throw new MikrotikError("Transport error ($errno): $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        if ($status === 401) {
            throw new MikrotikError('401 Unauthorized: wrong RouterOS username/password or user lacks the "api"/"rest-api" policy');
        }
        if ($status !== 200) {
            $msg = is_array($json) ? trim(($json['message'] ?? '') . ' ' . ($json['detail'] ?? '')) : '';
            throw new MikrotikError("HTTP $status" . ($msg !== '' ? ": $msg" : ''));
        }
        if ($json === null && trim((string) $body) !== 'null') {
            throw new MikrotikError('Response is not JSON');
        }
        return $json;
    }

    /**
     * Fetch the server certificate WITHOUT sending any credentials and return its
     * public-key pin (sha256//base64 of the DER SubjectPublicKeyInfo).
     */
    public static function fetchPin(string $host, int $port, float $timeout = 5.0): string
    {
        if (!extension_loaded('openssl')) {
            throw new MikrotikError('ext-openssl is required');
        }
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client("ssl://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new MikrotikError("TLS connection failed ($errno): $errstr — is the www-ssl service enabled with a certificate?");
        }
        $params = stream_context_get_params($fp);
        fclose($fp);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert === null) {
            throw new MikrotikError('No certificate presented');
        }
        $pub = openssl_pkey_get_public($cert);
        $details = $pub ? openssl_pkey_get_details($pub) : false;
        if (!$details || empty($details['key'])) {
            throw new MikrotikError('Cannot read certificate public key');
        }
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $details['key']), true);
        return 'sha256//' . base64_encode(hash('sha256', (string) $der, true));
    }

    /**
     * Remove every secret-looking field (private keys, shared secrets, passwords)
     * from RouterOS output before it is displayed or stored.
     * @param mixed $data
     * @return mixed
     */
    public static function redact($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match('/secret|password|private-key|preshared|passphrase/i', $k)) {
                $data[$k] = '***';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }
        return $data;
    }
}
