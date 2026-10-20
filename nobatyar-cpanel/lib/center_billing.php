<?php
declare(strict_types=1);

/**
 * Center subscriptions: plans with audience "center", proforma and invoice (shared yearly numbering),
 * discount codes (scope "center" or "all"), payment through the shared module (purpose
 * center_subscription), trial, admin gift/manual extension and offline payment.
 * A center subscription unlocks the center's own features (staff reception access, aggregated calendar,
 * reports, announcements, public page). Doctors keep paying their own subscription; bookings at a center
 * branch follow the doctor's subscription, not the center's.
 */

function center_plans(bool $activeOnly = true): array
{
    return array_values(array_filter(plans_list($activeOnly, 'center'), fn($p) => (int)$p['price_toman'] > 0));
}

function center_plan(int $id, bool $activeOnly = true): array
{
    $p = query("SELECT * FROM subscription_plans WHERE id=? AND audience='center'" . ($activeOnly ? ' AND active=1' : ''), [$id])->fetch();
    if (!$p) {
        throw new AuthError('پلن اشتراک مرکز پیدا نشد یا فعال نیست.', 404);
    }
    return $p;
}

function center_subscription_state(array $c): array
{
    $today = date('Y-m-d');
    $until = $c['subscription_until'];
    $graceEnd = $until ? date('Y-m-d', strtotime($until . ' +' . center_grace_days() . ' days')) : null;
    $state = !$until ? 'none' : ($until >= $today ? 'active' : ($graceEnd >= $today ? 'grace' : 'expired'));
    return [
        'state' => $state,
        'label' => ['none' => 'بدون اشتراک', 'active' => 'فعال', 'grace' => 'دوره فرجه', 'expired' => 'منقضی'][$state],
        'until' => $until,
        'days_left' => $until ? (int) floor((strtotime($until) - strtotime($today)) / 86400) : 0,
        'grace_end' => $graceEnd,
        'trial_available' => false,
    ];
}

function center_period_start(array $c): string
{
    $today = date('Y-m-d');
    $from = $c['subscription_until'] && $c['subscription_until'] >= $today ? date('Y-m-d', strtotime($c['subscription_until'] . ' +1 day')) : $today;
    $last = query('SELECT MAX(ends_on) FROM center_subscriptions WHERE center_id=?', [$c['id']])->fetchColumn();
    if ($last && $last >= $from) {
        $from = date('Y-m-d', strtotime($last . ' +1 day'));
    }
    return $from;
}

function center_cancel_order_locked(int $id): void
{
    $o = query('SELECT * FROM center_orders WHERE id=? FOR UPDATE', [$id])->fetch();
    if (!$o || $o['status'] !== 'proforma') {
        throw new AuthError('فقط پیش‌فاکتور پرداخت‌نشده قابل لغو است.', 409);
    }
    if (query("SELECT id FROM payment_transactions WHERE purpose='center_subscription' AND reference_id=? AND status='redirected'", [$id])->fetch()) {
        throw new AuthError('برای این پیش‌فاکتور پرداختی در جریان است؛ چند دقیقه بعد دوباره تلاش کنید.', 409);
    }
    query("UPDATE center_orders SET status='cancelled' WHERE id=?", [$id]);
    discount_release('center', $id);
}

