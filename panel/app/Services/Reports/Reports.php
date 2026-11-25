<?php

namespace App\Services\Reports;

use App\Models\CustomerOrder;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\ResellerSale;
use App\Models\VpnAccount;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only figures for the dashboard, reports and exports. Days are grouped in the display time zone
 * (Iran) in PHP, so the same code works on SQLite and MySQL. Amounts are rial (shown as toman).
 *
 * Sales revenue = completed reseller sales (what resellers paid from their wallets)
 *               + completed customer orders (bot purchases at the public price).
 */
class Reports
{
    public function tz(): string
    {
        return config('app.display_timezone', 'Asia/Tehran');
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} UTC [from, to) for local days [$from, $to] inclusive */
    public function range(CarbonImmutable $fromLocal, CarbonImmutable $toLocal): array
    {
        return [$fromLocal->startOfDay()->utc(), $toLocal->startOfDay()->addDay()->utc()];
    }

    public function lastDays(int $days): array
    {
        $today = CarbonImmutable::now($this->tz())->startOfDay();

        return [$today->subDays($days - 1), $today];
    }

    /** @return array{days: list<string>, revenue: array, profit: array, sales: array, orders: array, payments: array, accounts: array} */
    public function daily(CarbonImmutable $fromLocal, CarbonImmutable $toLocal, ?int $resellerId = null): array
    {
        [$from, $to] = $this->range($fromLocal, $toLocal);
        $days = [];
        for ($d = $fromLocal->startOfDay(); $d->lte($toLocal); $d = $d->addDay()) {
            $days[] = $d->toDateString();
        }
        $zero = array_fill_keys($days, 0);
        $out = ['days' => $days, 'revenue' => $zero, 'profit' => $zero, 'sales' => $zero, 'orders' => $zero, 'payments' => $zero, 'accounts' => $zero];
        $key = fn ($dt) => CarbonImmutable::parse($dt, 'UTC')->setTimezone($this->tz())->toDateString();

        $sales = ResellerSale::query()->where('status', 'completed')->whereBetween('created_at', [$from, $to]);
        if ($resellerId) {
            $sales->where('reseller_id', $resellerId);
        }
        foreach ($sales->get(['created_at', 'cost_rial', 'profit_rial']) as $s) {
            $k = $key($s->created_at);
            if (isset($out['sales'][$k])) {
                $out['sales'][$k]++;
                $out['revenue'][$k] += (int) $s->cost_rial;
                $out['profit'][$k] += (int) $s->profit_rial;
            }
        }
        if (! $resellerId) {
            foreach (CustomerOrder::query()->where('status', 'completed')->whereBetween('updated_at', [$from, $to])->get(['updated_at', 'price_rial']) as $o) {
                $k = $key($o->updated_at);
                if (isset($out['orders'][$k])) {
                    $out['orders'][$k]++;
                    $out['revenue'][$k] += (int) $o->price_rial;
                }
            }
            foreach (Payment::query()->where('status', 'paid')->whereBetween('paid_at', [$from, $to])->get(['paid_at', 'amount_rial']) as $p) {
                $k = $key($p->paid_at);
                if (isset($out['payments'][$k])) {
                    $out['payments'][$k] += (int) $p->amount_rial;
                }
            }
            foreach (VpnAccount::withTrashed()->where('source', '!=', 'imported')->whereBetween('created_at', [$from, $to])->get(['created_at']) as $a) {
                $k = $key($a->created_at);
                if (isset($out['accounts'][$k])) {
                    $out['accounts'][$k]++;
                }
            }
        }

        $out['count'] = array_combine($days, array_map(fn ($d) => $out['sales'][$d] + $out['orders'][$d], $days));

        return $out;
    }

    /** Totals for a range. */
    public function totals(CarbonImmutable $fromLocal, CarbonImmutable $toLocal): array
    {
        [$from, $to] = $this->range($fromLocal, $toLocal);
        $sales = ResellerSale::query()->where('status', 'completed')->whereBetween('created_at', [$from, $to]);
        $orders = CustomerOrder::query()->where('status', 'completed')->whereBetween('updated_at', [$from, $to]);
        $paid = Payment::query()->where('status', 'paid')->whereBetween('paid_at', [$from, $to]);

        return [
            'sales_count' => (clone $sales)->count(),
            'sales_rial' => (int) (clone $sales)->sum('cost_rial'),
            'reseller_profit' => (int) (clone $sales)->sum('profit_rial'),
            'orders_count' => (clone $orders)->count(),
            'orders_rial' => (int) (clone $orders)->sum('price_rial'),
            'payments_rial' => (int) (clone $paid)->sum('amount_rial'),
            'payments_by_method' => (clone $paid)->selectRaw('method, count(*) c, sum(amount_rial) s')->groupBy('method')->get()
                ->mapWithKeys(fn ($r) => [$r->method => ['count' => (int) $r->c, 'rial' => (int) $r->s]])->all(),
            'new_accounts' => VpnAccount::withTrashed()->where('source', '!=', 'imported')->whereBetween('created_at', [$from, $to])->count(),
            'manual_credit' => (int) LedgerEntry::query()->where('type', 'manual_credit')->whereBetween('created_at', [$from, $to])->sum('amount'),
        ];
    }

    /** Per-reseller performance in a range, best first. */
    public function resellers(CarbonImmutable $fromLocal, CarbonImmutable $toLocal): Collection
    {
        [$from, $to] = $this->range($fromLocal, $toLocal);
        $agg = ResellerSale::query()->where('status', 'completed')->whereBetween('created_at', [$from, $to])
            ->selectRaw('reseller_id, count(*) c, sum(cost_rial) cost, sum(profit_rial) profit')->groupBy('reseller_id')->get()->keyBy('reseller_id');
        $balances = Wallet::query()->where('owner_type', 'reseller')->pluck('balance', 'owner_id');
        $accounts = VpnAccount::query()->whereNull('ibs_deleted_at')->whereNotNull('reseller_id')->selectRaw('reseller_id, count(*) c')->groupBy('reseller_id')->pluck('c', 'reseller_id');

        return Reseller::query()->orderBy('name')->get()->map(fn (Reseller $r) => [
            'reseller' => $r,
            'sales' => (int) ($agg[$r->id]->c ?? 0),
            'rial' => (int) ($agg[$r->id]->cost ?? 0),
            'profit' => (int) ($agg[$r->id]->profit ?? 0),
            'balance' => (int) ($balances[$r->id] ?? 0),
            'accounts' => (int) ($accounts[$r->id] ?? 0),
        ])->sortByDesc('rial')->values();
    }

    /** Accounts overview by group and status (current). */
    public function accountsByGroup(): Collection
    {
        $now = now();

        return VpnAccount::query()->whereNull('ibs_deleted_at')
            ->selectRaw('group_name, count(*) total, sum(case when is_online then 1 else 0 end) online, sum(case when expires_at < ? then 1 else 0 end) expired', [$now])
            ->groupBy('group_name')->orderByDesc('total')->get();
    }
}
