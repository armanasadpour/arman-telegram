<?php

namespace App\Payments;

use App\Models\Payment;
use Throwable;

/**
 * Saman (SEP) token flow: onlinepg/onlinepg {action:token} -> POST OnlinePG/OnlinePG Token -> callback (POST:
 * State, RefNum, ResNum…) -> verifyTxnRandomSessionkey/ipg/VerifyTransaction {RefNum, TerminalNumber}. Rial.
 */
class Sep extends BaseGateway
{
    public function origin(): string
    {
        return self::originOf($this->host('https://sep.shaparak.ir'));
    }

    public function isConfigured(): bool
    {
        return $this->setting('sep.terminal_id') !== '';
    }

    public function start(Payment $payment, string $callbackUrl): GatewayRedirect
    {
        $r = $this->postJson($this->host('https://sep.shaparak.ir').'/onlinepg/onlinepg', [
            'action' => 'token', 'TerminalId' => $this->setting('sep.terminal_id'), 'Amount' => $payment->amount_rial,
            'ResNum' => $payment->uuid, 'RedirectUrl' => $callbackUrl,
        ]);
        if ((int) $r->json('status') !== 1 || ! $r->json('token')) {
            throw new PaymentException('درگاه سامان درخواست را نپذیرفت'.($r->json('errorCode') ? ' (کد '.$r->json('errorCode').')' : ''));
        }
        $payment->gateway_token = (string) $r->json('token');

        return new GatewayRedirect($this->host('https://sep.shaparak.ir').'/OnlinePG/OnlinePG', 'POST', ['Token' => $payment->gateway_token]);
    }

    public function verify(Payment $payment, array $cb): VerifyResult
    {
        if (($cb['ResNum'] ?? null) !== $payment->uuid) {
            return VerifyResult::failed('شناسه بازگشتی با پرداخت مطابقت ندارد.');
        }
        if (($cb['State'] ?? '') !== 'OK' || empty($cb['RefNum'])) {
            return VerifyResult::failed('پرداخت ناموفق یا لغو شد ('.mb_substr((string) ($cb['State'] ?? '-'), 0, 40).').');
        }
        try {
            $r = $this->http()->asJson()->post($this->host('https://sep.shaparak.ir').'/verifyTxnRandomSessionkey/ipg/VerifyTransaction', [
                'RefNum' => (string) $cb['RefNum'], 'TerminalNumber' => (int) $this->setting('sep.terminal_id'),
            ]);
        } catch (Throwable) {
            return VerifyResult::uncertain('اتصال به سامان برای تأیید برقرار نشد.');
        }
        $code = $r->json('ResultCode');
        $detail = (array) $r->json('TransactionDetail');
        if (in_array((int) $code, [0, 2], true) && $detail) {   // 2 = already verified
            $amount = (int) ($detail['OrginalAmount'] ?? $detail['AffectiveAmount'] ?? 0);
            if ($amount !== $payment->amount_rial) {
                return VerifyResult::failed('مبلغ تأییدشده بانک با مبلغ پرداخت برابر نیست.');
            }

            return VerifyResult::paid((string) $cb['RefNum'], $detail['MaskedPan'] ?? null, $amount);
        }
        if ($code === null) {
            return VerifyResult::uncertain('پاسخ نامعتبر از سامان (HTTP '.$r->status().')');
        }

        return VerifyResult::failed('تأیید سامان ناموفق بود (کد '.$code.').');
    }
}
