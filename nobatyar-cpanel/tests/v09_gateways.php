<?php
// 0.9.0 — gateway adapters and payment module, CLI only, against the disposable test install.
// Every bank call goes through an injected transport that also asserts the outgoing request:
// no live bank request is made and no real money moves.
if (PHP_SAPI !== 'cli' || getenv('NOBATYAR_TEST') !== '1') {
    exit("Test opt-in required\n");
}
$c = require __DIR__ . '/../config.php';
if ($c['database'] !== 'nobatyar_test_http') {
    exit("Disposable database required\n");
}
ob_start();
require __DIR__ . '/../core.php';
require __DIR__ . '/gateway_support.php';
require __DIR__ . '/../lib/jobs.php';
$checks = 0;
function t(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo 'PASS ' . $label . "\n";
}
function rejects(callable $fn, string $label, string $contains = ''): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        t($contains === '' || str_contains($e->getMessage(), $contains), $label . ($contains && !str_contains($e->getMessage(), $contains) ? ' [' . $e->getMessage() . ']' : ''));
        return;
    }
    t(false, $label);
}
function tx(int $id): array
{
    return query('SELECT * FROM payment_transactions WHERE id=?', [$id])->fetch();
}
function ok_json(array $data, int $http = 200): array
{
    return ['http' => $http, 'body' => json_encode($data)];
}
function soap(string $value): array
{
    return ['http' => 200, 'body' => '<soap:Envelope><soap:Body><ns2:x><return>' . $value . '</return></ns2:x></soap:Body></soap:Envelope>'];
}
/** New gateway_test transaction redirected through $transport; returns [tx, redirect]. */
function start(string $gateway, callable $transport, int $amount = 1000): array
{
    $tx = payment_create('gateway_test', 0, $amount, 'آزمون', ['gateway' => $gateway]);
    $r = payment_redirect((int) $tx['id'], $transport, false);
    return [tx((int) $tx['id']), $r];
}
function fail_transport(): callable
{
    return function () {
        throw new RuntimeException('transport must not be called');
    };
}

query('DELETE FROM request_limits');
test_gateway_off();
query("DELETE FROM payment_transactions WHERE purpose='gateway_test'");

