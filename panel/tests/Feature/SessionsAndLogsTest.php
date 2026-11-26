<?php

namespace Tests\Feature;

use App\Models\MtAccount;
use App\Models\VpnAccount;
use App\Services\Ibs\AccountSync;
use App\Services\Mikrotik\MtAccounting;
use App\Services\Mikrotik\MtProvisioning;
use Illuminate\Support\Str;
use Tests\Feature\Ibs\IbsTestCase;
use Tests\Feature\Mikrotik\MikrotikFixture;

class SessionsAndLogsTest extends IbsTestCase
{
    use MikrotikFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMikrotik();
    }

    public function test_openvpn_kick_and_ip_log(): void
    {
        $this->syncCatalog();
        $this->ibsUser('ovp');
        $this->ibsUser('ovp2');
        $a = app(AccountSync::class)->importByUsername('ovp');
        app(AccountSync::class)->importByUsername('ovp2');
        $this->fake->connect('ovp', stationIp: '5.120.1.2');
        $this->fake->connect('ovp2', stationIp: '37.1.1.1');
        app(AccountSync::class)->syncOnlines();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/accounts/'.$a->id)->assertSee('5.120.1.2')->assertSee('قطع همه نشست‌های آنلاین');
        $this->actingAs($admin)->from('/accounts/'.$a->id)->post('/accounts/'.$a->id.'/kick')->assertSessionHas('success');
        $this->assertFalse($a->fresh()->is_online);
        $this->assertCount(1, $this->fake->onlines, 'only this account was disconnected');
        $this->actingAs($admin)->from('/accounts')->post('/accounts/kick-all')->assertSessionHas('success');
        $this->assertCount(0, $this->fake->onlines);
        $this->assertSame(0, VpnAccount::query()->where('is_online', true)->count());
    }

    public function test_l2tp_and_wireguard_ip_logs_and_l2tp_kick(): void
    {
        $l = app(MtProvisioning::class)->create($this->l2tp, (string) Str::uuid(), ['username' => 'lip', 'comment' => 'x'], null);
        $w = app(MtProvisioning::class)->create($this->wg, (string) Str::uuid(), ['username' => 'wip', 'comment' => 'x'], null);
        $this->ros->connectL2tp('lip', '91.92.93.94');
        $this->ros->trafficWg($w->wg_public_key, 10, 10);   // endpoint 7.7.7.7
        app(MtAccounting::class)->syncRouter($this->router->fresh());
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/mt/accounts/'.$l->id)->assertSee('91.92.93.94')->assertSee('قطع همه نشست‌های آنلاین');
        $this->actingAs($admin)->get('/mt/accounts/'.$w->id)->assertSee('7.7.7.7')->assertDontSee('قطع همه نشست‌های آنلاین');
        $this->actingAs($admin)->get('/l2tp/accounts')->assertSee('قطع همه نشست‌ها');
        $this->actingAs($admin)->from('/mt/accounts/'.$l->id)->post('/mt/accounts/'.$l->id.'/kick')->assertSessionHas('success');
        $this->assertEmpty($this->ros->menus['ppp/active']);
        $this->ros->connectL2tp('lip', '91.92.93.94');
        app(MtAccounting::class)->syncRouter($this->router->fresh());
        $this->actingAs($admin)->from('/l2tp/accounts')->post('/mt/l2tp/kick-all')->assertSessionHas('success');
        $this->assertEmpty($this->ros->menus['ppp/active']);
        $this->assertSame(1, \DB::table('connection_logs')->where('account_key', 'm:'.$l->id)->count(), 'same IP: one row');
        $this->assertFalse(MtAccount::query()->find($l->id)->is_online);
    }
}
