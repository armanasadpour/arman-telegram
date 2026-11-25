<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel forms no longer ask for a "reason". Actions still record who did what in the audit log;
 * when no reason is sent, a neutral one is filled in so the shared validation and logging keep working.
 */
class DefaultReason
{
    public const TEXT = 'از پنل';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && ! $request->is('hook/*', 'pay/*') && trim((string) $request->input('reason', '')) === '') {
            $request->merge(['reason' => self::TEXT]);
        }

        return $next($request);
    }
}
