<?php

namespace App\RouterOs;

/** Certificate public-key pins: "sha256//" + base64(SHA-256 of the DER SubjectPublicKeyInfo) — curl's format. */
final class Pin
{
    /** @param \OpenSSLCertificate|string $cert */
    public static function fromCertificate($cert): string
    {
        $pub = openssl_pkey_get_public($cert);
        $details = $pub ? openssl_pkey_get_details($pub) : false;
        if (! $details || empty($details['key'])) {
            throw new RouterOsException('کلید عمومی گواهی روتر خوانده نشد.');
        }
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $details['key']), true);

        return 'sha256//'.base64_encode(hash('sha256', (string) $der, true));
    }

    /** Connect over TLS without sending anything and return the server's pin plus certificate facts. */
    public static function fetch(string $host, int $port, float $timeout = 6.0): array
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true, 'SNI_enabled' => true]]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('tls://'.$host.':'.$port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new RouterOsUnreachable(RestClient::network($errstr !== '' ? $errstr : 'error '.$errno).' (آیا سرویس www-ssl یا api-ssl با گواهی فعال است؟)');
        }
        $cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
        fclose($fp);
        if (! $cert) {
            throw new RouterOsException('روتر گواهی ارائه نکرد.');
        }
        $x = openssl_x509_parse($cert) ?: [];
        openssl_x509_export($cert, $pem);

        return [
            'pin' => self::fromCertificate($cert),
            'subject' => $x['subject']['CN'] ?? ($x['name'] ?? ''),
            'valid_to' => isset($x['validTo_time_t']) ? date('Y-m-d', (int) $x['validTo_time_t']) : null,
            'sha256' => strtoupper(implode(':', str_split(openssl_x509_fingerprint($cert, 'sha256') ?: '', 2))),
        ];
    }
}
