<?php

namespace Tests\Feature\Mikrotik;

use App\Models\MtAccount;
use App\Services\Mikrotik\MtAccounting;
use App\Services\Mikrotik\MtActions;
use App\Services\Mikrotik\MtProvisioning;
use App\Services\Mikrotik\WgConfig;
use App\Services\Mikrotik\WgKeys;
use Illuminate\Support\Str;

class MtAccountsTest extends MikrotikTestCase
{
    private function create($group, array $spec = [], ?string $key = null): MtAccount
    {
        return app(MtProvisioning::class)->create($group, $key ?? (string) Str::uuid(), $spec + ['days' => 30, 'volume_gb' => 10], null);
    }

    private function poll(): array
    {
        return app(MtAccounting::class)->syncRouter($this->router->fresh());
    }

    public function test_l2tp_create_adds_secret_with_comment_and_profile(): void
    {
        $a = $this->create($this->l2tp, ['username' => 'ali', 'password' => 'any pass!']);
        $this->assertSame('active', $a->status);
        $s = $this->ros->find('ppp/secret', 'name', 'ali');
        $this->assertSame(['any pass!', 'l2tp', 'l2tp-profile', 'false'], [$s['password'], $s['service'], $s['profile'], $s['disabled']]);
        $this->assertStringContainsString('panel:'.$a->create_key, $s['comment']);
        $this->assertSame($s['.id'], $a->ros_id);
        $this->assertNull($a->expires_at, 'clock starts at first use');
        $this->assertNotSame('any pass!', \DB::table('mt_accounts')->value('password'), 'encrypted at rest');
    }

    public function test_same_key_twice_creates_once_and_lost_reply_is_adopted(): void
    {
        $key = (string) Str::uuid();
        $this->ros->loseNextReply = true;
        $a = $this->create($this->l2tp, ['username' => 'bob'], $key);
        $this->assertSame('pending', $a->status, 'reply lost: outcome unknown');
        $this->assertNotNull($this->ros->find('ppp/secret', 'name', 'bob'), 'router did create it');
        $again = $this->create($this->l2tp, ['username' => 'bob'], $key);
        $this->assertSame('active', $again->status);
        $this->assertSame($a->id, $again->id);
        $this->assertCount(1, array_filter($this->ros->menus['ppp/secret'], fn ($s) => $s['name'] === 'bob'));
        $this->assertSame(1, MtAccount::query()->count());
    }

    public function test_router_down_keeps_pending_then_cron_finishes(): void
    {
        $this->ros->down = true;
        $a = $this->create($this->l2tp, ['username' => 'carl']);
        $this->assertSame('pending', $a->status);
        $this->ros->down = false;
        $a->forceFill(['updated_at' => now()->subMinutes(2)])->save();
        $this->artisan('mt:accounting')->assertSuccessful();
        $this->assertSame('active', $a->fresh()->status);
    }

    public function test_existing_manual_secret_name_is_refused(): void
    {
        $a = $this->create($this->l2tp, ['username' => 'manual1']);
        $this->assertSame('failed', $a->status);
        $this->assertStringContainsString('از قبل روی روتر', $a->error);
    }

    public function test_wireguard_peer_keys_address_and_config(): void
    {
        $a = $this->create($this->wg, ['username' => 'wgu1']);
        $this->assertSame('active', $a->status);
        $this->assertSame('10.20.0.3', $a->wg_address, '.1 server, .2 used by a manual peer');
        $p = $this->ros->find('interface/wireguard/peers', 'public-key', $a->wg_public_key);
        $this->assertSame(['wg1', '10.20.0.3/32'], [$p['interface'], $p['allowed-address']]);
        $this->assertSame($a->wg_public_key, WgKeys::publicFrom($a->wg_private_key));
        $conf = WgConfig::build($a);
        $this->assertStringContainsString('PrivateKey = '.$a->wg_private_key, $conf);
        $this->assertStringContainsString('PublicKey = '.$this->serverPub, $conf);
        $this->assertStringContainsString('Endpoint = ir11.example.com:13231', $conf);
        $this->assertStringContainsString('Address = 10.20.0.3/32', $conf);
        $b = $this->create($this->wg, ['username' => 'wgu2']);
        $this->assertSame('10.20.0.4', $b->wg_address);
    }

    public function test_l2tp_accounting_sessions_first_use_and_volume_limit(): void
    {
        $a = $this->create($this->l2tp, ['username' => 'dan', 'volume_gb' => 1]);
        $this->ros->connectL2tp('dan');
        $this->ros->trafficL2tp('dan', 100 * 1024 * 1024, 300 * 1024 * 1024);
        $this->poll();
        $a->refresh();
        $this->assertSame(400 * 1024 * 1024, $a->used_bytes);
        $this->assertSame(100 * 1024 * 1024, $a->upload_bytes);
        $this->assertTrue($a->is_online);
        $this->assertNotNull($a->expires_at, 'first use started the 30 days');
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $a->expires_at->getTimestamp(), 5);

        $this->poll();   // nothing new: no double counting
        $this->assertSame(400 * 1024 * 1024, $a->fresh()->used_bytes);

        // reconnect: new session counters start from 0
        $this->ros->disconnectL2tp('dan');
        $this->ros->connectL2tp('dan');
        $this->ros->trafficL2tp('dan', 0, 700 * 1024 * 1024);
        $this->poll();
        $a->refresh();
        $this->assertSame(1100 * 1024 * 1024, $a->used_bytes);
        $this->assertSame('depleted', $a->status);
        $this->assertTrue($a->router_disabled);
        $this->assertSame('true', $this->ros->find('ppp/secret', 'name', 'dan')['disabled']);
        $this->assertEmpty($this->ros->menus['ppp/active'], 'live session kicked');
        $this->assertSame(1100 * 1024 * 1024, (int) \DB::table('mt_usage_daily')->where('mt_account_id', $a->id)->sum(\DB::raw('upload_bytes + download_bytes')));

