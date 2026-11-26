<?php

namespace Tests\Feature;

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
        $this->actingAs($admin)->get('/accounts/'.$a->id)->assertSee('5.120.1.2')->assertSee('همه نشست‌های فعال این اکانت آفلاین می‌شوند')->assertSee('conn-card is-online', false);
        $this->actingAs($admin)->from('/accounts/'.$a->id)->post('/accounts/'.$a->id.'/kick')->assertSessionHas('success');
        $this->assertFalse($a->fresh()->is_online);
        $this->assertCount(1, $this->fake->onlines, 'only this account was disconnected');
        $this->actingAs($admin)->get('/accounts/'.$a->id)->assertSee('conn-card is-offline', false);
        $this->actingAs($admin)->get('/accounts')->assertDontSee('قطع همه نشست‌ها');
    }

    public function test_l2tp_and_wireguard_ip_logs_and_l2tp_kick(): void
    {
        $l = app(MtProvisioning::class)->create($this->l2tp, (string) Str::uuid(), ['username' => 'lip', 'comment' => 'x'], null);
        $w = app(MtProvisioning::class)->create($this->wg, (string) Str::uuid(), ['username' => 'wip', 'comment' => 'x'], null);
        $this->ros->connectL2tp('lip', '91.92.93.94');
        $this->ros->trafficWg($w->wg_public_key, 10, 10);   // endpoint 7.7.7.7
        app(MtAccounting::class)->syncRouter($this->router->fresh());
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/mt/accounts/'.$l->id)->assertSee('91.92.93.94')->assertSee('منصرف شدم');
        $this->actingAs($admin)->get('/mt/accounts/'.$w->id)->assertSee('7.7.7.7')->assertDontSee('همه نشست‌های فعال این اکانت آفلاین می‌شوند');
        $this->actingAs($admin)->get('/l2tp/accounts')->assertDontSee('قطع همه نشست‌ها');
        $this->actingAs($admin)->from('/mt/accounts/'.$l->id)->post('/mt/accounts/'.$l->id.'/kick')->assertSessionHas('success');
        $this->assertEmpty($this->ros->menus['ppp/active']);
        $this->ros->connectL2tp('lip', '91.92.93.94');
        app(MtAccounting::class)->syncRouter($this->router->fresh());
        $this->assertSame(1, \DB::table('connection_logs')->where('account_key', 'm:'.$l->id)->count(), 'same IP: one row');

    }

    public function test_account_page_shows_live_online_state_without_waiting_for_cron(): void
    {
        $this->syncCatalog();
        $this->ibsUser('live1');
        $a = app(AccountSync::class)->importByUsername('live1');
        $this->assertFalse($a->is_online);
        $this->fake->connect('live1', stationIp: '2.2.2.2');   // no onlines sync has run
        $this->actingAs($this->superAdmin())->get('/accounts/'.$a->id)->assertSee('conn-card is-online', false)->assertSee('2.2.2.2');
        $this->assertTrue($a->fresh()->is_online);
    }

    public function test_l2tp_online_from_ppp_active_even_when_l2tp_server_list_has_no_user(): void
    {
        $l = app(MtProvisioning::class)->create($this->l2tp, (string) Str::uuid(), ['username' => 'nouser', 'comment' => 'x'], null);
        $this->ros->connectL2tp('nouser', '8.8.4.4');
        $this->ros->trafficL2tp('nouser', 100, 200);
        $this->ros->menus['interface/l2tp-server'] = array_map(fn ($x) => array_diff_key($x, ['user' => 1]), $this->ros->menus['interface/l2tp-server']);
        $l->forceFill(['last_synced_at' => now()->subMinute()])->save();
        $this->actingAs($this->superAdmin())->get('/mt/accounts/'.$l->id)->assertSee('conn-card is-online', false)->assertSee('8.8.4.4');
        $this->assertSame(300, $l->fresh()->used_bytes, 'bytes found by interface name');
    }

    public function test_openvpn_kick_falls_back_to_the_router_and_reports_when_still_connected(): void
    {
        $this->syncCatalog();
        $this->ibsUser('stuck');
        $a = app(AccountSync::class)->importByUsername('stuck');
        $this->fake->connect('stuck');
        $this->fake->killDoesNothing = true;
        $admin = $this->superAdmin();
        // no OpenVPN session on the connected router: the panel must say it is still connected
        $this->actingAs($admin)->from('/accounts/'.$a->id)->post('/accounts/'.$a->id.'/kick')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'هنوز وصل است'));
        $this->assertTrue($a->fresh()->is_online);

        // the session exists on the router: removed there directly
        $this->ros->menus['ppp/active'][] = ['.id' => '*AA', 'name' => 'stuck', 'service' => 'ovpn', 'caller-id' => '1.2.3.4', 'uptime' => '5m'];
        $this->fake->killDoesNothing = false;
        $this->actingAs($admin)->from('/accounts/'.$a->id)->post('/accounts/'.$a->id.'/kick')->assertSessionHas('success');
        $this->assertSame([], array_values(array_filter($this->ros->menus['ppp/active'], fn ($x) => $x['name'] === 'stuck')));
    }
}
