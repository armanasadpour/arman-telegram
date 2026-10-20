<?php
declare(strict_types=1);

/**
 * Sadad (Bank Melli) — API v0.
 *   request: POST https://sadad.shaparak.ir/api/v0/Request/PaymentRequest
 *            {MerchantId, TerminalId, Amount (Rial), OrderId (numeric), LocalDateTime "m/d/Y g:i:s a",
 *             ReturnUrl, SignData = Base64(TripleDES-ECB(key, "TerminalId;OrderId;Amount")), AdditionalData, UserId}
 *            -> {ResCode: 0, Token, Description}
 *   pay:     GET https://sadad.shaparak.ir/Purchase?Token=...
 *   return:  POST OrderId, token, ResCode (0 = success)
 *   verify:  POST https://sadad.shaparak.ir/api/v0/Advice/Verify {Token, SignData = Base64(TripleDES(key, Token))}
 *            -> {ResCode: 0, Amount, RetrivalRefNo, SystemTraceNo, OrderId, Description}
 * The terminal key is the Base64 string issued by the bank (24-byte TripleDES key).
 */

const SADAD_REQUEST_URL = 'https://sadad.shaparak.ir/api/v0/Request/PaymentRequest';
const SADAD_PURCHASE_URL = 'https://sadad.shaparak.ir/Purchase';
const SADAD_VERIFY_URL = 'https://sadad.shaparak.ir/api/v0/Advice/Verify';

function sadad_sign(string $data, string $base64Key): string
{
    $key = base64_decode($base64Key, true);
    if ($key === false || !in_array(strlen($key), [16, 24], true)) {
        throw new AuthError('کلید ترمینال سداد معتبر نیست.');
    }
    $cipher = openssl_encrypt($data, 'DES-EDE3', strlen($key) === 16 ? $key . substr($key, 0, 8) : $key, OPENSSL_RAW_DATA);
    if ($cipher === false) {
        throw new AuthError('امضای TripleDES روی این هاست پشتیبانی نمی‌شود.', 503);
    }
    return base64_encode($cipher);
}

function sadad_message(int $code): string
{
    return [
        3 => 'پذیرنده فعال نیست.',
        23 => 'پذیرنده نامعتبر است.',
        58 => 'انجام تراکنش توسط این پایانه مجاز نیست.',
        61 => 'مبلغ تراکنش از حد مجاز بیشتر است.',
        101 => 'مهلت ارسال تراکنش تمام شده است.',
        1001 => 'پارامترهای پرداخت اشتباه است.',
        1006 => 'خطا در سیستم سداد.',
        1011 => 'شماره سفارش تکراری است.',
        1012 => 'اطلاعات پذیرنده (تاریخ، زمان یا کلید) صحیح نیست.',
        1018 => 'ساعت سرور با بانک هماهنگ نیست.',
        1023 => 'آدرس بازگشت پذیرنده نامعتبر است.',
        1025 => 'امضای تراکنش نامعتبر است.',
        1101 => 'مبلغ تراکنش نامعتبر است.',
        1103 => 'توکن نامعتبر است.',
        1105 => 'تراکنش بازگشت داده شده است.',
    ][$code] ?? 'سداد درخواست را نپذیرفت (کد ' . fa_number((string) $code) . ').';
}

function gateway_sadad_request(array $tx, array $cfg, string $callback, ?callable $transport = null): string
{
    $amount = (int) $tx['amount_toman'] * 10;
    $order = (string) $tx['gateway_order_id'];
    $raw = gateway_http(
        'sadad.request',
        'POST',
        SADAD_REQUEST_URL,
        json_encode([
            'MerchantId' => $cfg['merchant_id'],
            'TerminalId' => $cfg['terminal_id'],
            'Amount' => $amount,
            'OrderId' => (int) $order,
            'LocalDateTime' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('m/d/Y g:i:s a'),
            'ReturnUrl' => $callback,
            'SignData' => sadad_sign($cfg['terminal_id'] . ';' . $order . ';' . $amount, $cfg['key']),
            'AdditionalData' => mb_substr((string) $tx['description'], 0, 100),
            'UserId' => '',
        ]),
        ['Content-Type: application/json', 'Accept: application/json'],
        $transport,
    );
    $r = gateway_json($raw);
    if ((string) ($r['ResCode'] ?? '') !== '0' || !preg_match('/^[A-Za-z0-9-]{8,190}$/', (string) ($r['Token'] ?? ''))) {
        throw new AuthError(sadad_message((int) ($r['ResCode'] ?? -1)), 503);
    }
    return (string) $r['Token'];
}

function gateway_sadad_redirect(array $tx, array $cfg): array
{
    return ['method' => 'GET', 'url' => SADAD_PURCHASE_URL . '?Token=' . rawurlencode($tx['gateway_token']), 'fields' => []];
}

function gateway_sadad_verify(array $tx, array $cfg, array $input, ?callable $transport = null): array
{
    $token = (string) ($input['token'] ?? $input['Token'] ?? '');
    if (!hash_equals((string) $tx['gateway_token'], $token) || (string) ($input['OrderId'] ?? '') !== (string) $tx['gateway_order_id']) {
        return ['ok' => false, 'error' => 'اطلاعات بازگشتی سداد با تراکنش مطابقت ندارد.'];
    }
    if ((string) ($input['ResCode'] ?? '') !== '0') {
        return ['ok' => false, 'cancelled' => true, 'error' => 'پرداخت در درگاه سداد انجام نشد.'];
    }
    $raw = gateway_http(
        'sadad.verify',
        'POST',
        SADAD_VERIFY_URL,
        json_encode(['Token' => $token, 'SignData' => sadad_sign($token, $cfg['key'])]),
        ['Content-Type: application/json', 'Accept: application/json'],
        $transport,
    );
    $r = gateway_json($raw);
    if ((string) ($r['ResCode'] ?? '') !== '0' || (string) ($r['OrderId'] ?? '') !== (string) $tx['gateway_order_id']) {
        return ['ok' => false, 'error' => sadad_message((int) ($r['ResCode'] ?? -1))];
    }
    $ref = (string) ($r['RetrivalRefNo'] ?? '');
    if (!preg_match('/^[0-9]{4,30}$/', $ref)) {
        return ['ok' => false, 'error' => 'شماره مرجع سداد نامعتبر است.'];
    }
    return ['ok' => true, 'ref' => $ref, 'amount_rial' => (int) ($r['Amount'] ?? 0), 'card' => null];
}
