<?php
declare(strict_types=1);

namespace PM\Infra\Sms;

/**
 * sms.ir v1 "send/verify" (template-based OTP).
 * Implemented from the public API documentation; NOT yet tested against a live account.
 */
final class SmsIrProvider implements SmsProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly int $templateId,
        private readonly string $paramName = 'CODE',
    ) {
    }

    public function sendOtp(string $phoneE164, string $code, string $message): string
    {
        $payload = json_encode([
            'mobile' => HttpSms::iranLocal($phoneE164),
            'templateId' => $this->templateId,
            'parameters' => [['name' => $this->paramName, 'value' => $code]],
        ]);
        [$status, $body] = HttpSms::request('POST', 'https://api.sms.ir/v1/send/verify', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-api-key' => $this->apiKey,
        ], (string)$payload);
        $json = json_decode($body, true);
        if ($status !== 200 || (int)($json['status'] ?? 0) !== 1) {
            throw new SmsException('sms.ir error: HTTP ' . $status . ' ' . substr((string)($json['message'] ?? ''), 0, 200));
        }
        return (string)($json['data']['messageId'] ?? '');
    }
}
