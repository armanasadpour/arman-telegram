<?php

namespace App\Http\Middleware;

use App\Services\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Applies runtime settings (display timezone) for the current request. */
class ApplyDisplaySettings
{
    public function __construct(private Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tz = $this->settings->get('general.display_timezone');
        if ($tz && in_array($tz, timezone_identifiers_list(), true)) {
            config(['app.display_timezone' => $tz]);
        }

        return $next($request);
    }
}
