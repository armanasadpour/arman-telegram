<?php

namespace Tests\Feature\Mikrotik;

use App\Models\MtAccount;
use App\Services\Mikrotik\MtAccounting;
use App\Services\Mikrotik\MtProvisioning;
use App\Services\Mikrotik\WgConfig;
use App\Services\Mikrotik\WgKeys;
use Illuminate\Support\Str;

class MtImportTest extends MikrotikTestCase
{
    public function test_existing_wireguard_peers_are_imported_without_touching_the_router(): void
    {
        $k = WgKeys::pair();
        $this->ros->menus['interface/wireguard/peers'][] = ['.id' => '*9', 'interface' => 'wg1', 'public-key' => $k['public'], 'allowed-address' => '10.20.0.9/32',
            'comment' => 'Reza Phone', 'rx' => '5000000', 'tx' => '9000000', 'last-handshake' => '30s', 'disabled' => 'false'];
        $this->ros->menus['interface/wireguard/peers'][] = ['.id' => '*A', 'interface' => 'other', 'public-key' => WgKeys::pair()['public'], 'allowed-address' => '10.9.9.9/32', 'rx' => '0', 'tx' => '0'];
        $before = count($this->ros->requests);
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/mt/groups')->assertSee('وارد کردن از روتر');
        $this->actingAs($admin)->get('/mt/groups/'.$this->wg->id.'/import')->assertOk()->assertSee('reza-phone')->assertSee('10.20.0.2')->assertDontSee('10.9.9.9');
        $this->actingAs($admin)->post('/mt/groups/'.$this->wg->id.'/import', ['ids' => ['*9', '*4'], 'days' => 30, 'volume_gb' => 50])->assertRedirect();
        $this->assertSame(0, collect(array_slice($this->ros->requests, $before))->where('method', '!=', 'GET')->count(), 'read-only on the router');

        $a = MtAccount::query()->where('username', 'reza-phone')->firstOrFail();
        $this->assertSame(['*9', '10.20.0.9', 'active', 30, 50 * 1073741824, $admin->id], [$a->ros_id, $a->wg_address, $a->status, $a->duration_days, $a->volume_limit_bytes, $a->created_by]);
        $this->assertSame(2, MtAccount::query()->count());
        $this->actingAs($admin)->get('/mt/groups/'.$this->wg->id.'/import')->assertSee('موردی برای وارد کردن نیست');

        // earlier traffic is not charged; new traffic is
        app(MtAccounting::class)->syncRouter($this->router->fresh());
        $this->assertSame(0, $a->fresh()->used_bytes);
        $this->ros->trafficWg($k['public'], 100, 200);
        app(MtAccounting::class)->syncRouter($this->router->fresh());
        $this->assertSame(300, $a->fresh()->used_bytes);
        $this->assertNotNull($a->fresh()->expires_at, 'clock started (peer is in use)');

        // no private key: page explains; pasting the right key enables the file
        $this->actingAs($admin)->confirmed()->get(route('mt.accounts.connect', $a))->assertSee('کلید خصوصی مشتری را نگه نمی‌دارد');
        $this->actingAs($admin)->confirmed()->from(route('mt.accounts.show', $a))->post(route('mt.accounts.private-key', $a), ['private_key' => WgKeys::pair()['private']])->assertSessionHas('error');
        $this->actingAs($admin)->confirmed()->from(route('mt.accounts.show', $a))->post(route('mt.accounts.private-key', $a), ['private_key' => $k['private']])->assertSessionHas('success');
        $this->assertStringContainsString('PrivateKey = '.$k['private'], WgConfig::build($a->fresh()));

        // a new panel account does not reuse the imported addresses
        $n = app(MtProvisioning::class)->create($this->wg, (string) Str::uuid(), ['username' => 'fresh1'], null);
        $this->assertSame('10.20.0.3', $n->wg_address);
    }

    public function test_existing_l2tp_users_are_imported(): void
    {
        $this->ros->menus['ppp/secret'][0]['service'] = 'l2tp';
        $this->ros->connectL2tp('manual1');
        $this->ros->trafficL2tp('manual1', 1000, 1000);
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/mt/groups/'.$this->l2tp->id.'/import')->assertOk()->assertSee('manual1');
        $this->actingAs($admin)->post('/mt/groups/'.$this->l2tp->id.'/import', ['ids' => ['*1']])->assertRedirect();
        $a = MtAccount::query()->where('username', 'manual1')->firstOrFail();
        $this->assertSame(['x', null, null], [$a->password, $a->duration_days, $a->volume_limit_bytes]);
        app(MtAccounting::class)->syncRouter($this->router->fresh());
        $this->assertSame(0, $a->fresh()->used_bytes, 'the live session was baselined');
        $this->ros->trafficL2tp('manual1', 10, 20);
        app(MtAccounting::class)->syncRouter($this->router->fresh());
        $this->assertSame(30, $a->fresh()->used_bytes);
    }

    public function test_import_needs_permission(): void
    {
        $this->actingAs($this->userWith(['mt_accounts.view']))->get('/mt/groups/'.$this->wg->id.'/import')->assertForbidden();
        $this->actingAs($this->userWith(['mt_accounts.view']))->post('/mt/groups/'.$this->wg->id.'/import', ['ids' => ['*4']])->assertForbidden();
    }
}
