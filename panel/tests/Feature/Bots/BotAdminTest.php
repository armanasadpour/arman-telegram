<?php

namespace Tests\Feature\Bots;

use App\Bots\Engine;
use App\Bots\RelayCode;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\BotUser;
use App\Services\Settings;
use Illuminate\Support\Facades\Http;

class BotAdminTest extends BotTestCase
{
    public function test_admin_saves_token_with_confirmation_and_token_is_never_shown(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/bots')->assertOk()->assertSee('تلگرام')->assertSee('بله')->assertSee('روبیکا');
        $this->actingAs($admin)->put('/bots/bale', ['token' => '987:BALE_TOKEN_x', 'is_active' => 1])->assertRedirect('/confirm-password');
        $this->actingAs($admin)->confirmed()->from('/bots')->put('/bots/bale', ['token' => '987:BALE_TOKEN_x', 'is_active' => 1])->assertSessionHas('success');
        $bale = Bot::for('bale');
        $this->assertSame('987:BALE_TOKEN_x', $bale->token);
        $this->assertNotSame('987:BALE_TOKEN_x', \DB::table('bots')->where('id', $bale->id)->value('token'), 'encrypted at rest');
        $this->actingAs($admin)->get('/bots')->assertDontSee('987:BALE_TOKEN_x')->assertDontSee($bale->hook_secret);
        $this->assertStringNotContainsString('BALE_TOKEN', AuditLog::query()->get()->toJson());

        // Connect: getMe + setWebhook on tapi.bale.ai, with the panel hook URL
        $this->actingAs($admin)->from('/bots')->post('/bots/bale/connect')->assertSessionHas('success');
        $req = $this->request('/setWebhook');
        $this->assertStringStartsWith('https://tapi.bale.ai/bot987:BALE_TOKEN_x/', $req->url());
        $this->assertSame($bale->hookUrl(), $req->data()['url']);
        $this->assertSame('panel_bale_bot', $bale->fresh()->bot_username);
    }

    public function test_doubled_or_messy_paste_is_cleaned(): void
    {
        $this->bot('telegram');
        $admin = $this->superAdmin();
        $url = 'https://nubatyar.example.workers.dev';
        $this->actingAs($admin)->confirmed()->from('/bots')->put('/bots/telegram', ['token' => " 123456:ABCdef_-xyz123456:ABCdef_-xyz\u{200F}", 'relay_mode' => 'cloudflare',
            'relay_url' => $url.$url.' ', 'is_active' => 1])->assertSessionHasNoErrors();
        $b = Bot::for('telegram');
        $this->assertSame($url, $b->relay_url);
        $this->assertSame('123456:ABCdef_-xyz', $b->token);
        $this->actingAs($admin)->confirmed()->from('/bots')->put('/bots/telegram', ['relay_mode' => 'cloudflare', 'relay_url' => 'not a url'])
            ->assertSessionHasErrors(['relay_url' => 'آدرس واسط باید یک آدرس کامل و معتبر باشد (مثلاً https://…).']);
    }

    public function test_viewer_cannot_change_or_see_relay_code(): void
    {
        $u = $this->userWith(['bots.view']);
        $this->actingAs($u)->get('/bots')->assertOk()->assertDontSee('name="token"', false);
        $this->actingAs($u)->confirmed()->put('/bots/telegram', ['token' => '1:a'])->assertForbidden();
        $this->actingAs($u)->confirmed()->get('/bots/relay/cloudflare')->assertForbidden();
        $this->actingAs($u)->post('/bots/telegram/connect')->assertForbidden();
        $this->actingAs($this->userWith(['dashboard.view']))->get('/bots')->assertForbidden();
    }

