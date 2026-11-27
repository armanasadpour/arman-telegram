<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Money fields (names ending in "_toman") are shown with thousands separators (1,250,000) and may be typed
 * with Persian digits; turn them back into plain digits before validation.
 */
class NormalizeMoneyInput
{
    private const DIGITS = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            $fix = [];
            foreach ($request->request->all() as $k => $v) {
                if (is_string($v) && str_ends_with((string) $k, '_toman')) {
                    $clean = str_replace([',', '٬', '،', ' ', "\u{00A0}", "\u{200C}"], '', strtr($v, self::DIGITS));
                    if ($clean !== $v && preg_match('/^\d*$/', $clean)) {
                        $fix[$k] = $clean;
                    }
                }
            }
            if ($fix) {
                $request->merge($fix);
            }
        }

        return $next($request);
    }
}
