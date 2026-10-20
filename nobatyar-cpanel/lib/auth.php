<?php
declare(strict_types=1);
class AuthError extends RuntimeException
{
    public int $status;
    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}
/** Activity log. Since 0.12 it also keeps the request IP and a short detail (never secrets or codes). */
function audit(string $action, ?int $entity = null, string $detail = ''): void
{
    $args = [$_SESSION['user_id'] ?? null, $_SESSION['admin_id'] ?? null, $action, $entity];
    if (schema_is_current()) {
        query('INSERT INTO audit_log(actor_user_id,actor_admin_id,action,entity_id,ip,detail) VALUES(?,?,?,?,?,?)', array_merge($args, [
            PHP_SAPI === 'cli' ? null : client_ip(),
            $detail !== '' ? mb_substr($detail, 0, 255) : null,
        ]));
        return;
    }
    query('INSERT INTO audit_log(actor_user_id,actor_admin_id,action,entity_id) VALUES(?,?,?,?)', $args);
}
function user_now(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    if (time() - (int) ($_SESSION['login_at'] ?? 0) > 43200) {
        unset($_SESSION['user_id'], $_SESSION['user_version']);
        return null;
    }
    $u = query('SELECT * FROM users WHERE id=?', [(int) $_SESSION['user_id']])->fetch();
    if (!$u || !$u['active'] || (int) $u['session_version'] !== (int) ($_SESSION['user_version'] ?? 0)) {
        unset($_SESSION['user_id'], $_SESSION['user_version']);
        return null;
    }
    return $u;
}
function require_user(): array
{
    $u = user_now();
    if (!$u) {
        throw new AuthError('ابتدا با شماره موبایل وارد شوید.', 401);
    }
    return $u;
}
function roles(int $id): array
{
    return query('SELECT role FROM user_roles WHERE user_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN);
}
function has_role(string $role): bool
{
    $u = user_now();
    return $u && in_array($role, roles((int) $u['id']), true);
}
function require_role(string $role): array
{
    $u = require_user();
    if (!in_array($role, roles((int) $u['id']), true)) {
        throw new AuthError('به این پنل دسترسی ندارید.', 403);
    }
    return $u;
}
/**
 * Iranian mobile in the stored 09xxxxxxxxx form. Accepts Persian or Arabic digits, spaces, dashes and
 * the +98 / 0098 / 98 prefixes, or the ten digits after the leading zero (what the +98 field collects).
 */
function normalize_mobile(string $mobile): string
{
    $mobile = preg_replace('/[\s\-\x{200c}\x{200f}\x{200e}().]/u', '', digits(trim($mobile)));
    $mobile = preg_replace('/^(?:\+98|0098|98(?=9[0-9]{9}$))/', '0', $mobile);
    if (preg_match('/^9[0-9]{9}$/', $mobile)) {
        $mobile = '0' . $mobile;
    }
    if (!preg_match('/^09[0-9]{9}$/', $mobile)) {
        throw new AuthError('شماره موبایل ۱۱ رقمی با ۰۹ وارد کنید.');
    }
    return $mobile;
}
function rate_limit(string $bucket, int $limit, int $seconds): void
{
    $now = time();
    $bucket = hash('sha256', $bucket);
    query(
        'INSERT INTO auth_rate_limits(bucket,window_start,hits) VALUES(?,?,1) ON DUPLICATE KEY UPDATE hits=IF(window_start<=?,1,hits+1),window_start=IF(window_start<=?,VALUES(window_start),window_start)',
        [$bucket, $now, $now - $seconds, $now - $seconds],
    );
    if ((int) query('SELECT hits FROM auth_rate_limits WHERE bucket=?', [$bucket])->fetchColumn() > $limit) {
        throw new AuthError('تعداد درخواست‌ها زیاد است. کمی بعد دوباره تلاش کنید.', 429);
    }
}
function otp_settings(): array
{
    return [
        'ttl' => setting_int('otp_ttl_seconds', 180, 60, 900),
        'resend' => setting_int('otp_resend_seconds', 60, 30, 600),
        'attempts' => setting_int('otp_max_attempts', 5, 3, 10),
        'phone_hourly' => setting_int('otp_phone_hourly', 5, 1, 20),
        'ip_15min' => setting_int('otp_ip_15min', 10, 2, 200),
        'ip_distinct' => setting_int('otp_ip_distinct_hourly', 5, 1, 100),
    ];
}

/**
 * Sends a one-time login code. Limits: per IP, per phone (hourly and resend cooldown),
 * distinct phones per IP (number enumeration) and a lock after repeated wrong codes.
 * $testSender (CLI tests only) receives ($mobile, $code) instead of calling Kavenegar.
 */
function otp_request(string $mobile, ?callable $testSender = null): array
{
    if ($testSender !== null && PHP_SAPI !== 'cli') {
        throw new AuthError('مسیر آزمایش فقط در CLI مجاز است.', 403);
    }
    $mobile = normalize_mobile($mobile);
    if ($testSender === null && !sms_dev_mode() && !sms_enabled()) {
        throw new AuthError('ورود پیامکی هنوز توسط مدیر فعال نشده است.', 503);
    }
    $cfg = otp_settings();
    $ipHash = hash('sha256', client_ip());
    rate_limit('otp:ip:' . client_ip(), $cfg['ip_15min'], 900);
    rate_limit('otp:phone-hour:' . $mobile, $cfg['phone_hourly'], 3600);
    rate_limit('otp:cooldown:' . $mobile, 1, $cfg['resend']);
    $now = time();
    // Numbers requested from this network in the last hour that never completed a login:
    // many of them point to number enumeration or SMS bombing rather than real users.
    $known = query(
        'SELECT DISTINCT o.mobile FROM otp_challenges o WHERE o.ip_hash=? AND o.created_at>? AND NOT EXISTS(SELECT 1 FROM otp_challenges v WHERE v.mobile=o.mobile AND v.verified_at IS NOT NULL AND v.created_at>?)',
        [$ipHash, $now - 3600, $now - 3600],
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($mobile, $known, true) && count($known) >= $cfg['ip_distinct']) {
        audit('auth.suspicious_ip');
        throw new AuthError('درخواست‌های مشکوک از این شبکه شناسایی شد. یک ساعت بعد دوباره تلاش کنید.', 429);
    }
    $failures = (int) query('SELECT COALESCE(SUM(attempts),0) FROM otp_challenges WHERE mobile=? AND created_at>?', [
        $mobile,
        $now - 3600,
    ])->fetchColumn();
    if ($failures >= $cfg['attempts'] * 3) {
        throw new AuthError('به دلیل تلاش‌های ناموفق متعدد، ورود این شماره موقتاً قفل شده است.', 429);
    }
    $id = bin2hex(random_bytes(32));
    $code = (string) random_int(100000, 999999);
    // Stored codes are slow hashes; the plain code exists only in memory for the SMS call.
    query(
        'INSERT INTO otp_challenges(id,mobile,code_hash,session_hash,created_at,expires_at,ip_hash) VALUES(?,?,?,?,?,?,?)',
        [
            $id,
            $mobile,
            password_hash($code, PASSWORD_DEFAULT),
            hash('sha256', session_id()),
            $now,
            $now + $cfg['ttl'],
            $ipHash,
        ],
    );
    try {
        $transport = $testSender
            ? fn(array $payload) => $testSender($payload['receptor'], $payload['token'])
            : null;
        $response = sms_send_now('login', $mobile, ['token' => $code], $transport, ['store_tokens' => false]);
        db_transaction(function () use ($now, $mobile, $id, $response) {
            query('UPDATE otp_challenges SET consumed_at=? WHERE mobile=? AND id<>? AND consumed_at IS NULL', [
                $now,
                $mobile,
                $id,
            ]);
            query("UPDATE otp_challenges SET delivery_status='accepted',provider_id=? WHERE id=?", [
                mb_substr((string) $response['messageid'], 0, 40),
                $id,
            ]);
        });
    } catch (Throwable $e) {
        query("UPDATE otp_challenges SET delivery_status='failed',consumed_at=? WHERE id=?", [$now, $id]);
        throw new AuthError(error_message($e), 503);
    }
    return ['challenge_id' => $id, 'expires_in' => $cfg['ttl'], 'resend_after' => $cfg['resend']];
}
/**
 * Checks a one-time code. A known account signs in at once ('status' => 'logged_in', plus the user row).
 * A new number creates no account yet: the verified number waits in the session ('status' =>
 * 'profile_required') until auth_complete_signup() receives a real first and last name, so the system
 * never holds nameless accounts. Error texts are the same for every number.
 */
function otp_verify(string $id, string $code): array
{
    rate_limit('otp:verify-ip:' . client_ip(), 40, 900);
    $code = digits(trim($code));
    if (!preg_match('/^[a-f0-9]{64}$/', $id) || !preg_match('/^[0-9]{6}$/', $code)) {
        throw new AuthError('کد تأیید شش‌رقمی را کامل وارد کنید.');
    }
    db()->beginTransaction();
    try {
        $r = query('SELECT * FROM otp_challenges WHERE id=? FOR UPDATE', [$id])->fetch();
        if (
            !$r ||
            !hash_equals($r['session_hash'], hash('sha256', session_id())) ||
            $r['consumed_at'] !== null ||
            $r['delivery_status'] !== 'accepted' ||
            (int) $r['expires_at'] <= time() ||
            (int) $r['attempts'] >= otp_settings()['attempts']
        ) {
            db()->commit();
            security_event('auth.otp_rejected', $r ? mask_mobile($r['mobile']) : '');
            throw new AuthError('کد منقضی شده یا دیگر معتبر نیست. کد جدید دریافت کنید.');
        }
        if (!password_verify($code, $r['code_hash'])) {
            query('UPDATE otp_challenges SET attempts=attempts+1 WHERE id=?', [$id]);
            db()->commit();
            security_event('auth.otp_failed', mask_mobile($r['mobile']));
            $left = otp_settings()['attempts'] - (int) $r['attempts'] - 1;
            throw new AuthError($left > 0 ? 'کد تأیید اشتباه است. ' . fa_number($left) . ' بار دیگر می‌توانید تلاش کنید.' : 'کد تأیید اشتباه است. کد جدید دریافت کنید.');
        }
        query('UPDATE otp_challenges SET consumed_at=?,verified_at=? WHERE id=?', [time(), time(), $id]);
        $u = query('SELECT * FROM users WHERE mobile=? FOR UPDATE', [$r['mobile']])->fetch();
        if (!$u) {
            db()->commit();
            session_regenerate_id(true);
            // rc.18: an administrator signed in on the same browser stays signed in.
            unset($_SESSION['user_id'], $_SESSION['user_version']);
            $_SESSION['signup'] = ['mobile' => $r['mobile'], 'at' => time()];
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            return ['status' => 'profile_required', 'mobile' => $r['mobile']];
        }
        if (!$u['active']) {
            db()->commit();
            security_event('auth.inactive_login', mask_mobile($r['mobile']), (int) $u['id']);
            throw new AuthError('حساب شما تعلیق شده است' . (!empty($u['suspended_reason']) ? ': ' . $u['suspended_reason'] : '') . '. برای پیگیری با پشتیبانی تماس بگیرید.', 403);
        }
        query('UPDATE users SET verified_at=NOW() WHERE id=?', [$u['id']]);
        query("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'patient')", [$u['id']]);
        db()->commit();
        login_session($u);
        audit('auth.login', (int) $u['id']);
        return $u + ['status' => trim((string) $u['name']) === '' ? 'name_missing' : 'logged_in'];
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/**
 * Checks a one-time code without signing anyone in (administrator sign-in, rc.13): same rules as
 * otp_verify() — bound to this session, single use, delivered, not expired, limited attempts.
 * Returns the verified mobile number.
 */
function otp_consume(string $id, string $code): string
{
    rate_limit('otp:verify-ip:' . client_ip(), 40, 900);
    $code = digits(trim($code));
    if (!preg_match('/^[a-f0-9]{64}$/', $id) || !preg_match('/^[0-9]{6}$/', $code)) {
        throw new AuthError('کد تأیید شش‌رقمی را کامل وارد کنید.');
    }
    db()->beginTransaction();
    try {
        $r = query('SELECT * FROM otp_challenges WHERE id=? FOR UPDATE', [$id])->fetch();
        if (
            !$r ||
            !hash_equals($r['session_hash'], hash('sha256', session_id())) ||
            $r['consumed_at'] !== null ||
            $r['delivery_status'] !== 'accepted' ||
            (int) $r['expires_at'] <= time() ||
            (int) $r['attempts'] >= otp_settings()['attempts']
        ) {
            db()->commit();
            security_event('auth.otp_rejected', $r ? mask_mobile($r['mobile']) : '');
            throw new AuthError('کد منقضی شده یا دیگر معتبر نیست. کد جدید دریافت کنید.');
        }
        if (!password_verify($code, $r['code_hash'])) {
            // The failed attempt is committed before the error, so the limit really applies.
            query('UPDATE otp_challenges SET attempts=attempts+1 WHERE id=?', [$id]);
            db()->commit();
            security_event('auth.otp_failed', mask_mobile($r['mobile']));
            $left = otp_settings()['attempts'] - (int) $r['attempts'] - 1;
            throw new AuthError($left > 0 ? 'کد تأیید اشتباه است. ' . fa_number($left) . ' بار دیگر می‌توانید تلاش کنید.' : 'کد تأیید اشتباه است. کد جدید دریافت کنید.');
        }
        query('UPDATE otp_challenges SET consumed_at=?,verified_at=? WHERE id=?', [time(), time(), $id]);
        db()->commit();
        return (string) $r['mobile'];
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/** Pages a sign-in may return to; anything else falls back to the account page (no open redirects). */
function login_next(mixed $next): string
{
    if (
        is_string($next) &&
        preg_match(
            '/^(panel\.php|patient\.php|secretary\.php|reception-payment\.php|register\.php|doctor-panel\.php|center\.php|center-register\.php|service-book\.php|notifications\.php|support\.php|support\.php\?id=[1-9][0-9]*|book\.php\?slot=[1-9][0-9]*|doctor\.php\?id=[1-9][0-9]*(&clinic=[0-9]+)?)$/',
            $next,
        )
    ) {
        return $next;
    }
    return 'panel.php';
}

/** New session identity for a signed-in user (prevents session fixation) and a fresh CSRF token. */
function login_session(array $u): void
{
    session_regenerate_id(true);
    // rc.18: the administrator sign-in in the same browser is independent and is kept.
    unset($_SESSION['signup']);
    $_SESSION['user_id'] = (int) $u['id'];
    $_SESSION['user_version'] = (int) $u['session_version'];
    $_SESSION['login_at'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

/** Verified number waiting for its first and last name (valid for 15 minutes after the code). */
function pending_signup(): ?array
{
    $p = $_SESSION['signup'] ?? null;
    if (!is_array($p) || empty($p['mobile']) || time() - (int) ($p['at'] ?? 0) > 900) {
        unset($_SESSION['signup']);
        return null;
    }
    return $p;
}

/** First or last name: letters (any script), spaces, ZWNJ, hyphen or apostrophe; 2–50 characters. */
function person_name_part(array $data, string $key, string $label): string
{
    $v = preg_replace('/\s+/u', ' ', trim(field($data, $key, 100, false)));
    if (mb_strlen($v) < 2 || mb_strlen($v) > 50 || !preg_match("/^[\p{L}\p{M}][\p{L}\p{M} \x{200c}'\-]*$/u", $v)) {
        throw new AuthError($label . ' را با حروف و دست‌کم دو حرف وارد کنید.');
    }
    return $v;
}

/**
 * Last sign-up step: creates the account for the verified number with its real name and signs in.
 * A signed-in account without a name (created before 1.0.0-rc.3) completes its name the same way.
 */
function auth_complete_signup(array $data): array
{
    $first = person_name_part($data, 'first_name', 'نام');
    $last = person_name_part($data, 'last_name', 'نام خانوادگی');
    if (empty($data['accept_terms'])) {
        throw new AuthError('برای ساخت حساب، پذیرش قوانین و حریم خصوصی لازم است.');
    }
    $full = $first . ' ' . $last;
    $current = user_now();
    if ($current) {
        query('UPDATE users SET first_name=?,last_name=?,name=?,terms_accepted_at=COALESCE(terms_accepted_at,NOW()) WHERE id=?', [$first, $last, $full, $current['id']]);
        audit('profile.update', (int) $current['id']);
        return query('SELECT * FROM users WHERE id=?', [$current['id']])->fetch();
    }
    $p = pending_signup();
    if (!$p) {
        throw new AuthError('مهلت تکمیل ثبت‌نام تمام شده است. دوباره شماره موبایل را وارد کنید.', 401);
    }
    rate_limit('signup:ip:' . client_ip(), 20, 3600);
    $u = db_transaction(function () use ($p, $first, $last, $full) {
        query(
            "INSERT INTO users(mobile,name,first_name,last_name,verified_at,terms_accepted_at) VALUES(?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE first_name=IF(name='',VALUES(first_name),first_name),last_name=IF(name='',VALUES(last_name),last_name),name=IF(name='',VALUES(name),name),verified_at=NOW()",
            [$p['mobile'], $full, $first, $last],
        );
        $u = query('SELECT * FROM users WHERE mobile=? FOR UPDATE', [$p['mobile']])->fetch();
        if (!$u['active']) {
            throw new AuthError('حساب شما تعلیق شده است' . (!empty($u['suspended_reason']) ? ': ' . $u['suspended_reason'] : '') . '. برای پیگیری با پشتیبانی تماس بگیرید.', 403);
        }
        query("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'patient')", [$u['id']]);
        return $u;
    });
    login_session($u);
    audit('auth.signup', (int) $u['id'], mask_mobile($u['mobile']));
    return $u;
}
/** Signs the patient/doctor account out of this browser; an administrator sign-in here is kept (rc.18). */
function logout_user(): void
{
    unset($_SESSION['user_id'], $_SESSION['user_version'], $_SESSION['login_at'], $_SESSION['signup']);
    session_regenerate_id(true);
}

/** Signs the administrator out of this browser; a patient/doctor sign-in here is kept (rc.18). */
function logout_admin(): void
{
    unset($_SESSION['admin_id'], $_SESSION['admin_version'], $_SESSION['admin_login_at'], $_SESSION['admin_auth']);
    session_regenerate_id(true);
}

/** Invalidates every session of the current account (all devices), then logs out here. */
function logout_everywhere(): void
{
    $u = require_user();
    query('UPDATE users SET session_version=session_version+1 WHERE id=?', [$u['id']]);
    audit('auth.logout_all', (int) $u['id']);
    logout_user();
}
function update_profile(array $data): void
{
    $u = require_user();
    if (isset($data['first_name']) || isset($data['last_name'])) {
        $first = person_name_part($data, 'first_name', 'نام');
        $last = person_name_part($data, 'last_name', 'نام خانوادگی');
        $name = $first . ' ' . $last;
    } else {
        $name = preg_replace('/\s+/u', ' ', field($data, 'name', 190));
        if (mb_strlen($name) < 3) {
            throw new AuthError('نام کامل وارد کنید.');
        }
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
    }
    query('UPDATE users SET name=?,first_name=?,last_name=? WHERE id=?', [$name, mb_substr($first, 0, 100), mb_substr($last, 0, 100), $u['id']]);
    audit('profile.update', (int) $u['id']);
}
function my_doctor(int $user): ?array
{
    $d = query('SELECT d.* FROM doctors d JOIN doctor_accounts a ON a.doctor_id=d.id WHERE a.user_id=?', [
        $user,
    ])->fetch();
    return $d ?: null;
}
function scope_clinics(string $role, int $user): array
{
    if ($role === 'doctor') {
        return query(
            "SELECT cl.*,d.name doctor FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id JOIN doctor_accounts a ON a.doctor_id=d.id WHERE a.user_id=? AND d.review_status='approved' AND d.active=1",
            [$user],
        )->fetchAll();
    }
    if (!in_array($role, ['secretary', 'clinic'], true)) {
        throw new AuthError('نقش نامعتبر است.', 403);
    }
    return query(
        "SELECT cl.*,d.name doctor FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id JOIN staff_scopes s ON s.clinic_id=cl.id WHERE s.user_id=? AND s.role=? AND d.review_status='approved' AND d.active=1",
        [$user, $role],
    )->fetchAll();
}
function scoped_bookings(string $role, int $page = 1, int $clinic = 0): array
{
    $u = require_role($role);
    $page = max(1, $page);
    $offset = ($page - 1) * 30;
    $base =
        ' FROM bookings b JOIN slots sl ON sl.id=b.slot_id JOIN doctors d ON d.id=sl.doctor_id JOIN clinics cl ON cl.id=sl.clinic_id';
    if ($role === 'patient') {
        $where = ' WHERE b.patient_user_id=?';
        $args = [$u['id']];
    } else {
        $ids = array_map(fn($c) => (int) $c['id'], scope_clinics($role, (int) $u['id']));
        if ($clinic && !in_array($clinic, $ids, true)) {
            throw new AuthError('به این مطب دسترسی ندارید.', 403);
        }
        if (!$ids) {
            return ['total' => 0, 'items' => []];
        }
        if ($clinic) {
            $ids = [$clinic];
        }
        $where = ' WHERE cl.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = $ids;
    }
    if ($role === 'secretary') {
        $where .=
            " AND (sl.starts_at>=CURDATE() OR EXISTS(SELECT 1 FROM staff_scopes sc WHERE sc.clinic_id=cl.id AND sc.user_id=? AND sc.role='secretary' AND sc.can_view_history=1))";
        $args[] = $u['id'];
    }
    $where .= " AND b.status NOT IN ('held','expired')";
    $total = (int) query('SELECT COUNT(*)' . $base . $where, $args)->fetchColumn();
    $items = query(
        'SELECT b.id,b.patient_name,b.phone,b.tracking_code,b.status,sl.starts_at,d.name doctor,cl.name clinic,cl.address' .
            $base .
            $where .
            ' ORDER BY sl.starts_at DESC,b.id DESC LIMIT 30 OFFSET ' .
            $offset,
        $args,
    )->fetchAll();
    foreach ($items as &$row) {
        $row['starts_at_jalali'] = jdate($row['starts_at'], true);
        $row['status_label'] = booking_status_label($row['status']);
    }
    unset($row);
    return ['total' => $total, 'items' => $items];
}

function audit_label(string $action): string
{
    $labels = [
        'taxonomy' => 'تغییر اطلاعات پایه',
        'taxonomy.order' => 'تغییر ترتیب اطلاعات پایه',
        'delete_taxonomy' => 'حذف اطلاعات پایه',
        'specialty.restore' => 'بازگردانی تخصص',
        'settings' => 'تغییر کارمزد',
        'settings.general' => 'تغییر تنظیمات عمومی',
        'settings.revenue' => 'تغییر فروش اشتراک یا مالیات',
        'user.suspend' => 'تعلیق حساب کاربر',
        'user.unsuspend' => 'رفع تعلیق حساب کاربر',
        'user.delete' => 'حذف حساب کاربر',
        'doctor.suspend' => 'تعلیق پزشک',
        'doctor.unsuspend' => 'رفع تعلیق پزشک',
        'doctor.delete' => 'حذف پزشک',
        'pro.catalog' => 'تغییر امکانات نسخه پرو',
        'specialty.starter' => 'افزودن فهرست پیشنهادی تخصص‌ها',
        'doctor.claim_requested' => 'درخواست اتصال پروفایل پزشک',
        'settings.geo' => 'تغییر تنظیمات نقشه',
        'booking.settings' => 'تغییر قوانین رزرو',
        'booking.page' => 'تغییر تنظیمات صفحه رزرو',
        'geo.bulk' => 'فعال/غیرفعال کردن گروهی استان یا شهر',
        'gateway.logo' => 'تغییر نشان درگاه پرداخت',
        'update.upload' => 'بارگذاری بسته به‌روزرسانی',
        'update.rejected' => 'رد بسته به‌روزرسانی',
        'update.apply' => 'نصب به‌روزرسانی',
        'update.finish' => 'تکمیل به‌روزرسانی دیتابیس',
        'update.failed' => 'خطای به‌روزرسانی (فایل‌ها برگردانده شد)',
        'update.rollback' => 'بازگرداندن نسخه قبلی',
        'update.cancel' => 'لغو بسته به‌روزرسانی',
        'update.denied' => 'رمز اشتباه هنگام به‌روزرسانی',
        'auth.login' => 'ورود کاربر',
        'auth.signup' => 'ثبت‌نام کاربر جدید',
        'auth.otp_failed' => 'کد ورود اشتباه',
        'auth.otp_rejected' => 'کد ورود منقضی یا نامعتبر',
        'auth.inactive_login' => 'تلاش ورود حساب غیرفعال',
        'auth.logout' => 'خروج کاربر',
        'auth.logout_all' => 'خروج از همه دستگاه‌ها',
        'auth.suspicious_ip' => 'درخواست مشکوک ورود',
        'admin.login' => 'ورود مدیر',
        'admin.logout' => 'خروج مدیر',
        'admin.create' => 'ساخت حساب مدیر',
        'admin.update' => 'تغییر نقش یا نام مدیر',
        'admin.enable' => 'فعال‌سازی حساب مدیر',
        'admin.disable' => 'غیرفعال‌سازی حساب مدیر',
        'admin.password_reset' => 'تغییر رمز مدیر توسط مالک',
        'admin.password_change' => 'تغییر رمز توسط خود مدیر',
        'admin.denied' => 'تلاش برای دسترسی غیرمجاز مدیر',
        'sms.templates' => 'تغییر الگوهای پیامک',
        'sms.test' => 'ارسال پیامک آزمایشی',
        'sms.account' => 'استعلام اعتبار پیامک',
        'sms.settings' => 'تغییر تنظیمات پیامک',
        'profile.update' => 'ویرایش نام حساب',
        'family.save' => 'ثبت عضو خانواده',
        'family.archive' => 'حذف عضو خانواده',
        'user_status' => 'تغییر وضعیت حساب',
        'link_doctor' => 'اتصال حساب پزشک',
        'unlink_doctor' => 'قطع حساب پزشک',
        'grant_scope' => 'افزودن دسترسی مطب',
        'revoke_scope' => 'لغو دسترسی مطب',
        'fee.rule' => 'ثبت قانون کارمزد',
        'fee.rule_delete' => 'حذف قانون کارمزد',
        'gateway.save' => 'تغییر اطلاعات درگاه',
        'gateway.enable' => 'فعال‌سازی درگاه',
        'gateway.disable' => 'غیرفعال‌سازی درگاه',
        'gateway.roles' => 'تغییر درگاه پیش‌فرض/جایگزین',
        'gateway.test' => 'پرداخت آزمایشی درگاه',
        'refund.done' => 'ثبت بازگشت وجه',
        'refund.rejected' => 'رد درخواست بازگشت وجه',
        'plan.save' => 'تغییر پلن اشتراک',
        'discount.save' => 'تغییر کد تخفیف',
        'subscription.admin_order' => 'صدور پیش‌فاکتور اشتراک',
        'subscription.offline' => 'ثبت پرداخت آفلاین اشتراک',
        'subscription.cancel' => 'لغو پیش‌فاکتور اشتراک',
        'subscription.settings' => 'تغییر تنظیمات اشتراک',
        'center.register' => 'ثبت‌نام مرکز',
        'center.review' => 'بررسی مرکز',
        'center.active' => 'تغییر وضعیت فعالیت مرکز',
        'center.grant' => 'هدیه/تمدید اشتراک مرکز',
        'center.offline' => 'ثبت پرداخت آفلاین مرکز',
        'center.settings' => 'تغییر تنظیمات مراکز',
        'notify.settings' => 'تغییر تنظیمات اعلان',
        'notify.preferences' => 'تغییر تنظیمات دریافت اعلان',
        'push.keys' => 'ساخت کلیدهای اعلان مرورگر',
        'push.subscribe' => 'فعال‌سازی اعلان مرورگر',
        'push.unsubscribe' => 'غیرفعال‌سازی اعلان مرورگر',
        'cron.manual' => 'اجرای دستی Cron',
        'export.dashboard' => 'خروجی اکسل داشبورد',
        'export.payments' => 'خروجی اکسل تراکنش‌ها',
        'export.day_list' => 'خروجی اکسل نوبت‌های روز (پزشک یا منشی)',
        'export.subscriptions' => 'خروجی اکسل اشتراک‌ها',
        'export.audit' => 'خروجی اکسل گزارش فعالیت',
        'ticket.create' => 'ثبت تیکت پشتیبانی',
        'ticket.user_reply' => 'پاسخ کاربر به تیکت',
        'ticket.user_close' => 'بستن تیکت توسط کاربر',
        'ticket.reply' => 'پاسخ پشتیبانی به تیکت',
        'ticket.update' => 'تغییر وضعیت تیکت',
        'content.home' => 'تغییر محتوای صفحه اصلی',
        'content.banner' => 'ذخیره بنر',
        'content.banner_delete' => 'حذف بنر',
        'announcement.create' => 'ارسال اطلاعیه سراسری',
        'announcement.cancel' => 'لغو اطلاعیه سراسری',
        'home.draft' => 'ذخیره پیش‌نویس صفحه اصلی',
        'home.publish' => 'انتشار صفحه اصلی',
        'home.discard' => 'کنار گذاشتن پیش‌نویس صفحه اصلی',
        'home.featured' => 'تغییر پزشکان منتخب',
        'home.featured_order' => 'ترتیب پزشکان منتخب',
        'home.specialties' => 'تخصص‌های صفحه اصلی',
        'home.faq' => 'ذخیره سوال متداول',
        'home.faq_delete' => 'حذف سوال متداول',
        'home.page' => 'ویرایش صفحه سایت',
        'home.footer' => 'تنظیمات پانویس',
        'home.drawer_banner' => 'بنر منوی موبایل',
        'search.settings' => 'تنظیمات جستجو',
        'search.cache' => 'محاسبه دوباره نوبت‌های آزاد',
        'review.create' => 'ثبت نظر بیمار',
        'review.approved' => 'انتشار نظر بیمار',
        'review.hidden' => 'پنهان کردن نظر بیمار',
        'article.save' => 'ذخیره مقاله',
        'article.delete' => 'حذف مقاله',
        'backup.create' => 'ساخت پشتیبان دیتابیس',
        'backup.download' => 'دریافت فایل پشتیبان',
        'backup.delete' => 'حذف فایل پشتیبان',
        'backup.upload' => 'بارگذاری فایل پشتیبان',
        'backup.restore' => 'بازیابی دیتابیس از پشتیبان',
        'backup.restore_denied' => 'تلاش ناموفق برای بازیابی',
        'backup.settings' => 'تغییر تنظیمات پشتیبان',
        'admin.login_failed' => 'ورود ناموفق مدیر',
    ];
    return $labels[$action] ?? 'رویداد سامانه (' . $action . ')';
}

/** Action groups offered as a filter on the activity log. */
function audit_prefixes(): array
{
    return [
        'admin.' => 'حساب‌های مدیریت',
        'update.' => 'به‌روزرسانی سامانه',
        'auth.' => 'ورود و خروج کاربران',
        'settings' => 'تنظیمات',
        'gateway.' => 'درگاه‌ها',
        'refund.' => 'بازگشت وجه',
        'subscription.' => 'اشتراک‌ها',
        'center.' => 'مراکز',
        'sms.' => 'پیامک',
        'export.' => 'خروجی‌ها',
        'ticket.' => 'پشتیبانی',
        'content.' => 'محتوا',
        'home.' => 'صفحه اصلی',
        'article.' => 'مقالات',
        'search.' => 'جستجو',
        'review.' => 'نظرات بیماران',
        'announcement.' => 'اطلاعیه‌ها',
        'backup.' => 'پشتیبان و بازیابی',
    ];
}
