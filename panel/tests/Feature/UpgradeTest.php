<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UpgradeTest extends TestCase
{
    public function test_pending_migration_shows_banner_and_super_admin_can_upgrade(): void
    {
        $admin = $this->superAdmin();
        // Simulate a 0.2.1 install receiving 0.3.0 files: forget the phase-3 migration.
        Schema::dropIfExists('provisioning_jobs');
        Schema::dropIfExists('vpn_accounts');
        Schema::dropIfExists('ibs_groups');
        Schema::dropIfExists('ibs_charges');
        DB::table('migrations')->where('migration', 'like', '%create_ibs_tables')->delete();
        Cache::forget('system.pending_migrations');

        config(['panel.check_upgrade_in_tests' => true]);
        $this->actingAs($admin)->get('/')->assertRedirect(route('system.status'));      // no 500 before upgrading
        $this->actingAs($admin)->get('/accounts')->assertRedirect(route('system.status'));
        $this->actingAs($this->userWith(['dashboard.view']))->get('/')->assertStatus(503)->assertSee('در حال به‌روزرسانی');
        $this->actingAs($admin)->get('/system')->assertOk()->assertSee('اجرای به‌روزرسانی');
        $this->actingAs($admin)->post('/system/upgrade')->assertRedirect('/confirm-password');
        $this->actingAs($admin)->confirmed()->from('/system')->post('/system/upgrade')->assertSessionHas('success');
        $this->assertTrue(Schema::hasTable('vpn_accounts'));
        $this->actingAs($admin)->get('/')->assertOk()->assertDontSee('دیتابیس باید به‌روزرسانی شود');
        $this->actingAs($admin)->get('/accounts')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.upgraded']);
    }

    public function test_non_super_admin_cannot_upgrade(): void
    {
        $u = $this->userWith(['system.view', 'settings.sensitive']);
        $this->actingAs($u)->confirmed()->post('/system/upgrade')->assertForbidden();
    }
}
