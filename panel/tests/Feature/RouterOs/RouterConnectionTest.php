<?php

namespace Tests\Feature\RouterOs;

use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\BotOutbox;
use App\Models\BotUser;
use App\Models\Router;
use App\RouterOs\ApiClient;
use App\RouterOs\Pin;
use App\RouterOs\RouterConnector;
use App\RouterOs\RouterOsException;

class RouterConnectionTest extends RouterTestCase
{
    private const PIN = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private function router(array $attrs = []): Router
    {
        return Router::query()->create($attrs + ['name' => 'تهران', 'host' => '185.1.2.3', 'transport' => 'rest', 'port' => 443, 'username' => 'panel', 'password' => 'S3cret!pw', 'cert_pin' => self::PIN]);
    }

    public function test_rest_about_and_readiness_inventory(): void
    {
        $t = $this->fakeRest();
        $r = $this->router();
        $ros = app(RouterConnector::class);
        $info = $ros->about($r);
        $this->assertSame('ir11', $info['identity']);
        $this->assertSame('7.7 (stable)', $info['version']);
        $this->assertSame('6', $info['license']);
        $inv = $ros->inventory($r);
        $this->assertTrue($inv['l2tp_ready']);
        $this->assertTrue($inv['wg_ready']);
        $titles = array_column($inv['checks'], 1);
        $this->assertContains('سرویس ناامن telnet', $titles);
        $this->assertSame('***', $inv['data']['l2tp']['server']['ipsec-secret'], 'IPsec PSK never stored');
        $this->assertStringNotContainsString('PRIVATE=', json_encode($r->fresh()->inventory));
        $this->assertStringNotContainsString('TOPSECRET', json_encode($r->fresh()->inventory));
        $this->assertNotNull($r->fresh()->last_ok_at);
        $this->assertSame('https://185.1.2.3:443/rest/system/resource', $t->requests[0]['url']);
        $this->assertSame(self::PIN, $t->requests[0]['pin']);
        $this->assertSame(['panel', 'S3cret!pw'], [$t->requests[0]['user'], $t->requests[0]['password']]);
    }

    public function test_rest_refuses_without_pin_and_reports_bad_password(): void
    {
        $t = $this->fakeRest();
        $r = $this->router(['cert_pin' => null]);
        try {
            app(RouterConnector::class)->about($r);
            $this->fail('expected refusal');
        } catch (RouterOsException $e) {
            $this->assertStringContainsString('اثر انگشت', $e->getMessage());
        }
        $this->assertSame([], $t->requests, 'no credentials sent without a pin');

        $this->fakeRest(status: 401);
        $r2 = $this->router(['name' => 'دوم']);
        try {
            app(RouterConnector::class)->about($r2);
            $this->fail('expected 401');
        } catch (RouterOsException $e) {
            $this->assertStringContainsString('نام کاربری یا رمز', $e->getMessage());
        }
        $this->assertNotNull($r2->fresh()->last_error_at);
    }

    public function test_api_protocol_plain_and_readiness_when_not_ready(): void
    {
        $menus = $this->menus();
        $menus['interface/l2tp-server/server'] = [['enabled' => 'false', 'use-ipsec' => 'no']];
        $menus['interface/wireguard'] = [];
        [$port] = $this->startApiServer(false, $menus);
        $r = $this->router(['transport' => 'api', 'host' => '127.0.0.1', 'port' => $port, 'cert_pin' => null]);
        $info = app(RouterConnector::class)->about($r);
        $this->assertSame('ir11', $info['identity']);
        $inv = app(RouterConnector::class)->inventory($r);
        $this->assertFalse($inv['l2tp_ready']);
        $this->assertFalse($inv['wg_ready']);
        $fails = array_column(array_filter($inv['checks'], fn ($c) => $c[0] === 'fail'), 1);
        $this->assertContains('سرور L2TP', $fails);
        $this->assertContains('اینترفیس WireGuard', $fails);
    }

