<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Behpardakht Mellat (SOAP, raw envelopes — no ext-soap needed): bpPayRequest -> POST startpay.mellat RefId ->
 * callback (RefId, ResCode, SaleOrderId, SaleReferenceId, CardHolderPan) -> bpVerifyRequest -> bpSettleRequest.
 * orderId must be a unique number: 10_000_000 + payment id.
 */
class Mellat extends BaseGateway
{
    private const NS = 'http://interfaces.core.sw.bps.com/';

    public function origin(): string
    {
        return self::originOf($this->host('https://bpm.shaparak.ir'));
    }

    public function isConfigured(): bool
    {
        return $this->setting('mellat.terminal_id') !== '' && $this->setting('mellat.username') !== '' && $this->setting('mellat.password') !== '';
    }

    public static function orderId(Payment $p): int
    {
        return 10_000_000 + $p->id;
    }

    public function start(Payment $payment, string $callbackUrl): GatewayRedirect
    {
        try {
            $ret = $this->call('bpPayRequest', $this->auth() + [
                'orderId' => self::orderId($payment), 'amount' => $payment->amount_rial,
                'localDate' => now()->format('Ymd'), 'localTime' => now()->format('His'),
                'additionalData' => '', 'callBackUrl' => $callbackUrl, 'payerId' => 0,
            ]);
        } catch (Throwable) {
            throw new PaymentException('اتصال به درگاه ملت برقرار نشد.');
        }
        $parts = explode(',', (string) $ret);
        if (($parts[0] ?? '') !== '0' || empty($parts[1])) {
            throw new PaymentException('درگاه ملت درخواست را نپذیرفت (کد '.($parts[0] ?? '?').').');
        }
        $payment->gateway_token = $parts[1];

        return new GatewayRedirect($this->host('https://bpm.shaparak.ir').'/pgwchannel/startpay.mellat', 'POST', ['RefId' => $parts[1]]);
    }

    public function verify(Payment $payment, array $cb): VerifyResult
    {
        if (($cb['RefId'] ?? null) !== $payment->gateway_token || (string) ($cb['SaleOrderId'] ?? '') !== (string) self::orderId($payment)) {
            return VerifyResult::failed('شناسه بازگشتی با پرداخت مطابقت ندارد.');
        }
        if ((string) ($cb['ResCode'] ?? '') !== '0' || empty($cb['SaleReferenceId'])) {
            return VerifyResult::failed('پرداخت ناموفق یا لغو شد (کد '.mb_substr((string) ($cb['ResCode'] ?? '-'), 0, 10).').');
        }
        $args = $this->auth() + ['orderId' => self::orderId($payment), 'saleOrderId' => self::orderId($payment), 'saleReferenceId' => (string) $cb['SaleReferenceId']];
        try {
            $v = (string) $this->call('bpVerifyRequest', $args);
            if (! in_array($v, ['0', '43'], true)) {          // 43 = already verified
                return VerifyResult::failed('تأیید ملت ناموفق بود (کد '.$v.'). مبلغ توسط بانک به پرداخت‌کننده برمی‌گردد.');
            }
            $s = (string) $this->call('bpSettleRequest', $args);
            if (! in_array($s, ['0', '45'], true)) {          // 45 = already settled
                return VerifyResult::uncertain('تسویه ملت انجام نشد (کد '.$s.')؛ دوباره تلاش می‌شود.');
            }
        } catch (Throwable) {
            return VerifyResult::uncertain('اتصال به ملت برای تأیید برقرار نشد.');
        }

        return VerifyResult::paid((string) $cb['SaleReferenceId'], isset($cb['CardHolderPan']) ? (string) $cb['CardHolderPan'] : null, $payment->amount_rial);
    }

    private function auth(): array
    {
        return ['terminalId' => (int) $this->setting('mellat.terminal_id'), 'userName' => $this->setting('mellat.username'), 'userPassword' => $this->setting('mellat.password')];
    }

    /** @return string the <return> value */
    private function call(string $method, array $args): string
    {
        $body = '';
        foreach ($args as $k => $v) {
            $body .= '<'.$k.'>'.htmlspecialchars((string) $v, ENT_XML1).'</'.$k.'>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.self::NS.'">'
            .'<soapenv:Body><ns1:'.$method.'>'.$body.'</ns1:'.$method.'></soapenv:Body></soapenv:Envelope>';
        $res = Http::timeout(20)->connectTimeout(8)->withHeaders(['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '""'])
            ->withBody($xml, 'text/xml; charset=utf-8')->post($this->host('https://bpm.shaparak.ir').'/pgwchannel/services/pgw');
        if (! preg_match('#<return>([^<]*)</return>#', $res->body(), $m)) {
            throw new PaymentException('پاسخ نامعتبر از درگاه ملت');
        }

        return trim($m[1]);
    }
}
