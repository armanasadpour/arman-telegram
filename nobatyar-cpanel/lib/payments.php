<?php
declare(strict_types=1);

/**
 * Patient booking fee: rules, discounts, online payment through the shared module, refunds.
 * Only the fixed booking service fee is ever paid online; the visit fee is paid at the clinic.
 */

/** 1.0.0-rc.17: VAT percent added to platform invoices (0 = none). */
function vat_percent(): float
{
    return max(0.0, min(30.0, (float) setting('invoice_vat_percent', '0')));
}

/** VAT in Toman on an amount, rounded to the nearest Toman. */
function vat_amount(int $base, ?float $percent = null): int
{
    $percent ??= vat_percent();
    return $base > 0 && $percent > 0 ? (int) round($base * $percent / 100) : 0;
}

/** «۹٪»-style label of a percent. */
function vat_label(float $percent): string
{
    return fa_number(rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.')) . '٪';
}

/** Global fee (0 when disabled). */
function booking_fee(): int
{
    return setting('booking_fee_enabled') === '1' ? (int) setting('booking_fee', '0') : 0;
}

/**
 * Fee for a clinic: doctor rule > city rule > global fee. A rule may be 0 (exempt).
 * Returns [amount_toman, source].
 */
function booking_fee_for(int $clinic): array
{
    $c = query('SELECT doctor_id,city_id FROM clinics WHERE id=?', [$clinic])->fetch();
    if ($c) {
        foreach ([['doctor', $c['doctor_id']], ['city', $c['city_id']]] as [$scope, $target]) {
            $rule = query('SELECT fee_toman FROM booking_fee_rules WHERE scope=? AND target_id=? AND active=1', [
                $scope,
                $target,
            ])->fetchColumn();
            if ($rule !== false) {
                return [(int) $rule, $scope];
            }
        }
    }
    return [booking_fee(), 'global'];
}

function fee_rule_save(array $data): void
{
    $scope = (string) ($data['scope'] ?? '');
    if (!in_array($scope, ['city', 'doctor'], true)) {
        throw new AuthError('نوع قاعده نامعتبر است.');
    }
    $target = positive_id($data['target_id'] ?? 0);
    $exists = $scope === 'city'
        ? query('SELECT id FROM cities WHERE id=?', [$target])->fetch()
        : query('SELECT id FROM doctors WHERE id=?', [$target])->fetch();
    if (!$exists) {
        throw new AuthError($scope === 'city' ? 'شهر پیدا نشد.' : 'پزشک پیدا نشد.');
    }
    $fee = parse_toman((string) ($data['fee_toman'] ?? '0'));
    if ($fee > 0 && $fee < 1000) {
        throw new AuthError('کارمزد یا صفر (رایگان) است یا حداقل ۱٬۰۰۰ تومان.');
    }
    query(
        'INSERT INTO booking_fee_rules(scope,target_id,fee_toman,active,note) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE fee_toman=VALUES(fee_toman),active=VALUES(active),note=VALUES(note)',
        [$scope, $target, $fee, !empty($data['active']) ? 1 : 0, field($data, 'note', 255, false)],
    );
    audit('fee.rule');
}

/* ---------- Discount on a held booking ---------- */

function booking_apply_discount(int $bookingId, string $code): void
{
    $u = require_user();
    $old = own_booking($bookingId);
    db_transaction(function () use ($u, $old, $bookingId, $code) {
        lock_doctor((int) $old['doctor_id']);
        $b = own_booking($bookingId);
        if ($b['status'] !== 'held' || $b['expires_at'] <= date('Y-m-d H:i:s')) {
            throw new AuthError('تخفیف فقط برای رزرو موقت فعال قابل اعمال است.', 409);
        }
        if ($b['discount_code_id']) {
            throw new AuthError('برای این رزرو قبلاً کد تخفیف ثبت شده است.', 409);
        }
        if (
            query("SELECT id FROM payment_transactions WHERE purpose='booking' AND reference_id=? AND status='redirected'", [
                $bookingId,
            ])->fetch()
        ) {
            throw new AuthError('پرداخت این رزرو در جریان است.', 409);
        }
        $reminderFee = (int) ($b['reminder_fee_toman'] ?? 0);
        $fee = max(0, (int) $b['fee_toman'] - $reminderFee);
        [$row, $discount] = discount_apply($code, 'booking', $fee, 'user:' . $u['id'], (int) $b['doctor_id']);
        $payable = $fee - $discount;
        if ($payable > 0 && $payable + $reminderFee < 1000) {
            // Gateways reject amounts under 1,000 Toman; such a remainder becomes a free booking.
            $discount = $fee;
            $payable = 0;
        }
        query('UPDATE bookings SET fee_toman=?,discount_code_id=?,discount_toman=?,revision=revision+1 WHERE id=?', [
            $payable + $reminderFee,
            $row['id'],
            $discount,
            $bookingId,
        ]);
        query(
            "INSERT INTO discount_redemptions(code_id,order_type,order_id,owner_key,amount_toman,status) VALUES(?,'booking',?,?,?,'reserved')",
            [$row['id'], $bookingId, 'user:' . $u['id'], $discount],
        );
        event_record((int) $b['doctor_id'], 'booking.discount', ['code' => $row['code'], 'discount' => $discount], $bookingId);
    });
}

/* ---------- Online payment ---------- */

/**
 * Starts the booking-fee payment for a held booking and returns the redirect description.
 * An open transaction for the same booking and amount is reused instead of creating another.
 */
function start_payment(int $id, ?callable $transport = null, ?string $gateway = null): array
{
    $old = own_booking($id);
    if (!payment_available()) {
        throw new AuthError('درگاه فعال نیست.', 503);
    }
    $tx = db_transaction(function () use ($old, $id, $gateway) {
        lock_doctor((int) $old['doctor_id']);
        lock_contact($old['phone']);
        query('SELECT id FROM users WHERE id=? FOR UPDATE', [$old['patient_user_id']]);
        $b = own_booking($id);
        if ((int) $b['fee_toman'] < 1 || !hold_still_valid($b)) {
            throw new AuthError('رزرو موقت پرداخت‌پذیر نیست.', 409);
        }
        // 1.0.0-rc.17: VAT on the booking fee (after discount) is fixed on the booking when payment starts.
        $percent = vat_percent();
        $vat = vat_amount((int) $b['fee_toman'], $percent);
        if ((int) $b['vat_toman'] !== $vat || (float) $b['vat_percent'] !== $percent) {
            query('UPDATE bookings SET vat_percent=?,vat_toman=? WHERE id=?', [$percent, $vat, $id]);
        }
        $total = (int) $b['fee_toman'] + $vat;
        $open = query(
            "SELECT * FROM payment_transactions WHERE purpose='booking' AND reference_id=? AND status IN ('initiated','redirected') AND amount_toman=? ORDER BY id DESC LIMIT 1",
            [$id, $total],
        )->fetch();
        if ($open && (!$gateway || $open['gateway'] === $gateway)) {
            return $open;
        }
        $tx = payment_create('booking', $id, $total, 'کارمزد رزرو نوبت‌یار ' . (preg_match('/^[0-9]{12}$/', (string) $b['tracking_code']) ? $b['tracking_code'] : substr($b['tracking_code'], 0, 8)), [
            'user_id' => (int) $b['patient_user_id'],
            'doctor_id' => (int) $b['doctor_id'],
            'gateway' => $gateway,
        ]);
        if ($vat > 0) {
            query('UPDATE payment_transactions SET vat_toman=? WHERE id=?', [$vat, $tx['id']]);
            $tx['vat_toman'] = $vat;
        }
        event_record(
            (int) $b['doctor_id'],
            'payment.requested',
            ['amount_toman' => $total, 'vat_toman' => $vat, 'gateway' => $tx['gateway']],
            $id,
        );
        return $tx;
    });
    return payment_redirect((int) $tx['id'], $transport);
}

/** Verified payment: confirms the hold if still valid; otherwise throws so the module opens a refund. */
function payment_fulfil_booking(array $tx): void
{
    $old = booking_row((int) $tx['reference_id']);
    lock_doctor((int) $old['doctor_id']);
    lock_contact($old['phone']);
    query('SELECT id FROM users WHERE id=? FOR UPDATE', [$old['patient_user_id']]);
    $b = booking_row((int) $old['id']);
    if ((int) $b['fee_toman'] + (int) $b['vat_toman'] !== (int) $tx['amount_toman']) {
        throw new AuthError('مبلغ پرداخت با کارمزد رزرو یکسان نیست.', 409);
    }
    $user = query('SELECT active FROM users WHERE id=?', [$b['patient_user_id']])->fetch();
    $valid = $user && $user['active'] && hold_still_valid($b);
    if ($valid) {
        try {
            patient_limit(
                (int) $b['patient_user_id'],
                (int) $b['family_member_id'],
                (int) $b['doctor_id'],
                $b['starts_at'],
                $b['ends_at'] ?? $b['starts_at'],
                (int) $b['id'],
            );
        } catch (AuthError $e) {
            $valid = false;
        }
    }
    if (!$valid) {
        if ($b['status'] === 'held') {
            query("UPDATE bookings SET status='expired',revision=revision+1 WHERE id=?", [$b['id']]);
            discount_release('booking', (int) $b['id']);
        }
        notify_booking(
            (int) $b['id'],
            'پرداخت نیازمند بازگشت وجه است',
            'پرداخت تأیید شد اما مهلت یا ظرفیت رزرو دیگر معتبر نبود؛ نوبت قطعی نشد و کارمزد بازگردانده می‌شود.',
        );
        event_record(
            (int) $b['doctor_id'],
            'payment.refund_due',
            ['reference' => $tx['ref_num'], 'amount_toman' => $tx['amount_toman']],
            (int) $b['id'],
        );
        throw new AuthError('مهلت یا ظرفیت رزرو پیش از تأیید پرداخت تمام شد.', 409);
    }
    query('INSERT INTO payments(booking_id,amount,status,reference) VALUES(?,?,?,?)', [
        $b['id'],
        $tx['amount_toman'],
        'verified',
        $tx['gateway'] . ':' . $tx['ref_num'],
    ]);
    query("UPDATE discount_redemptions SET status='used' WHERE order_type='booking' AND order_id=? AND status='reserved'", [
        $b['id'],
    ]);
    confirm_held($b);
    event_record(
        (int) $b['doctor_id'],
        'payment.verified',
        ['reference' => $tx['ref_num'], 'amount_toman' => $tx['amount_toman'], 'gateway' => $tx['gateway']],
        (int) $b['id'],
    );
}

/**
 * Cancellation of a paid booking: the verified fee is marked for refund when the administrator's
 * policy says so (default: refund). Covers payments made before 0.9.0 as well.
 */
/** $force (rc.21): cancellations by the administrator (account deleted) always refund the fee. */
function booking_refund_on_cancel(int $bookingId, string $reason, bool $force = false): void
{
    if (wallet_refund_booking($bookingId,$reason,$force)) return;
    if (!$force && setting('booking_fee_refund_on_cancel', '1') !== '1') {
        return;
    }
    foreach (
        query("SELECT * FROM payment_transactions WHERE purpose='booking' AND reference_id=? AND status='verified'", [
            $bookingId,
        ])->fetchAll()
        as $tx
    ) {
        query("UPDATE payment_transactions SET status='refund_due' WHERE id=?", [$tx['id']]);
        refund_open($tx, 'لغو نوبت: ' . $reason);
    }
    foreach (query("SELECT * FROM payment_intents WHERE booking_id=? AND status='verified'", [$bookingId])->fetchAll() as $intent) {
        query("UPDATE payment_intents SET status='refund_due' WHERE id=?", [$intent['id']]);
        query(
            "INSERT IGNORE INTO refunds(legacy_intent_id,purpose,reference_id,amount_toman,reason,status) VALUES(?,'booking',?,?,?,'pending')",
            [$intent['id'], $bookingId, $intent['amount_toman'], mb_substr('لغو نوبت: ' . $reason, 0, 255)],
        );
    }
    query("UPDATE payments SET status='refund_due' WHERE booking_id=? AND status='verified'", [$bookingId]);
}

/* ---------- Payments started before 0.9.0 (payment_intents, Zarinpal) ---------- */

/** Completes a Zarinpal payment started before the upgrade (payment-callback.php). New payments never use it. */
function verify_payment(string $authority, ?callable $transport = null): array
{
    if (!preg_match('/^A[a-zA-Z0-9]{35}$/', $authority)) {
        throw new AuthError('شناسه پرداخت معتبر نیست.', 422);
    }
    $intent = query('SELECT * FROM payment_intents WHERE authority=?', [$authority])->fetch();
    if (!$intent) {
        throw new AuthError('درخواست پرداخت پیدا نشد.', 404);
    }
    if (in_array($intent['status'], ['verified', 'refund_due', 'refunded'], true)) {
        return $intent;
    }
    $data = zarinpal_call(
        'verify',
        ['sandbox' => '0'],
        ['merchant_id' => $intent['merchant_id'], 'amount' => (int) $intent['amount_toman'] * 10, 'authority' => $authority],
        $transport,
    );
    if (!in_array((int) $data['code'], [100, 101], true) || !preg_match('/^[0-9]{1,30}$/', (string) ($data['ref_id'] ?? ''))) {
        throw new AuthError('پرداخت تأیید نشد. رزرو قطعی نشده است.', 409);
    }
    $old = booking_row((int) $intent['booking_id']);
    return db_transaction(function () use ($old, $intent, $data) {
        lock_doctor((int) $old['doctor_id']);
        lock_contact($old['phone']);
        query('SELECT id FROM users WHERE id=? FOR UPDATE', [$old['patient_user_id']]);
        $pay = query('SELECT * FROM payment_intents WHERE id=? FOR UPDATE', [$intent['id']])->fetch();
        if (in_array($pay['status'], ['verified', 'refund_due', 'refunded'], true)) {
            return $pay;
        }
        $b = booking_row((int) $old['id']);
        $user = query('SELECT active FROM users WHERE id=?', [$b['patient_user_id']])->fetch();
        $valid = $user && $user['active'] && hold_still_valid($b);
        if ($valid) {
            try {
                patient_limit(
                    (int) $b['patient_user_id'],
                    (int) $b['family_member_id'],
                    (int) $b['doctor_id'],
                    $b['starts_at'],
                    $b['ends_at'] ?? $b['starts_at'],
                    (int) $b['id'],
                );
            } catch (AuthError $e) {
                $valid = false;
            }
        }
        $status = $valid ? 'verified' : 'refund_due';
        query('UPDATE payment_intents SET status=?,reference=?,verified_at=NOW() WHERE id=?', [
            $status,
            (string) $data['ref_id'],
            $pay['id'],
        ]);
        query('INSERT INTO payments(booking_id,amount,status,reference) VALUES(?,?,?,?)', [
            $b['id'],
            $pay['amount_toman'],
            $status,
            'zarinpal:' . $data['ref_id'],
        ]);
        if ($valid) {
            confirm_held($b);
        } else {
            if ($b['status'] === 'held') {
                query("UPDATE bookings SET status='expired',revision=revision+1 WHERE id=?", [$b['id']]);
            }
            query(
                "INSERT IGNORE INTO refunds(legacy_intent_id,purpose,reference_id,amount_toman,reason,status) VALUES(?,'booking',?,?,?,'pending')",
                [$pay['id'], $b['id'], $pay['amount_toman'], 'پرداخت پس از انقضای رزرو موقت'],
            );
            notify_booking(
                (int) $b['id'],
                'پرداخت نیازمند بازگشت وجه است',
                'پرداخت تأیید شد اما ظرفیت یا مهلت رزرو معتبر نیست؛ نوبت قطعی نشده و کارمزد بازگردانده می‌شود.',
            );
        }
        event_record(
            (int) $b['doctor_id'],
            'payment.' . $status,
            ['reference' => (string) $data['ref_id'], 'amount_toman' => $pay['amount_toman']],
            (int) $b['id'],
        );
        return query('SELECT * FROM payment_intents WHERE id=?', [$pay['id']])->fetch();
    });
}

function booking_reminder_hours(): int { return setting_int('patient_reminder_hours', 24, 1, 168); }
function booking_reminder_fee(): int { return setting_int('patient_reminder_fee', 0, 0, 10000000); }
function booking_reminder_available(): bool {
    $t = sms_template('booking_reminder');
    return setting('reminder_sms_enabled', '1') === '1' && (sms_dev_mode() || (sms_enabled() && sms_template_ready($t)));
}
function booking_reminder_eligible(string $start): bool {
    return booking_reminder_available() && strtotime($start) > time() + booking_reminder_hours() * 3600;
}
function booking_reminder_choice(string $start, array $post = []): void {
    if (!booking_reminder_eligible($start)) {
        echo '<p class="field-help">یادآوری پیامکی برای این زمان در دسترس نیست؛ هزینه‌ای دریافت نمی‌شود.</p>';
        return;
    }
    $on = !$post || !empty($post['reminder_opt_in']);
    echo '<section class="reminder-choice"><label class="reminder-toggle"><input type="checkbox" role="switch" name="reminder_opt_in" value="1"' . checked_attr($on) . '><span class="reminder-track" aria-hidden="true"></span><span>می‌خواهم ' . fa_number(booking_reminder_hours()) . ' ساعت قبل از نوبت به من یادآوری شود</span></label><p>هزینه پیامک یادآوری: <strong>' . esc(toman(booking_reminder_fee())) . '</strong>؛ با خاموش کردن این گزینه هزینه به فاکتور اضافه نمی‌شود.</p></section>';
}
/** Enqueue each purchased reminder once; existing bookings are never opted in by migration. */
function send_purchased_reminders(int $limit = 200): int {
    $rows = query("SELECT b.*,s.starts_at,d.name doctor,cl.name clinic FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN doctors d ON d.id=s.doctor_id JOIN clinics cl ON cl.id=s.clinic_id WHERE b.status='confirmed' AND b.reminder_opt_in=1 AND s.starts_at>NOW() AND DATE_SUB(s.starts_at,INTERVAL b.reminder_hours_snapshot HOUR)<=NOW() AND NOT EXISTS (SELECT 1 FROM sms_messages m WHERE m.dedupe_key=CONCAT('paid-reminder:',b.id)) ORDER BY s.starts_at LIMIT " . max(1,min(1000,$limit)))->fetchAll();
    $n = 0;
    foreach ($rows as $b) {
        if (sms_enqueue('booking_reminder', $b['phone'], ['token'=>digits(jdate($b['starts_at'])), 'token2'=>substr($b['estimated_at'] ?: $b['starts_at'],11,5), 'token10'=>doctor_display_name($b['doctor']), 'token20'=>mb_substr($b['clinic'],0,90)], 'paid-reminder:' . $b['id'], ['booking_id'=>(int)$b['id'], 'user_id'=>$b['patient_user_id'] ? (int)$b['patient_user_id'] : null])) $n++;
    }
    return $n;
}
