<?php
declare(strict_types=1);

/**
 * Shared payment module (subscription purchases, patient booking fees, gateway verification tests).
 *
 * Flow for every payment:
 *   payment_create()   -> transaction "initiated" (amount, purpose, chosen gateway, unique order code)
 *   payment_redirect() -> token from the gateway, status "redirected", browser sent to the bank
 *   payment_return()   -> pay-return.php: token/order check, server-to-server verification,
 *                         fulfilment exactly once under a row lock
 * Guarantees:
 *   - nothing is marked paid without a successful server-side verification;
 *   - a bank reference number can be fulfilled only once per gateway (unique index);
 *   - money confirmed for an order that can no longer be fulfilled becomes "refund_due"
 *     and a refund case is opened for the administrator;
 *   - a gateway may take real payments only after one real verified test payment with the
 *     exact credentials currently saved (changing credentials disables it again).
 */

foreach (['zarinpal', 'sep', 'sadad', 'mellat', 'asanpardakht'] as $__gateway) {
    require_once __DIR__ . '/gateways/' . $__gateway . '.php';
}
unset($__gateway);

/** Gateway registry: label, admin fields and whether the provider offers a public sandbox. */
function payment_gateways(): array
{
    return [
        'zarinpal' => [
            'label' => 'زرین‌پال',
            'sandbox' => true,
            'docs' => 'https://www.zarinpal.com/docs/paymentGateway/connectToGateway',
            'fields' => [
                'merchant_id' => ['label' => 'مرچنت کد (۳۶ نویسه)', 'secret' => true, 'pattern' => '/^[a-f0-9-]{36}$/i'],
            ],
        ],
        'sep' => [
            'label' => 'سامان کیش (SEP)',
            'sandbox' => false,
            'docs' => 'مستندات «درگاه پرداخت اینترنتی SEP – روش توکنی» که بانک هنگام صدور ترمینال تحویل می‌دهد',
            'fields' => [
                'terminal_id' => ['label' => 'شماره ترمینال', 'secret' => false, 'pattern' => '/^[0-9]{5,12}$/'],
            ],
        ],
        'sadad' => [
            'label' => 'سداد (بانک ملی)',
            'sandbox' => false,
            'docs' => 'مستندات «درگاه پرداخت سداد – API نسخه ۰» که بانک ملی به پذیرنده تحویل می‌دهد',
            'fields' => [
                'merchant_id' => ['label' => 'شماره پذیرنده (Merchant ID)', 'secret' => false, 'pattern' => '/^[0-9A-Za-z]{5,20}$/'],
                'terminal_id' => ['label' => 'شماره ترمینال', 'secret' => false, 'pattern' => '/^[0-9A-Za-z]{5,12}$/'],
                'key' => ['label' => 'کلید ترمینال (Base64)', 'secret' => true, 'pattern' => '/^[A-Za-z0-9+\/]{20,64}={0,2}$/'],
            ],
        ],
        'mellat' => [
            'label' => 'به‌پرداخت ملت',
            'sandbox' => false,
            'docs' => 'مستندات وب‌سرویس «درگاه پرداخت اینترنتی به‌پرداخت ملت» (bpPayRequest / bpVerifyRequest / bpSettleRequest)',
            'fields' => [
                'terminal_id' => ['label' => 'شماره ترمینال', 'secret' => false, 'pattern' => '/^[0-9]{3,12}$/'],
                'username' => ['label' => 'نام کاربری', 'secret' => false, 'pattern' => '/^\S{2,60}$/'],
                'password' => ['label' => 'رمز عبور', 'secret' => true, 'pattern' => '/^\S{2,100}$/'],
            ],
        ],
        'asanpardakht' => [
            'label' => 'آسان پرداخت (آپ)',
            'sandbox' => false,
            'docs' => 'مستندات «IPG REST v1 آسان پرداخت پرشین» که به پذیرنده تحویل می‌شود',
            'fields' => [
                'merchant_config_id' => ['label' => 'شناسه پیکربندی پذیرنده (MerchantConfigurationId)', 'secret' => false, 'pattern' => '/^[0-9]{1,12}$/'],
                'username' => ['label' => 'نام کاربری', 'secret' => false, 'pattern' => '/^\S{2,60}$/'],
                'password' => ['label' => 'رمز عبور', 'secret' => true, 'pattern' => '/^\S{2,100}$/'],
            ],
        ],
    ];
}