function center_order_create(int $center, int $planId, string $code = '', bool $byAdmin = false): int
{
    subscriptions_require_on();
    if (!$byAdmin) {
        center_actor($center, 'billing');
    }
    return db_transaction(function () use ($center, $planId, $code, $byAdmin) {
        $c = query('SELECT * FROM centers WHERE id=? FOR UPDATE', [$center])->fetch();
        if (!$c || $c['review_status'] !== 'approved') {
            throw new AuthError('خرید اشتراک پس از تأیید مرکز ممکن است.', 409);
        }
        $plan = center_plan($planId, !$byAdmin);
        if ((int) $plan['price_toman'] < 1 && !$byAdmin) {
            throw new AuthError('قیمت این پلن هنوز توسط مدیر تعیین نشده است.');
        }
        foreach (query("SELECT id FROM center_orders WHERE center_id=? AND status='proforma'", [$center])->fetchAll(PDO::FETCH_COLUMN) as $old) {
            center_cancel_order_locked((int) $old);
        }
        $base = (int) $plan['price_toman'];
        $row = null;
        $discount = 0;
        if (trim($code) !== '') {
            [$row, $discount] = discount_apply($code, 'center', $base, 'center:' . $center, null, $planId);
        }
        $vatPercent = (float) setting('invoice_vat_percent', '0');
        $taxable = $base - $discount;
        $vat = (int) round($taxable * $vatPercent / 100);
        $start = center_period_start($c);
        query(
            "INSERT INTO center_orders(center_id,plan_id,plan_name,duration_days,base_toman,discount_code_id,discount_toman,vat_percent,vat_toman,payable_toman,status,period_start,period_end,expires_at,created_by_user,created_by_admin) VALUES(?,?,?,?,?,?,?,?,?,?,'proforma',?,?,DATE_ADD(NOW(),INTERVAL ? HOUR),?,?)",
            [
                $center,
                $planId,
                $plan['name'],
                $plan['duration_days'],
                $base,
                $row['id'] ?? null,
                $discount,
                $vatPercent,
                $vat,
                $taxable + $vat,
                $start,
                date('Y-m-d', strtotime($start . ' +' . ((int) $plan['duration_days'] - 1) . ' days')),
                setting_int('proforma_valid_hours', 48, 1, 720),
                $byAdmin ? null : ($_SESSION['user_id'] ?? null),
                $byAdmin ? ($_SESSION['admin_id'] ?? null) : null,
            ],
        );
        $id = (int) db()->lastInsertId();
        if ($row) {
            query("INSERT INTO discount_redemptions(code_id,order_type,order_id,owner_key,amount_toman,status) VALUES(?,'center',?,?,?,'reserved')", [
                $row['id'],
                $id,
                'center:' . $center,
                $discount,
            ]);
        }
        center_event($center, 'subscription.order', ['order_id' => $id, 'plan' => $plan['name']]);
        return $id;
    });
}

function center_order(int $id): array
{
    $o = query('SELECT o.*,ce.name center_name,ce.national_id,ce.address,ce.phone,ce.license_number FROM center_orders o JOIN centers ce ON ce.id=o.center_id WHERE o.id=?', [$id])->fetch();
    if (!$o) {
        throw new AuthError('سفارش پیدا نشد.', 404);
    }
    return $o;
}

function center_order_cancel(int $center, int $orderId): void
{
    center_actor($center, 'billing');
    db_transaction(function () use ($center, $orderId) {
        if (!query('SELECT id FROM center_orders WHERE id=? AND center_id=?', [$orderId, $center])->fetch()) {
            throw new AuthError('سفارش پیدا نشد.', 404);
        }
        center_cancel_order_locked($orderId);
        center_event($center, 'subscription.cancel', ['order_id' => $orderId]);
    });
}

function center_checkout(int $center, int $orderId, ?callable $transport = null): array
{
    subscriptions_require_on();
    center_actor($center, 'billing');
    $o = center_order($orderId);
    if ((int) $o['center_id'] !== $center) {
        throw new AuthError('به این سفارش دسترسی ندارید.', 403);
    }
    if ($o['status'] !== 'proforma' || $o['expires_at'] <= date('Y-m-d H:i:s')) {
        throw new AuthError('این پیش‌فاکتور قابل پرداخت نیست؛ پیش‌فاکتور تازه بسازید.', 409);
    }
    if ((int) $o['payable_toman'] === 0) {
        db_transaction(fn() => center_subscription_activate($orderId, 'free'));
        return ['done' => true];
    }
    $existing = query(
        "SELECT * FROM payment_transactions WHERE purpose='center_subscription' AND reference_id=? AND status IN ('initiated','redirected') ORDER BY id DESC LIMIT 1",
        [$orderId],
    )->fetch();
    $tx = $existing ?: payment_create('center_subscription', $orderId, (int) $o['payable_toman'], 'اشتراک مرکز ' . $o['center_name'], [
        'user_id' => $_SESSION['user_id'] ?? null,
    ]);
    if (!$existing && (int) ($o['vat_toman'] ?? 0) > 0) {
        query('UPDATE payment_transactions SET vat_toman=? WHERE id=?', [(int) $o['vat_toman'], $tx['id']]);
    }
    return ['done' => false, 'redirect' => payment_redirect((int) $tx['id'], $transport), 'tx' => (int) $tx['id']];
}

