<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards sensitive POST/DELETE actions. If the password was not confirmed within
 * auth.password_timeout seconds, the user is sent to the confirmation screen and then
 * back to the page that contains the form (not to the POST URL), so they can resubmit.
 */
class RequireRecentPasswordConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        // Turned off at the owner's request: logged-in users with the permission act without re-entering their password.
        if (! config('panel.require_password_confirmation', false)) {
            return $next($request);
        }
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        if (time() - $confirmedAt > (int) config('auth.password_timeout', 900)) {
            $request->session()->put('url.intended', url()->previous());

            return redirect()->route('password.confirm')
                ->with('error', 'برای انجام این عملیات حساس، ابتدا رمز عبور خود را تأیید کنید و سپس دوباره اقدام کنید.');
        }

        return $next($request);
    }
}
