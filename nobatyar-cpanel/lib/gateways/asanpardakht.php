<?php
declare(strict_types=1);

/**
 * Asan Pardakht (AP) — IPG REST v1, base https://ipgrest.asanpardakht.ir/v1/, headers usr / pwd.
 *   GET  Time        -> "yyyyMMdd HHmmss" (bank clock, used as localDate)
 *   POST Token       {serviceTypeId:1, merchantConfigurationId, localInvoiceId, amountInRials, localDate,
 *                     callbackURL, paymentId:"0", additionalData} -> HTTP 200, body = token (JSON string)
 *   pay:  POST form to https://asan.shaparak.ir with RefID
 *   GET  TranResult?merchantConfigurationId=..&localInvoiceId=.. -> 200 {cardNumber, rrn, refID, amount, payGateTranID}
 *        (472 = no successful payment for this invoice)
 *   POST Verify     {merchantConfigurationId, payGateTranId} -> 200
 *   POST Settlement {merchantConfigurationId, payGateTranId} -> 200
 */

const AP_REST_URL = 'https://ipgrest.asanpardakht.ir/v1/';
const AP_PAY_URL = 'https://asan.shaparak.ir';

function ap_call(string $op, string $method, string $path, ?array $body, array $cfg, ?callable $transport): array
{
    return gateway_http(
        'asanpardakht.' . $op,
        $method,
        AP_REST_URL . $path,
        $body === null ? null : json_encode($body),
        ['Content-Type: application/json', 'Accept: application/json', 'usr: ' . $cfg['username'], 'pwd: ' . $cfg['password']],
        $transport,
    );
}

function ap_message(int $http): string
{
    return [
        401 => 'نام کاربری یا رمز آسان پرداخت نادرست است.',
        473 => 'نام کاربری یا رمز پذیرنده نادرست است.',
        474 => 'IP سرور در آسان پرداخت ثبت نشده است.',
        485 => 'تاریخ ارسالی نامعتبر است.',
        486 => 'مبلغ خارج از محدوده مجاز است.',
        487 => 'سرویس برای این پذیرنده فعال نیست.',
        489 => 'شماره فاکتور تکراری است.',
        490 => 'پذیرنده غیرفعال یا پیکربندی آن ناقص است.',
        472 => 'پرداخت موفقی برای این تراکنش ثبت نشده است.',
    ][$http] ?? 'آسان پرداخت درخواست را نپذیرفت (کد ' . fa_number((string) $http) . ').';
}

function gateway_asanpardakht_request(array $tx, array $cfg, string $callback, ?callable $transport = null): string
{
    $time = ap_call('time', 'GET', 'Time', null, $cfg, $transport);
    $localDate = trim((string) json_decode($time['body'], true));
    if ($time['http'] !== 200 || !preg_match('/^[0-9]{8} [0-9]{6}$/', $localDate)) {
        $localDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Ymd His');
    }
    $raw = ap_call(
        'token',
        'POST',
        'Token',
        [
            'serviceTypeId' => 1,
            'merchantConfigurationId' => (int) $cfg['merchant_config_id'],
            'localInvoiceId' => (int) $tx['gateway_order_id'],
            'amountInRials' => (int) $tx['amount_toman'] * 10,
            'localDate' => $localDate,
            'callbackURL' => $callback,
            'paymentId' => '0',
            'additionalData' => mb_substr((string) $tx['description'], 0, 100),
        ],
        $cfg,
        $transport,
    );
    $token = json_decode($raw['body'], true);
    if ($raw['http'] !== 200 || !is_string($token) || !preg_match('/^[A-Za-z0-9]{6,190}$/', $token)) {
        throw new AuthError(ap_message($raw['http']), 503);
    }
    return $token;
}

function gateway_asanpardakht_redirect(array $tx, array $cfg): array
{
    return ['method' => 'POST', 'url' => AP_PAY_URL, 'fields' => ['RefID' => $tx['gateway_token']]];
}

function gateway_asanpardakht_verify(array $tx, array $cfg, array $input, ?callable $transport = null): array
{
    $result = ap_call(
        'tranresult',
        'GET',
        'TranResult?' . http_build_query(['merchantConfigurationId' => $cfg['merchant_config_id'], 'localInvoiceId' => $tx['gateway_order_id']]),
        null,
        $cfg,
        $transport,
    );
    $data = gateway_json($result);
    if ($result['http'] !== 200) {
        return ['ok' => false, 'cancelled' => $result['http'] === 472, 'error' => ap_message($result['http'])];
    }
    if ((string) ($data['refID'] ?? '') !== (string) $tx['gateway_token'] || !preg_match('/^[0-9]{1,30}$/', (string) ($data['payGateTranID'] ?? ''))) {
        return ['ok' => false, 'error' => 'نتیجه آسان پرداخت با تراکنش مطابقت ندارد.'];
    }
    $payload = ['merchantConfigurationId' => (int) $cfg['merchant_config_id'], 'payGateTranId' => (int) $data['payGateTranID']];
    $verify = ap_call('verify', 'POST', 'Verify', $payload, $cfg, $transport);
    if ($verify['http'] !== 200) {
        return ['ok' => false, 'error' => ap_message($verify['http'])];
    }
    $settle = ap_call('settlement', 'POST', 'Settlement', $payload, $cfg, $transport);
    if ($settle['http'] !== 200) {
        // Verified money is settled automatically by AP at day end; record it so support can follow up.
        error_log('nobatyar asanpardakht settlement http ' . $settle['http'] . ' tx ' . $tx['id']);
    }
    return [
        'ok' => true,
        'ref' => (string) ($data['rrn'] ?? $data['payGateTranID']),
        'card' => $data['cardNumber'] ?? null,
        'amount_rial' => (int) ($data['amount'] ?? 0),
    ];
}
