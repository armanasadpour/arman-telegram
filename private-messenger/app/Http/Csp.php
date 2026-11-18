<?php
declare(strict_types=1);

namespace PM\Http;

/**
 * script-src for the app pages and the crypto worker. The encryption engine is WebAssembly; modern browsers allow
 * compiling it with the narrow 'wasm-unsafe-eval'. Browsers older than that keyword (Safari/iOS before 16,
 * Chrome before 97, Firefox before 102) ignore it and only accept the broader 'unsafe-eval' — without it the
 * app cannot start there («Refused to create a WebAssembly object»). Only those browsers get 'unsafe-eval'.
 */
final class Csp
{
    public static function scriptSrc(string $userAgent): string
    {
        return "script-src 'self' 'wasm-unsafe-eval'" . (self::legacyWasm($userAgent) ? " 'unsafe-eval'" : '');
    }

    public static function legacyWasm(string $ua): bool
    {
        // Every browser on iPhone/iPad is WebKit: the iOS version decides.
        if (preg_match('/\b(?:iPhone|CPU) OS (\d+)_/', $ua, $m)) {
            return (int)$m[1] < 16;
        }
        if (preg_match('/Firefox\/(\d+)/', $ua, $m)) {
            return (int)$m[1] < 102;
        }
        if (preg_match('/(?:Chrome|Chromium)\/(\d+)/', $ua, $m)) {
            return (int)$m[1] < 97;
        }
        if (str_contains($ua, 'Safari/') && preg_match('/Version\/(\d+)/', $ua, $m)) {
            return (int)$m[1] < 16;
        }
        return false;
    }
}
