<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel's "throttle:N,M" keys its counter by user (or IP) only, so every throttled route shares one
 * counter: a few clicks on one page could block a payment on another. This keys it per route as well.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $name = $route ? ($route->getName() ?: $route->uri()) : '';

        return sha1(parent::resolveRequestSignature($request).'|'.$name);
    }
}
