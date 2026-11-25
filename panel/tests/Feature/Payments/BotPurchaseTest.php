<?php

namespace Tests\Feature\Payments;

use App\Bots\Engine;
use App\Ibs\IbsException;
use App\Models\BotOutbox;
use App\Models\BotUser;
use App\Models\CustomerOrder;
use App\Models\Payment;
use App\Models\VpnAccount;
use App\Payments\Payments;
use App\Services\Finance\WalletService;

class BotPurchaseTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bot();
        $this->enable(['zarinpal.enabled' => '1', 'zarinpal.merchant_id' => 'M', 'card.enabled' => '1',
            'card.cards' => json_encode([['number' => '6037991234567890', 'holder' => 'آرمان', 'bank' => 'ملی']])]);
    }

    private function buy(string $chat = '700'): void
    {
        $this->tg('/start', chat: $chat);
        $this->tg(Engine::BUY, chat: $chat);
        $this->tg(collect($this->lastKeyboard())->first(fn ($l) => str_contains($l, 'یک‌ماهه')), chat: $chat);
    }

    public function test_customer_buys_online_and_receives_credentials(): void
    {
        $this->buy();
        $this->assertStringContainsString('روش پرداخت', $this->lastText());
        $this->assertSame([Engine::PAY_ONLINE, Engine::PAY_CARD, Engine::CANCEL], $this->lastKeyboard());
        $this->tg(Engine::PAY_ONLINE, chat: '700');
        $p = Payment::query()->firstOrFail();
        $this->assertStringContainsString(route('pay.show', $p->uuid), $this->lastText());
        $this->assertSame('order', $p->purpose);
        $this->assertSame(1_000_000, $p->amount_rial);

        $this->post('/pay/'.$p->uuid.'/go');
        $this->get('/pay/callback/'.$p->uuid.'?Authority='.$p->fresh()->gateway_token.'&Status=OK')->assertSee('به ربات برگردید');
        $order = CustomerOrder::query()->firstOrFail();
        $this->assertSame('completed', $order->status);
        $bu = BotUser::query()->where('chat_id', '700')->first();
        $this->assertTrue($bu->accounts->contains($order->vpn_account_id));
        $this->assertSame(0, app(WalletService::class)->walletFor('customer', $bu->id)->balance, 'topped up then spent');
        $this->assertNull($order->fresh()->getAttributes()['secret'], 'password not kept after it was queued');

        $msg = BotOutbox::query()->where('sensitive', true)->firstOrFail();
        $acc = VpnAccount::query()->find($order->vpn_account_id);
        $this->assertStringContainsString($acc->username, $msg->text);
        $this->assertStringContainsString($this->fake->users[$acc->ibs_user_id]['password'], $msg->text);
        $this->artisan('bots:send');
        $this->assertStringNotContainsString($acc->username, $msg->fresh()->text, 'credentials wiped from the outbox after sending');
        $this->assertStringContainsString($acc->username, $this->lastText());

        // the callback again does nothing more
        $this->get('/pay/callback/'.$p->uuid.'?Authority='.$p->fresh()->gateway_token.'&Status=OK');
        $this->assertSame(1, VpnAccount::query()->count());
    }

    public function test_card_purchase_completes_after_admin_approval(): void
    {
        $this->buy('701');
        $this->tg(Engine::PAY_CARD, chat: '701');
        $this->assertStringContainsString('6037 9912 3456 7890', $this->lastText());
        $this->tg('پیگیری 554433 کارت 1234', chat: '701');
        $this->assertStringContainsString('ثبت شد', $this->lastText());
        $p = Payment::query()->firstOrFail();
        $this->assertSame('review', $p->status);
        $this->assertSame('customer', $p->wallet->owner_type);
        app(Payments::class)->approve($p, 'در حساب دیدم', $this->superAdmin()->id);
        $this->assertSame('completed', CustomerOrder::query()->first()->status);
        $this->assertSame(1, VpnAccount::query()->count());
    }

    public function test_failed_provisioning_keeps_money_in_customer_wallet_for_next_purchase(): void
    {
        $this->buy('702');
        $this->tg(Engine::PAY_ONLINE, chat: '702');
        $p = Payment::query()->firstOrFail();
        $this->post('/pay/'.$p->uuid.'/go');
        $this->fake->failNext('user.addNewUsers', new IbsException('ACCESS_DENIED', 'denied'));
        $this->get('/pay/callback/'.$p->uuid.'?Authority='.$p->fresh()->gateway_token.'&Status=OK');
        $this->assertSame('refunded', CustomerOrder::query()->first()->status);
        $bu = BotUser::query()->where('chat_id', '702')->first();
        $this->assertSame(1_000_000, app(WalletService::class)->walletFor('customer', $bu->id)->balance);
        $this->assertTrue(BotOutbox::query()->where('text', 'like', '%در کیف پول شما%')->exists());

        // next purchase: pay from the wallet
        $this->buy('702');
        $this->assertContains(Engine::PAY_WALLET, $this->lastKeyboard());
        $this->tg(Engine::PAY_WALLET, chat: '702');
        $this->assertSame('completed', CustomerOrder::query()->orderByDesc('id')->first()->status);
        $this->assertSame(0, app(WalletService::class)->walletFor('customer', $bu->id)->balance);
        $this->assertSame(1, Payment::query()->count(), 'no second payment');
    }

    public function test_customer_renews_own_linked_account_only(): void
    {
        $this->syncCatalog();
        $id = $this->ibsUser('mine1', password: 'pw');
        $this->tg('/start', chat: '703');
        $this->tg(Engine::LINK, chat: '703');
        $this->tg('mine1', chat: '703');
        $this->tg('pw', chat: '703');
        $this->tg(Engine::CRENEW, chat: '703');
        $this->assertSame(['👤 mine1', Engine::CANCEL], $this->lastKeyboard());
        $this->tg('👤 someone', chat: '703');
        $this->assertSame(['👤 mine1', Engine::CANCEL], $this->lastKeyboard());
        $this->tg('👤 mine1', chat: '703');
        $this->tg(collect($this->lastKeyboard())->first(fn ($l) => str_contains($l, 'تمدید')), chat: '703');
        $this->tg(Engine::PAY_CARD, chat: '703');
        $this->tg('ref 1', chat: '703');
        app(Payments::class)->approve(Payment::query()->first(), 'ok ok', $this->superAdmin()->id);
        $this->assertSame('completed', CustomerOrder::query()->first()->status);
        $this->assertSame(VpnAccount::query()->where('ibs_user_id', $id)->value('id'), CustomerOrder::query()->first()->vpn_account_id);
    }

    public function test_reseller_tops_up_wallet_from_bot(): void
    {
        $r = $this->reseller();
        $this->actingAs($r->user)->post('/bot-link');
        $code = session('link_code');
        $this->tg(Engine::PARTNER_LOGIN, chat: '704');
        $this->tg($code, chat: '704');
        $this->tg(Engine::WALLET, chat: '704');
        $this->assertContains(Engine::TOPUP, $this->lastKeyboard());
        $this->tg(Engine::TOPUP, chat: '704');
        $this->tg('۲۰۰,۰۰۰', chat: '704');
        $this->tg(Engine::PAY_ONLINE, chat: '704');
        $p = Payment::query()->firstOrFail();
        $this->assertSame('reseller', $p->wallet->owner_type);
        $this->assertSame(2_000_000, $p->amount_rial);
        $this->post('/pay/'.$p->uuid.'/go');
        $this->get('/pay/callback/'.$p->uuid.'?Authority='.$p->fresh()->gateway_token.'&Status=OK');
        $this->assertSame(2_000_000, $this->balance($r));
        $this->assertTrue(BotOutbox::query()->where('text', 'like', '%کیف پول شما شارژ شد%')->exists());
    }

    public function test_no_payment_methods_means_clear_message(): void
    {
        $this->enable(['zarinpal.enabled' => '0', 'card.enabled' => '0']);
        $this->buy('705');
        $this->assertStringContainsString('روش پرداختی فعال نیست', $this->lastText());
        $this->assertSame(0, Payment::query()->count());
    }
}
