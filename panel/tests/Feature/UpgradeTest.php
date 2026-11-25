<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UpgradeTest extends TestCase
{
    /** Simulate a 0.2.1 install that just received the 0.3.0 files. */
    /** 1.2 tables reference older ones (bot_users, resellers, packages): remove them first, like an install from before 1.2. */
    private function dropMikrotik(): void
    {
        if (Schema::hasColumn('packages', 'mt_group_id')) {
            Schema::table('packages', fn ($t) => $t->dropConstrainedForeignId('mt_group_id'));
            Schema::table('packages', fn ($t) => $t->dropColumn('protocol'));
        }
        foreach (['reseller_sales', 'customer_orders'] as $t) {
            if (Schema::hasColumn($t, 'mt_account_id')) {
                Schema::table($t, fn ($b) => $b->dropConstrainedForeignId('mt_account_id'));
            }
        }
        foreach (['bot_mt_links', 'mt_usage_daily', 'mt_accounts', 'mt_groups'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::table('migrations')->where('migration', 'like', '%create_mikrotik_accounts')->delete();
    }

    private function makePending(bool $halfFinished = false): void
    {
        $this->dropMikrotik();
        // Later-phase tables reference these through foreign keys (MariaDB enforces the order).
        foreach (['customer_orders', 'payments', 'bot_link_codes', 'bot_outbox', 'bot_updates', 'bot_account_links', 'bot_users', 'bots', 'reseller_sales', 'reseller_category_discounts', 'reseller_prices', 'ledger_entries', 'wallets', 'provisioning_jobs', 'vpn_accounts', 'resellers'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::table('migrations')->where('migration', 'like', '%create_reseller_tables')->delete();
        DB::table('migrations')->where('migration', 'like', '%create_bot_tables')->delete();
        DB::table('migrations')->where('migration', 'like', '%create_payment_tables')->delete();
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
        $this->assertTrue(Schema::hasTable('bot_account_links'));
        $this->actingAs($admin)->get('/bots')->assertOk();
        $this->assertTrue(Schema::hasColumn('bot_outbox', 'sensitive'));
        $this->actingAs($admin)->get('/payments')->assertOk();
    }

    public function test_upgrade_from_040_adds_reseller_tables(): void
    {
        $admin = $this->superAdmin();
        $this->dropMikrotik();
        foreach (['customer_orders', 'payments', 'reseller_sales', 'reseller_category_discounts', 'reseller_prices', 'ledger_entries', 'wallets'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('vpn_accounts', fn ($t) => $t->dropConstrainedForeignId('reseller_id'));
        Schema::dropIfExists('resellers');
        DB::table('migrations')->where('migration', 'like', '%create_reseller_tables')->delete();
        DB::table('migrations')->where('migration', 'like', '%create_payment_tables')->delete();
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
