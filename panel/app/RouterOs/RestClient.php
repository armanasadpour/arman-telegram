<?php

namespace App\RouterOs;

/**
 * RouterOS v7 REST API: https://host:port/rest/<menu>, HTTP Basic auth, JSON.
 * The router's certificate is self-signed, so trust comes from the pinned public key
 * (curl CURLOPT_PINNEDPUBLICKEY "sha256//…"); credentials are never sent without a pin.
 */
class RestClient implements Client
{
    public function __construct(private string $host, private int $port, private string $username, private string $password, private string $pin, private int $timeout = 12) {}

    public function get(string $path, array $query = []): array
    {
        if (! preg_match('#^sha256//[A-Za-z0-9+/=]{43,44}$#', $this->pin)) {
            throw new RouterOsException('اثر انگشت گواهی روتر ثبت نشده است؛ ابتدا «دریافت اثر انگشت» را بزنید.');
        }
        $url = 'https://'.$this->hostPart().':'.$this->port.'/rest/'.Paths::check($path).($query ? '?'.http_build_query($query) : '');
        [$status, $body] = app(RestTransport::class)->send('GET', $url, $this->username, $this->password, $this->pin, null, $this->timeout);
        $j = json_decode($body, true);
        if ($status === 401) {
            throw new RouterOsException('نام کاربری یا رمز میکروتیک اشتباه است، یا کاربر دسترسی api/rest-api ندارد.');
        }
        if ($status < 200 || $status >= 300) {
            $msg = is_array($j) ? trim(($j['message'] ?? '').' '.($j['detail'] ?? '')) : '';
            throw new RouterOsException('پاسخ خطا از روتر (HTTP '.$status.')'.($msg !== '' ? ': '.mb_substr($msg, 0, 200) : ''));
        }

        return Paths::rows($j);
    }

    private function hostPart(): string
    {
        return str_contains($this->host, ':') && ! str_starts_with($this->host, '[') ? '['.$this->host.']' : $this->host;
    }

    public static function network(string $m): string
    {
        return match (true) {
            str_contains($m, 'timed out') || str_contains($m, 'error 28') => 'روتر در زمان مقرر پاسخ نداد (فایروال، IP یا پورت را بررسی کنید).',
            str_contains($m, 'refused') || str_contains($m, 'error 7') => 'اتصال رد شد: سرویس روی این پورت فعال نیست یا فایروال اجازه نمی‌دهد.',
            str_contains($m, 'resolve') || str_contains($m, 'error 6') => 'نام میزبان روتر پیدا نشد.',
            default => 'اتصال به روتر برقرار نشد: '.mb_substr($m, 0, 160),
        };
    }
}
