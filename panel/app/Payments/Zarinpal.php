<?php

namespace App\Payments;

use App\Models\Payment;
use Throwable;

/** Zarinpal REST v4: payment/request.json -> StartPay/{authority} -> callback ?Authority&Status -> payment/verify.json. Amounts in rial. */
class Zarinpal extends BaseGateway
{
    public function origin(): string
    {
        return self::originOf($this->base());
    }

    public function isConfigured(): bool
    {
        return $this->setting('zarinpal.merchant_id') !== '';
    }

    private function base(): string
    {
        return $this->host($this->setting('zarinpal.sandbox') === '1' ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com');
    }

    public function start(Payment $payment, string $callbackUrl): GatewayRedirect
    {
        $r = $this->postJson($this->base().'/pg/v4/payment/request.json', [
            'merchant_id' => $this->setting('zarinpal.merchant_id'),
            'amount' => $payment->amount_rial,
            'currency' => 'IRR',
            'callback_url' => $callbackUrl,
            'description' => 'شارژ کیف پول #'.$payment->id,
        ]);
        $d = $r->json('data');
        if (! is_array($d) || (int) ($d['code'] ?? 0) !== 100 || empty($d['authority'])) {
            throw new PaymentException('درگاه زرین‌پال درخواست را نپذیرفت'.$this->err($r->json('errors')));
        }
        $payment->gateway_token = (string) $d['authority'];

        return new GatewayRedirect($this->base().'/pg/StartPay/'.$d['authority']);
    }

    public function verify(Payment $payment, array $cb): VerifyResult
    {
        if (($cb['Authority'] ?? null) !== $payment->gateway_token) {
            return VerifyResult::failed('شناسه بازگشتی با پرداخت مطابقت ندارد.');
        }
        if (($cb['Status'] ?? '') !== 'OK') {
            return VerifyResult::failed('پرداخت توسط کاربر لغو شد یا ناموفق بود.');
        }
        try {
            $r = $this->http()->asJson()->post($this->base().'/pg/v4/payment/verify.json', [
                'merchant_id' => $this->setting('zarinpal.merchant_id'), 'amount' => $payment->amount_rial, 'authority' => $payment->gateway_token,
            ]);
        } catch (Throwable) {
            return VerifyResult::uncertain('اتصال به زرین‌پال برای تأیید برقرار نشد.');
        }
        $d = $r->json('data');
        if (is_array($d) && in_array((int) ($d['code'] ?? 0), [100, 101], true) && isset($d['ref_id'])) {
            return VerifyResult::paid((string) $d['ref_id'], $d['card_pan'] ?? null, $payment->amount_rial);
        }
        if ($r->serverError()) {
            return VerifyResult::uncertain('پاسخ نامعتبر از زرین‌پال (HTTP '.$r->status().')');
        }

        return VerifyResult::failed('تأیید زرین‌پال ناموفق بود'.$this->err($r->json('errors')));
    }

    private function err($errors): string
    {
        return is_array($errors) && isset($errors['code']) ? ' (کد '.$errors['code'].')' : '';
    }
}
