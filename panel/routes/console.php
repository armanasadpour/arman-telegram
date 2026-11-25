<?php

use App\Bots\Notifier;
use App\Bots\Outbox;
use App\Bots\Platforms;
use App\Bots\UpdateHandler;
use App\Ibs\ErrorMessages;
use App\Ibs\IbsConnector;
use App\Models\Bot;
use App\Models\MtAccount;
use App\Models\ProvisioningJob;
use App\Models\Role;
use App\Models\Router;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\Payments;
use App\RouterOs\RouterConnector;
use App\Services\Alerts\Alerts;
use App\Services\AuditLogger;
use App\Services\Finance\CustomerOrders;
use App\Services\Finance\ResellerSales;
use App\Services\Finance\WalletService;
use App\Services\Ibs\AccountSync;
use App\Services\Ibs\ExpiredCleanup;
use App\Services\Ibs\Provisioning;
use App\Services\Mikrotik\MtAccounting;
use App\Services\Mikrotik\MtProvisioning;
use App\Services\PermissionRegistry;
use App\Services\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('permissions:sync', function (PermissionRegistry $registry) {
    $r = $registry->sync();
    $this->info("Permissions synced: {$r['created']} created, {$r['removed']} removed.");
})->purpose('Sync permissions table with config/permissions.php');

Artisan::command('audit:verify', function (AuditLogger $audit) {
    $broken = $audit->verifyChain();
    if ($broken === null) {
        $this->info('Audit chain intact.');

        return 0;
    }
    $this->error("Audit chain broken at record #$broken (modified or deleted rows).");

    return 1;
})->purpose('Verify the audit log hash chain');

Artisan::command('panel:create-admin {username} {--name=} {--password=}', function (PermissionRegistry $registry, AuditLogger $audit) {
    $registry->sync();
    $password = $this->option('password') ?: $this->secret('Password (min 10 chars)');
    if (mb_strlen((string) $password) < 10) {
        $this->error('Password must be at least 10 characters.');

        return 1;
    }
    $user = User::query()->create([
        'name' => $this->option('name') ?: $this->argument('username'),
        'username' => $this->argument('username'),
        'password' => $password,
        'is_active' => true,
    ]);
    $user->forceFill(['password_changed_at' => now()])->save();
    $user->roles()->attach(Role::query()->where('name', config('permissions.super_role'))->value('id'));
    $audit->log('staff.create_super_admin', $user, 'ساخت مدیرکل از خط فرمان', actorLabel: 'cli');
    $this->info("Super admin '{$user->username}' created.");

    return 0;
})->purpose('Create a super admin (CLI fallback for the web installer)');

// Heartbeat so the status page can tell whether cron is actually running.
Artisan::command('panel:heartbeat', function (Settings $settings) {
    $settings->set('system.cron_last_run', now()->toIso8601String());
})->purpose('Record that the scheduler ran');

Schedule::command('panel:heartbeat')->everyMinute()->withoutOverlapping();

// ---------------------------------------------------------------- IBSng sync (phase 3)
// Each command is a no-op when IBSng is not configured; failures are logged, never fatal.
$ibsCommand = function (string $name, callable $fn, string $purpose) {
    Artisan::command($name, function () use ($fn) {
        $ibs = app(IbsConnector::class);
        if (! $ibs->isConfigured()) {
            $this->line('IBSng not configured; skipped.');

            return 0;
        }
        try {
            $this->info((string) $fn($this));
        } catch (Throwable $e) {
            $this->error(ErrorMessages::for($e));

            return 1;
        }

        return 0;
    })->purpose($purpose);
};
$ibsCommand('ibs:sync-onlines', fn () => 'online: '.app(AccountSync::class)->syncOnlines(), 'Refresh online sessions from IBSng');
$ibsCommand('ibs:sync-accounts', fn () => 'refreshed: '.app(AccountSync::class)->syncAccounts(), 'Refresh the stalest accounts from IBSng');
$ibsCommand('ibs:sync-catalog', fn () => json_encode(app(AccountSync::class)->syncCatalog()), 'Refresh IBSng groups and charges');
$ibsCommand('ibs:import-step', function () {
    $sync = app(AccountSync::class);
    $deadline = microtime(true) + 50;
    $st = $sync->importState();
    while (($st['running'] ?? false) && microtime(true) < $deadline) {
        $st = $sync->importStep(3000, $deadline);
    }

    return json_encode($st);
}, 'Continue a bulk import of IBSng accounts');
$ibsCommand('accounts:cleanup-step', function () {
    return json_encode(app(ExpiredCleanup::class)->step(microtime(true) + 45));
}, 'Continue a bulk delete of long-expired accounts');
$ibsCommand('ibs:retry-jobs', function () {
    $n = 0;
    foreach (ProvisioningJob::query()->whereIn('status', ['pending', 'raw_created'])->where('updated_at', '<', now()->subMinute())->limit(20)->get() as $job) {
        app(Provisioning::class)->run($job);
        $n++;
    }

    return "retried: $n";
}, 'Resume unfinished account creations');

