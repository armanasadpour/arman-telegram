<?php

namespace Tests\Feature\Bots;

use App\Bots\Engine;
use App\Ibs\IbsUnavailableException;
use App\Models\BotLinkCode;
use App\Models\BotOutbox;
use App\Models\BotUser;
use App\Models\ResellerSale;
use App\Models\VpnAccount;
use App\Services\Finance\ResellerSales;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BotFlowTest extends BotTestCase
{
    public function test_webhook_rejects_wrong_secret_header_or_inactive_bot(): void
    {
        $bot = $this->bot();
        $this->postJson('/hook/telegram/'.str_repeat('x', 40), ['update_id' => 1])->assertNotFound();
        $this->postJson('/hook/telegram/'.$bot->hook_secret, ['update_id' => 1])->assertForbidden();
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->postJson('/hook/telegram/'.$bot->hook_secret, ['update_id' => 1])->assertForbidden();
        $bot->forceFill(['is_active' => false])->save();
        $this->tg('/start')->assertNotFound();
        $this->assertSame([], $this->sent());
    }

    public function test_start_shows_menu_and_duplicates_are_ignored(): void
    {
        $this->bot();
        $this->tg('/start', updateId: 77)->assertOk();
        $this->tg('/start', updateId: 77)->assertOk()->assertJson(['duplicate' => true]);
        $this->assertCount(1, $this->sent());
        $this->assertContains(Engine::MY_ACCOUNTS, $this->lastKeyboard());
        $this->assertContains(Engine::PARTNER_LOGIN, $this->lastKeyboard());
        $this->assertNotContains(Engine::SELL, $this->lastKeyboard());
        $this->assertSame(1, BotUser::query()->count());
    }

    public function test_customer_links_account_with_password_and_sees_it(): void
    {
        $this->bot();
        $this->syncCatalog();
        $id = $this->ibsUser('cust7', password: 'my pass!');
        $this->tg(Engine::LINK);
        $this->tg('cust7');
        $this->tg('my pass!');
        $this->assertStringContainsString('اکانت به ربات اضافه شد', $this->lastText());
        $this->assertNotNull($this->request('/deleteMessage'), 'the password message is deleted');
        $acc = VpnAccount::query()->where('ibs_user_id', $id)->firstOrFail();
        $this->assertTrue(BotUser::query()->first()->accounts->contains($acc));
        $this->tg(Engine::MY_ACCOUNTS);
        $this->assertStringContainsString('cust7', $this->lastText());
    }

    public function test_wrong_passwords_lock_out_after_five_tries(): void
    {
        $this->bot();
        $this->syncCatalog();
        $this->ibsUser('cust8', password: 'right');
        for ($i = 0; $i < 5; $i++) {
            $this->tg(Engine::LINK);
            $this->tg('cust8');
            $this->tg('wrong'.$i);
            $this->assertStringContainsString('درست نیست', $this->lastText());
        }
        $this->tg(Engine::LINK);
        $this->tg('cust8');
        $this->tg('right');
        $this->assertStringContainsString('امکان افزودن اکانت نیست', $this->lastText());
        $this->assertSame(0, BotUser::query()->first()->accounts()->count());
        // unknown username gives the same answer as a wrong password
        BotUser::query()->update(['login_blocked_until' => null]);
        $this->tg(Engine::LINK);
        $this->tg('nobody');
        $this->tg('x');
        $this->assertStringContainsString('نام کاربری یا رمز عبور درست نیست', $this->lastText());
    }

    public function test_reseller_links_with_code_and_sells_from_wallet(): void
    {
        $this->bot();
        $r = $this->reseller(creditRial: 3_000_000);
        $this->actingAs($r->user)->post('/bot-link')->assertSessionHas('link_code');
        $code = session('link_code');
        $this->tg(Engine::PARTNER_LOGIN);
        $this->tg(strtolower($code));
        $this->assertStringContainsString('وصل شد', $this->lastText());
        $this->assertContains(Engine::SELL, $this->lastKeyboard());
        $this->assertNotNull(BotLinkCode::query()->first()->used_at);

        $this->tg(Engine::SELL);
        $label = collect($this->lastKeyboard())->first(fn ($l) => str_contains($l, 'یک‌ماهه'));
        $this->assertNotNull($label);
        $this->tg($label);
        $this->assertStringContainsString('نام مشتری', $this->lastText());
        $this->tg('علی رضایی');
        $this->tg('botcust1');
        $this->tg('simple');
        $this->assertStringContainsString('مبلغ کسر از کیف پول', $this->lastText());
        $this->tg(Engine::CONFIRM);
        $this->assertStringContainsString('اکانت ساخته شد', $this->lastText());
        $this->assertStringContainsString('simple', $this->lastText());
        $this->tg(Engine::CONFIRM);   // a second press does nothing
        $this->assertSame(1, ResellerSale::query()->count());
        $this->assertSame(2_000_000, $this->balance($r));
        $acc = VpnAccount::query()->where('username', 'botcust1')->firstOrFail();
        $this->assertSame($r->id, $acc->reseller_id);
        $this->assertSame('simple', $this->fake->users[$acc->ibs_user_id]['password']);

        // Renew: own account only
        $this->tg(Engine::RENEW);
        $this->tg('someone-else');
        $this->assertStringContainsString('پیدا نشد', $this->lastText());
        $this->tg('botcust1');
        $label = collect($this->lastKeyboard())->first(fn ($l) => str_contains($l, 'تمدید'));
        $this->tg($label);
        $this->tg(Engine::CONFIRM);
        $this->assertStringContainsString('به‌روزرسانی شد', $this->lastText());
        $this->assertSame(1_200_000, $this->balance($r));
    }

    public function test_insufficient_balance_and_codes_cannot_be_reused(): void
    {
        $this->bot();
        $r = $this->reseller(creditRial: 100);
        $this->actingAs($r->user)->post('/bot-link');
        $code = session('link_code');
        $this->tg(Engine::PARTNER_LOGIN);
        $this->tg($code);
        $this->tg(Engine::SELL);
        $this->tg(collect($this->lastKeyboard())->first(fn ($l) => str_contains($l, 'یک‌ماهه')));
        $this->tg('مشتری');
        $this->tg(Engine::AUTO);
        $this->tg(Engine::AUTO);
        $this->tg(Engine::CONFIRM);
        $this->assertStringContainsString('موجودی کیف پول کافی نیست', $this->lastText());
        $this->assertCount(0, $this->fake->users);

        $this->tg(Engine::PARTNER_LOGIN, chat: '999');
        $this->tg($code, chat: '999');   // a used code is no longer a code: treated as a username
        $this->tg('anything', chat: '999');
        $this->assertStringContainsString('درست نیست', $this->lastText());
        $this->assertNull(BotUser::query()->where('chat_id', '999')->value('user_id'));
    }

    public function test_customer_cannot_use_reseller_actions(): void
    {
        $this->bot();
        $this->tg(Engine::WALLET);
        $this->assertStringContainsString('لطفاً یکی از گزینه‌های منو', $this->lastText());
        $this->tg(Engine::SELL);
        $this->assertStringContainsString('لطفاً یکی از گزینه‌های منو', $this->lastText());
    }

    public function test_staff_gets_needs_review_notification_through_outbox(): void
    {
        $bot = $this->bot();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/bot-link');
        $code = session('link_code');
        $this->tg(Engine::PARTNER_LOGIN);
        $this->tg($code);
        $this->assertContains(Engine::STATUS, $this->lastKeyboard());
        $this->tg(Engine::STATUS);
        $this->assertStringContainsString('وضعیت سیستم', $this->lastText());

        $r = $this->reseller(creditRial: 2_000_000);
        $this->fake->failNext('user.addNewUsers', new IbsUnavailableException('timeout'));
        app(ResellerSales::class)->sell($r, $this->newPkg, (string) Str::uuid(), 1_000_000, [], null, $r->user_id);
        $this->assertSame(1, BotOutbox::query()->where('status', 'pending')->count());
        $this->artisan('bots:send')->assertSuccessful();
        $this->assertSame('sent', BotOutbox::query()->first()->status);
        $this->assertStringContainsString('نیازمند رسیدگی', $this->lastText());
    }

    public function test_outbox_retries_with_backoff(): void
    {
        $bot = $this->bot();
        $this->freshHttpFake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        BotOutbox::query()->create(['bot_id' => $bot->id, 'chat_id' => '1', 'text' => 'hi']);
        $this->artisan('bots:send');
        $row = BotOutbox::query()->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertTrue($row->next_attempt_at->isFuture());
        $this->assertStringNotContainsString('SECRET-TOKEN', (string) $row->error);
    }
}