function payment_status_labels(): array
{
    return [
        'initiated' => 'ایجادشده',
        'redirected' => 'در انتظار پرداخت',
        'verified' => 'موفق',
        'failed' => 'ناموفق',
        'cancelled' => 'انصراف کاربر',
        'refund_due' => 'نیازمند بازگشت وجه',
        'refunded' => 'بازگشت داده‌شده',
    ];
}

function payment_status_label(string $status): string
{
    return payment_status_labels()[$status] ?? 'نامشخص';
}

function payment_purpose_label(string $purpose): string
{
    return ['subscription' => 'اشتراک پزشک', 'booking' => 'کارمزد رزرو', 'gateway_test' => 'آزمون درگاه', 'center_subscription' => 'اشتراک مرکز'][$purpose] ?? $purpose;
}

/* ---------- Gateway configuration ---------- */

function gateway_row(string $name): array
{
    if (!isset(payment_gateways()[$name])) {
        throw new AuthError('درگاه نامعتبر است.', 404);
    }
    $row = query('SELECT * FROM payment_gateways WHERE name=?', [$name])->fetch();
    return $row ?: ['name' => $name, 'enabled' => 0, 'test_mode' => 0, 'config' => null, 'config_hash' => null, 'verified_at' => null, 'verified_config_hash' => null, 'verified_ref' => null, 'last_error' => null];
}

/** Decrypted credentials of a gateway (secrets only in memory). */
function gateway_config(string $name): array
{
    $row = gateway_row($name);
    $stored = $row['config'] ? json_decode($row['config'], true) : [];
    $out = [];
    foreach (payment_gateways()[$name]['fields'] as $field => $meta) {
        $value = (string) ($stored[$field] ?? '');
        $out[$field] = $meta['secret'] && $value !== '' ? decrypt_secret($value) : $value;
    }
    $out['sandbox'] = (string) ($stored['sandbox'] ?? '0');
    return $out;
}

function gateway_config_hash(array $plain): string
{
    ksort($plain);
    return hash('sha256', json_encode($plain));
}

/** True when every required credential is present. */
function gateway_configured(string $name): bool
{
    $cfg = gateway_config($name);
    foreach (payment_gateways()[$name]['fields'] as $field => $meta) {
        if (($cfg[$field] ?? '') === '') {
            return false;
        }
    }
    return true;
}

/** Verified with a real payment using exactly the credentials saved now. */
function gateway_verified(array $row): bool
{
    return !empty($row['verified_at']) && $row['config_hash'] && hash_equals((string) $row['config_hash'], (string) $row['verified_config_hash']);
}

/**
 * A gateway can take payments when it is enabled and verified. Sandbox mode is accepted only on a
 * development installation, so test money can never confirm real bookings or subscriptions.
 */
function gateway_usable(string $name): bool
{
    $row = gateway_row($name);
    if (!$row['enabled'] || !gateway_configured($name)) {
        return false;
    }
    if (gateway_config($name)['sandbox'] === '1') {
        return app_environment() === 'development' && payment_gateways()[$name]['sandbox'];
    }
    return gateway_verified($row);
}

function gateway_save(string $name, array $data): void
{
    $meta = payment_gateways()[$name] ?? null;
    if (!$meta) {
        throw new AuthError('درگاه نامعتبر است.');
    }
    $current = gateway_config($name);
    $plain = [];
    foreach ($meta['fields'] as $field => $f) {
        $value = trim(digits((string) ($data[$field] ?? '')));
        if ($value === '' && $f['secret']) {
            $value = $current[$field]; // empty secret field keeps the stored value
        }
        if ($value !== '' && !preg_match($f['pattern'], $value)) {
            throw new AuthError('مقدار «' . $f['label'] . '» معتبر نیست.');
        }
        $plain[$field] = $value;
    }
    $plain['sandbox'] = !empty($data['sandbox']) && $meta['sandbox'] ? '1' : '0';
    if ($plain['sandbox'] === '1' && app_environment() !== 'development') {
        throw new AuthError('حالت آزمایشی درگاه فقط در نصب توسعه (environment=development) مجاز است.');
    }
    $stored = [];
    foreach ($meta['fields'] as $field => $f) {
        $stored[$field] = $f['secret'] && $plain[$field] !== '' ? encrypt_secret($plain[$field]) : $plain[$field];
    }
    $stored['sandbox'] = $plain['sandbox'];
    $hash = gateway_config_hash($plain);
    $row = gateway_row($name);
    // New credentials must be verified again before real payments.
    $enabled = (int) $row['enabled'] && $hash === $row['verified_config_hash'];
    query(
        'INSERT INTO payment_gateways(name,config,config_hash,enabled) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE config=VALUES(config),config_hash=VALUES(config_hash),enabled=VALUES(enabled)',
        [$name, json_encode($stored), $hash, $enabled ? 1 : 0],
    );
    audit('gateway.save');
}

