<?php

namespace App\RouterOs;

/** HTTP layer of the REST client (swapped for a fake in tests). */
interface RestTransport
{
    /** @return array{0:int,1:string} [status, body]; throws RouterOsUnreachable on network/TLS failure */
    public function send(string $method, string $url, string $user, string $password, string $pin, ?string $json, int $timeout): array;
}
