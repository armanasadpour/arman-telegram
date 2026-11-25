<?php

namespace Tests\Feature\Bots;

use App\Bots\Engine;
use App\Bots\RelayCode;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\BotUser;
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

    public function test_viewer_cannot_change_or_see_relay_code(): void
    {
        $u = $this->userWith(['bots.view']);
        $this->actingAs($u)->get('/bots')->assertOk()->assertDontSee('name="token"', false);
        $this->actingAs($u)->confirmed()->put('/bots/telegram', ['token' => '1:a'])->assertForbidden();
        $this->actingAs($u)->confirmed()->get('/bots/relay/cloudflare')->assertForbidden();
        $this->actingAs($u)->post('/bots/telegram/connect')->assertForbidden();
        $this->actingAs($this->userWith(['dashboard.view']))->get('/bots')->assertForbidden();
    }

    public function test_cloudflare_relay_routes_calls_and_webhook(): void
    {
        $bot = $this->bot('telegram', ['relay_mode' => 'cloudflare', 'relay_url' => 'https://relay.example.workers.dev']);
        $admin = $this->superAdmin();
        $code = $this->actingAs($admin)->confirmed()->get('/bots/relay/cloudflare')->assertOk()->viewData('code');
        $this->assertStringContainsString($bot->relaySecret(), $code);
        $this->assertStringContainsString($bot->hookUrl(), $code);

        $this->actingAs($admin)->from('/bots')->post('/bots/telegram/connect')->assertSessionHas('success');
        $req = $this->request('/setWebhook');
        $this->assertSame('https://relay.example.workers.dev/api/bot123456:SECRET-TOKEN_abc/setWebhook', $req->url());
        $this->assertSame($bot->relaySecret(), $req->header('X-Relay-Secret')[0]);
        $this->assertSame('https://relay.example.workers.dev/hook', $req->data()['url']);
        $this->assertSame($bot->relaySecret(), $req->data()['secret_token']);
        $this->assertNotContains(true, array_map(fn ($u) => str_contains($u, 'api.telegram.org'), $this->urls()), 'never calls Telegram directly');

        // Incoming through the relay needs the relay header, not Telegram's
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $bot->relaySecret()])->postJson('/hook/telegram/'.$bot->hook_secret, ['update_id' => 5])->assertForbidden();
        $this->tg('/start')->assertOk();
        $this->assertNotNull($this->request('relay.example.workers.dev/api/bot123456:SECRET-TOKEN_abc/sendMessage'));
    }

    public function test_apps_script_relay_payload(): void
    {
        $bot = $this->bot('telegram', ['relay_mode' => 'gas', 'relay_url' => 'https://script.google.com/macros/s/ABC/exec']);
        $this->actingAs($this->superAdmin())->from('/bots')->post('/bots/telegram/connect')->assertSessionHas('success');
        $req = Http::recorded()->map(fn ($p) => $p[0])->first(fn ($r) => ($r->data()['method'] ?? null) === 'setWebhook');
        $this->assertSame('https://script.google.com/macros/s/ABC/exec', $req->url());
        $this->assertSame($bot->relaySecret(), $req->data()['s']);
        $this->assertSame('https://script.google.com/macros/s/ABC/exec?s='.$bot->relaySecret(), $req->data()['params']['url']);
        $this->assertArrayNotHasKey('secret_token', $req->data()['params']);
        $this->assertStringContainsString('doPost', RelayCode::appsScript($bot));
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
