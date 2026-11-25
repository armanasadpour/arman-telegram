<?php

use App\Bots\Outbox;
use App\Ibs\ErrorMessages;
use App\Ibs\IbsConnector;
use App\Models\ProvisioningJob;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\Payments;
use App\Services\AuditLogger;
use App\Services\Finance\CustomerOrders;
use App\Services\Finance\ResellerSales;
use App\Services\Finance\WalletService;
use App\Services\Ibs\AccountSync;
use App\Services\Ibs\ExpiredCleanup;
use App\Services\Ibs\Provisioning;
use App\Services\PermissionRegistry;
use App\Services\Settings;
use Illuminate\Support\Facades\Artisan;
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

    return $bad ? 1 : 0;
})->purpose('Verify every wallet balance against its ledger');

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
