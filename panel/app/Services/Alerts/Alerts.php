<?php

namespace App\Services\Alerts;

use App\Bots\Notifier;
use App\Ibs\IbsConnector;
use App\Models\BotLinkCode;
use App\Models\BotOutbox;
use App\Models\BotUser;
use App\Models\CustomerOrder;
use App\Models\MtAccount;
use App\Models\Payment;
use App\Models\ProvisioningJob;
use App\Models\Reseller;
use App\Models\ResellerSale;
use App\Models\Router;
use App\Models\VpnAccount;
use App\Services\ConnectionLog;
use App\Services\Finance\WalletService;
use App\Services\Ibs\AccountSync;
use App\Services\Notices;
use App\Services\Settings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Scheduled notifications (cron every 10 minutes). Every alert is recorded in alert_log under a unique
 * (kind, ref, marker) key first and only sent when that insert succeeds, so nothing is sent twice.
 */
class Alerts
{
    public const DEFAULTS = [
        'alerts.expiry_enabled' => '1', 'alerts.expiry_days' => '3,1', 'alerts.expired_enabled' => '1',
        'alerts.volume_enabled' => '1', 'alerts.volume_pct' => '20', 'alerts.volume_mb' => '1024',
        'alerts.reseller_digest_enabled' => '1', 'alerts.reseller_low_balance_toman' => '100000',
        'alerts.admin_report_enabled' => '1', 'alerts.report_hour' => '9', 'alerts.server_enabled' => '1',
    ];

    public function __construct(
        private Settings $settings,
        private Notifier $notifier,
        private AccountSync $sync,
        private IbsConnector $ibs,
        private WalletService $wallets,
    ) {}

    public function get(string $key): string
    {
        return (string) ($this->settings->get($key) ?? self::DEFAULTS[$key] ?? '');
    }

    private function on(string $key): bool
    {
        return $this->get($key) === '1';
    }

