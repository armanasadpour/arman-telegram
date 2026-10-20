<?php
declare(strict_types=1);

/**
 * Saman Electronic Payment (SEP) — token method.
 *   token:   POST https://sep.shaparak.ir/onlinepg/onlinepg
 *            {"action":"token","TerminalId","Amount"(Rial),"ResNum","RedirectUrl","CellNumber"} -> {"status":1,"token":"..."}
 *            failure -> {"status":-1,"errorCode":..,"errorDesc":".."}
 *   pay:     POST form to https://sep.shaparak.ir/OnlinePG/OnlinePG with Token, GetMethod=false
 *   return:  POST State, Status (2 = success), RefNum, ResNum, TerminalId, TraceNo, Token, RRN, SecurePan
 *   verify:  POST https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction {"RefNum","TerminalNumber"}
 *            -> {"ResultCode":0,"Success":true,"TransactionDetail":{"RRN","RefNum","MaskedPan","OrginalAmount","AffectiveAmount","StraceNo"}}
 * SEP reverses unverified payments automatically after 30 minutes.
 */

const SEP_TOKEN_URL = 'https://sep.shaparak.ir/onlinepg/onlinepg';
const SEP_PAY_URL = 'https://sep.shaparak.ir/OnlinePG/OnlinePG';
const SEP_VERIFY_URL = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction';

function sep_status_message(int $status): string
{
    return [
        1 => 'پرداخت توسط کاربر لغو شد.',
        3 => 'پرداخت انجام نشد.',
        4 => 'کاربر در زمان تعیین‌شده پاسخی نداد.',
        5 => 'پارامترهای ارسالی نامعتبر است.',
        8 => 'آدرس سرور پذیرنده نامعتبر است.',
        10 => 'توکن ارسال‌شده یافت نشد.',
        11 => 'این ترمینال فقط تراکنش توکنی می‌پذیرد.',
        12 => 'شماره ترمینال یافت نشد.',
    ][$status] ?? 'پرداخت در درگاه سامان ناموفق بود (وضعیت ' . fa_number((string) $status) . ').';
}

function gateway_sep_request(array $tx, array $cfg, string $callback, ?callable $transport = null): string
{
    $raw = gateway_http(
        'sep.token',
        'POST',
        SEP_TOKEN_URL,
        json_encode([
            'action' => 'token',
            'TerminalId' => $cfg['terminal_id'],
            'Amount' => (int) $tx['amount_toman'] * 10,
            'ResNum' => (string) $tx['gateway_order_id'],
            'RedirectUrl' => $callback,
            'CellNumber' => '',
        ]),
        ['Content-Type: application/json', 'Accept: application/json'],
        $transport,
    );
    $r = gateway_json($raw);
    if ($raw['http'] !== 200 || (int) ($r['status'] ?? 0) !== 1 || !preg_match('/^[A-Za-z0-9]{8,190}$/', (string) ($r['token'] ?? ''))) {
        throw new AuthError(
            'درگاه سامان توکن پرداخت صادر نکرد' . (isset($r['errorDesc']) ? ': ' . mb_substr((string) $r['errorDesc'], 0, 120) : '.'),
            503,
        );
    }
    return (string) $r['token'];
}

function gateway_sep_redirect(array $tx, array $cfg): array
{
    return ['method' => 'POST', 'url' => SEP_PAY_URL, 'fields' => ['Token' => $tx['gateway_token'], 'GetMethod' => 'false']];
}

function gateway_sep_verify(array $tx, array $cfg, array $input, ?callable $transport = null): array
{
    $status = (int) ($input['Status'] ?? 0);
    if (!hash_equals((string) $tx['gateway_token'], (string) ($input['Token'] ?? '')) || (string) ($input['ResNum'] ?? '') !== (string) $tx['gateway_order_id']) {
        return ['ok' => false, 'error' => 'اطلاعات بازگشتی سامان با تراکنش مطابقت ندارد.'];
    }
    if ($status !== 2) {
        return ['ok' => false, 'cancelled' => $status === 1, 'error' => sep_status_message($status)];
    }
    $refNum = (string) ($input['RefNum'] ?? '');
    if (!preg_match('/^[A-Za-z0-9\/+=-]{6,100}$/', $refNum)) {
        return ['ok' => false, 'error' => 'شماره مرجع سامان نامعتبر است.'];
    }
    $raw = gateway_http(
        'sep.verify',
        'POST',
        SEP_VERIFY_URL,
        json_encode(['RefNum' => $refNum, 'TerminalNumber' => (int) $cfg['terminal_id']]),
        ['Content-Type: application/json', 'Accept: application/json'],
        $transport,
    );
    $r = gateway_json($raw);
    $detail = $r['TransactionDetail'] ?? [];
    if ($raw['http'] !== 200 || (int) ($r['ResultCode'] ?? -1) !== 0 || empty($r['Success']) || !is_array($detail)) {
        return ['ok' => false, 'error' => 'سامان پرداخت را تأیید نکرد (کد ' . fa_number((string) ($r['ResultCode'] ?? '?')) . ').'];
    }
    if ((string) ($detail['RefNum'] ?? '') !== $refNum) {
        return ['ok' => false, 'error' => 'شماره مرجع تأییدشده سامان با بازگشت یکسان نیست.'];
    }
    return [
        'ok' => true,
        'ref' => $refNum,
        'card' => $detail['MaskedPan'] ?? null,
        'amount_rial' => (int) ($detail['OrginalAmount'] ?? 0),
    ];
}
