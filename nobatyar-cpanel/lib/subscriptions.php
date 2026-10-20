<?php
declare(strict_types=1);

/**
 * Doctor subscriptions (phase 7).
 *
 * doctors.subscription_until is the last day of paid/granted access. Every period (paid, trial, gift,
 * manual) is a row in subscriptions, so history, the current plan and refunds can be reconstructed.
 * A purchase is first a proforma (subscription_orders, status "proforma"); it becomes an invoice with a
 * sequential number only after a verified payment, a free (100% discount) checkout, or an offline
 * payment recorded by the administrator with its bank reference.
 *
 * Renewing early never loses days: the new period starts the day after the current one ends.
 * After expiry, new bookings continue for the configured grace days; confirmed bookings and the
 * doctor's panel are never removed.
 */

function subscription_order_kinds(): array
{
    return [
        'new' => 'خرید اشتراک',
        'renew' => 'تمدید',
        'change_now' => 'تغییر فوری پلن',
        'change_later' => 'تغییر پلن از پایان دوره فعلی',
    ];
}

function subscription_kind_label(string $kind): string
{
    return [
        'paid' => 'خرید آنلاین',
        'wallet' => 'کیف پول',
        'free' => 'رایگان با کد تخفیف',
        'offline' => 'پرداخت ثبت‌شده توسط مدیر',
        'trial' => 'آزمایشی رایگان',
        'gift' => 'روزهای هدیه',
        'manual' => 'تمدید دستی',
    ][$kind] ?? $kind;
}

function order_status_label(string $status): string
{
    return [
        'proforma' => 'پیش‌فاکتور',
        'paid' => 'پرداخت‌شده',
        'cancelled' => 'لغوشده',
        'expired' => 'منقضی',
    ][$status] ?? $status;
}

function grace_days(): int
{
    return setting_int('subscription_grace_days', 3, 0, 30);
}

/**
 * 1.0.0-rc.17: subscription sales on/off (Settings → «درآمد و مالیات»). Off: doctors and centers are not
 * required to hold a subscription (visibility, online booking, plan limits), the subscription menus are
 * hidden, nothing can be bought and no expiry reminders go out. The platform then earns only the patient
 * booking fee. Turning it back on restores the previous rules with every date kept.
 */
function subscriptions_on(): bool
{
    return setting('subscriptions_enabled', '1') !== '0';
}

function subscriptions_require_on(): void
{
    if (!subscriptions_on()) {
        throw new AuthError('فروش اشتراک در حال حاضر غیرفعال است.', 403);
    }
}

/**
 * SQL condition kept for compatibility. Since 1.0.0-rc.20 doctors are listed and booked on the free plan:
 * a subscription (Pro) only unlocks features (lib/pro.php), it no longer decides visibility.
 */
function subscription_open_sql(string $alias = 'd'): string
{
    return '1=1';
}

/** Last appointment date that may be booked online: no subscription limit since rc.20 (free plan). */
function bookable_until(?string $until): ?string
{
    return '9999-12-31';
}

/** Plans for doctors (default), centers, or every plan when $audience is null. */
function plans_list(bool $activeOnly = true, ?string $audience = 'doctor'): array
{
    $where = [];
    $args = [];
    if ($activeOnly) {
        $where[] = 'active=1';
    }
    if ($audience !== null) {
        $where[] = 'audience=?';
        $args[] = $audience;
    }
    return query(
        'SELECT * FROM subscription_plans' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY audience,sort_order,duration_days,id',
        $args,
    )->fetchAll();
}

function plan_row(int $id, bool $activeOnly = true): array
{
    $p = query("SELECT * FROM subscription_plans WHERE id=? AND audience='doctor'" . ($activeOnly ? ' AND active=1' : ''), [$id])->fetch();
    if (!$p) {
        throw new AuthError('پلن انتخابی فعال نیست.', 404);
    }
    return $p;
}

function plan_features(array $plan): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $plan['features'])), fn($f) => $f !== '' && !preg_match('/یادآوری.*بیمار|یاداور.*بیمار/u', $f)));
}

