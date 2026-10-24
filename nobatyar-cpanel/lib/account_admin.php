<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.21 — suspending and deleting users and doctors from the administration panel.
 *
 * Suspend (role with «users» / «doctors»): the account stays, cannot sign in (user) or is unpublished and
 * takes no new bookings (doctor); confirmed bookings are kept. Lifting the suspension restores everything.
 *
 * Delete (also needs «sensitive»: owner and executive manager): nothing is removed from the database —
 * bookings are the doctor's records and many tables refer to users and doctors. The account is anonymized
 * and deactivated, its mobile (and a doctor's council number) is released so the person can register
 * again, it disappears from lists and the public site, and its future confirmed bookings are cancelled
 * (fees go to the refund queue; for a deleted doctor the patients are told by SMS and in the app).
 */

const ACCOUNT_DELETE_WORD = 'حذف';

function account_delete_check(array $data): void
{
    if (!admin_sensitive()) {
        throw new AuthError('حذف حساب فقط برای مالک سامانه و مدیر اجرایی مجاز است.', 403);
    }
    if (trim((string) ($data['confirm'] ?? '')) !== ACCOUNT_DELETE_WORD) {
        throw new AuthError('برای حذف، کلمه «' . ACCOUNT_DELETE_WORD . '» را در کادر تأیید بنویسید.');
    }
}

/** Future confirmed bookings of a patient account or of a doctor (with clinic and time). */
function account_future_bookings(string $column, int $id): array
{
    $sql = $column === 'patient'
        ? "SELECT b.id,s.doctor_id,s.clinic_id,s.starts_at FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.patient_user_id=? AND b.status='confirmed' AND s.starts_at>NOW()"
        : "SELECT b.id,s.doctor_id,s.clinic_id,s.starts_at FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND b.status='confirmed' AND s.starts_at>NOW()";
    return query($sql . ' ORDER BY s.starts_at', [$id])->fetchAll();
}

/** Cancels one confirmed booking on the administrator's behalf; the fee always goes to the refund queue. */
function admin_cancel_booking(array $b, string $reason, bool $tellPatient): void
{
    query("UPDATE bookings SET status='cancelled',estimated_at=NULL,revision=revision+1 WHERE id=? AND status='confirmed'", [$b['id']]);
    booking_refund_on_cancel((int) $b['id'], $reason, true);
    waitlist_signal((int) $b['clinic_id']);
    event_record((int) $b['doctor_id'], 'booking.cancelled', ['by' => 'admin', 'reason' => $reason, 'after' => ['status' => 'cancelled']], (int) $b['id']);
    if ($tellPatient) {
        notify_booking((int) $b['id'], 'نوبت شما لغو شد', jdate($b['starts_at'], true) . '؛ علت: ' . $reason . '. کارمزد پرداخت‌شده بازگردانده می‌شود.');
        booking_sms((int) $b['id'], 'booking_cancelled');
    }
}

/* ---------- Users ---------- */

function admin_user_suspend(array $data): string
{
    $uid = positive_id($data['user_id'] ?? 0);
    $reason = field($data, 'reason', 255, false);
    $u = query('SELECT id,deleted_at FROM users WHERE id=?', [$uid])->fetch();
    if (!$u || $u['deleted_at']) {
        throw new AuthError('کاربر پیدا نشد.', 404);
    }
    query('UPDATE users SET active=0,suspended_reason=?,session_version=session_version+1 WHERE id=?', [$reason !== '' ? $reason : null, $uid]);
    audit('user.suspend', $uid, $reason);
    return 'حساب کاربر تعلیق شد و از همه دستگاه‌ها خارج شد. نوبت‌های قطعی او حفظ شده‌اند.';
}

function admin_user_unsuspend(array $data): string
{
    $uid = positive_id($data['user_id'] ?? 0);
    query('UPDATE users SET active=1,suspended_reason=NULL WHERE id=? AND deleted_at IS NULL', [$uid]);
    audit('user.unsuspend', $uid);
    return 'تعلیق حساب برداشته شد.';
}

function admin_user_delete(array $data): string
{
    account_delete_check($data);
    $uid = positive_id($data['user_id'] ?? 0);
    $cancelled = db_transaction(function () use ($uid) {
        $u = query('SELECT * FROM users WHERE id=? FOR UPDATE', [$uid])->fetch();
        if (!$u || $u['deleted_at']) {
            throw new AuthError('کاربر پیدا نشد.', 404);
        }
        $center = query('SELECT name FROM centers WHERE owner_user_id=? AND active=1 LIMIT 1', [$uid])->fetch();
        if ($center) {
            throw new AuthError('این کاربر مالک مرکز فعال «' . $center['name'] . '» است؛ ابتدا مرکز را در «کلینیک‌ها و مراکز» غیرفعال کنید.', 409);
        }
        $future = account_future_bookings('patient', $uid);
        foreach ($future as $b) {
            admin_cancel_booking($b, 'حذف حساب بیمار توسط مدیر', false);
        }
        query("UPDATE bookings SET status='expired',revision=revision+1 WHERE patient_user_id=? AND status='held'", [$uid]);
        // Access granted to the account (doctor link, secretary and center staff) ends.
        query('DELETE FROM doctor_accounts WHERE user_id=?', [$uid]);
        query('DELETE FROM staff_scopes WHERE user_id=?', [$uid]);
        query('UPDATE center_staff SET active=0 WHERE user_id=?', [$uid]);
        query('DELETE FROM user_roles WHERE user_id=?', [$uid]);
        query('DELETE FROM push_subscriptions WHERE user_id=?', [$uid]);
        query("DELETE FROM waitlist_entries WHERE user_id=?", [$uid]);
        query("UPDATE family_members SET name='عضو حذف‌شده',national_id_enc=NULL,birth_date=NULL,insurance=NULL WHERE user_id=?", [$uid]);
        // Anonymized; the placeholder mobile cannot match a real number, so the person can sign up again.
        query(
            "UPDATE users SET name='کاربر حذف‌شده',mobile=?,national_id_enc=NULL,birth_date=NULL,insurance=NULL,active=0,suspended_reason=NULL,session_version=session_version+1,deleted_at=NOW() WHERE id=?",
            ['D' . str_pad((string) $uid, 10, '0', STR_PAD_LEFT), $uid],
        );
        audit('user.delete', $uid, mask_mobile((string) $u['mobile']) . ' • ' . count($future) . ' future bookings cancelled');
        return count($future);
    });
    return 'حساب کاربر حذف شد (اطلاعات شخصی پاک و شماره آزاد شد)' . ($cancelled ? '؛ ' . fa_number($cancelled) . ' نوبت آینده او لغو و کارمزد برای استرداد ثبت شد.' : '.');
}

/* ---------- Doctors ---------- */

function admin_doctor_suspend(array $data): ?string
{
    $reason = field($data, 'reason', 2000, false);
    if ($reason === '') {
        throw new AuthError('علت تعلیق را بنویسید (به پزشک نمایش داده می‌شود).');
    }
    admin_review_doctor(['id' => $data['doctor_id'] ?? 0, 'status' => 'suspended', 'review_note' => $reason]);
    audit('doctor.suspend', positive_id($data['doctor_id'] ?? 0), $reason);
    return 'پزشک تعلیق شد: پروفایل از دید عموم پنهان است و نوبت جدید نمی‌گیرد. نوبت‌های قطعی حفظ شده‌اند.';
}

function admin_doctor_unsuspend(array $data): ?string
{
    $id = positive_id($data['doctor_id'] ?? 0);
    admin_review_doctor(['id' => $id, 'status' => 'approved', 'active' => '1', 'review_note' => '']);
    audit('doctor.unsuspend', $id);
    return 'تعلیق پزشک برداشته شد و پروفایل دوباره منتشر شد.';
}

const DOCTOR_PURGE_WORD = 'حذف دائمی';

/**
 * 1.0.26 «حذف دائمی پزشک»: removes the doctor and everything tied to it (clinics, schedule, services, slots,
 * bookings with their events, reviews, notifications and payment intents, staff access, queues, waitlist, center
 * links, discount codes, subscriptions, the wallet and the photo). The linked user account stays as a patient
 * account. Bank transactions stay in the ledger with the doctor link cleared, so the books still balance.
 * Refused while the doctor's wallet holds money or a payout is pending. A doctor not yet removed is first retired
 * with admin_doctor_delete() (future bookings cancelled, patients told, fees queued for refund).
 */
function admin_doctor_purge(array $data): string
{
    if (!admin_sensitive()) {
        throw new AuthError('حذف دائمی فقط برای مالک سامانه و مدیر اجرایی مجاز است.', 403);
    }
    if (trim((string) ($data['confirm'] ?? '')) !== DOCTOR_PURGE_WORD) {
        throw new AuthError('برای حذف دائمی، عبارت «' . DOCTOR_PURGE_WORD . '» را در کادر تأیید بنویسید.');
    }
    $id = positive_id($data['doctor_id'] ?? 0);
    $d = query('SELECT * FROM doctors WHERE id=?', [$id])->fetch();
    if (!$d) {
        throw new AuthError('پزشک پیدا نشد.', 404);
    }
    $wallet = query("SELECT * FROM wallets WHERE owner_type='doctor' AND owner_id=?", [$id])->fetch();
    if ($wallet && (int) $wallet['balance'] !== 0) {
        throw new AuthError('کیف پول این پزشک ' . toman($wallet['balance']) . ' موجودی دارد؛ ابتدا آن را تسویه کنید، سپس حذف دائمی کنید.', 409);
    }
    if ($wallet && query("SELECT id FROM wallet_payouts WHERE wallet_id=? AND status='pending' LIMIT 1", [$wallet['id']])->fetch()) {
        throw new AuthError('درخواست واریز در انتظار برای این پزشک وجود دارد؛ ابتدا آن را رسیدگی کنید.', 409);
    }
    if (!$d['deleted_at']) {
        admin_doctor_delete(['doctor_id' => $id, 'confirm' => ACCOUNT_DELETE_WORD]);
    }
    $photo = (string) ($d['photo_file'] ?? '');
    db_transaction(function () use ($id, $wallet) {
        lock_doctor($id);
        $in = fn(array $ids) => $ids ? implode(',', array_map('intval', $ids)) : '0';
        $clinics = $in(query('SELECT id FROM clinics WHERE doctor_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN));
        $slots = $in(query("SELECT id FROM slots WHERE doctor_id=? OR clinic_id IN ($clinics)", [$id])->fetchAll(PDO::FETCH_COLUMN));
        $bookings = $in(query("SELECT id FROM bookings WHERE slot_id IN ($slots)")->fetchAll(PDO::FETCH_COLUMN));
        $codes = $in(query('SELECT id FROM discount_codes WHERE doctor_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN));
        $delays = $in(query("SELECT id FROM reception_delays WHERE clinic_id IN ($clinics)")->fetchAll(PDO::FETCH_COLUMN));
        // Bank transactions are the platform's books: kept, only detached from the doctor.
        query('UPDATE payment_transactions SET doctor_id=NULL WHERE doctor_id=?', [$id]);
        query('UPDATE center_announcements SET doctor_id=NULL WHERE doctor_id=?', [$id]);
        foreach ([
            "DELETE FROM appointment_events WHERE doctor_id=$id OR booking_id IN ($bookings)",
            "DELETE FROM center_announcement_recipients WHERE booking_id IN ($bookings)",
            "DELETE FROM reception_delay_recipients WHERE booking_id IN ($bookings) OR delay_id IN ($delays)",
            "DELETE FROM notifications WHERE booking_id IN ($bookings)",
            "DELETE FROM payments WHERE booking_id IN ($bookings)",
            "DELETE FROM payment_intents WHERE booking_id IN ($bookings)",
            "DELETE FROM reviews WHERE doctor_id=$id OR booking_id IN ($bookings)",
            "DELETE FROM sms_messages WHERE booking_id IN ($bookings)",
            "DELETE FROM waitlist_entries WHERE doctor_id=$id OR clinic_id IN ($clinics)",
            "DELETE FROM bookings WHERE id IN ($bookings)",
            "DELETE FROM slots WHERE id IN ($slots)",
            "DELETE FROM reception_delays WHERE id IN ($delays)",
            "UPDATE reception_patients SET parent_id=NULL WHERE clinic_id IN ($clinics)",
            "DELETE FROM reception_patients WHERE clinic_id IN ($clinics)",
            "DELETE FROM clinic_queues WHERE clinic_id IN ($clinics)",
            "DELETE FROM clinic_free_cache WHERE clinic_id IN ($clinics)",
            "DELETE FROM schedule_blocks WHERE doctor_id=$id OR clinic_id IN ($clinics)",
            "DELETE FROM staff_scopes WHERE clinic_id IN ($clinics)",
            "DELETE FROM working_hours WHERE clinic_id IN ($clinics)",
            "DELETE FROM clinics WHERE id IN ($clinics)",
            "DELETE FROM discount_redemptions WHERE code_id IN ($codes)",
            "UPDATE subscription_orders SET discount_code_id=NULL WHERE discount_code_id IN ($codes)",
            "UPDATE bookings SET discount_code_id=NULL WHERE discount_code_id IN ($codes)",
            "DELETE FROM discount_codes WHERE id IN ($codes)",
            "DELETE FROM subscriptions WHERE doctor_id=$id",
            "DELETE FROM subscription_orders WHERE doctor_id=$id",
            "DELETE FROM center_doctors WHERE doctor_id=$id",
            "DELETE FROM doctor_accounts WHERE doctor_id=$id",
            "DELETE FROM doctor_services WHERE doctor_id=$id",
            "DELETE FROM doctor_profile_views WHERE doctor_id=$id",
        ] as $sql) {
            query($sql);
        }
        if ($wallet) {
            foreach (['wallet_entries', 'wallet_banks', 'wallet_payouts'] as $t) {
                query("DELETE FROM $t WHERE wallet_id=?", [$wallet['id']]);
            }
            query('DELETE FROM wallets WHERE id=?', [$wallet['id']]);
        }
        query('DELETE FROM doctors WHERE id=?', [$id]);
        audit('doctor.purge', $id, 'حذف دائمی');
    });
    if ($photo !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $photo)) {
        @unlink(__DIR__ . '/../private/photos/' . $photo);
    }
    return 'پزشک «' . doctor_bare_name((string) $d['name']) . '» برای همیشه حذف شد؛ مطب‌ها، برنامه، نوبت‌ها و سوابق او پاک شد و تراکنش‌های بانکی برای حسابداری باقی ماند.';
}

function admin_doctor_delete(array $data): string
{
    account_delete_check($data);
    $id = positive_id($data['doctor_id'] ?? 0);
    $future = [];
    db_transaction(function () use ($id, &$future) {
        lock_doctor($id);
        $d = query('SELECT * FROM doctors WHERE id=? FOR UPDATE', [$id])->fetch();
        if (!$d || $d['deleted_at']) {
            throw new AuthError('پزشک پیدا نشد.', 404);
        }
        $future = account_future_bookings('doctor', $id);
        foreach ($future as $b) {
            admin_cancel_booking($b, 'پزشک از نوبت‌یار خارج شد', true);
        }
        query("UPDATE bookings b JOIN slots s ON s.id=b.slot_id SET b.status='expired',b.revision=b.revision+1 WHERE s.doctor_id=? AND b.status='held'", [$id]);
        // The linked account keeps working as a patient account; its doctor and staff access ends.
        foreach (query('SELECT user_id FROM doctor_accounts WHERE doctor_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            query("DELETE FROM user_roles WHERE user_id=? AND role='doctor'", [$uid]);
        }
        query('DELETE FROM doctor_accounts WHERE doctor_id=?', [$id]);
        query('DELETE s FROM staff_scopes s JOIN clinics c ON c.id=s.clinic_id WHERE c.doctor_id=?', [$id]);
        query('UPDATE clinics SET active=0 WHERE doctor_id=?', [$id]);
        query("UPDATE center_doctors SET status='ended',responded_at=NOW() WHERE doctor_id=? AND status IN ('active','invited','requested')", [$id]);
        query('DELETE FROM waitlist_entries WHERE doctor_id=?', [$id]);
        query('UPDATE discount_codes SET active=0 WHERE doctor_id=?', [$id]);
        query(
            "UPDATE doctors SET active=0,review_status='suspended',review_note='حذف‌شده توسط مدیر',mobile=NULL,medical_code=NULL,featured=0,notice_text=NULL,deleted_at=NOW() WHERE id=?",
            [$id],
        );
        event_record($id, 'doctor.deleted', ['future_cancelled' => count($future)]);
        audit('doctor.delete', $id, $d['name'] . ' • ' . count($future) . ' future bookings cancelled');
    });
    return 'پزشک حذف شد: پروفایل از سامانه برداشته شد، دسترسی حساب و منشی‌ها قطع شد و شماره و کد نظام پزشکی آزاد شد' .
        ($future ? '؛ ' . fa_number(count($future)) . ' نوبت آینده لغو، به بیماران اطلاع داده و کارمزد برای استرداد ثبت شد.' : '.');
}
