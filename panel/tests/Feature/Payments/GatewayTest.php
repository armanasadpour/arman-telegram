<?php

namespace Tests\Feature\Payments;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Payments\Mellat;
use App\Payments\Sadad;

class GatewayTest extends PaymentTestCase
{
    public function test_zarinpal_reseller_topup_end_to_end_and_idempotent(): void
    {
        $this->enable(['zarinpal.enabled' => '1', 'zarinpal.merchant_id' => 'MERCHANT-123']);
        $r = $this->reseller();
        $this->actingAs($r->user)->get('/r/topup')->assertOk()->assertSee('زرین‌پال');
        $res = $this->actingAs($r->user)->post('/r/topup', ['amount_toman' => 50000, 'method' => 'zarinpal']);
        $p = Payment::query()->firstOrFail();
        $res->assertRedirect(route('pay.show', $p->uuid));
        $page = $this->get('/pay/'.$p->uuid)->assertOk()->assertSee('انتقال به درگاه');
        $this->assertStringContainsString("form-action 'self' https://payment.zarinpal.com;", $page->headers->get('Content-Security-Policy'), 'the form post is redirected to the bank: CSP of the submitting page must allow it');
        $this->post('/pay/'.$p->uuid.'/go')->assertRedirect('https://payment.zarinpal.com/pg/StartPay/'.$p->fresh()->gateway_token);
        $this->assertSame(500000, $this->bank['zp_request']['amount']);
        $this->assertSame(route('pay.callback', $p->uuid), $this->bank['zp_request']['callback_url']);
        $this->assertSame('redirected', $p->fresh()->status);

        $cb = '/pay/callback/'.$p->uuid.'?Authority='.$p->fresh()->gateway_token.'&Status=OK';
        $this->get($cb)->assertOk()->assertSee('پرداخت تأیید شد');
        $this->get($cb)->assertOk();                              // reload / second bank call
        $this->post($cb, ['Authority' => $p->fresh()->gateway_token, 'Status' => 'OK'])->assertOk();
        $this->assertSame(500000, $this->balance($r));
        $this->assertSame(1, LedgerEntry::query()->where('type', 'topup')->count());
        $this->assertSame(['merchant_id' => 'MERCHANT-123', 'amount' => 500000, 'authority' => $p->fresh()->gateway_token], $this->bank['zp_verify']);
        $p->refresh();
        $this->assertSame('paid', $p->status);
        $this->assertSame('502229******1234', $p->card_pan);
    }