    public function test_api_wrong_password(): void
    {
        [$port] = $this->startApiServer(false);
        $r = $this->router(['transport' => 'api', 'host' => '127.0.0.1', 'port' => $port, 'password' => 'wrong-pass']);
        $this->expectException(RouterOsException::class);
        $this->expectExceptionMessage('نام کاربری یا رمز');
        app(RouterConnector::class)->about($r);
    }

    public function test_api_ssl_with_pin_fetch_and_pin_mismatch(): void
    {
        [$port, $pin] = $this->startApiServer(true);
        $fetched = Pin::fetch('127.0.0.1', $port);
        $this->assertSame($pin, $fetched['pin']);
        $this->assertSame('panel-cert', $fetched['subject']);
        $r = $this->router(['transport' => 'api-ssl', 'host' => '127.0.0.1', 'port' => $port, 'cert_pin' => $pin]);
        $this->assertSame('ir11', app(RouterConnector::class)->about($r)['identity']);

        $evil = $this->router(['name' => 'x', 'transport' => 'api-ssl', 'host' => '127.0.0.1', 'port' => $port, 'cert_pin' => self::PIN]);
        $this->expectException(RouterOsException::class);
        $this->expectExceptionMessage('مطابقت ندارد');
        app(RouterConnector::class)->about($evil);
    }

    public function test_length_encoding_round_trip(): void
    {
        foreach ([0, 1, 0x7F, 0x80, 0x3FFF, 0x4000, 0x1FFFFF, 0x200000, 0xFFFFFFF] as $n) {
            $e = ApiClient::encodeLength($n);
            $this->assertSame([1, 1, 1, 2, 2, 3, 3, 4, 4][array_search($n, [0, 1, 0x7F, 0x80, 0x3FFF, 0x4000, 0x1FFFFF, 0x200000, 0xFFFFFFF], true)], strlen($e), "len($n)");
        }
        $this->assertSame(['name' => 'a=b', '.id' => '*1'], ApiClient::attrs(['=name=a=b', '=.id=*1', '!re']));
    }

    public function test_admin_pages_pin_trust_test_and_protocol_toggle(): void
    {
        [$port, $pin] = $this->startApiServer(true);
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/routers')->assertOk()->assertSee('هنوز روتری');
        $this->actingAs($admin)->get('/routers/create')->assertRedirect('/confirm-password');
        $this->actingAs($admin)->confirmed()->post('/routers', ['name' => 'تهران ۱', 'host' => '127.0.0.1', 'transport' => 'api-ssl', 'port' => $port,
            'username' => 'panel', 'password' => 'S3cret!pw', 'is_active' => 1])->assertRedirect();
        $r = Router::query()->firstOrFail();
        $this->assertNotSame('S3cret!pw', \DB::table('routers')->value('password'), 'encrypted at rest');
        $this->actingAs($admin)->get('/routers/'.$r->id)->assertOk()->assertDontSee('S3cret!pw')->assertSee('دریافت اثر انگشت');
        $this->actingAs($admin)->confirmed()->from('/routers/'.$r->id)->post('/routers/'.$r->id.'/pin')->assertSessionHas('pin_candidate');
        $this->actingAs($admin)->confirmed()->from('/routers/'.$r->id)->post('/routers/'.$r->id.'/pin/trust', ['pin' => $pin])->assertSessionHas('success');
        $this->actingAs($admin)->from('/routers/'.$r->id)->post('/routers/'.$r->id.'/test')->assertSessionHas('success');
        $this->actingAs($admin)->get('/routers/'.$r->id)->assertSee('ir11')->assertSee('آمادگی برای L2TP')->assertSee('panel-cert');
        $this->actingAs($admin)->from('/routers/'.$r->id)->post('/routers/'.$r->id.'/protocol', ['protocol' => 'wireguard', 'enabled' => 1])->assertSessionHas('success');
        $this->assertTrue($r->fresh()->wireguard_enabled);
        $this->assertStringNotContainsString('S3cret', AuditLog::query()->get()->toJson());

        // changing the address drops the trusted pin
        $this->actingAs($admin)->confirmed()->put('/routers/'.$r->id, ['name' => 'تهران ۱', 'host' => '127.0.0.2', 'transport' => 'api-ssl', 'port' => $port, 'username' => 'panel', 'is_active' => 1]);
        $this->assertNull($r->fresh()->cert_pin);
        $this->assertSame('S3cret!pw', $r->fresh()->password, 'empty password field keeps the old one');

        // "host:port" pasted into the address field is split, not stored as "host:port" + port
        $this->actingAs($admin)->confirmed()->put('/routers/'.$r->id, ['name' => 'تهران ۱', 'host' => 'https://ir11.example.com:8443/', 'transport' => 'rest', 'port' => 443, 'username' => 'panel', 'is_active' => 1])->assertRedirect();
        $this->assertSame(['ir11.example.com', 8443], [$r->fresh()->host, (int) $r->fresh()->port]);
        $this->actingAs($admin)->confirmed()->from('/routers/'.$r->id.'/edit')->put('/routers/'.$r->id, ['name' => 'x', 'host' => 'a.com:1:2', 'transport' => 'rest', 'port' => 443, 'username' => 'panel'])->assertSessionHasErrors('host');
    }