function payment_fulfil_center_subscription(array $tx): void
{
    $o = query('SELECT * FROM center_orders WHERE id=? FOR UPDATE', [$tx['reference_id']])->fetch();
    if (!$o || $o['status'] !== 'proforma') {
        throw new AuthError('سفارش اشتراک مرکز دیگر باز نیست؛ مبلغ باید بازگردانده شود.', 409);
    }
    if ((int) $o['payable_toman'] !== (int) $tx['amount_toman']) {
        throw new AuthError('مبلغ پرداخت با پیش‌فاکتور یکسان نیست.', 409);
    }
    center_subscription_activate((int) $o['id'], 'paid', (int) $tx['id']);
}

function center_subscription_activate(int $orderId, string $method, ?int $txId = null, ?string $offlineRef = null): void
{
    $o = query('SELECT * FROM center_orders WHERE id=? FOR UPDATE', [$orderId])->fetch();
    if (!$o || $o['status'] !== 'proforma') {
        throw new AuthError('سفارش قابل فعال‌سازی نیست.', 409);
    }
    $c = query('SELECT * FROM centers WHERE id=? FOR UPDATE', [$o['center_id']])->fetch();
    $start = center_period_start($c);
    $end = date('Y-m-d', strtotime($start . ' +' . ((int) $o['duration_days'] - 1) . ' days'));
    $number = invoice_next_number();
    query("UPDATE center_orders SET status='paid',payment_method=?,payment_tx_id=?,offline_reference=?,period_start=?,period_end=?,number=?,paid_at=NOW() WHERE id=?", [
        $method,
        $txId,
        $offlineRef,
        $start,
        $end,
        $number,
        $orderId,
    ]);
    query('INSERT INTO center_subscriptions(center_id,starts_on,ends_on,kind,order_id,amount_toman,days,created_by_admin) VALUES(?,?,?,?,?,?,?,?)', [
        $o['center_id'],
        $start,
        $end,
        $method === 'paid' ? 'paid' : $method,
        $orderId,
        $o['payable_toman'],
        $o['duration_days'],
        $method === 'offline' ? ($_SESSION['admin_id'] ?? null) : null,
    ]);
    $until = max((string) $c['subscription_until'], $end);
    query('UPDATE centers SET subscription_until=? WHERE id=?', [$until, $o['center_id']]);
    query("UPDATE discount_redemptions SET status='used' WHERE order_type='center' AND order_id=? AND status='reserved'", [$orderId]);
    center_event((int) $o['center_id'], 'subscription.paid', ['order_id' => $orderId, 'invoice' => $number, 'method' => $method, 'end' => $end]);
    center_notify((int) $o['center_id'], 'perm_billing', 'اشتراک مرکز فعال شد', 'فاکتور ' . $number . ' • اعتبار تا ' . jdate($until));
    center_sync_scopes((int) $o['center_id']);
}

function center_start_trial(int $center): void
{
    subscriptions_require_on();
    center_actor($center, 'billing');
    db_transaction(function () use ($center) {
        $c = query('SELECT * FROM centers WHERE id=? FOR UPDATE', [$center])->fetch();
        if (!center_subscription_state($c)['trial_available']) {
            throw new AuthError('دوره آزمایشی برای این مرکز در دسترس نیست.', 409);
        }
        $days = setting_int('center_trial_days', 14, 1, 90);
        $start = center_period_start($c);
        $end = date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days'));
        query("INSERT INTO center_subscriptions(center_id,starts_on,ends_on,kind,days) VALUES(?,?,?,'trial',?)", [$center, $start, $end, $days]);
        query('UPDATE centers SET subscription_until=?,trial_used_at=NOW() WHERE id=?', [$end, $center]);
        center_event($center, 'subscription.trial', ['days' => $days, 'end' => $end]);
        center_sync_scopes($center);
    });
}

