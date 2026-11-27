<?php

namespace Tests\Feature\Mikrotik;

use App\Bots\Engine;
use App\Models\BotOutbox;
use App\Models\BotUser;
use App\Models\MtAccount;
use App\Models\Package;
use App\Models\ResellerSale;
use App\Services\Alerts\Alerts;
use App\Services\Finance\CustomerOrders;
use App\Services\Finance\InsufficientBalanceException;
use App\Services\Finance\ResellerSales;
use App\Services\Finance\WalletService;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Bots\BotTestCase;

class MtSalesTest extends BotTestCase
{
    use MikrotikFixture;

    private Package $l2Pkg;

    private Package $wgPkg;

    private Package $mtRenew;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMikrotik();
        $this->l2Pkg = Package::query()->create(['name' => 'L2TP ماهانه', 'type' => 'new', 'protocol' => 'mikrotik', 'mt_group_id' => $this->l2tp->id,
            'duration_days' => 30, 'volume_gb' => 20, 'price_rial' => 500_000]);
        $this->wgPkg = Package::query()->create(['name' => 'WG ماهانه', 'type' => 'new', 'protocol' => 'mikrotik', 'mt_group_id' => $this->wg->id,
            'duration_days' => 30, 'price_rial' => 700_000]);
        $this->mtRenew = Package::query()->create(['name' => 'تمدید میکروتیک', 'type' => 'renewal', 'protocol' => 'mikrotik', 'duration_days' => 30, 'volume_gb' => 10, 'price_rial' => 400_000]);
    }

    private function sellMt($r, $pkg, $target = null, ?string $key = null): array
    {
        return app(ResellerSales::class)->sell($r, $pkg, $key ?? (string) Str::uuid(), 900_000, ['customer_name' => 'رضا', 'username' => null], $target, $r->user_id);
    }

    public function test_reseller_sells_l2tp_debits_and_owns_account(): void
    {
        $r = $this->reseller(creditRial: 2_000_000);
        ['sale' => $s, 'password' => $pw] = $this->sellMt($r, $this->l2Pkg);
        $this->assertSame('completed', $s->status);
        $a = MtAccount::query()->findOrFail($s->mt_account_id);
        $this->assertSame([$r->id, 'active', 20 * $this->GB, 30], [$a->reseller_id, $a->status, $a->volume_limit_bytes, $a->duration_days]);
        $this->assertSame($pw, $this->ros->find('ppp/secret', 'name', $a->username)['password']);
        $this->assertSame(1_500_000, $this->balance($r));
        $this->assertSame('mikrotik', $s->price_snapshot['package']['protocol'] ?? $s->price_snapshot['protocol'] ?? 'mikrotik');
    }

    public function test_router_down_holds_money_then_cron_completes_sale(): void
    {
        $r = $this->reseller(creditRial: 1_000_000);
        $this->ros->down = true;
        ['sale' => $s] = $this->sellMt($r, $this->wgPkg);
        $this->assertSame('pending', $s->status);
        $this->assertSame(300_000, $this->balance($r), 'held, not refunded');
        $this->ros->down = false;
        MtAccount::query()->update(['updated_at' => now()->subMinutes(2)]);
        $this->artisan('mt:accounting')->assertSuccessful();
        $this->assertSame('completed', $s->fresh()->status);
        $this->assertSame(300_000, $this->balance($r));
    }

    public function test_router_refusal_refunds(): void
    {
        $r = $this->reseller(creditRial: 1_000_000);
        $this->ros->failNext['PUT ppp/secret'] = 'failure: input does not match any value of profile';
        ['sale' => $s] = $this->sellMt($r, $this->l2Pkg);
        $this->assertSame('refunded', $s->status);
        $this->assertSame(1_000_000, $this->balance($r));
    }

    public function test_insufficient_balance_creates_nothing_on_router(): void
    {
        $r = $this->reseller(creditRial: 100);
        try {
            $this->sellMt($r, $this->l2Pkg);
            $this->fail('expected exception');
        } catch (InsufficientBalanceException) {
        }
        $this->assertSame(0, MtAccount::query()->count());
        $this->assertCount(1, $this->ros->menus['ppp/secret'], 'only the manual one');
    }

    public function test_renewal_rules_between_protocols(): void
    {
        $r = $this->reseller(creditRial: 5_000_000);
        ['sale' => $s] = $this->sellMt($r, $this->l2Pkg);
        $a = MtAccount::query()->find($s->mt_account_id);
        ['sale' => $renew] = $this->sellMt($r, $this->mtRenew, $a);
        $this->assertSame('completed', $renew->status);
        $this->assertSame(60, $a->fresh()->duration_days, 'not started yet: period extended');
        $this->assertSame(30 * $this->GB, $a->fresh()->volume_limit_bytes);

        $this->expectException(RuntimeException::class);
        $this->sellMt($r, $this->renewPkg, $a);   // OpenVPN renewal on a MikroTik account
    }

    public function test_other_reseller_cannot_renew_or_see(): void
    {
        $a = $this->reseller('shopa', creditRial: 5_000_000);
        $b = $this->reseller('shopb', creditRial: 5_000_000);
        ['sale' => $s] = $this->sellMt($a, $this->wgPkg);
        $acc = MtAccount::query()->find($s->mt_account_id);
        $this->actingAs($a->user)->get('/r/mt/'.$acc->id)->assertOk()->assertSee('PrivateKey')->assertSee('<svg', false);
        $this->actingAs($b->user)->get('/r/mt/'.$acc->id)->assertNotFound();
        $this->actingAs($b->user)->get('/r/sell?mt='.$acc->id)->assertNotFound();
        $this->actingAs($b->user)->post('/r/sell', ['sale_key' => (string) Str::uuid(), 'package_id' => $this->mtRenew->id, 'mt_account_id' => $acc->id, 'sale_price_toman' => 1])->assertNotFound();
        $this->actingAs($b->user)->get('/r/accounts')->assertOk()->assertDontSee($acc->username);
    }

    public function test_portal_sell_shows_wireguard_config_once(): void
    {
        $r = $this->reseller(creditRial: 3_000_000);
        $this->actingAs($r->user)->get('/r/sell')->assertOk()->assertSee('WG ماهانه')->assertSee('WireGuard');
        $res = $this->actingAs($r->user)->post('/r/sell', ['sale_key' => (string) Str::uuid(), 'package_id' => $this->wgPkg->id, 'sale_price_toman' => 90000, 'customer_name' => 'مشتری']);
        $sale = ResellerSale::query()->firstOrFail();
        $res->assertRedirect(route('portal.sales.show', $sale));
        $this->followRedirects($res)->assertSee('PrivateKey')->assertSee('Endpoint = ir11.example.com:13231');
        $this->actingAs($r->user)->get(route('portal.sales.show', $sale))->assertDontSee('PrivateKey');
    }

    public function test_customer_order_buys_wireguard_and_gets_config_by_bot(): void
    {
        $this->bot();
        $this->tg('/start', chat: '901');
        $bu = BotUser::query()->where('chat_id', '901')->firstOrFail();
        app(WalletService::class)->post(app(WalletService::class)->walletFor('customer', $bu->id), 700_000, 'manual_credit', 'seed:c');
        $o = app(CustomerOrders::class)->create($bu, $this->wgPkg);
        $o = app(CustomerOrders::class)->fulfil($o);
        $this->assertSame('completed', $o->status);
        $acc = MtAccount::query()->findOrFail($o->mt_account_id);
        $this->assertTrue($bu->mtAccounts->contains($acc->id));
        $msg = BotOutbox::query()->where('sensitive', true)->latest('id')->firstOrFail();
        $this->assertStringContainsString('PrivateKey = '.$acc->wg_private_key, $msg->text);

        // "my accounts" shows it with meters
        $this->tg(Engine::MY_ACCOUNTS, chat: '901');
        $this->assertStringContainsString($acc->username, $this->lastText());
        $this->assertStringContainsString('WireGuard', $this->lastText());
    }

    public function test_customer_links_existing_l2tp_account_in_bot(): void
    {
        $r = $this->reseller(creditRial: 2_000_000);
        ['sale' => $s, 'password' => $pw] = $this->sellMt($r, $this->l2Pkg);
        $acc = MtAccount::query()->find($s->mt_account_id);
        $this->bot();
        $this->tg('/start', chat: '902');
        $this->tg(Engine::LINK, chat: '902');
        $this->tg($acc->username, chat: '902');
        $this->tg('wrong', chat: '902');
        $this->assertStringContainsString('درست نیست', $this->lastText());
        $this->tg(Engine::LINK, chat: '902');
        $this->tg($acc->username, chat: '902');
        $this->tg($pw, chat: '902');
        $this->assertStringContainsString('اضافه شد', $this->lastText());
        $this->assertTrue(BotUser::query()->where('chat_id', '902')->first()->mtAccounts->contains($acc->id));
    }

    public function test_expiry_alert_reaches_linked_customer(): void
    {
        $this->bot();
        $this->tg('/start', chat: '903');
        $bu = BotUser::query()->where('chat_id', '903')->firstOrFail();
        $r = $this->reseller(creditRial: 2_000_000);
        ['sale' => $s] = $this->sellMt($r, $this->l2Pkg);
        $acc = MtAccount::query()->find($s->mt_account_id);
        $acc->forceFill(['expires_at' => now()->addHours(20), 'first_used_at' => now()->subDays(29), 'last_synced_at' => now()])->save();
        $bu->mtAccounts()->attach($acc->id, ['created_at' => now()]);
        $n = app(Alerts::class)->mtCustomerAlerts();
        $this->assertSame(1, $n);
        $this->assertSame(0, app(Alerts::class)->mtCustomerAlerts(), 'once');
        $this->assertStringContainsString($acc->username, BotOutbox::query()->latest('id')->value('text'));
    }
}
