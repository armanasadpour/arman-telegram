<?php

namespace App\Http\Middleware;

use App\Services\SystemStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After new release files are extracted (and before the web upgrade has run), pages may query
 * tables that do not exist yet. Until migrations are applied: the super admin is sent to the
 * upgrade page; everyone else sees a short maintenance notice.
 */
class EnsureDatabaseUpToDate
{
    private const ALLOWED = ['system.status', 'system.upgrade', 'logout', 'login', 'password.confirm', 'cron'];

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningUnitTests() && ! config('panel.check_upgrade_in_tests')) {
            return $next($request);
        }
        $route = $request->route()?->getName();
        if (in_array($route, self::ALLOWED, true) || $request->is('confirm-password') || SystemStatus::pendingMigrations() === 0) {
            return $next($request);
        }
        $user = $request->user();
        if ($user && $user->isSuperAdmin()) {
            return redirect()->route('system.status')->with('error', 'نسخه جدید نصب شده است؛ ابتدا به‌روزرسانی دیتابیس را اجرا کنید.');
        }

        return response()->view('errors.upgrading', [], 503);
    }
}
