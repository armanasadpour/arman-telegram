<?php

namespace Tests\Feature\Finance;

use App\Models\Reseller;
use App\Models\VpnAccount;
use App\Services\Finance\ResellerSales;
use App\Services\Finance\WalletService;
use Illuminate\Support\Str;

class ResellerPrefixBulkTest extends ResellerTestCase
{
    public function test_prefix_is_required_permanent_and_applied_to_sold_accounts(): void
    {
        $admin = $this->superAdmin();
        $base = ['name' => 'سامان', 'username' => 'saman', 'password' => 'abcd1', 'password_confirmation' => 'abcd1'];
        $this->actingAs($admin)->post('/resellers', $base)->assertSessionHasErrors('account_prefix');
        $this->actingAs($admin)->post('/resellers', $base + ['account_prefix' => 'Sam!'])->assertSessionHasErrors('account_prefix');
        $this->actingAs($admin)->post('/resellers', $base + ['account_prefix' => 'sam'])->assertRedirect();
        $r = Reseller::query()->where('account_prefix', 'sam')->firstOrFail();

        // cannot be changed later
        $this->actingAs($admin)->put('/resellers/'.$r->id, ['name' => 'سامان', 'global_discount_percent' => 0, 'status' => 'active', 'account_prefix' => 'zzz'])->assertSessionHas('success');
        $this->assertSame('sam', $r->fresh()->account_prefix);
        $this->actingAs($admin)->get('/resellers/'.$r->id)->assertSee('قابل تغییر نیست');

        app(WalletService::class)->post(app(WalletService::class)->walletFor('reseller', $r->id), 5_000_000, 'manual_credit', 'seed');
        $sell = ['sale_key' => (string) Str::uuid(), 'package_id' => $this->newPkg->id, 'sale_price_toman' => '130,000', 'customer_name' => 'علی'];
        $this->actingAs($r->user)->get('/r/sell')->assertSee('un-prefix', false)->assertSee('>sam<', false);
        $this->actingAs($r->user)->post('/r/sell', $sell)->assertSessionHasErrors('username');   // username required
        $this->actingAs($r->user)->post('/r/sell', $sell + ['username' => 'ali'])->assertSessionHasNoErrors();
        $this->assertTrue(VpnAccount::query()->where('username', 'samali')->where('reseller_id', $r->id)->exists());
    }

    public function test_bulk_disable_enable_delete_and_report(): void
    {
        $admin = $this->superAdmin();
        $r = $this->reseller(creditRial: 5_000_000);
        $r->forceFill(['account_prefix' => 'shp'])->save();
        $sales = app(ResellerSales::class);
        foreach (['shpa1', 'shpa2'] as $u) {
            $sales->sell($r, $this->newPkg, (string) Str::uuid(), 1_300_000, ['username' => $u, 'customer_name' => 'x'], null, $r->user_id);
        }
        $ids = VpnAccount::query()->where('reseller_id', $r->id)->pluck('id')->map(fn ($i) => 'v:'.$i)->all();
        $this->assertCount(2, $ids);

        $page = $this->actingAs($admin)->get('/resellers/'.$r->id)->assertOk()->assertSee('گزارش کامل')->assertSee('shpa1')->assertSee('اجرا روی انتخاب‌شده‌ها');
        $this->actingAs($admin)->post('/resellers/'.$r->id.'/accounts/bulk', ['action' => 'disable', 'ids' => $ids])->assertSessionHas('success');
        $this->assertSame(2, VpnAccount::query()->where('reseller_id', $r->id)->where('locked', true)->count());
        $this->actingAs($admin)->post('/resellers/'.$r->id.'/accounts/bulk', ['action' => 'enable', 'ids' => $ids])->assertSessionHas('success');
        $this->assertSame(0, VpnAccount::query()->where('reseller_id', $r->id)->where('locked', true)->count());
        $this->actingAs($admin)->post('/resellers/'.$r->id.'/accounts/bulk', ['action' => 'delete', 'ids' => [$ids[0]]])->assertSessionHas('success');
        $this->assertSame(1, VpnAccount::query()->where('reseller_id', $r->id)->whereNull('ibs_deleted_at')->count());

        // another reseller's account cannot be touched through this reseller's page
        $other = $this->reseller('shop2');
        $this->actingAs($admin)->post('/resellers/'.$other->id.'/accounts/bulk', ['action' => 'disable', 'ids' => [$ids[1]]])->assertSessionHas('error');
        $this->actingAs($this->userWith(['resellers.view']))->post('/resellers/'.$r->id.'/accounts/bulk', ['action' => 'delete', 'ids' => [$ids[1]]])->assertForbidden();
    }
}
