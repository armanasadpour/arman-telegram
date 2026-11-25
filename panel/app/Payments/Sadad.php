<?php

namespace App\Payments;

use App\Models\Payment;
use Throwable;

/**
 * Sadad (Bank Melli): Request/PaymentRequest (SignData = 3DES-ECB "TerminalId;OrderId;Amount" with the merchant key)
 * -> GET VPG/Purchase?Token -> callback (OrderId, token, ResCode) -> Advice/Verify (SignData = 3DES(token)). Rial.
 */
class Sadad extends BaseGateway
{
    public function origin(): string
    {
        return self::originOf($this->host('https://sadad.shaparak.ir'));
    }

    public function isConfigured(): bool
    {
        return $this->setting('sadad.merchant_id') !== '' && $this->setting('sadad.terminal_id') !== '' && $this->setting('sadad.key') !== '';
    }

    public static function orderId(Payment $p): int
    {
        return 10_000_000 + $p->id;
    }

    public function sign(string $data): string
    {
        $enc = openssl_encrypt($data, 'DES-EDE3', (string) base64_decode($this->setting('sadad.key')), OPENSSL_RAW_DATA);
        if ($enc === false) {
            throw new PaymentException('کلید سداد نامعتبر است.');
        }

        return base64_encode($enc);
    }

    public function start(Payment $payment, string $callbackUrl): GatewayRedirect
    {
        $tid = $this->setting('sadad.terminal_id');
        $oid = self::orderId($payment);
        $r = $this->postJson($this->host('https://sadad.shaparak.ir').'/vpg/api/v0/Request/PaymentRequest', [
            'TerminalId' => $tid, 'MerchantId' => $this->setting('sadad.merchant_id'), 'Amount' => $payment->amount_rial,
            'OrderId' => $oid, 'LocalDateTime' => now()->format('m/d/Y g:i:s a'), 'ReturnUrl' => $callbackUrl,
            'SignData' => $this->sign($tid.';'.$oid.';'.$payment->amount_rial),
        ]);
        if ((string) $r->json('ResCode') !== '0' || ! $r->json('Token')) {
            throw new PaymentException('درگاه سداد درخواست را نپذیرفت'.($r->json('Description') ? ': '.mb_substr((string) $r->json('Description'), 0, 120) : '.'));
        }
        $payment->gateway_token = (string) $r->json('Token');

        return new GatewayRedirect($this->host('https://sadad.shaparak.ir').'/VPG/Purchase?Token='.urlencode($payment->gateway_token));
    }

    public function verify(Payment $payment, array $cb): VerifyResult
    {
        $token = (string) ($cb['token'] ?? $cb['Token'] ?? '');
        if ($token !== $payment->gateway_token || (string) ($cb['OrderId'] ?? '') !== (string) self::orderId($payment)) {
            return VerifyResult::failed('شناسه بازگشتی با پرداخت مطابقت ندارد.');
        }
        if ((string) ($cb['ResCode'] ?? '') !== '0') {
            return VerifyResult::failed('پرداخت ناموفق یا لغو شد.');
        }
        try {
            $r = $this->http()->asJson()->post($this->host('https://sadad.shaparak.ir').'/vpg/api/v0/Advice/Verify', ['Token' => $token, 'SignData' => $this->sign($token)]);
        } catch (Throwable) {
            return VerifyResult::uncertain('اتصال به سداد برای تأیید برقرار نشد.');
        }
        if ((string) $r->json('ResCode') === '0') {
            if ((int) $r->json('Amount') !== $payment->amount_rial) {
                return VerifyResult::failed('مبلغ تأییدشده بانک با مبلغ پرداخت برابر نیست.');
            }

            return VerifyResult::paid((string) ($r->json('RetrivalRefNo') ?: $r->json('SystemTraceNo')), null, (int) $r->json('Amount'));
        }
        if ($r->json('ResCode') === null) {
            return VerifyResult::uncertain('پاسخ نامعتبر از سداد (HTTP '.$r->status().')');
        }

        return VerifyResult::failed('تأیید سداد ناموفق بود'.($r->json('Description') ? ': '.mb_substr((string) $r->json('Description'), 0, 120) : '.'));
    }
}
