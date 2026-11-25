<?php

namespace App\Payments;

/** Where to send the payer: a GET redirect or an auto-posted form. */
final class GatewayRedirect
{
    /** @param array<string,string> $fields */
    public function __construct(public readonly string $url, public readonly string $method = 'GET', public readonly array $fields = []) {}
}