/** Status summary shown to the doctor and the administrator. */
function subscription_status(array $doctor): array
{
    $today = date('Y-m-d');
    $until = $doctor['subscription_until'] ?? null;
    $grace = $until ? date('Y-m-d', strtotime($until . ' +' . grace_days() . ' days')) : null;
    $state = 'none';
    if ($until && $until >= $today) {
        $state = 'active';
    } elseif ($until && $grace >= $today) {
        $state = 'grace';
    } elseif ($until) {
        $state = 'expired';
    }
    $current = query(
        'SELECT s.*,p.name plan_name,p.max_clinics,p.max_secretaries,p.max_services FROM subscriptions s LEFT JOIN subscription_plans p ON p.id=s.plan_id WHERE s.doctor_id=? AND s.starts_on<=? AND s.ends_on>=? ORDER BY s.id DESC LIMIT 1',
        [$doctor['id'], $today, $today],
    )->fetch();
    $future = (int) query('SELECT COUNT(*) FROM subscriptions WHERE doctor_id=? AND starts_on>?', [
        $doctor['id'],
        $today,
    ])->fetchColumn();
    return [
        'state' => $state,
        'label' => ['active' => 'فعال', 'grace' => 'در دوره فرجه', 'expired' => 'منقضی', 'none' => 'بدون اشتراک'][$state],
        'until' => $until,
        'grace_until' => $grace,
        'days_left' => $until && $until >= $today ? (int) ((strtotime($until) - strtotime($today)) / 86400) + 1 : 0,
        'grace_left' => $state === 'grace' ? (int) ((strtotime($grace) - strtotime($today)) / 86400) + 1 : 0,
        'current' => $current ?: null,
        'has_future' => $future > 0,
        'trial_available' => setting('subscription_trial_enabled', '0') === '1' &&
            empty($doctor['trial_used_at']) &&
            !query("SELECT id FROM subscriptions WHERE doctor_id=? AND kind IN ('paid','offline','free','trial') LIMIT 1", [
                $doctor['id'],
            ])->fetch(),
    ];
}

/** Plan limits apply only while a plan-based period is current; trial, gift and manual periods have none. */
function plan_limit(int $doctor, string $column): ?int
{
    if (!subscriptions_on()) {
        return null;
    }
    if (!in_array($column, ['max_clinics', 'max_secretaries', 'max_services'], true)) {
        return null;
    }
    $v = query(
        "SELECT p.$column FROM subscriptions s JOIN subscription_plans p ON p.id=s.plan_id WHERE s.doctor_id=? AND s.starts_on<=CURDATE() AND s.ends_on>=CURDATE() ORDER BY s.id DESC LIMIT 1",
        [$doctor],
    )->fetchColumn();
    return $v === false || $v === null ? null : (int) $v;
}

/* ---------- Discount codes ---------- */

function normalize_discount_code(string $code): string
{
    $code = strtoupper(trim(digits($code)));
    if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
        throw new AuthError('کد تخفیف فقط شامل حروف انگلیسی، عدد، خط تیره و زیرخط است.');
    }
    return $code;
}

/**
 * Validates a code for an amount and returns [row, discount]. Must run inside a transaction:
 * the code row is locked so concurrent checkouts cannot exceed max_uses.
 */
function discount_apply(string $code, string $scope, int $amount, string $ownerKey, ?int $doctorId = null, ?int $planId = null): array
{
    $code = normalize_discount_code($code);
    $d = query('SELECT * FROM discount_codes WHERE code=? FOR UPDATE', [$code])->fetch();
    $now = date('Y-m-d H:i:s');
    if (
        !$d ||
        !$d['active'] ||
        !in_array($d['scope'], [$scope, 'all'], true) ||
        ($d['starts_at'] && $d['starts_at'] > $now) ||
        ($d['ends_at'] && $d['ends_at'] < $now)
    ) {
        throw new AuthError('کد تخفیف معتبر نیست یا منقضی شده است.');
    }
    if ($d['doctor_id'] && (int) $d['doctor_id'] !== (int) $doctorId) {
        throw new AuthError('این کد تخفیف اختصاصی پزشک دیگری است.');
    }
    if ($d['plan_id'] && (int) $d['plan_id'] !== (int) $planId) {
        throw new AuthError('این کد تخفیف برای پلن انتخابی نیست.');
    }
    if ($amount < (int) $d['min_amount_toman']) {
        throw new AuthError('حداقل مبلغ برای این کد ' . toman($d['min_amount_toman']) . ' است.');
    }
    $used = (int) query("SELECT COUNT(*) FROM discount_redemptions WHERE code_id=? AND status IN ('reserved','used')", [
        $d['id'],
    ])->fetchColumn();
    if ($d['max_uses'] !== null && $used >= (int) $d['max_uses']) {
        throw new AuthError('ظرفیت استفاده از این کد تخفیف تمام شده است.');
    }
    $mine = (int) query(
        "SELECT COUNT(*) FROM discount_redemptions WHERE code_id=? AND owner_key=? AND status IN ('reserved','used')",
        [$d['id'], $ownerKey],
    )->fetchColumn();
    if ($mine >= (int) $d['per_owner_limit']) {
        throw new AuthError('سقف استفاده شما از این کد تخفیف تکمیل شده است.');
    }
    $discount = $d['kind'] === 'percent' ? intdiv($amount * min(100, (int) $d['value']), 100) : (int) $d['value'];
    if ($d['max_discount_toman'] !== null) {
        $discount = min($discount, (int) $d['max_discount_toman']);
    }
    return [$d, max(0, min($amount, $discount))];
}

