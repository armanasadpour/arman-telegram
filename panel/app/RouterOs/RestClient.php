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
        return Paths::rows($this->request('GET', Paths::check($path).($query ? '?'.http_build_query($query) : ''), null));
    }

    public function add(string $path, array $attrs): string
    {
        $j = $this->request('PUT', Paths::check($path), Paths::attrs($attrs));
        $id = is_array($j) ? ($j['.id'] ?? $j['ret'] ?? null) : null;
        if (! is_string($id)) {
            throw new RouterOsException('روتر شناسه ردیف ساخته‌شده را برنگرداند.');
        }

        return $id;
    }

    public function set(string $path, string $id, array $attrs): void
    {
        $this->request('PATCH', Paths::check($path).'/'.Paths::id($id), Paths::attrs($attrs));
    }

    public function remove(string $path, string $id): void
    {
        $this->request('DELETE', Paths::check($path).'/'.Paths::id($id), null);
    }

    private function request(string $method, string $rel, ?array $body): mixed
    {
        if (! preg_match('#^sha256//[A-Za-z0-9+/=]{43,44}$#', $this->pin)) {
            throw new RouterOsException('اثر انگشت گواهی روتر ثبت نشده است؛ ابتدا «دریافت اثر انگشت» را بزنید.');
        }
        $url = 'https://'.$this->hostPart().':'.$this->port.'/rest/'.$rel;
        [$status, $body] = app(RestTransport::class)->send($method, $url, $this->username, $this->password, $this->pin,
            $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->timeout);
        $j = json_decode($body, true);
        if ($status === 401) {
            throw new RouterOsException('نام کاربری یا رمز میکروتیک اشتباه است، یا کاربر دسترسی api/rest-api ندارد.');
        }
        if ($status < 200 || $status >= 300) {
            $msg = is_array($j) ? trim(($j['message'] ?? '').' '.($j['detail'] ?? '')) : '';
            throw new RouterOsException('پاسخ خطا از روتر (HTTP '.$status.')'.($msg !== '' ? ': '.mb_substr($msg, 0, 200) : ''));
        }

        return $j;
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
            $m === 'error 0' || str_contains($m, 'getaddrinfo') || str_contains($m, 'resolve') || str_contains($m, 'error 6 ') => 'آدرس روتر پیدا نشد: نام دامنه را بررسی کنید و فقط دامنه یا IP بنویسید (بدون :پورت).',
            default => 'اتصال به روتر برقرار نشد: '.mb_substr($m, 0, 160),
        };
    }
}
