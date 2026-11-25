<?php

namespace Tests\Feature\Mikrotik;

use App\Models\MtAccount;
use App\Models\MtDevice;
use App\Models\Package;
use App\Services\Finance\CustomerOrders;
use App\Services\Mikrotik\MtAccounting;
use App\Services\Mikrotik\MtActions;
use App\Services\Mikrotik\MtProvisioning;
use App\Services\Mikrotik\WgConfig;
use Illuminate\Support\Str;

class MtDevicesTest extends MikrotikTestCase
{
    private function poll(): void
    {
        app(MtAccounting::class)->syncRouter($this->router->fresh());
    }

    private function peerOf($dev): ?array
    {
        return $this->ros->find('interface/wireguard/peers', 'public-key', ($dev->fresh() ?? $dev)->wg_public_key);
    }

    public function test_three_devices_share_one_account(): void
    {
        $a = app(MtProvisioning::class)->create($this->wg, (string) Str::uuid(), ['username' => 'family', 'days' => 10, 'volume_gb' => 1, 'multi_login' => 3], null);
        $this->assertSame(['active', 3], [$a->status, $a->deviceCount()]);
        $devs = $a->fresh()->allDevices();
        $this->assertCount(3, $devs);
        $ips = array_map(fn ($d) => $d->wg_address, $devs);
        $this->assertSame(['10.20.0.3', '10.20.0.4', '10.20.0.5'], $ips);
        foreach ($devs as $d) {
            $this->assertNotNull($this->peerOf($d), 'peer on router for device '.$d->deviceNo());
        }
        $this->assertNotSame(WgConfig::build($a), WgConfig::build($a, $devs[1]));

        // usage of any device counts for the account
        $this->ros->trafficWg($devs[1]->wg_public_key, 300 * 1048576, 300 * 1048576);
        $this->ros->trafficWg($devs[2]->wg_public_key, 100 * 1048576, 0);
        $this->poll();
        $a->refresh();
        $this->assertSame(700 * 1048576, $a->used_bytes);
        $this->assertSame(2, $a->online_count);
        $this->assertNotNull($a->expires_at);
        $this->poll();
        $this->assertSame(700 * 1048576, $a->fresh()->used_bytes, 'no double counting');

        // over the shared volume: every device is cut
        $this->ros->trafficWg($devs[0]->wg_public_key, 400 * 1048576, 0);
        $this->poll();
        $this->assertSame('depleted', $a->fresh()->status);
        foreach ($devs as $d) {
            $this->assertSame('true', $this->peerOf($d)['disabled']);
        }
        app(MtActions::class)->addVolume($a->fresh(), 5, 'x');
        foreach ($devs as $d) {
            $this->assertSame('false', $this->peerOf($d)['disabled']);
        }

        // fewer devices: the highest one is removed from the router
        app(MtActions::class)->setLimits($a->fresh(), null, 6, 2, 'x');
        $this->assertSame(2, $a->fresh()->deviceCount());
        $this->assertNull($this->peerOf($devs[2]));
        $this->assertSame(1, MtDevice::query()->count());
        // more devices again: a fresh key and address
        app(MtActions::class)->setLimits($a->fresh(), null, 6, 3, 'x');
        $this->assertSame(2, MtDevice::query()->count());

        // a device peer deleted by hand on the router comes back
        $d2 = MtDevice::query()->where('idx', 2)->first();
        $this->ros->menus['interface/wireguard/peers'] = array_values(array_filter($this->ros->menus['interface/wireguard/peers'], fn ($p) => $p['public-key'] !== $d2->wg_public_key));
        $this->poll();
        $this->poll();
        $this->assertNotNull($this->peerOf($d2));

        // new key for one device only
        $old1 = $a->fresh()->wg_public_key;
        app(MtActions::class)->regenerateKeys($a->fresh(), 'x', 2);
        $this->assertSame($old1, $a->fresh()->wg_public_key);
        $this->assertNotSame($d2->wg_public_key, $d2->fresh()->wg_public_key);

        // delete removes every peer
        app(MtActions::class)->delete($a->fresh(), 'x');
        $this->assertCount(1, $this->ros->menus['interface/wireguard/peers'], 'only the manual peer is left');
        $this->assertSame(0, MtDevice::query()->count());
    }

    public function test_pages_and_bot_message_show_every_device(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/mt/accounts', ['comment' => 'مشتری تست', 'job_key' => (string) Str::uuid(), 'mt_group_id' => $this->wg->id, 'username' => 'two', 'multi_login' => 2]);
        $a = MtAccount::query()->firstOrFail();
        $this->actingAs($admin)->confirmed()->get(route('mt.accounts.connect', $a))->assertOk()->assertSee('دستگاه ۲ از ۲')->assertSee('two-2.conf');
        $this->assertSame(2, substr_count(CustomerOrders::mtCredentials($a), 'PrivateKey'));
    }

    public function test_package_device_count_and_group_default(): void
    {
        $this->wg->update(['multi_login' => 2]);
        $a = app(MtProvisioning::class)->create($this->wg->fresh(), (string) Str::uuid(), ['username' => 'dflt'], null);
        $this->assertSame(2, $a->deviceCount());
        $p = Package::query()->create(['name' => 'WG ۳ دستگاه', 'type' => 'new', 'protocol' => 'mikrotik', 'mt_group_id' => $this->wg->id, 'duration_days' => 30, 'multi_login' => 3, 'price_rial' => 1]);
        $b = app(MtProvisioning::class)->create($this->wg->fresh(), (string) Str::uuid(), ['username' => 'pkg3', 'multi_login' => $p->multi_login], null);
        $this->assertSame(3, $b->deviceCount());
        $this->assertSame(3 + 2, count(array_filter($this->ros->menus['interface/wireguard/peers'], fn ($x) => str_contains($x['comment'] ?? '', 'panel:'))));
    }
}
