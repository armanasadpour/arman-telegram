<?php
declare(strict_types=1);

/**
 * Behpardakht Mellat — SOAP web service (namespace http://interfaces.core.sw.bps.com/).
 * Sent as raw XML over cURL, so the PHP SOAP extension is not required on the host.
 *   bpPayRequest(terminalId,userName,userPassword,orderId,amount(Rial),localDate Ymd,localTime His,
 *                additionalData,callBackUrl,payerId) -> "0,RefId"
 *   pay:    POST form to https://bpm.shaparak.ir/pgwchannel/startpay.mellat with RefId
 *   return: POST RefId, ResCode (0 = success), SaleOrderId, SaleReferenceId, CardHolderPan, FinalAmount
 *   bpVerifyRequest(terminalId,userName,userPassword,orderId,saleOrderId,saleReferenceId) -> "0" (43 = already verified)
 *   bpSettleRequest(...same...) -> "0" (45 = already settled)
 *   bpReversalRequest(...same...) when verification or settlement fails, so the customer is refunded by the bank.
 */

const MELLAT_SOAP_URL = 'https://bpm.shaparak.ir/pgwchannel/services/pgw';
const MELLAT_PAY_URL = 'https://bpm.shaparak.ir/pgwchannel/startpay.mellat';

function mellat_soap(string $operation, array $params, ?callable $transport): string
{
    $body = '';
    foreach ($params as $k => $v) {
        $body .= '<' . $k . '>' . htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $k . '>';
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://interfaces.core.sw.bps.com/"><soapenv:Body><ns1:' .
        $operation .
        '>' .
        $body .
        '</ns1:' .
        $operation .
        '></soapenv:Body></soapenv:Envelope>';
    $raw = gateway_http(
        'mellat.' . $operation,
        'POST',
        MELLAT_SOAP_URL,
        $xml,
        ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'],
        $transport,
    );
    if (!preg_match('#<return>([^<]*)</return>#', $raw['body'], $m)) {
        throw new AuthError('پاسخ نامعتبر از وب‌سرویس به‌پرداخت ملت.', 503);
    }
    return trim(html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
}

function mellat_message(string $code): string
{
    return [
        '11' => 'شماره کارت نامعتبر است.',
        '12' => 'موجودی کافی نیست.',
        '13' => 'رمز نادرست است.',
        '17' => 'کاربر از انجام تراکنش منصرف شد.',
        '21' => 'پذیرنده نامعتبر است.',
        '24' => 'اطلاعات کاربری پذیرنده نامعتبر است.',
        '25' => 'مبلغ نامعتبر است.',
        '41' => 'شماره درخواست تکراری است.',
        '42' => 'تراکنش یافت نشد.',
        '43' => 'قبلاً درخواست تأیید داده شده است.',
        '45' => 'تراکنش قبلاً تسویه شده است.',
        '48' => 'تراکنش برگشت خورده است.',
        '421' => 'IP سرور نامعتبر است؛ IP هاست را در بانک ثبت کنید.',
        '62' => 'آدرس بازگشت در دامنه ثبت‌شده پذیرنده نیست.',
    ][$code] ?? 'به‌پرداخت ملت درخواست را نپذیرفت (کد ' . fa_number($code) . ').';
}

function mellat_auth(array $cfg): array
{
    return ['terminalId' => $cfg['terminal_id'], 'userName' => $cfg['username'], 'userPassword' => $cfg['password']];
}

function gateway_mellat_request(array $tx, array $cfg, string $callback, ?callable $transport = null): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    $result = mellat_soap(
        'bpPayRequest',
        mellat_auth($cfg) + [
            'orderId' => $tx['gateway_order_id'],
            'amount' => (int) $tx['amount_toman'] * 10,
            'localDate' => $now->format('Ymd'),
            'localTime' => $now->format('His'),
            'additionalData' => mb_substr((string) $tx['description'], 0, 100),
            'callBackUrl' => $callback,
            'payerId' => 0,
        ],
        $transport,
    );
    $parts = explode(',', $result);
    if ($parts[0] !== '0' || !preg_match('/^[A-Za-z0-9]{6,64}$/', $parts[1] ?? '')) {
        throw new AuthError(mellat_message($parts[0]), 503);
    }
    return $parts[1];
}

function gateway_mellat_redirect(array $tx, array $cfg): array
{
    return ['method' => 'POST', 'url' => MELLAT_PAY_URL, 'fields' => ['RefId' => $tx['gateway_token']]];
}

function gateway_mellat_verify(array $tx, array $cfg, array $input, ?callable $transport = null): array
{
    if (!hash_equals((string) $tx['gateway_token'], (string) ($input['RefId'] ?? '')) || (string) ($input['SaleOrderId'] ?? '') !== (string) $tx['gateway_order_id']) {
        return ['ok' => false, 'error' => 'اطلاعات بازگشتی ملت با تراکنش مطابقت ندارد.'];
    }
    $resCode = (string) ($input['ResCode'] ?? '');
    if ($resCode !== '0') {
        return ['ok' => false, 'cancelled' => $resCode === '17', 'error' => mellat_message($resCode)];
    }
    $saleRef = (string) ($input['SaleReferenceId'] ?? '');
    if (!preg_match('/^[0-9]{1,30}$/', $saleRef)) {
        return ['ok' => false, 'error' => 'شماره مرجع ملت نامعتبر است.'];
    }
    $params = mellat_auth($cfg) + ['orderId' => $tx['gateway_order_id'], 'saleOrderId' => $tx['gateway_order_id'], 'saleReferenceId' => $saleRef];
    $verify = mellat_soap('bpVerifyRequest', $params, $transport);
    if ($verify !== '0' && $verify !== '43') {
        mellat_soap('bpReversalRequest', $params, $transport);
        return ['ok' => false, 'error' => mellat_message($verify) . ' مبلغ توسط بانک برگشت داده می‌شود.'];
    }
    $settle = mellat_soap('bpSettleRequest', $params, $transport);
    if ($settle !== '0' && $settle !== '45') {
        if ($settle !== '48') {
            mellat_soap('bpReversalRequest', $params, $transport);
        }
        return ['ok' => false, 'error' => mellat_message($settle) . ' مبلغ توسط بانک برگشت داده می‌شود.'];
    }
    return ['ok' => true, 'ref' => $saleRef, 'card' => $input['CardHolderPan'] ?? null];
}
