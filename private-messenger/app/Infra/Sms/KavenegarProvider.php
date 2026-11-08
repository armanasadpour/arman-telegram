<?php
declare(strict_types=1);

namespace PM\Infra\Sms;

/**
 * Kavenegar "verify/lookup" (template-based OTP).
 * Implemented from the public API documentation; NOT yet tested against a live account.
 */
final class KavenegarProvider implements SmsProvider
{
    public function __construct(private readonly string $apiKey, private readonly string $template)
    {
    }

    public function sendOtp(string $phoneE164, string $code, string $message): string
    {
        $url = 'https://api.kavenegar.com/v1/' . rawurlencode($this->apiKey) . '/verify/lookup.json?'
            . http_build_query(['receptor' => HttpSms::iranLocal($phoneE164), 'token' => $code, 'template' => $this->template]);
        [$status, $body] = HttpSms::request('GET', $url);
        $json = json_decode($body, true);
        if ($status !== 200 || ($json['return']['status'] ?? 0) !== 200) {
            throw new SmsException('Kavenegar error: HTTP ' . $status . ' ' . substr((string)($json['return']['message'] ?? ''), 0, 200));
        }
        return (string)($json['entries'][0]['messageid'] ?? '');
    }
}