        // top-up re-enables (base = what was used, so the full 2 GB is available)
        app(MtActions::class)->addVolume($a, 2, 'test');
        $a->refresh();
        $this->assertSame('active', $a->status);
        $this->assertSame(1100 * 1024 * 1024 + 2 * $this->GB, $a->volume_limit_bytes);
        $this->assertSame('false', $this->ros->find('ppp/secret', 'name', 'dan')['disabled']);
    }

    public function test_multi_login_drops_extra_sessions(): void
    {
        $this->create($this->l2tp, ['username' => 'eve', 'multi_login' => 1]);
        $this->ros->connectL2tp('eve', '1.1.1.1');
        $this->ros->connectL2tp('eve', '2.2.2.2');
        $r = $this->poll();
        $this->assertSame(1, $r['kicked']);
        $this->assertCount(1, $this->ros->menus['ppp/active']);
    }

    public function test_wireguard_accounting_handles_reboot_and_expiry(): void
    {
        $a = $this->create($this->wg, ['username' => 'wgu9', 'volume_gb' => null, 'days' => 10]);
        $this->ros->trafficWg($a->wg_public_key, 1000, 5000);
        $this->poll();
        $a->refresh();
        $this->assertSame(6000, $a->used_bytes);
        $this->assertTrue($a->is_online);
        $this->assertSame('7.7.7.7', $a->online_ip);
        $this->assertNull($a->volume_limit_bytes);

        $this->ros->trafficWg($a->wg_public_key, 500, 500);
        $this->poll();
        $this->assertSame(7000, $a->fresh()->used_bytes);

        $this->ros->reboot();
        $this->ros->trafficWg($a->wg_public_key, 100, 200);
        $this->poll();
        $this->assertSame(7300, $a->fresh()->used_bytes, 'after reboot the new counters are the delta');

        $this->travel(11)->days();
        $this->poll();
        $a->refresh();
        $this->assertSame('expired', $a->status);
        $this->assertSame('true', $this->ros->find('interface/wireguard/peers', 'public-key', $a->wg_public_key)['disabled']);

        app(MtActions::class)->renew($a, 30, 'test');
        $a->refresh();
        $this->assertSame('active', $a->status);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $a->expires_at->getTimestamp(), 5);
        $this->assertSame('false', $this->ros->find('interface/wireguard/peers', 'public-key', $a->wg_public_key)['disabled']);
    }

    public function test_manual_enable_on_router_is_reverted_and_missing_secret_is_recreated(): void
    {
        $a = $this->create($this->l2tp, ['username' => 'fay']);
        app(MtActions::class)->disable($a, 'test');
        $id = $this->ros->find('ppp/secret', 'name', 'fay')['.id'];
        $this->ros->menus['ppp/secret'] = array_map(fn ($s) => $s['name'] === 'fay' ? ['disabled' => 'false'] + $s : $s, $this->ros->menus['ppp/secret']);
        $this->poll();
        $this->assertSame('true', $this->ros->find('ppp/secret', 'name', 'fay')['disabled'], 'panel state wins');

        $this->ros->menus['ppp/secret'] = array_values(array_filter($this->ros->menus['ppp/secret'], fn ($s) => $s['name'] !== 'fay'));
        $this->poll();
        $s = $this->ros->find('ppp/secret', 'name', 'fay');
        $this->assertNotNull($s, 'recreated');
        $this->assertSame('true', $s['disabled'], 'still disabled as in the panel');
        $this->assertSame('disabled', $a->fresh()->status);
        $this->assertNotSame($id, $a->fresh()->ros_id);
    }

    public function test_password_keys_and_delete(): void
    {
        $a = $this->create($this->l2tp, ['username' => 'gus']);
        app(MtActions::class)->changePassword($a, 'new-pass', 'test');
        $this->assertSame('new-pass', $this->ros->find('ppp/secret', 'name', 'gus')['password']);
        $this->assertSame('new-pass', $a->fresh()->password);

        $w = $this->create($this->wg, ['username' => 'wgu5']);
        $old = $w->wg_public_key;
        app(MtActions::class)->regenerateKeys($w, 'test');
        $w->refresh();
        $this->assertNotSame($old, $w->wg_public_key);
        $this->assertNotNull($this->ros->find('interface/wireguard/peers', 'public-key', $w->wg_public_key));

        app(MtActions::class)->delete($w, 'test');
        $this->assertNull($this->ros->find('interface/wireguard/peers', 'public-key', $w->wg_public_key));
        $this->assertSoftDeleted($w);
        $again = $this->create($this->wg, ['username' => 'wgu5']);
        $this->assertSame('active', $again->status, 'name and address are free again');
        $this->assertSame('10.20.0.3', $again->wg_address);
    }

    public function test_router_down_during_renew_still_renews_and_syncs_later(): void
    {
        $a = $this->create($this->wg, ['username' => 'wgu7', 'days' => 1]);
        $this->ros->trafficWg($a->wg_public_key, 10, 10);
        $this->poll();
        $this->travel(2)->days();
        $this->poll();
        $this->assertSame('expired', $a->fresh()->status);
        $this->ros->down = true;
        app(MtActions::class)->renew($a->fresh(), 30, 'test');
        $this->assertSame('active', $a->fresh()->status);
        $this->assertTrue($a->fresh()->router_disabled, 'router not reached yet');
        $this->ros->down = false;
        $this->poll();
        $this->assertFalse($a->fresh()->router_disabled);
        $this->assertSame('false', $this->ros->find('interface/wireguard/peers', 'public-key', $a->wg_public_key)['disabled']);
    }
}