function discount_release(string $orderType, int $orderId): void
{
    query("UPDATE discount_redemptions SET status='released' WHERE order_type=? AND order_id=? AND status='reserved'", [
        $orderType,
        $orderId,
    ]);
}

/* ---------- Quotes and orders ---------- */

/**
 * Prices a purchase. change_now gives credit for the unused days of the current paid period,
 * valued at the price actually paid for it; it is not allowed when later periods are already bought.
 */
function subscription_quote(array $doctor, array $plan, string $kind): array
{
    if (!isset(subscription_order_kinds()[$kind])) {
        throw new AuthError('نوع خرید نامعتبر است.');
    }
    $status = subscription_status($doctor);
    $today = date('Y-m-d');
    $credit = 0;
    if ($kind === 'change_now') {
        if ($status['state'] !== 'active' || !$status['current']) {
            throw new AuthError('تغییر فوری فقط برای اشتراک فعال ممکن است؛ «خرید اشتراک» را انتخاب کنید.');
        }
        if ($status['has_future']) {
            throw new AuthError('برای این حساب دوره بعدی هم خریداری شده است؛ تغییر از پایان دوره را انتخاب کنید.');
        }
        $cur = $status['current'];
        if ($cur['order_id']) {
            $order = query('SELECT payable_toman,vat_toman,duration_days FROM subscription_orders WHERE id=?', [
                $cur['order_id'],
            ])->fetch();
            $remaining = (int) ((strtotime($cur['ends_on']) - strtotime($today)) / 86400);
            $paid = (int) $order['payable_toman'] - (int) $order['vat_toman'];
            $credit = max(0, intdiv($paid * max(0, $remaining), max(1, (int) $order['duration_days'])));
        }
        if ($credit >= (int) $plan['price_toman']) {
            throw new AuthError('اعتبار باقی‌مانده بیشتر از مبلغ پلن جدید است؛ تغییر از پایان دوره را انتخاب کنید.');
        }
        $start = $today;
    } else {
        $from = $doctor['subscription_until'] && $doctor['subscription_until'] >= $today
            ? date('Y-m-d', strtotime($doctor['subscription_until'] . ' +1 day'))
            : $today;
        $last = query('SELECT MAX(ends_on) FROM subscriptions WHERE doctor_id=?', [$doctor['id']])->fetchColumn();
        if ($last && $last >= $from) {
            $from = date('Y-m-d', strtotime($last . ' +1 day'));
        }
        $start = $from;
    }
    $end = date('Y-m-d', strtotime($start . ' +' . ((int) $plan['duration_days'] - 1) . ' days'));
    return [
        'base' => (int) $plan['price_toman'],
        'credit' => $credit,
        'start' => $start,
        'end' => $end,
        'vat_percent' => (float) setting('invoice_vat_percent', '0'),
    ];
}

function subscription_order_owner(array $doctor): string
{
    return 'doctor:' . (int) $doctor['id'];
}

function subscription_create_order(int $doctorId, int $planId, string $kind, string $code = '', bool $byAdmin = false): int
{
    subscriptions_require_on();
    return db_transaction(function () use ($doctorId, $planId, $kind, $code, $byAdmin) {
        $doctor = query('SELECT * FROM doctors WHERE id=? FOR UPDATE', [$doctorId])->fetch();
        if (!$doctor) {
            throw new AuthError('پزشک پیدا نشد.', 404);
        }
        $plan = plan_row($planId, !$byAdmin);
        if ((int) $plan['price_toman'] < 1 && !$byAdmin) {
            throw new AuthError('قیمت این پلن هنوز توسط مدیر تعیین نشده است.');
        }
        // One open proforma per doctor keeps checkout unambiguous.
        foreach (query("SELECT id FROM subscription_orders WHERE doctor_id=? AND status='proforma'", [$doctorId])->fetchAll(PDO::FETCH_COLUMN) as $old) {
            subscription_cancel_order_locked((int) $old);
        }
        $quote = subscription_quote($doctor, $plan, $kind);
        $subtotal = $quote['base'] - $quote['credit'];
        $discountRow = null;
        $discount = 0;
        if (trim($code) !== '') {
            [$discountRow, $discount] = discount_apply($code, 'subscription', $subtotal, subscription_order_owner($doctor), $doctorId, $planId);
        }
        $taxable = $subtotal - $discount;
        $vat = (int) round($taxable * $quote['vat_percent'] / 100);
        query(
            "INSERT INTO subscription_orders(doctor_id,plan_id,plan_name,duration_days,kind,base_toman,credit_toman,discount_code_id,discount_toman,vat_percent,vat_toman,payable_toman,status,period_start,period_end,expires_at,created_by_user,created_by_admin) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'proforma',?,?,DATE_ADD(NOW(),INTERVAL ? HOUR),?,?)",
            [
                $doctorId,
                $planId,
                $plan['name'],
                $plan['duration_days'],
                $kind,
                $quote['base'],
                $quote['credit'],
                $discountRow['id'] ?? null,
                $discount,
                $quote['vat_percent'],
                $vat,
                $taxable + $vat,
                $quote['start'],
                $quote['end'],
                setting_int('proforma_valid_hours', 48, 1, 720),
                $_SESSION['user_id'] ?? null,
                $byAdmin ? ($_SESSION['admin_id'] ?? null) : null,
            ],
        );
        $id = (int) db()->lastInsertId();
        if ($discountRow) {
            query(
                "INSERT INTO discount_redemptions(code_id,order_type,order_id,owner_key,amount_toman,status) VALUES(?,'subscription',?,?,?,'reserved')",
                [$discountRow['id'], $id, subscription_order_owner($doctor), $discount],
            );
        }
        event_record($doctorId, 'subscription.proforma', ['order_id' => $id, 'plan' => $plan['name'], 'kind' => $kind]);
        return $id;
    });
}

