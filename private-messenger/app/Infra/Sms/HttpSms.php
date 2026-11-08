<?php
declare(strict_types=1);

namespace PM\Infra\Sms;

/** Small cURL helper shared by HTTP-based providers. */
final class HttpSms
{
    /** @return array{0:int,1:string} status, body */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new SmsException('cURL extension is not available');
        }
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp === false) {
            throw new SmsException('SMS HTTP request failed: ' . $err);
        }
        return [$status, (string)$resp];
    }

    /** Local Iranian format 09xxxxxxxxx used by Iranian providers. */
    public static function iranLocal(string $e164): string
    {
        return str_starts_with($e164, '+98') ? '0' . substr($e164, 3) : ltrim($e164, '+');
    }
}