function center_grant(int $center, int $days, string $kind, string $note): void
{
    if (!admin()) {
        throw new AuthError('دسترسی مجاز نیست.', 403);
    }
    if (!in_array($kind, ['gift', 'manual'], true) || $days < 1 || $days > 3650) {
        throw new AuthError('تعداد روز باید بین ۱ تا ۳۶۵۰ باشد.');
    }
    if (mb_strlen(trim($note)) < 3) {
        throw new AuthError('توضیح تمدید را بنویسید.');
    }
    db_transaction(function () use ($center, $days, $kind, $note) {
        $c = query('SELECT * FROM centers WHERE id=? FOR UPDATE', [$center])->fetch();
        if (!$c) {
            throw new AuthError('مرکز پیدا نشد.', 404);
        }
        $start = center_period_start($c);
        $end = date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days'));
        query('INSERT INTO center_subscriptions(center_id,starts_on,ends_on,kind,days,note,created_by_admin) VALUES(?,?,?,?,?,?,?)', [
            $center,
            $start,
            $end,
            $kind,
            $days,
            mb_substr(trim($note), 0, 255),
            $_SESSION['admin_id'] ?? null,
        ]);
        query('UPDATE centers SET subscription_until=? WHERE id=?', [$end, $center]);
        center_event($center, 'subscription.grant', ['days' => $days, 'kind' => $kind, 'end' => $end, 'note' => $note]);
        center_notify($center, 'perm_billing', $kind === 'gift' ? 'روز هدیه به اشتراک مرکز اضافه شد' : 'اشتراک مرکز تمدید شد', fa_number($days) . ' روز • اعتبار تا ' . jdate($end));
        center_sync_scopes($center);
        audit('center.grant', $center);
    });
}

function center_offline_paid(int $orderId, string $reference): void
{
    if (!admin()) {
        throw new AuthError('دسترسی مجاز نیست.', 403);
    }
    $reference = trim(digits($reference));
    if (mb_strlen($reference) < 4) {
        throw new AuthError('شماره پیگیری یا مرجع واریز را وارد کنید.');
    }
    db_transaction(fn() => center_subscription_activate($orderId, 'offline', null, mb_substr($reference, 0, 100)));
    audit('center.offline', $orderId);
}

function center_expire_proformas(): int
{
    $ids = query(
        "SELECT o.id FROM center_orders o WHERE o.status='proforma' AND o.expires_at<NOW() AND NOT EXISTS(SELECT 1 FROM payment_transactions t WHERE t.purpose='center_subscription' AND t.reference_id=o.id AND t.status='redirected' AND t.redirected_at>DATE_SUB(NOW(),INTERVAL 1 HOUR))",
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        db_transaction(function () use ($id) {
            if (query("UPDATE center_orders SET status='expired' WHERE id=? AND status='proforma'", [$id])->rowCount()) {
                discount_release('center', (int) $id);
            }
        });
    }
    return count($ids);
}

/** Expiry warnings for center billing staff, once per threshold and period. */
function center_subscription_warnings(): int
{
    if (!subscriptions_on()) {
        return 0;
    }
    $sent = 0;
    $thresholds = array_filter(array_map('intval', explode(',', setting('subscription_warning_thresholds', '7,3,1'))), fn($d) => $d > 0 && $d <= 60);
    foreach ($thresholds as $days) {
        $date = date('Y-m-d', strtotime('+' . $days . ' days'));
        foreach (query("SELECT id,name,subscription_until FROM centers WHERE review_status='approved' AND active=1 AND subscription_until=?", [$date])->fetchAll() as $c) {
            if (notice_once('center-expiry:' . $c['id'] . ':' . $c['subscription_until'] . ':' . $days)) {
                center_notify((int) $c['id'], 'perm_billing', 'پایان نزدیک اشتراک مرکز', fa_number($days) . ' روز دیگر (' . jdate($c['subscription_until']) . ') اشتراک ' . $c['name'] . ' پایان می‌یابد.');
                $sent++;
            }
        }
    }
    return $sent;
}

function center_revenue(string $from, string $to): array
{
    $rows = query(
        "SELECT o.*,ce.name center_name,ci.name city FROM center_orders o JOIN centers ce ON ce.id=o.center_id JOIN cities ci ON ci.id=ce.city_id WHERE o.status='paid' AND o.paid_at BETWEEN ? AND ? ORDER BY o.paid_at",
        [$from . ' 00:00:00', $to . ' 23:59:59'],
    )->fetchAll();
    return ['rows' => $rows, 'total' => array_sum(array_map(fn($r) => (int) $r['payable_toman'], $rows))];
}
