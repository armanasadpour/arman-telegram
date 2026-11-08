<?php
declare(strict_types=1);

namespace PM\Infra\Sms;

/**
 * Configurable HTTPS webhook for any provider. Placeholders {phone}, {phone_local}, {code}, {message}
 * are URL-encoded in the URL and JSON-escaped in the body. Success = HTTP 2xx.
 */
final class GenericHttpProvider implements SmsProvider
{
    public function __construct(
        private readonly string $method,
        private readonly string $urlTemplate,
        private readonly string $bodyTemplate,
        private readonly string $authHeaderName,
        private readonly string $authHeaderValue,
    ) {
    }

    public function sendOtp(string $phoneE164, string $code, string $message): string
    {
        $vals = ['{phone}' => $phoneE164, '{phone_local}' => HttpSms::iranLocal($phoneE164), '{code}' => $code, '{message}' => $message];
        $url = strtr($this->urlTemplate, array_map('rawurlencode', $vals));
        if (!str_starts_with($url, 'https://')) {
            throw new SmsException('Generic SMS URL must use https://');
        }
        $body = null;
        $headers = [];
        if ($this->method === 'POST') {
            $body = strtr($this->bodyTemplate, array_map(static fn ($v) => substr((string)json_encode($v, JSON_UNESCAPED_UNICODE), 1, -1), $vals));
            $headers['Content-Type'] = 'application/json';
        }
        if ($this->authHeaderName !== '') {
            $headers[$this->authHeaderName] = $this->authHeaderValue;
        }
        [$status] = HttpSms::request($this->method, $url, $headers, $body);
        if ($status < 200 || $status > 299) {
            throw new SmsException('SMS webhook returned HTTP ' . $status);
        }
        return 'http-' . $status;
    }
}
