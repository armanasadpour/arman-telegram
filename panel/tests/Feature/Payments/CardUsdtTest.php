<?php

namespace Tests\Feature\Payments;

use App\Bots\Engine;
use App\Models\AuditLog;
use App\Models\BotOutbox;
use App\Models\Reseller;
use App\Payments\Payments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CardUsdtTest extends PaymentTestCase
{
    private const TRON_ADDR = 'TLa2f6VPqDgRE67v1736s7bJ8Ray5wYjU7';

    private const EVM_ADDR = '0x1111111111111111111111111111111111111111';

    private function usdtOn(): void
    {
        $this->enable(['usdt.enabled' => '1', 'usdt.trc20.enabled' => '1', 'usdt.erc20.enabled' => '1', 'usdt.bep20.enabled' => '1', 'usdt.rate_toman' => '60000',
            'usdt.address.trc20' => self::TRON_ADDR, 'usdt.address.erc20' => self::EVM_ADDR, 'usdt.address.bep20' => self::EVM_ADDR]);
    }

    public function test_card_to_card_needs_admin_approval_with_confirmation(): void
    {
        Storage::fake('local');
        $this->enable(['card.enabled' => '1', 'card.cards' => json_encode([['number' => '6037991234567890', 'holder' => 'آرمان', 'bank' => 'ملی']])]);
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/bot-link');
        $code = session('link_code');
        $this->bot();
        $this->tg('/start', chat: '42');
        $this->tg(Engine::PARTNER_LOGIN, chat: '42');
        $this->tg($code, chat: '42');
        $this->flushSession();

        $p = $this->resellerPayment('card', 250000);
        $this->get('/pay/'.$p->uuid)->assertSee('6037 9912 3456 7890');
        $this->post('/pay/'.$p->uuid.'/card', ['tracking' => '123456', 'last4' => '۴۴۴۴', 'receipt' => UploadedFile::fake()->image('r.jpg')])->assertRedirect();
        $p->refresh();
        $this->assertSame('review', $p->status);
        $this->assertStringContainsString('****4444', $p->payer_note);
        Storage::disk('local')->assertExists($p->receipt_path);
        $this->assertSame(1, BotOutbox::query()->where('text', 'like', '%کارت‌به‌کارت%')->count(), 'staff notified');

        $viewer = $this->userWith(['payments.view']);
        $this->actingAs($viewer)->get('/payments/'.$p->id)->assertOk()->assertDontSee('تأیید و شارژ');
        $this->actingAs($viewer)->get('/payments/'.$p->id.'/receipt')->assertOk();
        $this->actingAs($viewer)->confirmed()->post('/payments/'.$p->id.'/approve', ['reason' => 'ok ok'])->assertForbidden();
        $this->flushSession();
        $this->actingAs($admin)->post('/payments/'.$p->id.'/approve', ['reason' => 'در حساب دیدم'])->assertRedirect('/confirm-password');
        $this->actingAs($admin)->confirmed()->from('/payments/'.$p->id)->post('/payments/'.$p->id.'/approve', ['reason' => 'در حساب دیدم'])->assertSessionHas('success');
        $this->actingAs($admin)->confirmed()->from('/payments/'.$p->id)->post('/payments/'.$p->id.'/approve', ['reason' => 'دوباره'])->assertSessionHas('success');
        $this->assertSame(250000, $this->walletBalance($p));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payments.approve']);

        $p2 = $this->resellerPayment('card', 90000);
        $this->post('/pay/'.$p2->uuid.'/card', ['tracking' => '1', 'last4' => '1111']);
        $this->actingAs($admin)->confirmed()->from('/payments/'.$p2->id)->post('/payments/'.$p2->id.'/reject', ['reason' => 'واریزی پیدا نشد'])->assertSessionHas('success');
        $this->assertSame('rejected', $p2->fresh()->status);
        $this->assertSame(0, $this->walletBalance($p2));
        $this->actingAs($admin)->confirmed()->from('/payments/'.$p2->id)->post('/payments/'.$p2->id.'/approve', ['reason' => 'x x x'])->assertSessionHas('error');
    }

    public function test_usdt_trc20_auto_verification(): void
    {
        $this->usdtOn();
        $p = $this->resellerPayment('usdt', 1_200_000, ['usdt_network' => 'trc20']);   // 120,000 toman / 60,000 = 2 USDT
        $this->assertSame('2.000000', (string) $p->usdt_amount);
        $this->get('/pay/'.$p->uuid)->assertSee(self::TRON_ADDR)->assertSee('2 USDT');
        $hash = str_repeat('ab', 32);
        $this->tronTx($hash, self::TRON_ADDR, 2.0, block: 970);
        $this->post('/pay/'.$p->uuid.'/usdt', ['tx_hash' => $hash])->assertSessionHas('success');
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame(1_200_000, $this->walletBalance($p));

        // the same hash can never pay again
        $p2 = $this->resellerPayment('usdt', 1_200_000, ['usdt_network' => 'trc20']);
        $this->post('/pay/'.$p2->uuid.'/usdt', ['tx_hash' => $hash])->assertSessionHas('error');
        $this->assertSame(0, $this->walletBalance($p2));
    }

    public function test_usdt_rejections_go_to_review_and_never_credit(): void
    {
        $this->usdtOn();
        $cases = [
            'wrong recipient' => fn ($h) => $this->tronTx($h, 'TPL66VK2gCXNCD7EJg9pgJRfqcRazjhUZY', 2.0),
            'too little' => fn ($h) => $this->tronTx($h, self::TRON_ADDR, 1.5),
            'fake token' => fn ($h) => $this->tronTx($h, self::TRON_ADDR, 2.0, contractB58: 'TPL66VK2gCXNCD7EJg9pgJRfqcRazjhUZY'),
            'failed tx' => fn ($h) => $this->tronTx($h, self::TRON_ADDR, 2.0, result: 'REVERT'),
        ];
        $i = 0;
        foreach ($cases as $name => $make) {
            $p = $this->resellerPayment('usdt', 1_200_000, ['usdt_network' => 'trc20']);
            $hash = str_pad(dechex(++$i), 64, 'c', STR_PAD_LEFT);
            $make($hash);
            $this->post('/pay/'.$p->uuid.'/usdt', ['tx_hash' => $hash]);
            $p->refresh();
            $this->assertNotSame('paid', $p->status, $name);
            $this->assertSame(0, $this->walletBalance($p), $name);
            $this->assertNotNull($p->error, $name);
        }
        // invalid hash format for the network
        $p = $this->resellerPayment('usdt', 1_200_000, ['usdt_network' => 'erc20']);
        $this->post('/pay/'.$p->uuid.'/usdt', ['tx_hash' => str_repeat('a', 64)])->assertSessionHas('error');
    }

    public function test_usdt_waits_for_confirmations_then_cron_credits(): void
    {
        $this->usdtOn();
        $p = $this->resellerPayment('usdt', 1_200_000, ['usdt_network' => 'trc20']);
        $hash = str_repeat('de', 32);
        $this->tronTx($hash, self::TRON_ADDR, 2.5, block: 995);   // latest 1000: 5 < 20 confirmations
        $this->post('/pay/'.$p->uuid.'/usdt', ['tx_hash' => $hash])->assertSessionHas('warning');
        $this->assertSame('review', $p->fresh()->status);
        $this->bank['latest'] = 1030;
        $this->travel(4)->minutes();
        $this->artisan('payments:reverify');
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame(1_200_000, $this->walletBalance($p), 'credits the requested amount, not the overpayment');
    }

    public function test_usdt_erc20_and_bep20_decimals_via_etherscan(): void
    {
        $this->usdtOn();
        $this->enable(['usdt.etherscan_key' => 'ESKEY']);
        $p = $this->resellerPayment('usdt', 600_000, ['usdt_network' => 'erc20']);   // 1 USDT
        $h = '0x'.str_repeat('1', 64);
        $this->evmTx($h, self::EVM_ADDR, 1.0, '0xdAC17F958D2ee523a2206206994597C13D831ec7', 6);
        $this->post('/pay/'.$p->uuid.'/usdt', ['tx_hash' => $h]);
        $this->assertSame('paid', $p->fresh()->status);

        $p2 = $this->resellerPayment('usdt', 600_000, ['usdt_network' => 'bep20']);
        $h2 = '0x'.str_repeat('2', 64);
        $this->evmTx($h2, self::EVM_ADDR, 1.0, '0x55d398326f99059fF775485246999027B3197955', 18);
        $this->post('/pay/'.$p2->uuid.'/usdt', ['tx_hash' => $h2]);
        $this->assertSame('paid', $p2->fresh()->status);

        $p3 = $this->resellerPayment('usdt', 600_000, ['usdt_network' => 'bep20']);
        $h3 = '0x'.str_repeat('3', 64);
        $this->evmTx($h3, self::EVM_ADDR, 1.0, '0xdAC17F958D2ee523a2206206994597C13D831ec7', 18);   // ERC20 contract on BSC: wrong token
        $this->post('/pay/'.$p3->uuid.'/usdt', ['tx_hash' => $h3]);
        $this->assertSame('review', $p3->fresh()->status);
    }

    public function test_explorer_down_goes_to_manual_review(): void
    {
        $this->usdtOn();
        $p = $this->resellerPayment('usdt', 1_200_000, ['usdt_network' => 'trc20']);
        $this->bank['down'] = true;
        $this->post('/pay/'.$p->uuid.'/usdt', ['tx_hash' => str_repeat('ef', 32)])->assertSessionHas('warning');
        $this->assertSame('review', $p->fresh()->status);
        $this->assertStringContainsString('دستی', (string) $p->fresh()->error);
    }

    public function test_settings_page_hides_secrets_and_requires_confirmation(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/settings/payments')->assertRedirect('/confirm-password');
        $this->actingAs($admin)->confirmed()->put('/settings/payments', ['zarinpal__enabled' => '1', 'zarinpal__merchant_id' => 'SECRET-MERCHANT-XYZ',
            'cards' => [['number' => '۶۰۳۷-۹۹۱۲-۳۴۵۶-۷۸۹۰', 'holder' => 'الف', 'bank' => 'ملی']]])->assertSessionHas('success');
        $this->actingAs($admin)->confirmed()->get('/settings/payments')->assertOk()->assertDontSee('SECRET-MERCHANT-XYZ')->assertSee('ذخیره شده')->assertSee('6037991234567890');
        $this->assertSame(['zarinpal' => 'زرین‌پال'], app(Payments::class)->enabledMethods());
        $this->assertStringNotContainsString('SECRET-MERCHANT', AuditLog::query()->get()->toJson());
        $this->actingAs($admin)->confirmed()->put('/settings/payments', ['usdt__address__trc20' => 'not-an-address'])->assertSessionHasErrors('usdt__address__trc20');
        $this->actingAs($this->userWith(['payments.view']))->confirmed()->get('/settings/payments')->assertForbidden();
    }

    public function test_admin_payment_pages(): void
    {
        $this->enable(['card.enabled' => '1', 'card.cards' => json_encode([['number' => '6037991234567890', 'holder' => 'a', 'bank' => 'b']])]);
        $p = $this->resellerPayment('card', 100000);
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/payments')->assertOk()->assertSee('کارت به کارت');
        $this->actingAs($admin)->get('/payments?status=pending&method=card')->assertOk();
        $this->actingAs($admin)->get('/payments/'.$p->id)->assertOk()->assertDontSee('تأیید و شارژ');
        $this->actingAs($admin)->confirmed()->from('/payments/'.$p->id)->post('/payments/'.$p->id.'/approve', ['reason' => 'بدون اطلاعات'])->assertSessionHas('error');
        $this->assertSame(0, $this->walletBalance($p), 'cannot approve before the payer reports the transfer');
        $this->post('/pay/'.$p->uuid.'/card', ['tracking' => '9', 'last4' => '1234']);
        $this->actingAs($admin)->get('/payments/'.$p->id)->assertOk()->assertSee('تأیید و شارژ');
        $this->actingAs($this->userWith(['dashboard.view']))->get('/payments')->assertForbidden();
        $r = $p->wallet->owner_id;
        $this->actingAs(Reseller::query()->find($r)->user)->get('/payments')->assertForbidden();
    }
}
