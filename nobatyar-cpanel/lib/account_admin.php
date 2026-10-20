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
