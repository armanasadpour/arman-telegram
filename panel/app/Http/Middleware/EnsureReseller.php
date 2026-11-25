<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Reseller portal: only users linked to a reseller record. */
class EnsureReseller
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->reseller !== null, 403, 'این بخش فقط برای همکاران فروش است.');

        return $next($request);
    }
}
