<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UpgradeTest extends TestCase
{
    /** Simulate a 0.2.1 install that just received the 0.3.0 files. */
    private function makePending(bool $halfFinished = false): void
    {
        // Later-phase tables reference these through foreign keys (MariaDB enforces the order).
        foreach (['reseller_sales', 'reseller_category_discounts', 'reseller_prices', 'ledger_entries', 'wallets', 'provisioning_jobs', 'vpn_accounts', 'resellers'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::table('migrations')->where('migration', 'like', '%create_reseller_tables')->delete();
        if (! $halfFinished) {
            Schema::dropIfExists('ibs_groups');
            Schema::dropIfExists('ibs_charges');
        }
        DB::table('migrations')->where('migration', 'like', '%create_ibs_tables')->delete();
        Cache::forget('system.pending_migrations');
        config(['panel.check_upgrade_in_tests' => true]);
    }

    public function test_logged_out_super_admin_can_log_in_and_upgrade_in_one_step(): void
    {
        $this->superAdmin(['username' => 'boss']);
        $this->makePending();

        // Regression: POST /login used to be blocked (503) while an upgrade was pending.
        $this->get('/login')->assertOk();
        $this->post('/login', ['username' => 'boss', 'password' => 'Password12345'])->assertRedirect('/');
        $this->get('/')->assertRedirect(route('system.status'));
        $this->get('/system')->assertOk()->assertSee('اجرای به‌روزرسانی')->assertSee('upgrade_password');

        $this->from('/system')->post('/system/upgrade', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertFalse(Schema::hasTable('vpn_accounts'));

        $this->from('/system')->post('/system/upgrade', ['password' => 'Password12345'])->assertSessionHas('success');
        $this->assertTrue(Schema::hasTable('vpn_accounts'));
        $this->get('/')->assertOk();
        $this->get('/accounts')->assertOk();
        $this->get('/system')->assertDontSee('upgrade_password');
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.upgraded']);
    }

    public function test_upgrade_completes_after_a_half_finished_previous_attempt(): void
    {
        $admin = $this->superAdmin();
        $this->makePending(halfFinished: true); // ibs_charges/ibs_groups exist, migration not recorded
        $this->actingAs($admin)->from('/system')->post('/system/upgrade', ['password' => 'Password12345'])->assertSessionHas('success');
        $this->assertTrue(Schema::hasTable('provisioning_jobs'));
        $this->assertTrue(Schema::hasColumn('vpn_accounts', 'reseller_id'));
    }

    public function test_upgrade_from_040_adds_reseller_tables(): void
    {
        $admin = $this->superAdmin();
        foreach (['reseller_sales', 'reseller_category_discounts', 'reseller_prices', 'ledger_entries', 'wallets'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('vpn_accounts', fn ($t) => $t->dropConstrainedForeignId('reseller_id'));
        Schema::dropIfExists('resellers');
        DB::table('migrations')->where('migration', 'like', '%create_reseller_tables')->delete();
        Cache::forget('system.pending_migrations');
        config(['panel.check_upgrade_in_tests' => true]);

        $this->actingAs($admin)->from('/system')->post('/system/upgrade', ['password' => 'Password12345'])->assertSessionHas('success');
        $this->assertTrue(Schema::hasTable('ledger_entries'));
        $this->assertTrue(Schema::hasColumn('vpn_accounts', 'reseller_id'));
        $this->actingAs($admin)->get('/resellers')->assertOk();
    }

    public function test_others_see_maintenance_and_cannot_upgrade(): void
    {
        $u = $this->userWith(['dashboard.view', 'system.view']);
        $this->makePending();
        $this->actingAs($u)->get('/')->assertStatus(503)->assertSee('در حال به‌روزرسانی');
        $this->actingAs($u)->post('/system/upgrade', ['password' => 'Password12345'])->assertForbidden();
    }
}
