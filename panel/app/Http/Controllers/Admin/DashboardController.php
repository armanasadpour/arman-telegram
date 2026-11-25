<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomerOrder;
use App\Models\Payment;
use App\Models\ProvisioningJob;
use App\Models\ResellerSale;
use App\Models\User;
use App\Models\VpnAccount;
use App\Services\Reports\Reports;
use App\Services\Settings;
use App\Services\SystemStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SystemStatus $status): View|RedirectResponse
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

        $finance = null;
        if ($user->can('reports.view')) {
            $r = app(Reports::class);
            [$from14, $to] = $r->lastDays(14);
            $sum = fn (array $t) => $t['sales_rial'] + $t['orders_rial'];
            $finance = [
                'today' => $r->totals($to, $to),
                'week' => $r->totals($to->subDays(6), $to),
                'month' => $r->totals($to->subDays(29), $to),
                'daily' => $r->daily($from14, $to),
                'sum' => $sum,
            ];
        }
        $attention = [
            'sales_review' => ResellerSale::query()->where('status', 'needs_review')->count(),
            'orders_review' => CustomerOrder::query()->where('status', 'needs_review')->count(),
            'payments_review' => Payment::query()->where('status', 'review')->count(),
            'jobs' => ProvisioningJob::query()->whereIn('status', ProvisioningJob::OPEN)->count(),
        ];

        return view('dashboard', compact('stats', 'problems', 'recent', 'accounts', 'finance', 'attention') + ['generatedAt' => now()]);
    }
}
