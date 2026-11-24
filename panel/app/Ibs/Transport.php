<?php

namespace App\Ibs;

interface Transport
{
    /**
     * Send one XML-RPC call. $params is the single struct (auth fields included).
     *
     * @param  array<string,mixed>  $params
     *
     * @throws IbsException application fault
     * @throws IbsUnavailableException network/protocol failure
     */
    public function call(string $method, array $params): mixed;
}