function subscription_order(int $id): array
{
    $o = query(
        'SELECT o.*,d.name doctor_name,d.medical_code,d.mobile doctor_mobile,dc.code discount_code FROM subscription_orders o JOIN doctors d ON d.id=o.doctor_id LEFT JOIN discount_codes dc ON dc.id=o.discount_code_id WHERE o.id=?',
        [$id],
    )->fetch();
    if (!$o) {
        throw new AuthError('سفارش پیدا نشد.', 404);
    }
    return $o;
}

function subscription_cancel_order_locked(int $id): void
{
    $o = query('SELECT * FROM subscription_orders WHERE id=? FOR UPDATE', [$id])->fetch();
    if (!$o || $o['status'] !== 'proforma') {
        throw new AuthError('فقط پیش‌فاکتور پرداخت‌نشده قابل لغو است.', 409);
    }
    // Keep bank transactions intact: a late verified callback is routed to refund_due
    // by payment_fulfil_subscription instead of activating a cancelled order.
    query("UPDATE subscription_orders SET status='cancelled' WHERE id=?", [$id]);
    discount_release('subscription', $id);
}

function subscription_cancel_order(int $id, int $doctorId): void
{
    db_transaction(function () use ($id, $doctorId) {
        $o = subscription_order($id);
        if ((int) $o['doctor_id'] !== $doctorId) {
            throw new AuthError('به این سفارش دسترسی ندارید.', 403);
        }
        subscription_cancel_order_locked($id);
        event_record($doctorId, 'subscription.cancel_order', ['order_id' => $id]);
    });
}

/**
 * Starts checkout. Zero-amount orders (full discount or credit) are activated immediately;
 * otherwise a payment transaction is created and the redirect description is returned.
 */
function subscription_checkout(int $orderId, int $doctorId, ?callable $transport = null): array
{
    subscriptions_require_on();
    $o = subscription_order($orderId);
    if ((int) $o['doctor_id'] !== $doctorId) {
        throw new AuthError('به این سفارش دسترسی ندارید.', 403);
    }
    if ($o['status'] !== 'proforma' || $o['expires_at'] <= date('Y-m-d H:i:s')) {
        throw new AuthError('این پیش‌فاکتور قابل پرداخت نیست؛ پیش‌فاکتور تازه بسازید.', 409);
    }
    if ((int) $o['payable_toman'] === 0) {
        db_transaction(fn() => subscription_activate($orderId, 'free'));
        return ['done' => true];
    }
    $existing = query(
        "SELECT * FROM payment_transactions WHERE purpose='subscription' AND reference_id=? AND status IN ('initiated','redirected') ORDER BY id DESC LIMIT 1",
        [$orderId],
    )->fetch();
    $tx = $existing ?: payment_create('subscription', $orderId, (int) $o['payable_toman'], 'اشتراک ' . $o['plan_name'] . ' نوبت‌یار', [
        'user_id' => $_SESSION['user_id'] ?? null,
        'doctor_id' => $doctorId,
    ]);
    if (!$existing && (int) ($o['vat_toman'] ?? 0) > 0) {
        query('UPDATE payment_transactions SET vat_toman=? WHERE id=?', [(int) $o['vat_toman'], $tx['id']]);
    }
    return ['done' => false, 'redirect' => payment_redirect((int) $tx['id'], $transport), 'tx' => (int) $tx['id']];
}

/** Called by the payment module after a verified payment (inside its transaction). */
function payment_fulfil_subscription(array $tx): void
{
    $o = query('SELECT * FROM subscription_orders WHERE id=? FOR UPDATE', [$tx['reference_id']])->fetch();
    if (!$o || $o['status'] !== 'proforma') {
        throw new AuthError('سفارش اشتراک دیگر باز نیست؛ مبلغ باید بازگردانده شود.', 409);
    }
    if ((int) $o['payable_toman'] !== (int) $tx['amount_toman']) {
        throw new AuthError('مبلغ پرداخت با پیش‌فاکتور یکسان نیست.', 409);
    }
    subscription_activate((int) $o['id'], 'paid', (int) $tx['id']);
}