function gateway_set_enabled(string $name, bool $enabled): void
{
    $row = gateway_row($name);
    if ($enabled) {
        if (!gateway_configured($name)) {
            throw new AuthError('ابتدا اطلاعات پذیرندگی درگاه را کامل کنید.');
        }
        $sandbox = gateway_config($name)['sandbox'] === '1';
        if (!$sandbox && !gateway_verified($row)) {
            throw new AuthError('درگاه هنوز با یک پرداخت واقعی موفق تأیید نشده است؛ ابتدا «پرداخت آزمایشی» را انجام دهید.');
        }
    }
    query('UPDATE payment_gateways SET enabled=? WHERE name=?', [$enabled ? 1 : 0, $name]);
    if (!$enabled) {
        foreach (['payment_default_gateway', 'payment_fallback_gateway'] as $key) {
            if (setting($key) === $name) {
                setting_set($key, '');
            }
        }
    }
    audit($enabled ? 'gateway.enable' : 'gateway.disable');
}

function gateway_set_roles(string $default, string $fallback): void
{
    foreach ([$default, $fallback] as $name) {
        if ($name !== '' && !gateway_usable($name)) {
            throw new AuthError('فقط درگاه فعال و تأییدشده را می‌توان پیش‌فرض یا جایگزین کرد.');
        }
    }
    if ($default !== '' && $default === $fallback) {
        throw new AuthError('درگاه جایگزین باید با درگاه پیش‌فرض متفاوت باشد.');
    }
    settings_save(['payment_default_gateway' => $default, 'payment_fallback_gateway' => $fallback]);
    audit('gateway.roles');
}