/* ---------- Registry, secrets, activation gate ---------- */
$names = query('SELECT name FROM payment_gateways ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN);
t($names === ['zarinpal', 'sep', 'sadad', 'mellat', 'asanpardakht'], 'five gateways registered in order');
t(!payment_available(), 'no gateway usable before configuration');
rejects(fn() => gateway_save('zarinpal', ['merchant_id' => 'short']), 'invalid merchant rejected', 'معتبر نیست');
rejects(fn() => gateway_save('zarinpal', ['merchant_id' => '11111111-1111-1111-1111-111111111111', 'sandbox' => '1']), 'sandbox refused on production installation', 'development');
rejects(fn() => gateway_save('nope', []), 'unknown gateway rejected');

$sadadKey = base64_encode(str_repeat('K', 24));
gateway_save('sadad', ['merchant_id' => '000000140336964', 'terminal_id' => '24000615', 'key' => $sadadKey]);
$raw = query("SELECT config FROM payment_gateways WHERE name='sadad'")->fetchColumn();
t(!str_contains($raw, $sadadKey) && gateway_config('sadad')['key'] === $sadadKey, 'secret key encrypted at rest, decrypted in memory');
gateway_save('sadad', ['merchant_id' => '000000140336964', 'terminal_id' => '24000615', 'key' => '']);
t(gateway_config('sadad')['key'] === $sadadKey, 'empty secret field keeps stored key');
rejects(fn() => gateway_set_enabled('sadad', true), 'unverified gateway cannot be enabled', 'پرداخت واقعی');
rejects(fn() => gateway_set_enabled('mellat', true), 'incomplete gateway cannot be enabled', 'کامل');
rejects(fn() => gateway_set_roles('sadad', ''), 'unverified gateway cannot be default');
rejects(fn() => payment_create('booking', 1, 5000, 'x'), 'no payment without a usable gateway', 'درگاه');

gateway_save('zarinpal', ['merchant_id' => '11111111-1111-1111-1111-111111111111']);
gateway_save('sep', ['terminal_id' => '13045678']);
gateway_save('mellat', ['terminal_id' => '5556677', 'username' => 'nobat', 'password' => 'S3cret!pw']);
gateway_save('asanpardakht', ['merchant_config_id' => '2045', 'username' => 'apuser', 'password' => 'AP-pass-1']);
foreach ($names as $n) {
    t(gateway_configured($n) && !gateway_usable($n), $n . ' configured but not usable before verification');
}

/* ---------- Zarinpal ---------- */
$zp = function (string $op, string $url, $body) {
    $b = json_decode((string) $body, true);
    if ($op === 'zarinpal.request') {
        if ($b['amount'] !== 10000 || $b['currency'] !== 'IRR' || !str_contains($b['callback_url'], 'pay-return.php?tx=') || !str_starts_with($url, 'https://payment.zarinpal.com/')) {
            throw new RuntimeException('bad zarinpal request');
        }
        return ok_json(['data' => ['code' => 100, 'authority' => 'A' . str_pad((string) random_int(1, 99999999), 35, '0', STR_PAD_LEFT)]]);
    }
    if ($b['amount'] !== 10000) {
        throw new RuntimeException('bad zarinpal verify amount');
    }
    return ok_json(['data' => ['code' => 100, 'ref_id' => 70001, 'card_pan' => '6037****1111']]);
};
[$t, $r] = start('zarinpal', $zp);
t($r['method'] === 'GET' && str_ends_with($r['url'], '/StartPay/' . $t['gateway_token']) && $t['status'] === 'redirected', 'zarinpal: token stored and StartPay redirect');
t(payment_return((int) $t['id'], ['Authority' => 'A' . str_repeat('9', 35), 'Status' => 'OK'], fail_transport())['status'] === 'failed', 'zarinpal: forged authority rejected without bank call');
[$t] = start('zarinpal', $zp);
t(payment_return((int) $t['id'], ['Authority' => $t['gateway_token'], 'Status' => 'NOK'], fail_transport())['status'] === 'cancelled', 'zarinpal: user cancel recorded');
[$t] = start('zarinpal', $zp);
$v = payment_return((int) $t['id'], ['Authority' => $t['gateway_token'], 'Status' => 'OK'], $zp);
t($v['status'] === 'verified' && $v['ref_num'] === '70001' && $v['card_mask'] === '6037****1111', 'zarinpal: verify success stores reference and card');
t(payment_return((int) $t['id'], ['Authority' => $t['gateway_token'], 'Status' => 'OK'], fail_transport())['callback_count'] == 2, 'zarinpal: repeated callback is idempotent');
t(gateway_verified(gateway_row('zarinpal')), 'zarinpal: verified test payment marks credentials verified');
[$t] = start('zarinpal', $zp);
t(payment_return((int) $t['id'], ['Authority' => $t['gateway_token'], 'Status' => 'OK'], fn() => ok_json(['errors' => ['code' => -51]]))['status'] === 'failed', 'zarinpal: bank refusal is a failure');
rejects(fn() => start('zarinpal', fn() => ok_json(['errors' => ['code' => -9]])), 'zarinpal: request refusal raises', 'زرین‌پال');

/* ---------- SEP (Saman) ---------- */
$sep = function (string $op, string $url, $body) {
    $b = json_decode((string) $body, true);
    if ($op === 'sep.token') {
        if ($b['action'] !== 'token' || $b['TerminalId'] !== '13045678' || $b['Amount'] !== 10000 || !preg_match('/^1[0-9]{9}$/', $b['ResNum'])) {
            throw new RuntimeException('bad sep token request');
        }
        return ok_json(['status' => 1, 'token' => 'SEPTOKEN' . $b['ResNum']]);
    }
    if ($op === 'sep.verify') {
        if ($b['TerminalNumber'] !== 13045678) {
            throw new RuntimeException('bad sep verify');
        }
        return ok_json(['ResultCode' => 0, 'Success' => true, 'TransactionDetail' => ['RefNum' => $b['RefNum'], 'MaskedPan' => '621986****8080', 'OrginalAmount' => $GLOBALS['sepAmount'] ?? 10000]]);
    }
    throw new RuntimeException('unexpected ' . $op);
};
[$t, $r] = start('sep', $sep);
t($r['method'] === 'POST' && $r['url'] === 'https://sep.shaparak.ir/OnlinePG/OnlinePG' && $r['fields']['Token'] === $t['gateway_token'], 'sep: token request and POST form to OnlinePG');
$cb = ['Token' => $t['gateway_token'], 'ResNum' => (string) $t['gateway_order_id'], 'State' => 'OK', 'Status' => '2', 'RefNum' => 'GmshtyjwKSu7'];
t(payment_return((int) $t['id'], ['ResNum' => '1999999999'] + $cb, fail_transport())['status'] === 'failed', 'sep: mismatched ResNum rejected');
[$t] = start('sep', $sep);
$cb = ['Token' => $t['gateway_token'], 'ResNum' => (string) $t['gateway_order_id'], 'Status' => '1'];
t(payment_return((int) $t['id'], $cb, fail_transport())['status'] === 'cancelled', 'sep: Status=1 user cancel');
[$t] = start('sep', $sep);
$cb = ['Token' => $t['gateway_token'], 'ResNum' => (string) $t['gateway_order_id'], 'Status' => '2', 'RefNum' => 'GmshtyjwKSu7'];
$v = payment_return((int) $t['id'], $cb, $sep);
t($v['status'] === 'verified' && $v['ref_num'] === 'GmshtyjwKSu7' && gateway_verified(gateway_row('sep')), 'sep: verify success and gateway verified');
[$t] = start('sep', $sep);
$GLOBALS['sepAmount'] = 5000;
$cb = ['Token' => $t['gateway_token'], 'ResNum' => (string) $t['gateway_order_id'], 'Status' => '2', 'RefNum' => 'MismatchRef01'];
$v = payment_return((int) $t['id'], $cb, $sep);
t($v['status'] === 'refund_due' && query('SELECT COUNT(*) FROM refunds WHERE tx_id=?', [$t['id']])->fetchColumn() == 1, 'sep: amount mismatch never fulfils, opens refund');
unset($GLOBALS['sepAmount']);
[$t] = start('sep', $sep);
$cb = ['Token' => $t['gateway_token'], 'ResNum' => (string) $t['gateway_order_id'], 'Status' => '2', 'RefNum' => 'OtherRef9'];
t(payment_return((int) $t['id'], $cb, fn() => ok_json(['ResultCode' => -2, 'Success' => false]))['status'] === 'failed', 'sep: verify refusal is failure');
[$t] = start('sep', $sep);
$cb = ['Token' => $t['gateway_token'], 'ResNum' => (string) $t['gateway_order_id'], 'Status' => '2', 'RefNum' => 'NetRef123'];
$v = payment_return((int) $t['id'], $cb, function () {
    throw new AuthError('network down', 503);
});
t($v['status'] === 'redirected' && $v['error'] !== null, 'sep: network error during verify keeps transaction open');
rejects(fn() => start('sep', fn() => ok_json(['status' => -1, 'errorCode' => 5, 'errorDesc' => 'Invalid terminal'])), 'sep: token refusal raises', 'Invalid terminal');

/* ---------- Sadad ---------- */
$sadad = function (string $op, string $url, $body) use ($sadadKey) {
    $b = json_decode((string) $body, true);
    if ($op === 'sadad.request') {
        $expected = sadad_sign('24000615;' . $b['OrderId'] . ';10000', $sadadKey);
        if ($b['MerchantId'] !== '000000140336964' || $b['Amount'] !== 10000 || $b['SignData'] !== $expected || !preg_match('#^\d{2}/\d{2}/\d{4} #', $b['LocalDateTime'])) {
            throw new RuntimeException('bad sadad request');
        }
        return ok_json(['ResCode' => 0, 'Token' => 'SADAD-' . $b['OrderId'] . '-TOKEN', 'Description' => 'ok']);
    }
    if ($b['SignData'] !== sadad_sign($b['Token'], $sadadKey)) {
        throw new RuntimeException('bad sadad verify sign');
    }
    return ok_json(['ResCode' => 0, 'Amount' => 10000, 'RetrivalRefNo' => '8876543', 'SystemTraceNo' => '1234', 'OrderId' => (int) explode('-', $b['Token'])[1]]);
};
t(strlen(base64_decode(sadad_sign('24000615;1;10000', $sadadKey))) % 8 === 0, 'sadad: TripleDES signature is block aligned');
rejects(fn() => sadad_sign('x', 'not-base64!!'), 'sadad: invalid key rejected');
[$t, $r] = start('sadad', $sadad);
t($r['method'] === 'GET' && $r['url'] === 'https://sadad.shaparak.ir/Purchase?Token=' . rawurlencode($t['gateway_token']), 'sadad: signed request and Purchase redirect');
$cb = ['token' => $t['gateway_token'], 'OrderId' => (string) $t['gateway_order_id'], 'ResCode' => '-1'];
t(payment_return((int) $t['id'], $cb, fail_transport())['status'] === 'cancelled', 'sadad: ResCode<>0 is cancellation');
[$t] = start('sadad', $sadad);
$cb = ['token' => 'SADAD-1-FORGED', 'OrderId' => (string) $t['gateway_order_id'], 'ResCode' => '0'];
t(payment_return((int) $t['id'], $cb, fail_transport())['status'] === 'failed', 'sadad: forged token rejected');
[$t] = start('sadad', $sadad);
$cb = ['token' => $t['gateway_token'], 'OrderId' => (string) $t['gateway_order_id'], 'ResCode' => '0'];
$v = payment_return((int) $t['id'], $cb, $sadad);
t($v['status'] === 'verified' && $v['ref_num'] === '8876543' && gateway_verified(gateway_row('sadad')), 'sadad: verify success');
[$t] = start('sadad', $sadad);
$cb = ['token' => $t['gateway_token'], 'OrderId' => (string) $t['gateway_order_id'], 'ResCode' => '0'];
t(payment_return((int) $t['id'], $cb, fn() => ok_json(['ResCode' => 101]))['status'] === 'failed', 'sadad: verify refusal is failure');

/* ---------- Mellat ---------- */
$mellatCalls = [];
$mellat = function (string $op, string $url, $body) use (&$mellatCalls) {
    $mellatCalls[] = $op;
    if (!str_contains((string) $body, '<terminalId>5556677</terminalId>') || !str_contains((string) $body, '<userPassword>S3cret!pw</userPassword>')) {
        throw new RuntimeException('bad mellat auth');
    }
    if ($op === 'mellat.bpPayRequest') {
        if (!str_contains((string) $body, '<amount>10000</amount>') || !preg_match('#<orderId>(1\d{9})</orderId>#', (string) $body, $m)) {
            throw new RuntimeException('bad mellat pay');
        }
        return soap('0,AF82041A2BF6989' . substr($m[1], -4));
    }
    return soap($GLOBALS['mellatCodes'][$op] ?? '0');
};
[$t, $r] = start('mellat', $mellat);
t($r['method'] === 'POST' && $r['fields']['RefId'] === $t['gateway_token'] && str_contains($r['url'], 'startpay.mellat'), 'mellat: SOAP bpPayRequest and POST to startpay');
$cb = ['RefId' => $t['gateway_token'], 'ResCode' => '17', 'SaleOrderId' => (string) $t['gateway_order_id']];
t(payment_return((int) $t['id'], $cb, fail_transport())['status'] === 'cancelled', 'mellat: ResCode 17 user cancel');
[$t] = start('mellat', $mellat);
$mellatCalls = [];
$cb = ['RefId' => $t['gateway_token'], 'ResCode' => '0', 'SaleOrderId' => (string) $t['gateway_order_id'], 'SaleReferenceId' => '151510101', 'CardHolderPan' => '610433******1234'];
$v = payment_return((int) $t['id'], $cb, $mellat);
t($v['status'] === 'verified' && $mellatCalls === ['mellat.bpVerifyRequest', 'mellat.bpSettleRequest'] && gateway_verified(gateway_row('mellat')), 'mellat: verify then settle');
[$t] = start('mellat', $mellat);
$mellatCalls = [];
$GLOBALS['mellatCodes'] = ['mellat.bpVerifyRequest' => '415'];
$cb = ['RefId' => $t['gateway_token'], 'ResCode' => '0', 'SaleOrderId' => (string) $t['gateway_order_id'], 'SaleReferenceId' => '151510102'];
$v = payment_return((int) $t['id'], $cb, $mellat);
t($v['status'] === 'failed' && in_array('mellat.bpReversalRequest', $mellatCalls, true), 'mellat: failed verify triggers reversal');
[$t] = start('mellat', $mellat);
$GLOBALS['mellatCodes'] = ['mellat.bpVerifyRequest' => '43', 'mellat.bpSettleRequest' => '45'];
$cb = ['RefId' => $t['gateway_token'], 'ResCode' => '0', 'SaleOrderId' => (string) $t['gateway_order_id'], 'SaleReferenceId' => '151510103'];
t(payment_return((int) $t['id'], $cb, $mellat)['status'] === 'verified', 'mellat: already verified/settled codes accepted');
unset($GLOBALS['mellatCodes']);
rejects(fn() => start('mellat', fn() => soap('421')), 'mellat: request refusal raises with message', 'IP');

/* ---------- Asan Pardakht ---------- */
$ap = function (string $op, string $url, $body) {
    $b = json_decode((string) $body, true);
    switch ($op) {
        case 'asanpardakht.time':
            return ['http' => 200, 'body' => '"20261015 101010"'];
        case 'asanpardakht.token':
            if ($b['amountInRials'] !== 10000 || $b['merchantConfigurationId'] !== 2045 || $b['localDate'] !== '20261015 101010') {
                throw new RuntimeException('bad ap token');
            }
            return ['http' => 200, 'body' => json_encode('APTOKEN' . $b['localInvoiceId'])];
        case 'asanpardakht.tranresult':
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            return $GLOBALS['apResult'] ?? ok_json(['cardNumber' => '5022****0000', 'rrn' => '660011', 'refID' => 'APTOKEN' . $q['localInvoiceId'], 'amount' => '10000', 'payGateTranID' => '7788']);
        default:
            return ['http' => 200, 'body' => ''];
    }
};
[$t, $r] = start('asanpardakht', $ap);
t($r['method'] === 'POST' && $r['url'] === 'https://asan.shaparak.ir' && $r['fields']['RefID'] === $t['gateway_token'], 'ap: bank time, token and POST redirect');
$GLOBALS['apResult'] = ['http' => 472, 'body' => ''];
t(payment_return((int) $t['id'], [], $ap)['status'] === 'cancelled', 'ap: 472 = no payment, cancelled');
unset($GLOBALS['apResult']);
[$t] = start('asanpardakht', $ap);
$v = payment_return((int) $t['id'], ['ReturningParams' => 'x'], $ap);
t($v['status'] === 'verified' && $v['ref_num'] === '660011' && gateway_verified(gateway_row('asanpardakht')), 'ap: tranresult, verify, settle');
[$t] = start('asanpardakht', $ap);
$GLOBALS['apResult'] = ok_json(['rrn' => '1', 'refID' => 'SOMEONE-ELSE', 'amount' => '10000', 'payGateTranID' => '1']);
t(payment_return((int) $t['id'], [], $ap)['status'] === 'failed', 'ap: result of another token rejected');
unset($GLOBALS['apResult']);

/* ---------- Activation, roles, credential change, fallback ---------- */
foreach ($names as $n) {
    gateway_set_enabled($n, true);
}
t(count(usable_gateways()) === 5 && payment_available(), 'verified gateways can be enabled');
gateway_set_roles('sep', 'zarinpal');
t(usable_gateways()[0] === 'sep' && payment_active_gateway() === 'sep', 'default gateway listed first');
rejects(fn() => gateway_set_roles('sep', 'sep'), 'fallback must differ from default');
gateway_save('sep', ['terminal_id' => '13045679']);
t(!gateway_usable('sep') && payment_active_gateway() === 'zarinpal', 'credential change disables gateway until re-verified; fallback takes over');
gateway_save('sep', ['terminal_id' => '13045678']);
t(gateway_verified(gateway_row('sep')), 'restoring verified credentials restores verification');
gateway_set_enabled('sep', true);
gateway_set_roles('sep', 'zarinpal');
$fallback = function (string $op, string $url, $body) use ($zp) {
    if ($op === 'sep.token') {
        return ok_json(['status' => -1, 'errorCode' => 8, 'errorDesc' => 'down']);
    }
    return $zp($op, $url, $body);
};
$tx = payment_create('subscription', 999999, 1000, 'fallback');
$r = payment_redirect((int) $tx['id'], $fallback);
t(($r['fallback'] ?? '') === 'zarinpal' && tx((int) $tx['id'])['status'] === 'failed' && tx((int) $r['tx'])['gateway'] === 'zarinpal', 'default failure retries once on the fallback gateway');
t(str_contains((string) gateway_row('sep')['last_error'], 'down'), 'gateway last error recorded for admin');
gateway_set_enabled('mellat', false);
t(!gateway_usable('mellat'), 'gateway can be disabled');
rejects(fn() => payment_redirect((int) $tx['id'], $zp), 'failed transaction cannot be redirected again');
rejects(fn() => payment_create('booking', 1, 500, 'x'), 'amount under 1000 Toman refused', '۱٬۰۰۰');

/* ---------- Stale transactions ---------- */
query("UPDATE payment_transactions SET created_at=NOW() - INTERVAL 2 DAY WHERE id=?", [$r['tx']]);
cron_payment_stale();
t(tx((int) $r['tx'])['status'] === 'failed', 'cron closes transactions without bank return after 24h');

/* ---------- Refund handling ---------- */
$refund = query("SELECT * FROM refunds WHERE status='pending' ORDER BY id DESC LIMIT 1")->fetch();
rejects(fn() => refund_handle((int) $refund['id'], 'done', 'card', '', ''), 'refund done requires transfer reference');
rejects(fn() => refund_handle((int) $refund['id'], 'rejected', 'card', '', 'no'), 'refund rejection requires reason');
$_SESSION['admin_id'] = 1;
refund_handle((int) $refund['id'], 'done', 'card', '123456789', 'واریز شد');
t(query('SELECT status FROM refunds WHERE id=?', [$refund['id']])->fetchColumn() === 'done' && tx((int) $refund['tx_id'])['status'] === 'refunded', 'refund closed and transaction marked refunded');
rejects(fn() => refund_handle((int) $refund['id'], 'done', 'card', '123456789', ''), 'refund cannot be handled twice');

/* ---------- Gateway test entry point ---------- */
unset($_SESSION['admin_id']);
rejects(fn() => gateway_test_start('zarinpal', $zp), 'gateway test needs administrator');
$_SESSION['admin_id'] = 1;
$_SESSION['admin_login_at'] = time();
$r = gateway_test_start('zarinpal', $zp);
t($r['method'] === 'GET' && tx((int) $r['tx'])['purpose'] === 'gateway_test' && (int) tx((int) $r['tx'])['amount_toman'] === 1000, 'gateway test starts a real 1,000 Toman payment');

$rev = payment_revenue(date('Y-m-d', strtotime('-1 day')), date('Y-m-d'));
t(!array_filter($rev['by_purpose'], fn($x) => $x['purpose'] === 'gateway_test'), 'gateway tests excluded from revenue');

test_gateway_off();
query('UPDATE payment_gateways SET config=NULL,config_hash=NULL,last_error=NULL,verified_ref=NULL');
query("DELETE FROM payment_transactions WHERE purpose IN ('gateway_test') OR reference_id=999999");
query('DELETE FROM refunds WHERE tx_id NOT IN (SELECT id FROM payment_transactions)');
ob_end_flush();
echo "SUCCESS $checks v0.9 gateway checks\n";
