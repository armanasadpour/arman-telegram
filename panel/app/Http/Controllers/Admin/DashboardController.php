<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ProvisioningJob;
use App\Models\User;
use App\Models\VpnAccount;
use App\Services\Settings;
use App\Services\SystemStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SystemStatus $status): View|\Illuminate\Http\RedirectResponse
    {
        if ($request->user()->isReseller()) {
            return redirect()->route('portal.dashboard');
        }
        $this->authorize('dashboard.view');
        $user = $request->user();
        $todayStart = now(config('app.display_timezone'))->startOfDay()->utc();

        $stats = [
            'staff_active' => User::query()->where('is_active', true)->count(),
            'staff_total' => User::query()->count(),
            'logins_today' => AuditLog::query()->where('action', 'auth.login')->where('created_at', '>=', $todayStart)->count(),
            'failed_24h' => AuditLog::query()->where('action', 'auth.login_failed')->where('created_at', '>=', now()->subDay())->count(),
        ];
        $accounts = $user->can('accounts.view') ? [
            'total' => VpnAccount::query()->whereNull('ibs_deleted_at')->count(),
            'online' => VpnAccount::query()->where('is_online', true)->count(),
            'expired' => VpnAccount::query()->whereNull('ibs_deleted_at')->where('expires_at', '<', now())->count(),
            'expiring' => VpnAccount::query()->whereNull('ibs_deleted_at')->whereBetween('expires_at', [now(), now()->addDays(3)])->count(),
            'locked' => VpnAccount::query()->whereNull('ibs_deleted_at')->where('locked', true)->count(),
            'open_jobs' => ProvisioningJob::query()->whereIn('status', ProvisioningJob::OPEN)->count(),
            'onlines_at' => app(Settings::class)->get('sync.onlines_at'),
        ] : null;
        $problems = $user->can('system.view')
            ? array_values(array_filter($status->checks(), fn ($c) => $c['status'] !== 'ok'))
            : [];
        $recent = $user->can('audit.view')
            ? AuditLog::query()->orderByDesc('id')->limit(8)->get()
            : collect();

        return view('dashboard', compact('stats', 'problems', 'recent', 'accounts') + ['generatedAt' => now()]);
    }
}