    /** @return array<string,int> what was sent */
    public function run(): array
    {
        $out = ['server' => 0, 'customers' => 0, 'mt_customers' => 0, 'admin_report' => 0, 'digests' => 0];
        foreach (['server' => fn () => $this->serverHealth(), 'customers' => fn () => $this->customerAlerts(), 'mt_customers' => fn () => $this->mtCustomerAlerts(),
            'admin_report' => fn () => $this->dailyAdminReport(), 'digests' => fn () => $this->resellerDigests()] as $k => $fn) {
            try {
                $out[$k] = $fn();
            } catch (Throwable $e) {
                Log::warning('alerts: '.$k.' failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }
        $this->housekeeping();

        return $out;
    }

    /** Record first; true only for the first caller. */
    public function once(string $kind, int $ref, string $marker): bool
    {
        return DB::table('alert_log')->insertOrIgnore(['kind' => $kind, 'ref' => $ref, 'marker' => mb_substr($marker, 0, 64), 'created_at' => now()]) === 1;
    }

    // ------------------------------------------------------------------ server

    public function serverHealth(): int
    {
        if (! $this->ibs->isConfigured()) {
            return 0;
        }
        $h = $this->ibs->health();
        $down = $h['breaker_open'] || ($h['error_at'] && (! $h['ok_at'] || strtotime($h['error_at']) > strtotime($h['ok_at'])) && (! $h['ok_at'] || strtotime($h['ok_at']) < time() - 600));
        $state = $down ? 'down' : ($h['ok_at'] ? 'up' : null);
        // In-panel notice: always on (independent of the bot alert setting).
        if ($state === 'down') {
            app(Notices::class)->raise('ibs', 'ارتباط پنل با سرور OpenVPN (IBSng) قطع است', $h['error'] ? mb_substr((string) $h['error'], 0, 300) : null, route('ibs.settings'));
        } elseif ($state === 'up') {
            app(Notices::class)->resolve('ibs', 'ارتباط پنل با سرور OpenVPN (IBSng) دوباره برقرار شد', route('ibs.settings'));
        }
        if (! $this->on('alerts.server_enabled')) {
            return 0;
        }
        $prev = $this->settings->get('alerts.ibs_state');
        if ($state === null || $state === $prev) {
            return 0;
        }
        $this->settings->set('alerts.ibs_state', $state);
        if ($prev === null && $state === 'up') {
            return 0;   // first observation, nothing changed
        }

        return $this->notifier->toStaff('system.view', $state === 'down'
            ? '🔴 ارتباط پنل با سرور IBSng قطع است'.($h['error'] ? ': '.mb_substr($h['error'], 0, 150) : '.').' ساخت و تمدید اکانت تا برقراری ارتباط انجام نمی‌شود.'
            : '🟢 ارتباط پنل با سرور IBSng دوباره برقرار شد.');
    }

    // ------------------------------------------------------------------ customers

    public function customerAlerts(int $limit = 400): int
    {
        if (! $this->on('alerts.expiry_enabled') && ! $this->on('alerts.expired_enabled') && ! $this->on('alerts.volume_enabled')) {
            return 0;
        }
        $days = collect(explode(',', $this->get('alerts.expiry_days')))->map(fn ($d) => (int) trim($d))->filter(fn ($d) => $d > 0 && $d <= 30)->sort()->values();
        $maxDays = (int) ($days->max() ?? 0);
        $linked = VpnAccount::query()->whereNull('ibs_deleted_at')->whereIn('id', DB::table('bot_account_links')->select('vpn_account_id'));
        $candidates = (clone $linked)->where(function ($q) use ($maxDays) {
            $q->whereBetween('expires_at', [now()->subDays(2), now()->addDays($maxDays + 1)]);
            if ($this->on('alerts.volume_enabled')) {
                $q->orWhereNotNull('volume_total_bytes');
            }
        })->orderBy('id')->limit($limit)->get();
        if ($candidates->isEmpty()) {
            return 0;
        }
        // Decide on fresh data: a customer who already renewed must not be told it is expiring.
        try {
            $this->sync->refreshMany($candidates->filter(fn ($a) => ! $a->last_synced_at || $a->last_synced_at->lt(now()->subMinutes(15))));
        } catch (Throwable $e) {
            return 0;   // server unreachable: try again next run rather than alert on stale data
        }
        $sent = 0;
        foreach (VpnAccount::query()->whereKey($candidates->modelKeys())->whereNull('ibs_deleted_at')->get() as $a) {
            foreach ($this->accountMessages($a, $days->all()) as [$kind, $marker, $text]) {
                if ($this->once($kind, $a->id, $marker)) {
                    foreach (BotUser::query()->whereIn('id', DB::table('bot_account_links')->where('vpn_account_id', $a->id)->select('bot_user_id'))->pluck('id') as $bu) {
                        $sent += $this->notifier->toBotUser($bu, $text);
                    }
                }
            }
        }

        return $sent;
    }

    /** Same alerts for L2TP / WireGuard accounts (their data is kept fresh by the per-minute accounting). */
    public function mtCustomerAlerts(int $limit = 400): int
    {
        if (! Schema::hasTable('bot_mt_links') || (! $this->on('alerts.expiry_enabled') && ! $this->on('alerts.expired_enabled') && ! $this->on('alerts.volume_enabled'))) {
            return 0;
        }
        $days = collect(explode(',', $this->get('alerts.expiry_days')))->map(fn ($d) => (int) trim($d))->filter(fn ($d) => $d > 0 && $d <= 30)->sort()->values();
        $maxDays = (int) ($days->max() ?? 0);
        $sent = 0;
        $q = MtAccount::query()->whereIn('status', ['active', 'expired', 'depleted'])->whereIn('id', DB::table('bot_mt_links')->select('mt_account_id'))
            ->where(function ($q) use ($maxDays) {
                $q->whereBetween('expires_at', [now()->subDays(2), now()->addDays($maxDays + 1)]);
                if ($this->on('alerts.volume_enabled')) {
                    $q->orWhereNotNull('volume_limit_bytes');
                }
            })->where('last_synced_at', '>=', now()->subMinutes(30))->orderBy('id')->limit($limit);
        foreach ($q->get() as $a) {
            foreach ($this->accountMessages($a, $days->all()) as [$kind, $marker, $text]) {
                if ($this->once('mt_'.$kind, $a->id, $marker)) {
                    foreach (DB::table('bot_mt_links')->where('mt_account_id', $a->id)->pluck('bot_user_id') as $bu) {
                        $sent += $this->notifier->toBotUser((int) $bu, $text);
                    }
                }
            }
        }

        return $sent;
    }

    /** @return list<array{0:string,1:string,2:string}> [kind, marker, text] */
    public function accountMessages(VpnAccount|MtAccount $a, array $thresholds): array
    {
        $out = [];
        $renew = "\n\nبرای تمدید، در ربات «🔄 تمدید اکانت من» را بزنید.";
        if ($a->expires_at) {
            $left = $a->expires_at->getTimestamp() - now()->getTimestamp();
            $date = $a->expires_at->format('Y-m-d');
            if ($left > 0 && $this->on('alerts.expiry_enabled')) {
                $t = collect($thresholds)->sort()->first(fn ($d) => $left <= $d * 86400);
                if ($t !== null) {
                    $out[] = ['exp_soon', $date.':'.$t, '⏰ اکانت '.$a->username.' '.($left < 86400 ? 'کمتر از یک روز دیگر' : fa((int) ceil($left / 86400)).' روز دیگر')
                        .' ('.jdate($a->expires_at, 'Y/m/d H:i').') منقضی می‌شود.'.$renew];
                }
            } elseif ($left <= 0 && $left > -2 * 86400 && $this->on('alerts.expired_enabled')) {
                $out[] = ['expired', $date, '⛔️ اکانت '.$a->username.' منقضی شد و دیگر متصل نمی‌شود.'.$renew];
            }
        }
        if ($this->on('alerts.volume_enabled') && ($vm = $a->volumeMeter()) && ! ($a->expires_at && $a->expires_at->isPast())) {
            $marker = (string) $vm['total'];
            if ($vm['remaining'] <= 0) {
                $out[] = ['vol_out', $marker, '📵 حجم اکانت '.$a->username.' تمام شد.'.$renew];
            } elseif ($vm['pct'] <= (int) $this->get('alerts.volume_pct') || $vm['remaining'] <= (int) $this->get('alerts.volume_mb') * 1048576) {
                $out[] = ['vol_low', $marker, '📶 حجم اکانت '.$a->username.' رو به اتمام است: '.fa_bytes($vm['remaining']).' مانده ('.fa($vm['pct']).'٪).'.$renew];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ daily reports

    private function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.display_timezone', 'Asia/Tehran'));
    }

    private function reportDue(): bool
    {
        return $this->localNow()->hour >= (int) $this->get('alerts.report_hour');
    }

    public function dailyAdminReport(bool $force = false, ?int $onlyUserId = null): int
    {
        if (! $force && (! $this->on('alerts.admin_report_enabled') || ! $this->reportDue() || ! $this->once('report_admin', 0, $this->localNow()->toDateString()))) {
            return 0;
        }
        $text = $this->adminReportText();

        return $onlyUserId ? $this->notifier->toUser($onlyUserId, $text) : $this->notifier->toStaff('dashboard.view', $text);
    }

    public function adminReportText(): string
    {
        $tz = config('app.display_timezone', 'Asia/Tehran');
        $from = $this->localNow()->subDay()->startOfDay()->utc();
        $to = $from->addDay();
        $sales = ResellerSale::query()->where('status', 'completed')->whereBetween('created_at', [$from, $to]);
        $orders = CustomerOrder::query()->where('status', 'completed')->whereBetween('updated_at', [$from, $to]);
        $pay = Payment::query()->where('status', 'paid')->whereBetween('paid_at', [$from, $to])->selectRaw('method, count(*) c, sum(amount_rial) s')->groupBy('method')->get();
        $h = $this->ibs->isConfigured() ? $this->ibs->health() : null;
        $lines = [
            '📊 گزارش روز '.jdate($from->setTimezone($tz), 'Y/m/d'),
            '',
            '🤝 فروش همکاران: '.fa((clone $sales)->count()).' — '.fa_money((int) (clone $sales)->sum('cost_rial')).' (سود همکاران '.fa_money((int) (clone $sales)->sum('profit_rial')).')',
            '🛍 خرید مستقیم مشتریان: '.fa((clone $orders)->count()).' — '.fa_money((int) (clone $orders)->sum('price_rial')),
            '💳 پرداخت‌ها: '.($pay->isEmpty() ? 'ندارد' : fa_money((int) $pay->sum('s')).' — '.$pay->map(fn ($r) => (Payment::METHODS[$r->method] ?? $r->method).' '.fa((int) $r->c))->implode('، ')),
            '🆕 اکانت جدید: '.fa(VpnAccount::query()->where('source', '!=', 'imported')->whereBetween('created_at', [$from, $to])->count()),
            '⏰ در آستانه انقضا (۳ روز آینده): '.fa(VpnAccount::query()->whereNull('ibs_deleted_at')->whereBetween('expires_at', [now(), now()->addDays(3)])->count()),
            '🟢 آنلاین اکنون: '.fa(VpnAccount::query()->where('is_online', true)->count()),
        ];
        $review = ResellerSale::query()->where('status', 'needs_review')->count() + CustomerOrder::query()->where('status', 'needs_review')->count()
            + Payment::query()->where('status', 'review')->count() + ProvisioningJob::query()->whereIn('status', ProvisioningJob::OPEN)->count();
        $lines[] = $review ? '⚠️ نیازمند رسیدگی: '.fa($review).' مورد (پنل را ببینید)' : '✅ موردی برای رسیدگی نیست';
        if ($h) {
            $lines[] = $h['breaker_open'] ? '🔴 سرور IBSng: قطع' : '🖥 سرور IBSng: '.($h['ok_at'] ? 'آخرین تماس موفق '.fa_ago(Carbon::parse($h['ok_at'])) : 'نامشخص');
        }
        if (Schema::hasTable('mt_accounts') && MtAccount::query()->exists()) {
            $mt = MtAccount::query();
            $lines[] = '📡 L2TP/WireGuard: '.fa((clone $mt)->whereBetween('created_at', [$from, $to])->count()).' جدید — '
                .fa((clone $mt)->where('is_online', true)->count()).' آنلاین — مصرف دیروز '
                .fa_bytes((int) DB::table('mt_usage_daily')->where('day', $from->setTimezone($tz)->toDateString())->sum(DB::raw('upload_bytes + download_bytes')));
            foreach (Router::query()->where('is_active', true)->get() as $r) {
                if (! $r->isUp()) {
                    $lines[] = '🔴 روتر «'.$r->name.'»: قطع';
                }
            }
        }

        return implode("\n", $lines);
    }

    public function resellerDigests(): int
    {
        if (! $this->on('alerts.reseller_digest_enabled') || ! $this->reportDue()) {
            return 0;
        }
        $date = $this->localNow()->toDateString();
        $low = (int) $this->get('alerts.reseller_low_balance_toman') * 10;
        $sent = 0;
        $withBots = BotUser::query()->whereNotNull('user_id')->pluck('user_id')->unique();
        foreach (Reseller::query()->whereIn('user_id', $withBots)->where('status', 'active')->get() as $r) {
            if (! $this->once('digest', $r->id, $date)) {
                continue;
            }
            $soon = VpnAccount::query()->where('reseller_id', $r->id)->whereNull('ibs_deleted_at')->whereBetween('expires_at', [now(), now()->addDays(3)])->orderBy('expires_at')->get();
            $expired = VpnAccount::query()->where('reseller_id', $r->id)->whereNull('ibs_deleted_at')->whereBetween('expires_at', [now()->subDay(), now()])->count();
            $balance = $this->wallets->walletFor('reseller', $r->id)->balance;
            if ($soon->isEmpty() && ! $expired && ! ($low > 0 && $balance < $low)) {
                continue;
            }
            $lines = ['📋 خلاصه روزانه '.$r->name, ''];
            if ($soon->isNotEmpty()) {
                $lines[] = '⏰ در آستانه انقضا ('.fa($soon->count()).'):';
                foreach ($soon->take(15) as $a) {
                    $lines[] = '• '.$a->username.' — '.jdate($a->expires_at, 'm/d H:i');
                }
                if ($soon->count() > 15) {
                    $lines[] = '… و '.fa($soon->count() - 15).' اکانت دیگر';
                }
            }
            if ($expired) {
                $lines[] = '⛔️ منقضی‌شده در ۲۴ ساعت گذشته: '.fa($expired);
            }
            $lines[] = '';
            $lines[] = '👛 موجودی: '.fa_money($balance).($low > 0 && $balance < $low ? ' — ⚠️ کمتر از '.fa_money($low).'؛ برای فروش بدون وقفه کیف پول را شارژ کنید.' : '');
            $sent += $this->notifier->toUser($r->user_id, implode("\n", $lines));
        }

        return $sent;
    }

    // ------------------------------------------------------------------ housekeeping

    public function housekeeping(): void
    {
        if (! $this->once('housekeeping', 0, now()->toDateString())) {
            return;
        }
        BotOutbox::query()->where('status', 'sent')->where('sent_at', '<', now()->subDays(30))->delete();
        BotLinkCode::query()->where('expires_at', '<', now()->subDay())->delete();
        app(Notices::class)->prune();
        DB::table('alert_log')->where('created_at', '<', now()->subDays(120))->delete();
        app(ConnectionLog::class)->prune(180);
    }
}