    public function test_cloudflare_relay_is_transparent_and_messages_are_polled(): void
    {
        $bot = $this->bot('telegram', ['relay_mode' => 'cloudflare', 'relay_url' => 'https://relay.example.workers.dev/']);
        app(Settings::class)->set('bots.telegram_receive', 'poll');
        $admin = $this->superAdmin();
        $code = $this->actingAs($admin)->confirmed()->get('/bots/relay/cloudflare')->assertOk()->viewData('code');
        $this->assertStringContainsString('"https://api.telegram.org" + url.pathname', $code, 'same worker as the news plugin');

        $this->actingAs($admin)->from('/bots')->post('/bots/telegram/connect')->assertSessionHas('success', fn ($m) => str_contains($m, 'واسط'));
        $this->assertNotNull($this->request('relay.example.workers.dev/bot123456:SECRET-TOKEN_abc/getMe'));
        $this->assertNotNull($this->request('relay.example.workers.dev/bot123456:SECRET-TOKEN_abc/deleteWebhook'), 'polling: webhook removed');
        $this->assertNull($this->request('/setWebhook'));
        $this->assertNotContains(true, array_map(fn ($u) => str_contains($u, 'api.telegram.org'), $this->urls()), 'never calls Telegram directly');

        // the panel fetches messages through the relay and answers through it
        $this->freshHttpFake();
        $polls = 0;
        Http::fake(['relay.example.workers.dev/*' => function ($req) use (&$polls) {
            if (str_ends_with($req->url(), '/getUpdates')) {
                $polls++;

                return Http::response(['ok' => true, 'result' => $polls === 1 && ($req->data()['offset'] ?? null) === 0 ? [
                    ['update_id' => 77, 'message' => ['message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555, 'first_name' => 'Ali'], 'text' => '/start']],
                ] : []]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 9]]);
        }]);
        config(['panel.bot_poll_seconds' => 4]);
        $this->artisan('bots:poll')->assertSuccessful();
        $this->assertSame('78', app(Settings::class)->get('bots.telegram_offset'));
        $sent = Http::recorded()->first(fn ($p) => str_ends_with($p[0]->url(), '/bot123456:SECRET-TOKEN_abc/sendMessage'));
        $this->assertNotNull($sent, 'reply sent through the relay');
        $this->assertSame('555', (string) $sent[0]->data()['chat_id']);
        $this->artisan('bots:poll')->assertSuccessful();
        $this->assertSame(1, \DB::table('bot_updates')->count(), 'each update handled once');
    }

    public function test_relay_for_sending_and_fast_direct_webhook_for_receiving(): void
    {
        $bot = $this->bot('telegram', ['relay_mode' => 'cloudflare', 'relay_url' => 'https://relay.example.workers.dev']);
        $this->actingAs($this->superAdmin())->from('/bots')->post('/bots/telegram/connect')->assertSessionHas('success');
        $req = $this->request('relay.example.workers.dev/bot123456:SECRET-TOKEN_abc/setWebhook');
        $this->assertNotNull($req, 'webhook registered through the relay');
        $this->assertSame($bot->hookUrl(), $req->data()['url']);
        $this->assertSame($bot->relaySecret(), $req->data()['secret_token']);
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->postJson('/hook/telegram/'.$bot->hook_secret, ['update_id' => 5])->assertForbidden();
        $this->tg('/start')->assertOk();   // Telegram's own header
        $this->assertNotNull($this->request('relay.example.workers.dev/bot123456:SECRET-TOKEN_abc/sendMessage'), 'answer goes out through the relay');
        $this->artisan('bots:poll')->assertSuccessful();
        $this->assertNull($this->request('/getUpdates'), 'no polling in webhook mode');
    }

    public function test_apps_script_relay_payload(): void
    {
        $bot = $this->bot('telegram', ['relay_mode' => 'gas', 'relay_url' => 'https://script.google.com/macros/s/ABC/exec']);
        app(Settings::class)->set('bots.telegram_receive', 'poll');
        $this->freshHttpFake();
        Http::fake(['script.google.com/*' => Http::response('{"ok":true,"result":{"username":"panel_bot"}}', 200, ['Content-Type' => 'text/plain'])]);
        $this->actingAs($this->superAdmin())->from('/bots')->post('/bots/telegram/connect')->assertSessionHas('success');
        $req = Http::recorded()->map(fn ($p) => $p[0])->first(fn ($r) => ($r->data()['method'] ?? null) === 'getMe');
        $this->assertSame('https://script.google.com/macros/s/ABC/exec', $req->url());
        $this->assertSame('123456:SECRET-TOKEN_abc', $req->data()['bot_token'], 'same fields as the news plugin script');
        $this->assertTrue($req->isForm());
        $del = Http::recorded()->map(fn ($p) => $p[0])->first(fn ($r) => ($r->data()['method'] ?? null) === 'deleteWebhook');
        $this->assertSame(['drop_pending_updates' => false], json_decode($del->data()['args'], true));
        $this->assertStringContainsString('e.parameter.bot_token', RelayCode::appsScript($bot));
    }

    public function test_rubika_parse_and_keypad(): void
    {
        $bot = $this->bot('rubika');
        $payload = ['update' => ['type' => 'NewMessage', 'chat_id' => 'b0abc', 'new_message' => ['message_id' => '42', 'text' => '/start', 'sender_type' => 'User', 'sender_id' => 'u1']]];
        $this->postJson('/hook/rubika/'.$bot->hook_secret, $payload)->assertOk();
        $this->postJson('/hook/rubika/'.$bot->hook_secret, $payload)->assertJson(['duplicate' => true]);
        $req = $this->request('/RUBIKATOKEN123/sendMessage');
        $this->assertSame('https://botapi.rubika.ir/v3/RUBIKATOKEN123/sendMessage', $req->url());
        $this->assertSame('b0abc', $req->data()['chat_id']);
        $this->assertSame('New', $req->data()['chat_keypad_type']);
        $this->assertSame(Engine::MY_ACCOUNTS, $req->data()['chat_keypad']['rows'][0]['buttons'][0]['button_text']);
        $this->assertSame('Simple', $req->data()['chat_keypad']['rows'][0]['buttons'][0]['type']);
        $this->assertSame(1, BotUser::query()->where('bot_id', $bot->id)->count());

        $this->actingAs($this->superAdmin())->from('/bots')->post('/bots/rubika/connect')->assertSessionHas('success');
        $this->assertSame(['url' => $bot->hookUrl(), 'type' => 'ReceiveUpdate'], $this->request('/updateBotEndpoints')->data());
    }

    public function test_reach_test_and_link_page_for_reseller_and_staff(): void
    {
        $this->bot();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->from('/bots')->post('/bots/reach')->assertSessionHas('reach');
        $this->actingAs($admin)->get('/bot-link')->assertOk()->assertSee('ساخت کد اتصال');
        $r = $this->reseller();
        $this->actingAs($r->user)->get('/bot-link')->assertOk()->assertSee('اکانت بفروشید');
        $this->actingAs($r->user)->post('/bot-link')->assertSessionHas('link_code');
        $this->actingAs($r->user)->get('/bots')->assertForbidden();
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->bot();
        $r = $this->reseller();
        $this->actingAs($r->user)->post('/bot-link');
        $code = session('link_code');
        $this->travel(16)->minutes();
        $this->tg(Engine::PARTNER_LOGIN);
        $this->tg($code);
        $this->assertStringContainsString('منقضی', $this->lastText());
    }
}