Schedule::command('ibs:sync-onlines')->everyMinute()->withoutOverlapping(5);
Schedule::command('ibs:retry-jobs')->everyMinute()->withoutOverlapping(5);
Schedule::command('ibs:import-step')->everyMinute()->withoutOverlapping(5);
Schedule::command('accounts:cleanup-step')->everyMinute()->withoutOverlapping(5);
Schedule::command('ibs:sync-accounts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('ibs:sync-catalog')->hourly()->withoutOverlapping(10);

// ---------------------------------------------------------------- Resellers (phase 5)
$ibsCommand('resellers:sync-sales', fn () => 'updated: '.app(ResellerSales::class)->syncPending(), 'Settle reseller sales whose provisioning finished later');
Schedule::command('resellers:sync-sales')->everyMinute()->withoutOverlapping(5);

Artisan::command('wallets:reconcile', function (WalletService $w) {
    $bad = 0;
    foreach (Wallet::query()->cursor() as $wallet) {
        $r = $w->reconcile($wallet);
        if (! $r['ok']) {
            $bad++;
            $this->error("wallet #{$wallet->id}: cached {$r['cached']} vs ledger {$r['ledger_sum']} (chain ".($r['chain_ok'] ? 'ok' : 'BROKEN').')');
        }
    }
    $bad ? $this->error("$bad wallet(s) out of balance") : $this->info('All wallets reconcile with the ledger.');
    if ($bad) {
        app(Notifier::class)->toStaff('wallets.view', '🚨 '.fa($bad).' کیف پول با دفتر کل مطابقت ندارد (احتمال دست‌کاری مستقیم دیتابیس). صفحه همکاران را بررسی کنید.');
    }

    return $bad ? 1 : 0;
})->purpose('Verify every wallet balance against its ledger');
Schedule::command('wallets:reconcile')->dailyAt('03:17')->withoutOverlapping(30);

Artisan::command('bots:send', function () {
    if (! Schema::hasTable('bot_outbox')) {
        return 0;
    }
    $r = app(Outbox::class)->flush();
    $this->info(json_encode($r));
    DB::table('bot_updates')->where('created_at', '<', now()->subDays(7))->delete();

    return 0;
})->purpose('Send queued bot notifications');
Schedule::command('bots:send')->everyMinute()->withoutOverlapping(5);

Artisan::command('payments:reverify', function () {
    if (! Schema::hasTable('payments')) {
        return 0;
    }
    $n = app(Payments::class)->reverifyStuck();
    $o = app(CustomerOrders::class)->syncPending();
    $this->info("payments rechecked: {$n}, orders settled: {$o}");

    return 0;
})->purpose('Re-verify uncertain bank/USDT payments and settle customer orders');
Schedule::command('payments:reverify')->everyMinute()->withoutOverlapping(5);

Artisan::command('alerts:run', function () {
    if (! Schema::hasTable('alert_log')) {
        return 0;
    }
    $this->info(json_encode(app(Alerts::class)->run()));

    return 0;
})->purpose('Customer expiry/volume alerts, reseller digests, admin daily report, server down/up');
Schedule::command('alerts:run')->everyTenMinutes()->withoutOverlapping(15);

Artisan::command('routers:health', function () {
    if (! Schema::hasTable('routers')) {
        return 0;
    }
    $ros = app(RouterConnector::class);
    foreach (Router::query()->where('is_active', true)->get() as $r) {
        $prev = $r->health;
        try {
            $ros->about($r);
            $state = 'up';
        } catch (Throwable $e) {
            $state = 'down';
            $r->forceFill(['health' => 'down'])->save();
        }
        $this->line($r->name.': '.$state);
        if ($prev !== null && $prev !== $state) {
            app(Notifier::class)->toStaff('system.view', $state === 'down'
                ? '🔴 ارتباط پنل با روتر «'.$r->name.'» قطع شد: '.mb_substr((string) $r->fresh()->last_error, 0, 150)
                : '🟢 ارتباط پنل با روتر «'.$r->name.'» دوباره برقرار شد.');
        }
    }

    return 0;
})->purpose('Check every active MikroTik router; alert on down/up');
Schedule::command('routers:health')->everyFiveMinutes()->withoutOverlapping(10);

// ---------------------------------------------------------------- MikroTik accounts (L2TP / WireGuard)
Artisan::command('mt:accounting', function () {
    if (! Schema::hasTable('mt_accounts')) {
        return 0;
    }
    $prov = app(MtProvisioning::class);
    foreach (MtAccount::query()->where('status', 'pending')->where('updated_at', '<', now()->subMinute())->limit(30)->get() as $a) {
        $prov->push($a);
    }
    $acct = app(MtAccounting::class);
    foreach (Router::query()->where('is_active', true)->get() as $r) {
        try {
            $this->line($r->name.': '.json_encode($acct->syncRouter($r)));
        } catch (Throwable $e) {
            $this->error($r->name.': '.$e->getMessage());
        }
    }
    if (Schema::hasColumn('reseller_sales', 'mt_account_id')) {
        app(ResellerSales::class)->syncPendingMt();
        app(CustomerOrders::class)->syncPendingMt();
    }

    return 0;
})->purpose('Poll MikroTik usage, enforce limits, retry pending creations');
Schedule::command('mt:accounting')->everyMinute()->withoutOverlapping(5);

// Telegram through a Cloudflare / Apps Script relay: the panel fetches messages itself (getUpdates long polling)
// for ~50 seconds per run, so replies come within a few seconds without Telegram having to reach this host.
Artisan::command('bots:poll', function () {
    if (! Schema::hasTable('bots')) {
        return 0;
    }
    $bot = Bot::for('telegram');
    if (! $bot->is_active || ! $bot->isConfigured() || $bot->relay_mode === 'direct') {
        return 0;
    }
    $lock = Cache::lock('bots:poll', 70);
    if (! $lock->get()) {
        return 0;
    }
    try {
        $api = app(Platforms::class)->for($bot);
        $handler = app(UpdateHandler::class);
        $settings = app(Settings::class);
        $deadline = microtime(true) + (int) config('panel.bot_poll_seconds', 50);
        $n = 0;
        do {
            $offset = (int) $settings->get('bots.telegram_offset', '0');
            $left = (int) ($deadline - microtime(true));
            $updates = $api->getUpdates($offset, max(0, min(20, $left - 2)));
            foreach ($updates as $u) {
                $settings->set('bots.telegram_offset', (string) ((int) ($u['update_id'] ?? 0) + 1));
                $n += (int) ($handler->handle($bot, $u) === 'handled');
            }
            if ($updates) {
                app(Outbox::class)->flush();
            }
        } while (microtime(true) < $deadline - 3);
        $bot->forceFill(['last_error' => null, 'last_error_at' => null])->save();
        $this->info("handled: $n");
    } catch (Throwable $e) {
        $msg = mb_substr(str_replace((string) $bot->token, '***', $e->getMessage()), 0, 500);
        $bot->forceFill(['last_error_at' => now(), 'last_error' => $msg])->save();
        $this->error($msg);
    } finally {
        $lock->release();
    }

    return 0;
})->purpose('Fetch Telegram messages through the relay (when Telegram cannot reach this host)');
Schedule::command('bots:poll')->everyMinute()->withoutOverlapping(2)->runInBackground();