function payment_released_subscription(array $tx): void
{
    // The proforma stays open until it expires so the doctor can retry; nothing to release here.
}

/**
 * Turns a proforma into a paid invoice and grants the period. A late payment for an expired
 * proforma is still honoured: the period is recomputed from today/the current end date.
 */
function subscription_activate(int $orderId, string $method, ?int $txId = null, ?string $offlineRef = null): void
{
    $o = query('SELECT * FROM subscription_orders WHERE id=? FOR UPDATE', [$orderId])->fetch();
    if (!$o || $o['status'] !== 'proforma') {
        throw new AuthError('سفارش قابل فعال‌سازی نیست.', 409);
    }
    $doctor = query('SELECT * FROM doctors WHERE id=? FOR UPDATE', [$o['doctor_id']])->fetch();
    $plan = query('SELECT * FROM subscription_plans WHERE id=?', [$o['plan_id']])->fetch();
    $plan['duration_days'] = $o['duration_days'];
    $plan['price_toman'] = $o['base_toman'];
    if ($o['kind'] === 'change_now') {
        $start = date('Y-m-d');
        // Unused days of the replaced period end today; history keeps the original row.
        query('UPDATE subscriptions SET ends_on=? WHERE doctor_id=? AND starts_on<=? AND ends_on>=?', [
            date('Y-m-d', strtotime('-1 day')),
            $o['doctor_id'],
            $start,
            $start,
        ]);
        $end = date('Y-m-d', strtotime($start . ' +' . ((int) $o['duration_days'] - 1) . ' days'));
    } else {
        $q = subscription_quote($doctor, $plan, 'renew');
        [$start, $end] = [$q['start'], $q['end']];
    }
    $number = invoice_next_number();
    query(
        "UPDATE subscription_orders SET status='paid',payment_method=?,payment_tx_id=?,offline_reference=?,period_start=?,period_end=?,number=?,paid_at=NOW() WHERE id=?",
        [$method, $txId, $offlineRef, $start, $end, $number, $orderId],
    );
    query(
        'INSERT INTO subscriptions(doctor_id,starts_on,ends_on,amount,payment_status,plan_id,order_id,kind,days,created_by_admin) VALUES(?,?,?,?,?,?,?,?,?,?)',
        [
            $o['doctor_id'],
            $start,
            $end,
            $o['payable_toman'],
            $method,
            $o['plan_id'],
            $orderId,
            $method === 'paid' ? 'paid' : $method,
            $o['duration_days'],
            $method === 'offline' ? ($_SESSION['admin_id'] ?? null) : null,
        ],
    );
    $newUntil = max((string) $doctor['subscription_until'], $end);
    if ($o['kind'] === 'change_now') {
        $newUntil = $end;
    }
    query('UPDATE doctors SET subscription_until=? WHERE id=?', [$newUntil, $o['doctor_id']]);
    entitlement_sync((int) $o['doctor_id']); // rc.20: Pro limits
    query("UPDATE discount_redemptions SET status='used' WHERE order_type='subscription' AND order_id=? AND status='reserved'", [
        $orderId,
    ]);
    event_record((int) $o['doctor_id'], 'subscription.paid', [
        'order_id' => $orderId,
        'invoice' => $number,
        'method' => $method,
        'start' => $start,
        'end' => $end,
    ]);
    notify_doctor((int) $o['doctor_id'], 'اشتراک فعال شد', 'فاکتور ' . $number . ' • اعتبار تا ' . jdate($newUntil));
}

/** Sequential invoice number per Jalali year: NY-1405-000001. */
function invoice_next_number(): string
{
    $year = (int) digits(substr(jdate(date('Y-m-d')), 0, 4));
    query('INSERT IGNORE INTO invoice_counters(year,last_number) VALUES(?,0)', [$year]);
    $last = (int) query('SELECT last_number FROM invoice_counters WHERE year=? FOR UPDATE', [$year])->fetchColumn();
    query('UPDATE invoice_counters SET last_number=? WHERE year=?', [$last + 1, $year]);
    return sprintf('NY-%d-%06d', $year, $last + 1);
}

function notify_doctor(int $doctorId, string $title, string $body): void
{
    $owner = query('SELECT user_id FROM doctor_accounts WHERE doctor_id=?', [$doctorId])->fetchColumn();
    if ($owner) {
        notify_user((int) $owner, $title, $body, null, 'general', 'doctor-panel.php?doctor=' . $doctorId);
    }
}

/* ---------- Trial, gifts, manual extension, offline payment ---------- */

