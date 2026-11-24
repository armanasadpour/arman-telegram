<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Until the web installer has finished, every page points to install.php. */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! is_file(config('panel.install_lock')) && ! app()->runningUnitTests()) {
            return response()->view('errors.not-installed', [], 503);
        }

        return $next($request);
    }
}
