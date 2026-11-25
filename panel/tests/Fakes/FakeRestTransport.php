<?php

namespace Tests\Fakes;

use App\RouterOs\RestTransport;
use App\RouterOs\RouterOsUnreachable;

class FakeRestTransport implements RestTransport
{
    public array $requests = [];

    public function __construct(public array $menus, public int $status = 200, public bool $down = false) {}

    public function send(string $method, string $url, string $user, string $password, string $pin, ?string $json, int $timeout): array
    {
        $this->requests[] = compact('method', 'url', 'user', 'password', 'pin', 'json');
        if ($this->down) {
            throw new RouterOsUnreachable('روتر در زمان مقرر پاسخ نداد (فایروال، IP یا پورت را بررسی کنید).');
        }
        if ($this->status !== 200) {
            return [$this->status, json_encode(['error' => $this->status, 'message' => 'Unauthorized'])];
        }
        $path = preg_replace('#^.*/rest/|\?.*$#', '', $url);
        if (! array_key_exists($path, $this->menus)) {
            return [400, '{"error":400,"message":"no such command"}'];
        }
        $rows = $this->menus[$path];
        $single = in_array($path, ['system/resource', 'system/identity', 'system/license', 'interface/l2tp-server/server'], true);

        return [200, json_encode($single ? ($rows[0] ?? new \stdClass) : $rows)];
    }
}