    public function test_permissions(): void
    {
        $r = $this->router();
        $viewer = $this->userWith(['routers.view']);
        $this->actingAs($viewer)->get('/routers/'.$r->id)->assertOk()->assertDontSee('اسکریپت راه‌اندازی');
        $this->actingAs($viewer)->confirmed()->get('/routers/create')->assertForbidden();
        $this->actingAs($viewer)->post('/routers/'.$r->id.'/test')->assertForbidden();
        $this->actingAs($this->userWith(['dashboard.view']))->get('/routers')->assertForbidden();
    }

    public function test_real_https_rest_with_pinned_certificate(): void
    {
        [$port, $pin] = $this->startApiServer(true, http: true);
        $r = $this->router(['host' => '127.0.0.1', 'port' => $port, 'cert_pin' => $pin]);
        $this->assertSame('ir11', app(RouterConnector::class)->about($r)['identity']);
        $this->assertSame($pin, Pin::fetch('127.0.0.1', $port)['pin']);

        $evil = $this->router(['name' => 'y', 'host' => '127.0.0.1', 'port' => $port, 'cert_pin' => self::PIN]);
        try {
            app(RouterConnector::class)->about($evil);
            $this->fail('pin mismatch must refuse');
        } catch (RouterOsException $e) {
            $this->assertStringContainsString('مطابقت ندارد', $e->getMessage());
        }
        $bad = $this->router(['name' => 'z', 'host' => '127.0.0.1', 'port' => $port, 'cert_pin' => $pin, 'password' => 'nope']);
        $this->expectExceptionMessage('نام کاربری یا رمز');
        app(RouterConnector::class)->about($bad);
    }

    public function test_health_cron_alerts_on_transitions(): void
    {
        $t = $this->fakeRest();
        $r = $this->router();
        $admin = $this->superAdmin();
        $bot = Bot::for('telegram');
        $bot->forceFill(['token' => '1:x', 'is_active' => true])->save();
        BotUser::query()->create(['bot_id' => $bot->id, 'chat_id' => '9', 'user_id' => $admin->id]);
        $this->artisan('routers:health')->assertSuccessful();
        $this->assertSame('up', $r->fresh()->health);
        $this->assertSame(0, BotOutbox::query()->count(), 'first observation is not an alert');
        $t->down = true;
        $this->artisan('routers:health');
        $this->artisan('routers:health');
        $this->assertSame('down', $r->fresh()->health);
        $this->assertSame(1, BotOutbox::query()->count());
        $this->assertStringContainsString('قطع شد', BotOutbox::query()->value('text'));
    }
}
