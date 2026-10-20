<?php
declare(strict_types=1);

/**
 * Zarinpal REST v4 — official docs: https://www.zarinpal.com/docs/paymentGateway/connectToGateway
 *   request: POST {base}/pg/v4/payment/request.json {merchant_id, amount (Rial), currency, description, callback_url, metadata}
 *            -> data.code = 100, data.authority
 *   pay:     GET  {base}/pg/StartPay/{authority}
 *   return:  ?Authority=...&Status=OK|NOK
 *   verify:  POST {base}/pg/v4/payment/verify.json {merchant_id, amount, authority}
 *            -> data.code 100 (or 101 = already verified), data.ref_id, data.card_pan
 * Sandbox base: https://sandbox.zarinpal.com (only allowed on development installations).
 */

function zarinpal_base(array $cfg): string
{
    return ($cfg['sandbox'] ?? '0') === '1' ? 'https://sandbox.zarinpal.com/pg/' : 'https://payment.zarinpal.com/pg/';
}

function zarinpal_call(string $op, array $cfg, array $payload, ?callable $transport): array
{
    $raw = gateway_http(
        'zarinpal.' . $op,
        'POST',
        zarinpal_base($cfg) . 'v4/payment/' . $op . '.json',
        json_encode($payload),
        ['Content-Type: application/json', 'Accept: application/json'],
        $transport,
    );
    $r = gateway_json($raw);
    if (!is_array($r['data'] ?? null) || !isset($r['data']['code'])) {
        $code = $r['errors']['code'] ?? null;
        return ['code' => $code !== null ? (int) $code : 0];
    }
    return $r['data'];
}

function gateway_zarinpal_request(array $tx, array $cfg, string $callback, ?callable $transport = null): string
{
    $data = zarinpal_call(
        'request',
        $cfg,
        [
            'merchant_id' => $cfg['merchant_id'],
            'amount' => (int) $tx['amount_toman'] * 10,
            'currency' => 'IRR',
            'description' => mb_substr($tx['description'] ?: 'پرداخت نوبت‌یار', 0, 200),
            'callback_url' => $callback,
            'metadata' => ['order_id' => $tx['order_code']],
        ],
        $transport,
    );
    if ((int) $data['code'] !== 100 || !preg_match('/^[A-Za-z0-9]{20,64}$/', (string) ($data['authority'] ?? ''))) {
        throw new AuthError('درخواست پرداخت توسط زرین‌پال پذیرفته نشد (کد ' . fa_number((string) $data['code']) . ').', 503);
    }
    return (string) $data['authority'];
}

function gateway_zarinpal_redirect(array $tx, array $cfg): array
{
    return ['method' => 'GET', 'url' => zarinpal_base($cfg) . 'StartPay/' . rawurlencode($tx['gateway_token']), 'fields' => []];
}

function gateway_zarinpal_verify(array $tx, array $cfg, array $input, ?callable $transport = null): array
{
    $authority = (string) ($input['Authority'] ?? '');
    if (!hash_equals((string) $tx['gateway_token'], $authority)) {
        return ['ok' => false, 'error' => 'شناسه بازگشتی با تراکنش مطابقت ندارد.'];
    }
    if (($input['Status'] ?? '') !== 'OK') {
        return ['ok' => false, 'cancelled' => true, 'error' => 'پرداخت توسط کاربر لغو شد یا ناموفق بود.'];
    }
    $data = zarinpal_call(
        'verify',
        $cfg,
        ['merchant_id' => $cfg['merchant_id'], 'amount' => (int) $tx['amount_toman'] * 10, 'authority' => $authority],
        $transport,
    );
    if (!in_array((int) $data['code'], [100, 101], true) || !preg_match('/^[0-9]{1,30}$/', (string) ($data['ref_id'] ?? ''))) {
        return ['ok' => false, 'error' => 'زرین‌پال پرداخت را تأیید نکرد (کد ' . fa_number((string) $data['code']) . ').'];
    }
    return ['ok' => true, 'ref' => (string) $data['ref_id'], 'card' => $data['card_pan'] ?? null];
}
