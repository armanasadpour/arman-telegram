<?php

namespace App\RouterOs;

/**
 * Plain ext-curl: needed for CURLOPT_PINNEDPUBLICKEY, which Guzzle's option allow-list refuses.
 * Peer verification is off because RouterOS uses a self-signed certificate; trust comes from the pin,
 * which libcurl checks during the handshake, before any credentials are sent.
 */
class CurlRestTransport implements RestTransport
{
    public function send(string $method, string $url, string $user, string $password, string $pin, ?string $json, int $timeout): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $user.':'.$password,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PINNEDPUBLICKEY => $pin,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($errno === (defined('CURLE_SSL_PINNEDPUBKEYNOTMATCH') ? CURLE_SSL_PINNEDPUBKEYNOTMATCH : 90)) {
                throw new RouterOsUnreachable('گواهی روتر با اثر انگشت ثبت‌شده مطابقت ندارد (گواهی عوض شده یا اتصال شنود می‌شود).');
            }
            throw new RouterOsUnreachable(RestClient::network('error '.$errno.' '.$err));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }
}