/** Usable gateways in display order, default first. */
function usable_gateways(): array
{
    $names = query('SELECT name FROM payment_gateways ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN);
    $names = array_values(array_filter($names, fn($n) => isset(payment_gateways()[$n]) && gateway_usable($n)));
    $default = setting('payment_default_gateway');
    usort($names, fn($a, $b) => ($b === $default) <=> ($a === $default));
    return $names;
}

function payment_available(): bool
{
    return usable_gateways() !== [];
}

/** Gateway that should take a new payment: requested one if usable, otherwise default, fallback, first usable. */
function payment_active_gateway(?string $preferred = null): ?string
{
    $usable = usable_gateways();
    foreach ([$preferred, setting('payment_default_gateway'), setting('payment_fallback_gateway')] as $candidate) {
        if ($candidate && in_array($candidate, $usable, true)) {
            return $candidate;
        }
    }
    return $usable[0] ?? null;
}

/* ---------- Transactions ---------- */

function payment_create(string $purpose, int $referenceId, int $amountToman, string $description, array $owner = []): array
{
    if (!in_array($purpose, ['subscription', 'booking', 'gateway_test', 'center_subscription'], true)) {
        throw new AuthError('نوع پرداخت نامعتبر است.');
    }
    if ($amountToman < 1000) {
        throw new AuthError('حداقل مبلغ پرداخت آنلاین ۱٬۰۰۰ تومان است.');
    }
    $gateway = $purpose === 'gateway_test' ? ($owner['gateway'] ?? null) : payment_active_gateway($owner['gateway'] ?? null);
    if (!$gateway || ($purpose !== 'gateway_test' && !gateway_usable($gateway))) {
        throw new AuthError('درگاه پرداخت فعالی تنظیم نشده است.', 503);
    }
    $code = strtoupper(bin2hex(random_bytes(10)));
    query(
        'INSERT INTO payment_transactions(purpose,reference_id,gateway,amount_toman,order_code,user_id,doctor_id,description,ip,test_mode) VALUES(?,?,?,?,?,?,?,?,?,?)',
        [
            $purpose,
            $referenceId,
            $gateway,
            $amountToman,
            $code,
            $owner['user_id'] ?? null,
            $owner['doctor_id'] ?? null,
            mb_substr($description, 0, 255),
            mb_substr(client_ip(), 0, 45),
            gateway_config($gateway)['sandbox'] === '1' ? 1 : 0,
        ],
    );
    $id = (int) db()->lastInsertId();
    // Banks that need a numeric order id receive this value; it is unique per installation.
    query('UPDATE payment_transactions SET gateway_order_id=? WHERE id=?', [payment_order_number($id), $id]);
    return query('SELECT * FROM payment_transactions WHERE id=?', [$id])->fetch();
}

/** Numeric order id sent to banks: 1 + zero-padded transaction id keeps it positive and unique. */
function payment_order_number(int $txId): int
{
    return (int) ('1' . str_pad((string) $txId, 9, '0', STR_PAD_LEFT));
}

function payment_callback_url(array $tx): string
{
    return app_base_url() . 'pay-return.php?tx=' . (int) $tx['id'];
}

/**
 * Gets a payment token and returns ['method','url','fields'] for the browser.
 * When the default gateway refuses the request, the configured fallback is tried once
 * with a fresh transaction (the failed one stays "failed" for the audit trail).
 * $transport (CLI tests only): fn(string $operation, string $url, mixed $body): ['http'=>int,'body'=>string]
 */
function payment_redirect(int $txId, ?callable $transport = null, bool $allowFallback = true): array
{
    if ($transport !== null && PHP_SAPI !== 'cli') {
        throw new AuthError('انتقال آزمایشی فقط در CLI مجاز است.', 403);
    }
    $tx = query('SELECT * FROM payment_transactions WHERE id=?', [$txId])->fetch();
    if (!$tx || !in_array($tx['status'], ['initiated', 'redirected'], true)) {
        throw new AuthError('تراکنش قابل پرداخت نیست.', 409);
    }
    if ($tx['status'] === 'redirected' && $tx['gateway_token']) {
        return ('gateway_' . $tx['gateway'] . '_redirect')($tx, gateway_config($tx['gateway']));
    }
    try {
        $cfg = gateway_config($tx['gateway']);
        $token = ('gateway_' . $tx['gateway'] . '_request')($tx, $cfg, payment_callback_url($tx), $transport);
    } catch (Throwable $e) {
        query("UPDATE payment_transactions SET status='failed',error=? WHERE id=? AND status='initiated'", [
            mb_substr(error_message($e), 0, 250),
            $txId,
        ]);
        query('UPDATE payment_gateways SET last_error=? WHERE name=?', [mb_substr(error_message($e), 0, 250), $tx['gateway']]);
        $fallback = setting('payment_fallback_gateway');
        if ($allowFallback && $tx['purpose'] !== 'gateway_test' && $fallback && $fallback !== $tx['gateway'] && gateway_usable($fallback)) {
            $retry = payment_create($tx['purpose'], (int) $tx['reference_id'], (int) $tx['amount_toman'], $tx['description'], [
                'user_id' => $tx['user_id'],
                'doctor_id' => $tx['doctor_id'],
                'gateway' => $fallback,
            ]);
            if ((int) ($tx['vat_toman'] ?? 0) > 0) {
                query('UPDATE payment_transactions SET vat_toman=? WHERE id=?', [(int) $tx['vat_toman'], $retry['id']]);
            }
            return payment_redirect((int) $retry['id'], $transport, false) + ['fallback' => $fallback];
        }
        throw $e instanceof AuthError ? $e : new AuthError('درگاه پرداخت درخواست را نپذیرفت.', 503);
    }
    query("UPDATE payment_transactions SET status='redirected',gateway_token=?,redirected_at=NOW() WHERE id=? AND status='initiated'", [
        $token,
        $txId,
    ]);
    $tx['gateway_token'] = $token;
    return ('gateway_' . $tx['gateway'] . '_redirect')($tx, $cfg) + ['tx' => $txId];
}

/**
 * Handles the bank callback ($input = GET + POST). Returns the final transaction row.
 * Safe to call repeatedly (refresh, double callback): only a "redirected" transaction is verified.
 */
function payment_return(int $txId, array $input, ?callable $transport = null): array
{
    if ($transport !== null && PHP_SAPI !== 'cli') {
        throw new AuthError('انتقال آزمایشی فقط در CLI مجاز است.', 403);
    }
    return db_transaction(function () use ($txId, $input, $transport) {
        $tx = query('SELECT * FROM payment_transactions WHERE id=? FOR UPDATE', [$txId])->fetch();
        if (!$tx) {
            throw new AuthError('تراکنش پیدا نشد.', 404);
        }
        query('UPDATE payment_transactions SET callback_count=callback_count+1 WHERE id=?', [$txId]);
        if ($tx['status'] !== 'redirected') {
            return query('SELECT * FROM payment_transactions WHERE id=?', [$txId])->fetch();
        }
        try {
            $result = ('gateway_' . $tx['gateway'] . '_verify')($tx, gateway_config($tx['gateway']), $input, $transport);
        } catch (Throwable $e) {
            // Network or protocol error: the outcome is unknown, keep the transaction open for a re-check.
            query('UPDATE payment_transactions SET error=? WHERE id=?', [mb_substr(error_message($e), 0, 250), $txId]);
            return query('SELECT * FROM payment_transactions WHERE id=?', [$txId])->fetch();
        }
        if (!$result['ok']) {
            query('UPDATE payment_transactions SET status=?,error=? WHERE id=?', [
                !empty($result['cancelled']) ? 'cancelled' : 'failed',
                mb_substr((string) ($result['error'] ?? 'پرداخت تأیید نشد.'), 0, 250),
                $txId,
            ]);
            payment_release($tx);
            return query('SELECT * FROM payment_transactions WHERE id=?', [$txId])->fetch();
        }
        if (isset($result['amount_rial']) && (int) $result['amount_rial'] !== (int) $tx['amount_toman'] * 10) {
            query("UPDATE payment_transactions SET status='refund_due',ref_num=?,error=? WHERE id=?", [
                mb_substr((string) $result['ref'], 0, 100),
                'مبلغ تأییدشده بانک با مبلغ سفارش یکسان نیست.',
                $txId,
            ]);
            refund_open(query('SELECT * FROM payment_transactions WHERE id=?', [$txId])->fetch(), 'مغایرت مبلغ تأییدشده بانک');
            return query('SELECT * FROM payment_transactions WHERE id=?', [$txId])->fetch();
        }
        query("UPDATE payment_transactions SET status='verified',ref_num=?,card_mask=?,verified_at=NOW(),error=NULL WHERE id=?", [
            mb_substr((string) $result['ref'], 0, 100),
            isset($result['card']) ? mb_substr((string) $result['card'], 0, 40) : null,
            $txId,
        ]);
        $tx = query('SELECT * FROM payment_transactions WHERE id=?', [$txId])->fetch();
        try {
            ('payment_fulfil_' . $tx['purpose'])($tx);
        } catch (Throwable $e) {
            // Money received but the order cannot be completed (expired hold, cancelled order, capacity gone).
            query("UPDATE payment_transactions SET status='refund_due',error=? WHERE id=?", [mb_substr(error_message($e), 0, 250), $txId]);
            refund_open($tx, error_message($e));
        }
        return query('SELECT * FROM payment_transactions WHERE id=?', [$txId])->fetch();
    });
}

function payment_release(array $tx): void
{
    $fn = 'payment_released_' . $tx['purpose'];
    if (function_exists($fn)) {
        $fn($tx);
    }
}

/* ---------- Gateway verification test ---------- */

function gateway_test_start(string $name, ?callable $transport = null): array
{
    if (!admin()) {
        throw new AuthError('دسترسی مجاز نیست.', 403);
    }
    if (!gateway_configured($name)) {
        throw new AuthError('ابتدا اطلاعات پذیرندگی درگاه را ذخیره کنید.');
    }
    throttle('gateway_test', 20, 3600);
    $amount = setting_int('payment_test_amount', 1000, 1000, 100000);
    $tx = payment_create('gateway_test', 0, $amount, 'آزمون اتصال درگاه نوبت‌یار', ['gateway' => $name]);
    audit('gateway.test');
    return payment_redirect((int) $tx['id'], $transport, false);
}

/** A real verified payment proves the saved credentials work end to end. */
function payment_fulfil_gateway_test(array $tx): void
{
    if (!$tx['test_mode']) {
        query('UPDATE payment_gateways SET verified_at=NOW(),verified_ref=?,verified_config_hash=config_hash,last_error=NULL WHERE name=?', [
            $tx['ref_num'],
            $tx['gateway'],
        ]);
    }
}

/* ---------- Refunds ---------- */

function refund_open(array $tx, string $reason): void
{
    query(
        "INSERT IGNORE INTO refunds(tx_id,purpose,reference_id,amount_toman,reason,status) VALUES(?,?,?,?,?,'pending')",
        [$tx['id'], $tx['purpose'], $tx['reference_id'], $tx['amount_toman'], mb_substr($reason, 0, 255)],
    );
}

/** Admin closes a refund case: done (with bank reference) or rejected (with note). */
function refund_handle(int $id, string $status, string $method, string $reference, string $note): void
{
    if (!in_array($status, ['done', 'rejected'], true)) {
        throw new AuthError('وضعیت استرداد نامعتبر است.');
    }
    if ($status === 'done' && $method !== 'wallet' && mb_strlen(trim($reference)) < 4) {
        throw new AuthError('شماره پیگیری واریز استرداد را وارد کنید.');
    }
    if ($status === 'rejected' && mb_strlen(trim($note)) < 5) {
        throw new AuthError('علت رد استرداد را بنویسید.');
    }
    db_transaction(function () use ($id, $status, $method, $reference, $note) {
        $r = query('SELECT * FROM refunds WHERE id=? FOR UPDATE', [$id])->fetch();
        if (!$r || $r['status'] !== 'pending') {
            throw new AuthError('این مورد قبلاً رسیدگی شده است.', 409);
        }
        if ($status === 'done' && $method === 'wallet') {
            if ($r['tx_id']) {
                $original = query('SELECT test_mode,status FROM payment_transactions WHERE id=? FOR UPDATE', [$r['tx_id']])->fetch();
                if (!$original || $original['test_mode'] || !in_array($original['status'], ['verified','refund_due'], true)) {
                    throw new AuthError('پرداخت آزمایشی، ناموفق یا قبلاً مستردشده قابل تبدیل به موجودی کیف پول نیست.');
                }
            } else {
                throw new AuthError('استرداد پرداخت قدیمی را با روش بانکی انجام دهید؛ اصالت آن برای اعتبار کیف پول قابل بررسی نیست.');
            }
            if ($r['purpose'] === 'booking') {
                $b=booking_row((int)$r['reference_id']);
                $w=wallet_account('user',(int)$b['patient_user_id']);
            } elseif ($r['purpose'] === 'subscription') {
                $o=subscription_order((int)$r['reference_id']);
                $w=wallet_account('doctor',(int)$o['doctor_id']);
            } elseif ($r['purpose'] === 'center_subscription') {
                $o=query('SELECT center_id FROM center_orders WHERE id=?',[$r['reference_id']])->fetch();
                if(!$o) throw new AuthError('مرکز مرتبط با فاکتور پیدا نشد.');
                $w=wallet_account('center',(int)$o['center_id']);
            } else throw new AuthError('این نوع استرداد به کیف پول پشتیبانی نمی‌شود.');
            if ((int)$r['amount_toman']>0) wallet_post((int)$w['id'],(int)$r['amount_toman'],'admin-refund:'.$id,'استرداد تأییدشده مدیر شماره '.$id);
            $reference='WREF-ADMIN-'.$id;
        }
        query('UPDATE refunds SET status=?,method=?,bank_reference=?,note=?,handled_at=NOW(),handled_by_admin=? WHERE id=?', [
            $status,
            mb_substr($method, 0, 30),
            mb_substr(trim($reference), 0, 100),
            mb_substr(trim($note), 0, 500),
            $_SESSION['admin_id'] ?? null,
            $id,
        ]);
        if ($status === 'done') {
            if ($r['tx_id']) {
                query("UPDATE payment_transactions SET status='refunded' WHERE id=? AND status IN ('refund_due','verified')", [$r['tx_id']]);
            }
            if ($r['legacy_intent_id']) {
                query("UPDATE payment_intents SET status='refunded' WHERE id=?", [$r['legacy_intent_id']]);
                query("UPDATE payments SET status='refunded' WHERE booking_id=? AND status='refund_due'", [$r['reference_id']]);
            }
        }
        audit('refund.' . $status, $id);
    });
}

/** Payment revenue (verified minus refunded) for reports and the admin dashboard; VAT (rc.17) is reported separately and left out of income. */
function payment_revenue(string $from, string $to): array
{
    $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $where = "t.verified_at BETWEEN ? AND ? AND t.purpose<>'gateway_test' AND t.test_mode=0";
    return [
        'by_purpose' => query(
            "SELECT t.purpose,COUNT(*) n,SUM(CASE WHEN t.status IN ('verified','refund_due','refunded') THEN t.amount_toman-t.vat_toman ELSE 0 END) gross,SUM(CASE WHEN t.status='refunded' THEN GREATEST(0,t.amount_toman-t.vat_toman-t.refund_penalty_toman) ELSE 0 END) refunded,SUM(CASE WHEN t.status IN ('verified','refund_due') THEN t.vat_toman ELSE 0 END) vat FROM payment_transactions t WHERE $where GROUP BY t.purpose",
            $args,
        )->fetchAll(),
        'by_gateway' => query(
            "SELECT t.gateway,COUNT(*) n,SUM(t.amount_toman) amount FROM payment_transactions t WHERE $where AND t.status='verified' GROUP BY t.gateway",
            $args,
        )->fetchAll(),
        'by_day' => query(
            "SELECT DATE(t.verified_at) day,SUM(CASE WHEN t.purpose='subscription' AND t.status='verified' THEN t.amount_toman ELSE 0 END) subscription,SUM(CASE WHEN t.purpose='booking' THEN CASE WHEN t.status='refunded' THEN t.refund_penalty_toman ELSE t.amount_toman-t.vat_toman END ELSE 0 END) booking FROM payment_transactions t WHERE $where AND t.status IN ('verified','refunded') GROUP BY DATE(t.verified_at) ORDER BY day",
            $args,
        )->fetchAll(),
        'failed' => (int) query(
            "SELECT COUNT(*) FROM payment_transactions t WHERE t.created_at BETWEEN ? AND ? AND t.status IN ('failed','cancelled') AND t.purpose<>'gateway_test'",
            $args,
        )->fetchColumn(),
    ];
}

/* ---------- HTTP helper used by adapters ---------- */

/**
 * POST/GET to a gateway. Returns ['http'=>int,'body'=>string]. TLS is always verified and redirects are
 * not followed. In CLI tests $transport replaces the network call with the same signature.
 */
function gateway_http(string $operation, string $method, string $url, $body, array $headers, ?callable $transport = null, int $timeout = 20): array
{
    if ($transport) {
        $r = $transport($operation, $url, $body);
        if (!is_array($r) || !isset($r['http'], $r['body'])) {
            throw new AuthError('پاسخ آزمایشی درگاه نامعتبر است.', 503);
        }
        return $r;
    }
    if (!extension_loaded('curl')) {
        throw new AuthError('افزونه cURL روی هاست فعال نیست.', 503);
    }
    $ch = curl_init($url);
    $options = [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($response === false || strlen((string) $response) > 262144) {
        throw new AuthError('پاسخ معتبر از درگاه دریافت نشد؛ وضعیت پرداخت را دوباره بررسی کنید.', 503);
    }
    return ['http' => $http, 'body' => (string) $response];
}

function gateway_json(array $response): array
{
    $data = json_decode($response['body'], true);
    return is_array($data) ? $data : [];
}

/** Kept for code written before 0.9.0 (booking fee checks). */
function payment_ready(): bool
{
    return payment_available();
}
