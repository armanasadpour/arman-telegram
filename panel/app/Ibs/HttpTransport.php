<?php

namespace App\Ibs;

/** Plain-HTTP XML-RPC transport (IBSng has no TLS), via curl. */
final class HttpTransport implements Transport
{
    public function __construct(
        private string $host,
        private int $port,
        private float $timeout = 15.0,
        private float $connectTimeout = 5.0,
    ) {
        if (! preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) || $port < 1 || $port > 65535) {
            throw new IbsNotConfiguredException('آدرس یا پورت سرور نامعتبر است.');
        }
    }

    public function call(string $method, array $params): mixed
    {
        $body = XmlRpcCodec::encodeRequest($method, [(object) $params]);
        $ch = curl_init("http://{$this->host}:{$this->port}/");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml', 'User-Agent: vpnpanel'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new IbsUnavailableException(match ($errno) {
                CURLE_OPERATION_TIMEDOUT => 'مهلت پاسخ تمام شد (timeout)',
                CURLE_COULDNT_CONNECT => "اتصال به {$this->host}:{$this->port} ممکن نشد؛ IP و پورت را بررسی کنید یا پورت در فایروال بسته است",
                CURLE_COULDNT_RESOLVE_HOST => "نام میزبان {$this->host} پیدا نشد",
                default => "network error ($errno)",
            });
        }
        if ($status !== 200) {
            throw new IbsUnavailableException("HTTP $status from IBSng");
        }

        return XmlRpcCodec::decodeResponse((string) $resp);
    }
}