function subscription_start_trial(int $doctorId): void
{
    db_transaction(function () use ($doctorId) {
        $d = query('SELECT * FROM doctors WHERE id=? FOR UPDATE', [$doctorId])->fetch();
        if (!$d || $d['review_status'] !== 'approved') {
            throw new AuthError('دوره آزمایشی پس از تأیید پروفایل فعال می‌شود.', 409);
        }
        if (!subscription_status($d)['trial_available']) {
            throw new AuthError('دوره آزمایشی برای این حساب در دسترس نیست.', 409);
        }
        $days = setting_int('subscription_trial_days', 14, 1, 90);
        $start = $d['subscription_until'] && $d['subscription_until'] >= date('Y-m-d')
            ? date('Y-m-d', strtotime($d['subscription_until'] . ' +1 day'))
            : date('Y-m-d');
        $end = date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days'));
        query("INSERT INTO subscriptions(doctor_id,starts_on,ends_on,amount,payment_status,kind,days) VALUES(?,?,?,0,'trial','trial',?)", [
            $doctorId,
            $start,
            $end,
            $days,
        ]);
        query('UPDATE doctors SET subscription_until=?,trial_used_at=NOW() WHERE id=?', [$end, $doctorId]);
        entitlement_sync((int) $doctorId); // rc.20: Pro limits
        event_record($doctorId, 'subscription.trial', ['days' => $days, 'end' => $end]);
    });
}

/** Admin: add gift days (kind=gift) or extend manually (kind=manual) with a note. */
function subscription_grant(int $doctorId, int $days, string $kind, string $note): void
{
    if (!in_array($kind, ['gift', 'manual'], true) || $days < 1 || $days > 3650) {
        throw new AuthError('تعداد روز باید بین ۱ تا ۳۶۵۰ باشد.');
    }
    db_transaction(function () use ($doctorId, $days, $kind, $note) {
        $d = query('SELECT * FROM doctors WHERE id=? FOR UPDATE', [$doctorId])->fetch();
        if (!$d) {
            throw new AuthError('پزشک پیدا نشد.', 404);
        }
        $start = $d['subscription_until'] && $d['subscription_until'] >= date('Y-m-d')
            ? date('Y-m-d', strtotime($d['subscription_until'] . ' +1 day'))
            : date('Y-m-d');
        $end = date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days'));
        query(
            'INSERT INTO subscriptions(doctor_id,starts_on,ends_on,amount,payment_status,kind,days,note,created_by_admin) VALUES(?,?,?,0,?,?,?,?,?)',
            [$doctorId, $start, $end, $kind, $kind, $days, mb_substr($note, 0, 255), $_SESSION['admin_id'] ?? null],
        );
        query('UPDATE doctors SET subscription_until=? WHERE id=?', [$end, $doctorId]);
        entitlement_sync((int) $doctorId); // rc.20: Pro limits
        event_record($doctorId, 'subscription.' . $kind, ['days' => $days, 'end' => $end, 'note' => $note]);
        notify_doctor($doctorId, $kind === 'gift' ? 'روز هدیه به اشتراک اضافه شد' : 'اشتراک تمدید شد', fa_number($days) . ' روز • اعتبار تا ' . jdate($end));
    });
}

/** Admin records a payment received outside the gateway (e.g. bank transfer) with its reference. */
function subscription_offline_paid(int $orderId, string $reference): void
{
    $reference = trim($reference);
    if (mb_strlen($reference) < 4) {
        throw new AuthError('شماره پیگیری یا مرجع واریز را وارد کنید.');
    }
    db_transaction(fn() => subscription_activate($orderId, 'offline', null, mb_substr($reference, 0, 100)));
    audit('subscription.offline', $orderId);
}

/** Cron: expire stale proformas and release their discount reservations. */
function subscription_expire_proformas(): int
{
    $ids = query(
        "SELECT o.id FROM subscription_orders o WHERE o.status='proforma' AND o.expires_at<NOW() AND NOT EXISTS(SELECT 1 FROM payment_transactions t WHERE t.purpose='subscription' AND t.reference_id=o.id AND t.status='redirected' AND t.redirected_at>DATE_SUB(NOW(),INTERVAL 1 HOUR))",
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        db_transaction(function () use ($id) {
            $q = query("UPDATE subscription_orders SET status='expired' WHERE id=? AND status='proforma'", [$id]);
            if ($q->rowCount()) {
                discount_release('subscription', (int) $id);
            }
        });
    }
    return count($ids);
}

/* ---------- Administration ---------- */

