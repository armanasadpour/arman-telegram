<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomerOrder;
use App\Models\MtAccount;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $protocols = $this->protocolCards($user);

        return view('dashboard', compact('stats', 'problems', 'recent', 'accounts', 'finance', 'attention', 'protocols') + ['generatedAt' => now()]);
    }

    /** Headline numbers per protocol: active / online / expired / data used (total and today). */
    private function protocolCards(User $user): array
    {
        $cards = [];
        if ($user->can('accounts.view')) {
            // IBSng reports remaining credit, not usage: used = package volume − remaining, for volume-limited accounts.
            $used = Cache::remember('dash.ovpn_used', 600, function () {
                $sum = 0;
                VpnAccount::query()->with('group.charge')->whereNull('ibs_deleted_at')->whereNotNull('volume_total_bytes')->select(['id', 'group_name', 'credit', 'volume_total_bytes'])
                    ->chunkById(1000, function ($rows) use (&$sum) {
                        foreach ($rows as $a) {
                            $sum += (int) ($a->usedBytes() ?? 0);
                        }
                    });

                return $sum;
            });
            $live = VpnAccount::query()->whereNull('ibs_deleted_at');
            $cards['openvpn'] = [
                'title' => 'OpenVPN', 'url' => route('accounts.index'),
                'active' => (clone $live)->where('locked', false)->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()))->count(),
                'online' => VpnAccount::query()->where('is_online', true)->count(),
                'expired' => (clone $live)->where('expires_at', '<', now())->count(),
                'used' => $used, 'today' => null,
            ];
        }
        if ($user->can('mt_accounts.view') && Schema::hasTable('mt_accounts')) {
            $day = now()->setTimezone('Asia/Tehran')->toDateString();
            foreach (['l2tp' => ['L2TP', 'l2tp.accounts'], 'wireguard' => ['WireGuard', 'wg.accounts']] as $p => [$title, $route]) {
                $q = MtAccount::query()->where('protocol', $p);
                $cards[$p] = [
                    'title' => $title, 'url' => route($route),
                    'active' => (clone $q)->where('status', 'active')->count(),
                    'online' => (clone $q)->where('is_online', true)->count(),
                    'expired' => (clone $q)->whereIn('status', ['expired', 'depleted'])->count(),
                    'used' => (int) (clone $q)->withTrashed()->sum('used_bytes'),
                    'today' => (int) DB::table('mt_usage_daily')->join('mt_accounts', 'mt_accounts.id', '=', 'mt_usage_daily.mt_account_id')
                        ->where('mt_accounts.protocol', $p)->where('mt_usage_daily.day', $day)->sum(DB::raw('mt_usage_daily.upload_bytes + mt_usage_daily.download_bytes')),
                ];
            }
        }

        return $cards;
    }
}
