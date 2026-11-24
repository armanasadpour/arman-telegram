<?php

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PermissionRegistry;
use App\Services\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