function plan_admin_save(array $data): void
{
    $id = empty($data['id']) ? 0 : positive_id($data['id']);
    $name = field($data, 'name', 120);
    $days = number_range($data, 'duration_days', 1, 3650);
    $price = parse_toman((string) ($data['price_toman'] ?? '0'));
    $active = !empty($data['active']) ? 1 : 0;
    if ($active && $price < 1000) {
        throw new AuthError('برای فعال‌سازی، قیمت پلن حداقل ۱٬۰۰۰ تومان باشد.');
    }
    $limit = function (string $key) use ($data): ?int {
        $v = trim(digits((string) ($data[$key] ?? '')));
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^[0-9]{1,4}$/', $v) || (int) $v < 1) {
            throw new AuthError('محدودیت تعداد باید عدد مثبت یا خالی باشد.');
        }
        return (int) $v;
    };
    $audience = (string) ($data['audience'] ?? 'doctor');
    if (!in_array($audience, ['doctor', 'center'], true)) {
        throw new AuthError('مخاطب پلن نامعتبر است.');
    }
    $values = [
        $name,
        $days,
        $price,
        field($data, 'features', 3000, false),
        $limit('max_clinics'),
        $limit('max_secretaries'),
        !empty($data['highlight']) ? 1 : 0,
        $active,
        (int) digits((string) ($data['sort_order'] ?? '0')),
        $audience,
    ];
    if ($id) {
        query(
            'UPDATE subscription_plans SET name=?,duration_days=?,price_toman=?,features=?,max_clinics=?,max_secretaries=?,highlight=?,active=?,sort_order=?,audience=? WHERE id=?',
            [...$values, $id],
        );
    } else {
        query(
            'INSERT INTO subscription_plans(name,duration_days,price_toman,features,max_clinics,max_secretaries,highlight,active,sort_order,audience) VALUES(?,?,?,?,?,?,?,?,?,?)',
            $values,
        );
        $id = (int) db()->lastInsertId();
    }
    // rc.20: services limit of a Pro plan (empty = unlimited).
    query('UPDATE subscription_plans SET max_services=? WHERE id=?', [$limit('max_services'), $id]);
    audit('plan.save', $id);
    entitlement_sync_all();
}

function discount_admin_save(array $data): void
{
    $id = empty($data['id']) ? 0 : positive_id($data['id']);
    $code = normalize_discount_code((string) ($data['code'] ?? ''));
    $kind = (string) ($data['kind'] ?? 'percent');
    $scope = (string) ($data['scope'] ?? 'subscription');
    if (!in_array($kind, ['percent', 'fixed'], true) || !in_array($scope, ['subscription', 'booking', 'center', 'all'], true)) {
        throw new AuthError('نوع یا کاربرد کد نامعتبر است.');
    }
    $value = $kind === 'percent' ? number_range($data, 'value', 1, 100) : parse_toman((string) ($data['value'] ?? '0'));
    if ($value < 1) {
        throw new AuthError('مقدار تخفیف باید مثبت باشد.');
    }
    $optional = fn(string $k) => trim((string) ($data[$k] ?? '')) === '' ? null : parse_toman((string) $data[$k]);
    $maxUses = trim(digits((string) ($data['max_uses'] ?? '')));
    $doctor = empty($data['doctor_id']) ? null : positive_id($data['doctor_id']);
    if ($doctor && !query('SELECT id FROM doctors WHERE id=?', [$doctor])->fetch()) {
        throw new AuthError('پزشک کد اختصاصی پیدا نشد.');
    }
    $plan = empty($data['plan_id']) ? null : positive_id($data['plan_id']);
    $starts = trim((string) ($data['starts_at'] ?? '')) !== '' ? parse_jdate((string) $data['starts_at']) . ' 00:00:00' : null;
    $ends = trim((string) ($data['ends_at'] ?? '')) !== '' ? parse_jdate((string) $data['ends_at']) . ' 23:59:59' : null;
    if ($starts && $ends && $ends < $starts) {
        throw new AuthError('پایان اعتبار کد باید بعد از شروع باشد.');
    }
    $values = [
        $code,
        field($data, 'title', 190, false),
        $scope,
        $kind,
        $value,
        $optional('max_discount_toman'),
        $optional('min_amount_toman') ?? 0,
        $maxUses === '' ? null : (int) $maxUses,
        number_range($data + ['per_owner_limit' => '1'], 'per_owner_limit', 1, 1000),
        $doctor,
        $plan,
        $starts,
        $ends,
        !empty($data['active']) ? 1 : 0,
    ];
    if ($id) {
        query(
            'UPDATE discount_codes SET code=?,title=?,scope=?,kind=?,value=?,max_discount_toman=?,min_amount_toman=?,max_uses=?,per_owner_limit=?,doctor_id=?,plan_id=?,starts_at=?,ends_at=?,active=? WHERE id=?',
            [...$values, $id],
        );
    } else {
        query(
            'INSERT INTO discount_codes(code,title,scope,kind,value,max_discount_toman,min_amount_toman,max_uses,per_owner_limit,doctor_id,plan_id,starts_at,ends_at,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            $values,
        );
        $id = (int) db()->lastInsertId();
    }
    audit('discount.save', $id);
}