    public function test_zarinpal_cancel_wrong_authority_and_failed_verify_credit_nothing(): void
    {
        $this->enable(['zarinpal.enabled' => '1', 'zarinpal.merchant_id' => 'M']);
        $p = $this->resellerPayment('zarinpal', 100000);
        $this->post('/pay/'.$p->uuid.'/go');
        $this->get('/pay/callback/'.$p->uuid.'?Authority=SOMETHINGELSE&Status=OK')->assertOk();
        $this->assertSame('failed', $p->fresh()->status);
        $this->assertArrayNotHasKey('zp_verify', $this->bank, 'never verified with a foreign authority');

        $p2 = $this->resellerPayment('zarinpal', 100000);
        $this->post('/pay/'.$p2->uuid.'/go');
        $this->get('/pay/callback/'.$p2->uuid.'?Authority='.$p2->fresh()->gateway_token.'&Status=NOK')->assertSee('هیچ مبلغی');
        $this->assertSame('failed', $p2->fresh()->status);

        $p3 = $this->resellerPayment('zarinpal', 100000);
        $this->post('/pay/'.$p3->uuid.'/go');
        $this->bank['verify_code'] = -51;
        $this->get('/pay/callback/'.$p3->uuid.'?Authority='.$p3->fresh()->gateway_token.'&Status=OK');
        $this->assertSame('failed', $p3->fresh()->status);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_uncertain_verify_is_retried_by_cron_and_credited_once(): void
    {
        $this->enable(['zarinpal.enabled' => '1', 'zarinpal.merchant_id' => 'M']);
        $p = $this->resellerPayment('zarinpal', 200000);
        $this->post('/pay/'.$p->uuid.'/go');
        $this->bank['down'] = true;
        $this->get('/pay/callback/'.$p->uuid.'?Authority='.$p->fresh()->gateway_token.'&Status=OK')->assertSee('خودکار دوباره انجام می‌شود');
        $this->assertSame('redirected', $p->fresh()->status);
        $this->bank['down'] = false;
        $this->travel(3)->minutes();
        $this->artisan('payments:reverify')->assertSuccessful();
        $this->artisan('payments:reverify')->assertSuccessful();
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame(200000, $this->walletBalance($p));
    }

    public function test_sep_post_redirect_csp_and_amount_check(): void
    {
        $this->enable(['sep.enabled' => '1', 'sep.terminal_id' => '13001234']);
        $p = $this->resellerPayment('sep', 300000);
        $res = $this->post('/pay/'.$p->uuid.'/go')->assertOk()->assertSee('https://sep.shaparak.ir/OnlinePG/OnlinePG', false)->assertSee('SEPTOKEN1');
        $this->assertStringContainsString("form-action 'self' https://sep.shaparak.ir", $res->headers->get('Content-Security-Policy'));
        $this->assertSame($p->uuid, $this->bank['sep_request']['ResNum']);

        $this->bank['verify_amount'] = 299000;
        $this->post('/pay/callback/'.$p->uuid, ['State' => 'OK', 'RefNum' => 'REF-A', 'ResNum' => $p->uuid])->assertOk();
        $this->assertSame('failed', $p->fresh()->status, 'amount mismatch');

        $p2 = $this->resellerPayment('sep', 300000);
        $this->post('/pay/'.$p2->uuid.'/go');
        $this->bank['verify_amount'] = 300000;
        $this->post('/pay/callback/'.$p2->uuid, ['State' => 'OK', 'RefNum' => 'REF-B', 'ResNum' => $p2->uuid])->assertOk();
        $this->assertSame('paid', $p2->fresh()->status);
        $this->assertSame(['RefNum' => 'REF-B', 'TerminalNumber' => 13001234], $this->bank['sep_verify']);

        // The same bank reference cannot pay a second payment.
        $p3 = $this->resellerPayment('sep', 300000);
        $this->post('/pay/'.$p3->uuid.'/go');
        $this->post('/pay/callback/'.$p3->uuid, ['State' => 'OK', 'RefNum' => 'REF-B', 'ResNum' => $p3->uuid])->assertOk()->assertSee('قبلاً');
        $this->assertSame('failed', $p3->fresh()->status);
        $this->assertSame(0, $this->walletBalance($p3));
    }

    public function test_mellat_soap_verify_and_settle(): void
    {
        $this->enable(['mellat.enabled' => '1', 'mellat.terminal_id' => '555', 'mellat.username' => 'u', 'mellat.password' => 'pw']);
        $p = $this->resellerPayment('mellat', 120000);
        $this->post('/pay/'.$p->uuid.'/go')->assertOk()->assertSee('startpay.mellat')->assertSee('MELLATREF1');
        $oid = Mellat::orderId($p);
        $this->post('/pay/callback/'.$p->uuid, ['RefId' => 'MELLATREF1', 'ResCode' => '0', 'SaleOrderId' => $oid, 'SaleReferenceId' => '777888', 'CardHolderPan' => '610433****1234'])->assertOk();
        $this->assertSame(['bpPayRequest', 'bpVerifyRequest', 'bpSettleRequest'], $this->bank['mellat_calls']);
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame('777888', $p->fresh()->gateway_ref);

        // failed verify: no credit, settle never called
        $p2 = $this->resellerPayment('mellat', 120000);
        $this->post('/pay/'.$p2->uuid.'/go');
        $this->bank['mellat_verify'] = '415';
        $this->bank['mellat_calls'] = [];
        $this->post('/pay/callback/'.$p2->uuid, ['RefId' => 'MELLATREF2', 'ResCode' => '0', 'SaleOrderId' => Mellat::orderId($p2), 'SaleReferenceId' => '999']);
        $this->assertSame('failed', $p2->fresh()->status);
        $this->assertNotContains('bpSettleRequest', $this->bank['mellat_calls']);
    }

    public function test_sadad_signdata_and_verify(): void
    {
        $key = base64_encode(str_repeat('K', 24));
        $this->enable(['sadad.enabled' => '1', 'sadad.merchant_id' => '000000140000000', 'sadad.terminal_id' => '24000000', 'sadad.key' => $key]);
        $p = $this->resellerPayment('sadad', 150000);
        $this->post('/pay/'.$p->uuid.'/go')->assertRedirect('https://sadad.shaparak.ir/VPG/Purchase?Token=SADADTOKEN1');
        $oid = Sadad::orderId($p);
        $expected = base64_encode(openssl_encrypt('24000000;'.$oid.';150000', 'DES-EDE3', str_repeat('K', 24), OPENSSL_RAW_DATA));
        $this->assertSame($expected, $this->bank['sadad_request']['SignData']);
        $this->bank['verify_amount'] = 150000;
        $this->post('/pay/callback/'.$p->uuid, ['OrderId' => $oid, 'token' => 'SADADTOKEN1', 'ResCode' => '0'])->assertOk();
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame(base64_encode(openssl_encrypt('SADADTOKEN1', 'DES-EDE3', str_repeat('K', 24), OPENSSL_RAW_DATA)), $this->bank['sadad_verify']['SignData']);
    }

    public function test_disabled_or_unconfigured_methods_are_refused(): void
    {
        $r = $this->reseller();
        $this->actingAs($r->user)->get('/r/topup')->assertSee('هنوز روش پرداختی فعال نشده');
        $this->enable(['zarinpal.enabled' => '1']);   // no merchant id
        $this->actingAs($r->user)->post('/r/topup', ['amount_toman' => 50000, 'method' => 'zarinpal'])->assertSessionHasErrors('method');
        $this->assertSame(0, Payment::query()->count());
        $this->get('/pay/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }
}
