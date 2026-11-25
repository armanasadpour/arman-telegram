<?php

namespace Tests\Feature\Finance;

use App\Models\Reseller;
use App\Models\ResellerSale;
use App\Models\VpnAccount;
use Illuminate\Support\Str;

class ResellerHttpTest extends ResellerTestCase
{
    public function test_admin_creates_reseller_who_logs_into_portal(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/resellers', ['name' => 'فروشگاه نمونه', 'username' => 'shopx', 'password' => 'ShopPass123',
            'password_confirmation' => 'ShopPass123', 'global_discount_percent' => 15])->assertRedirect();
        $r = Reseller::query()->where('name', 'فروشگاه نمونه')->firstOrFail();
        $this->assertSame('R'.(1000 + $r->id), $r->code);
        $this->post('/logout');

        $this->post('/login', ['username' => 'shopx', 'password' => 'ShopPass123'])->assertRedirect('/');
        $this->get('/')->assertRedirect('/r');
        $this->get('/r')->assertOk()->assertSee('فروشگاه نمونه');
        foreach (['/staff', '/accounts', '/packages', '/resellers', '/audit', '/settings'] as $adminPage) {
            $this->get($adminPage)->assertForbidden();
        }
    }

    public function test_admin_pages_are_not_portal_and_staff_form_hides_reseller_role(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/r')->assertForbidden();
        $this->actingAs($admin)->get('/staff/create')->assertOk()->assertDontSee('همکار فروش');
    }

    public function test_manual_adjust_requires_permission_confirmation_and_reason(): void
    {
        $r = $this->reseller();
        $viewer = $this->userWith(['resellers.view', 'wallets.view']);
        $this->actingAs($viewer)->confirmed()->post("/resellers/{$r->id}/adjust", ['direction' => 'credit', 'amount_toman' => 100, 'reason' => 'x x x', 'op_key' => (string) Str::uuid()])->assertForbidden();

        $admin = $this->superAdmin();
        $payload = ['direction' => 'credit', 'amount_toman' => 250000, 'reason' => 'واریز کارت', 'op_key' => (string) Str::uuid()];
        $this->flushSession();
        $this->actingAs($admin)->from("/resellers/{$r->id}")->post("/resellers/{$r->id}/adjust", $payload)->assertRedirect('/confirm-password');
        $this->assertSame(0, $this->balance($r));
        $this->actingAs($admin)->confirmed()->from("/resellers/{$r->id}")->post("/resellers/{$r->id}/adjust", ['reason' => ''] + $payload)->assertSessionHas('success');   // reason optional
        $this->actingAs($admin)->confirmed()->from("/resellers/{$r->id}")->post("/resellers/{$r->id}/adjust", $payload)->assertSessionHas('success');
        $this->actingAs($admin)->confirmed()->from("/resellers/{$r->id}")->post("/resellers/{$r->id}/adjust", $payload)->assertSessionHas('success');
        $this->assertSame(2_500_000, $this->balance($r), 'double submit with the same op_key credits once');
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallets.adjust', 'reason' => 'از پنل']);

        $this->actingAs($admin)->confirmed()->from("/resellers/{$r->id}")->post("/resellers/{$r->id}/adjust",
            ['direction' => 'debit', 'amount_toman' => 999999, 'reason' => 'too much', 'op_key' => (string) Str::uuid()])->assertSessionHas('error');
        $this->assertSame(2_500_000, $this->balance($r));
    }

    public function test_portal_sell_flow_and_data_isolation(): void
    {
        $a = $this->reseller('shopa', creditRial: 3_000_000);
        $b = $this->reseller('shopb', creditRial: 3_000_000);

        $this->actingAs($a->user)->get('/r/sell')->assertOk()->assertSee('یک‌ماهه');
        $r = $this->actingAs($a->user)->post('/r/sell', ['sale_key' => (string) Str::uuid(), 'package_id' => $this->newPkg->id,
            'sale_price_toman' => 130000, 'customer_name' => 'مشتری الف']);
        $sale = ResellerSale::query()->firstOrFail();
        $r->assertRedirect(route('portal.sales.show', $sale))->assertSessionHas('new_password');
        $acc = VpnAccount::query()->findOrFail($sale->vpn_account_id);

        $this->actingAs($a->user)->get("/r/accounts/{$acc->id}")->assertOk();
        $this->actingAs($a->user)->get("/r/sales/{$sale->id}")->assertOk()->assertSee('مشتری الف');
        $this->actingAs($a->user)->get('/r/ledger')->assertOk();

        // Reseller B sees nothing of A's.
        $this->actingAs($b->user)->get("/r/accounts/{$acc->id}")->assertNotFound();
        $this->actingAs($b->user)->get("/r/sales/{$sale->id}")->assertNotFound();
        $this->actingAs($b->user)->get('/r/accounts')->assertOk()->assertDontSee($acc->username);
        $this->actingAs($b->user)->get("/r/sell?account={$acc->id}")->assertNotFound();
        $this->actingAs($b->user)->post('/r/sell', ['sale_key' => (string) Str::uuid(), 'package_id' => $this->renewPkg->id,
            'account_id' => $acc->id, 'sale_price_toman' => 1])->assertNotFound();
        $this->assertSame(3_000_000, $this->balance($b));
    }

    public function test_admin_reseller_page_walk(): void
    {
        $r = $this->reseller(creditRial: 1_000_000);
        $admin = $this->superAdmin();
        foreach (['/resellers', '/resellers/create', "/resellers/{$r->id}"] as $u) {
            $this->actingAs($admin)->get($u)->assertOk();
        }
        $this->actingAs($admin)->get("/resellers/{$r->id}")->assertSee('تطبیق با دفتر کل: درست');
    }
}