/**
 * Subscription revenue between two dates, grouped by day/month and by the city of the doctor's
 * primary clinic. Only paid orders count; free and trial periods carry no revenue.
 */
function subscription_revenue(string $from, string $to, int $province = 0): array
{
    $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $where = "o.status='paid' AND o.paid_at BETWEEN ? AND ?";
    if ($province) {
        $where .= ' AND c.province_id=?';
        $args[] = $province;
    }
    $join = ' FROM subscription_orders o JOIN doctors d ON d.id=o.doctor_id LEFT JOIN clinics cl ON cl.doctor_id=d.id AND cl.is_primary=1 LEFT JOIN cities c ON c.id=cl.city_id LEFT JOIN provinces p ON p.id=c.province_id WHERE ' . $where;
    return [
        'totals' => query(
            'SELECT COUNT(*) orders,COALESCE(SUM(o.payable_toman),0) revenue,COALESCE(SUM(o.vat_toman),0) vat,COALESCE(SUM(o.discount_toman),0) discounts' . $join,
            $args,
        )->fetch(),
        'by_city' => query(
            "SELECT COALESCE(p.name,'نامشخص') province,COALESCE(c.name,'نامشخص') city,COUNT(*) orders,SUM(o.payable_toman) revenue" .
                $join .
                ' GROUP BY p.name,c.name ORDER BY revenue DESC',
            $args,
        )->fetchAll(),
        'by_day' => query('SELECT DATE(o.paid_at) day,COUNT(*) orders,SUM(o.payable_toman) revenue' . $join . ' GROUP BY DATE(o.paid_at) ORDER BY day', $args)->fetchAll(),
        'by_plan' => query('SELECT o.plan_name,COUNT(*) orders,SUM(o.payable_toman) revenue' . $join . ' GROUP BY o.plan_name ORDER BY revenue DESC', $args)->fetchAll(),
        'rows' => query(
            "SELECT o.number,o.paid_at,o.plan_name,o.kind,o.payment_method,o.base_toman,o.discount_toman,o.vat_toman,o.payable_toman,d.name doctor,COALESCE(c.name,'') city,COALESCE(p.name,'') province" .
                $join .
                ' ORDER BY o.paid_at',
            $args,
        )->fetchAll(),
    ];
}

/* ---------- Doctor panel actions ---------- */

function doctor_subscription_action(int $doctorId, string $action, array $data): array
{
    subscriptions_require_on();
    switch ($action) {
        case 'sub_order':
            $kind = (string) ($data['kind'] ?? 'new');
            $id = subscription_create_order($doctorId, positive_id($data['plan_id'] ?? 0), $kind, (string) ($data['discount_code'] ?? ''));
            return ['message' => 'پیش‌فاکتور صادر شد؛ پس از بررسی، پرداخت را انجام دهید.', 'order' => $id];
        case 'sub_checkout':
            throttle('subscription_checkout', 30, 3600);
            if (!empty($data['use_wallet'])) { wallet_pay_subscription(positive_id($data['order_id']??0),$doctorId); return ['message'=>'اشتراک با کیف پول فعال شد.']; }
            $r = subscription_checkout(positive_id($data['order_id'] ?? 0), $doctorId);
            return $r['done'] ? ['message' => 'اشتراک بدون نیاز به پرداخت فعال شد.'] : ['message' => '', 'redirect' => $r['redirect']];
        case 'sub_cancel':
            subscription_cancel_order(positive_id($data['order_id'] ?? 0), $doctorId);
            return ['message' => 'پیش‌فاکتور لغو شد.'];
        case 'sub_trial':
            subscription_start_trial($doctorId);
            return ['message' => 'دوره آزمایشی رایگان فعال شد.'];
    }
    throw new AuthError('عملیات نامعتبر است.');
}

/** Sends the browser to the bank: plain redirect for GET gateways, auto-submitted form for POST gateways. */
function gateway_send(array $redirect): never
{
    if (($redirect['method'] ?? 'GET') === 'GET') {
        redirect($redirect['url']);
    }
    header('Content-Type: text/html; charset=utf-8');
    // No inline script (CSP): assets/gateway.js submits the form; the button is the no-JavaScript fallback.
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>انتقال به درگاه پرداخت</title><link rel="stylesheet" href="assets/style.css"><script src="assets/gateway.js" defer></script></head><body class="gateway-redirect"><main class="narrow"><div class="card"><p>در حال انتقال به درگاه پرداخت…</p><form id="gw" method="post" data-autosubmit action="' .
        esc($redirect['url']) .
        '">';
    foreach ($redirect['fields'] ?? [] as $name => $value) {
        echo '<input type="hidden" name="' . esc($name) . '" value="' . esc($value) . '">';
    }
    echo '<button>اگر منتقل نشدید اینجا را بزنید</button></form></div></main></body></html>';
    exit();
}
