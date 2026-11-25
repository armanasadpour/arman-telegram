<?php

namespace App\Payments;

use App\Services\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class BaseGateway implements Gateway
{
    public function __construct(protected Settings $settings) {}

    protected function setting(string $key): string
    {
        return trim((string) $this->settings->get('pay.'.$key));
    }

    /** Production host, or the test override (config panel.pay_api_override) — never set on real installs. */
    protected function host(string $real): string
    {
        $o = config('panel.pay_api_override');

        return $o ? rtrim($o, '/').'/'.parse_url($real, PHP_URL_HOST) : $real;
    }

    protected static function originOf(string $url): string
    {
        $u = parse_url($url);

        return $u['scheme'].'://'.$u['host'].(isset($u['port']) ? ':'.$u['port'] : '');
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(20)->connectTimeout(8)->acceptJson();
    }

    /** POST JSON; connection problems become PaymentException (start) — verify() catches them itself. */
    protected function postJson(string $url, array $body): Response
    {
        try {
            return $this->http()->asJson()->post($url, $body);
        } catch (ConnectionException $e) {
            throw new PaymentException('اتصال به درگاه برقرار نشد.', 0, $e);
        }
    }
}
