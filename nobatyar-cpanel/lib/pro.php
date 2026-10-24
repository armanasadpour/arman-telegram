<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.20 — doctor plans: «رایگان» and «پرو».
 *
 * Every approved doctor is listed and takes online bookings without paying (free plan). Pro (a paid
 * subscription period, or the free trial) unlocks the features the administrator ticks as «فقط پرو» in
 * Subscriptions → «امکانات و قیمت پرو». The free plan's limits (clinics, services, secretaries) are set
 * there too; a Pro plan's limits come from the plan (empty = unlimited).
 *
 * With subscription sales off (rc.17 switch) every doctor has every feature and no limit.
 * Every check — server actions, API, menus, search, cron — goes through doctor_has() / doctor_limit().
 * Medical centers have no free plan: their subscription (lib/center_billing.php) is unchanged.
 */

/**
 * Feature catalog. kind: base (always free, shown for information) | feature | limit.
 * pro: default «فقط پرو» value; free_limit: default free-plan limit for kind=limit (null = unlimited).
 */
function pro_catalog(): array
{
    return [
        'register' => ['kind' => 'base', 'label' => 'ثبت‌نام و پروفایل عمومی پزشک', 'icon' => 'doctor'],
        'calendar' => ['kind' => 'base', 'label' => 'تقویم و نوبت‌دهی آنلاین', 'icon' => 'calendar'],
        'bookings' => ['kind' => 'base', 'label' => 'مدیریت نوبت‌ها (تأیید، لغو، جابه‌جایی، حضور)', 'icon' => 'calendar-check'],
        'patient_notify' => ['kind' => 'base', 'label' => 'پیامک تأیید، لغو و جابه‌جایی برای بیمار', 'icon' => 'sms'],
        'clinics' => ['kind' => 'limit', 'label' => 'تعداد مطب', 'icon' => 'map', 'pro' => true, 'free_limit' => 1, 'unit' => 'مطب'],
        'services' => ['kind' => 'limit', 'label' => 'تعداد خدمت', 'icon' => 'list', 'pro' => true, 'free_limit' => 1, 'unit' => 'خدمت'],
        'secretaries' => ['kind' => 'limit', 'label' => 'منشی', 'icon' => 'users', 'pro' => true, 'free_limit' => 0, 'unit' => 'منشی'],
        'verified_badge' => ['kind' => 'feature', 'label' => 'تیک آبی کنار نام پزشک', 'icon' => 'verified', 'pro' => true],
        'search_boost' => ['kind' => 'feature', 'label' => 'بالاتر قرار گرفتن در نتایج جستجو', 'icon' => 'search', 'pro' => true],
        'day_export' => ['kind' => 'feature', 'label' => 'دریافت اکسل نوبت‌های روز', 'icon' => 'download', 'pro' => true],
        'daily_summary_sms' => ['kind' => 'feature', 'label' => 'پیامک خلاصه نوبت‌ها در پایان روز', 'icon' => 'sms', 'pro' => true],
        'monthly_report' => ['kind' => 'feature', 'label' => 'گزارش عملکرد و آمار بازدید پروفایل', 'icon' => 'chart', 'pro' => true],
        'waitlist' => ['kind' => 'feature', 'label' => 'صف انتظار برای روزهای پر', 'icon' => 'clock', 'pro' => true],
        'doctor_notice' => ['kind' => 'feature', 'label' => 'اطلاعیه در صفحه پزشک', 'icon' => 'megaphone', 'pro' => true],
        'booking_qr' => ['kind' => 'feature', 'label' => 'لینک اختصاصی، QR و پوستر رزرو برای مطب', 'icon' => 'share', 'pro' => true],
        'checkin' => ['kind' => 'feature', 'label' => 'چک نوبت (تأیید حضور بیمار با کد ملی یا نام)', 'icon' => 'calendar-check', 'pro' => true],
        'reception' => ['kind' => 'feature', 'label' => 'ثبت نوبت تلفنی و حضوری توسط پزشک', 'icon' => 'phone', 'pro' => false],
        'centers' => ['kind' => 'feature', 'label' => 'همکاری با مراکز درمانی', 'icon' => 'building', 'pro' => false],
    ];
}

/** The administrator's choices: [pro => [key => bool], limits => [key => ?int]]. */
function pro_settings(): array
{
    if (isset($GLOBALS['nobatyar_pro_settings'])) {
        return $GLOBALS['nobatyar_pro_settings'];
    }
    $saved = json_decode(setting('pro_features', ''), true);
    $saved = is_array($saved) ? $saved : [];
    $pro = [];
    $limits = [];
    foreach (pro_catalog() as $key => $f) {
        if ($f['kind'] === 'base') {
            continue;
        }
        $pro[$key] = array_key_exists($key, $saved['pro'] ?? []) ? (bool) $saved['pro'][$key] : (bool) $f['pro'];
        if ($f['kind'] === 'limit') {
            $v = array_key_exists($key, $saved['limits'] ?? []) ? $saved['limits'][$key] : $f['free_limit'];
            $limits[$key] = $v === null || $v === '' ? null : max(0, (int) $v);
        }
    }
    return $GLOBALS['nobatyar_pro_settings'] = ['pro' => $pro, 'limits' => $limits];
}

/** pro_settings() keeps a per-request cache; cleared after the administrator saves the catalog. */
function pro_settings_reset_cache(): void
{
    unset($GLOBALS['nobatyar_pro_settings']);
}

/** Whether a feature is reserved for Pro (while subscription sales are on). */
function feature_is_pro(string $key): bool
{
    return subscriptions_on() && (pro_settings()['pro'][$key] ?? false);
}

/** Pro now: a paid, trial, gift or manual period (with its grace days) covers today. */
function doctor_is_pro(int $doctorId, ?array $row = null): bool
{
    $overrides = doctor_overrides($doctorId);
    if (array_key_exists('pro', $overrides)) return (bool) $overrides['pro'];
    if (!subscriptions_on()) {
        return true;
    }
    $until = $row !== null && array_key_exists('subscription_until', $row)
        ? $row['subscription_until']
        : query('SELECT subscription_until FROM doctors WHERE id=?', [$doctorId])->fetchColumn();
    return $until && date('Y-m-d', strtotime($until . ' +' . grace_days() . ' days')) >= date('Y-m-d');
}

function doctor_has(int $doctorId, string $feature, ?array $row = null): bool
{
    $o = doctor_overrides($doctorId);
    if (array_key_exists($feature, $o)) return (bool) $o[$feature];
    return !feature_is_pro($feature) || doctor_is_pro($doctorId, $row);
}

/**
 * Limit for clinics | services | secretaries; null = unlimited. Free plan: catalog limit. Pro: the current
 * plan's limit (trial, gift and manual periods have none).
 */
function doctor_limit(int $doctorId, string $key): ?int
{
    $o = doctor_overrides($doctorId);
    if (array_key_exists($key, $o)) return $o[$key] === null ? null : (int) $o[$key];
    if (!feature_is_pro($key)) {
        return null;
    }
    if (doctor_is_pro($doctorId)) {
        return plan_limit($doctorId, 'max_' . $key);
    }
    return pro_settings()['limits'][$key] ?? null;
}

/** Stops an action that needs a Pro feature. */
function require_feature(int $doctorId, string $feature): void
{
    if (!doctor_has($doctorId, $feature)) {
        throw new AuthError('«' . (pro_catalog()[$feature]['label'] ?? $feature) . '» ویژه اشتراک پرو است. از بخش «اشتراک پرو» فعالش کنید.', 403);
    }
}

/** Stops adding one more clinic / service / secretary beyond the plan. $current = how many are counted now. */
function require_within_limit(int $doctorId, string $key, int $current): void
{
    $limit = doctor_limit($doctorId, $key);
    if ($limit !== null && $current >= $limit) {
        $unit = pro_catalog()[$key]['unit'] ?? '';
        throw new AuthError(
            ($limit === 0 ? 'افزودن ' . $unit : 'بیش از ' . fa_number($limit) . ' ' . $unit) .
                (doctor_is_pro($doctorId) ? ' در پلن فعلی شما ممکن نیست؛ پلن بالاتر را انتخاب کنید.' : ' در نسخه رایگان ممکن نیست؛ با اشتراک پرو نامحدود می‌شود.'),
            403,
        );
    }
}

/**
 * Keeps clinics and services beyond the current limit «بدون رزرو جدید» (plan_locked=1) — primary clinic
 * and the oldest services stay open — and unlocks everything within the limit. Nothing is deleted and
 * confirmed bookings are untouched. Run after any plan, catalog or clinic/service change and daily.
 */
function entitlement_sync(int $doctorId): void
{
    // Clinics inside a medical center's branch belong to the center (which has its own subscription) and
    // never count toward, or get locked by, the doctor's own plan — the same rule as adding a clinic.
    foreach (['clinics' => 'SELECT id FROM clinics WHERE doctor_id=? AND active=1 AND center_id IS NULL ORDER BY is_primary DESC,id', 'services' => 'SELECT id FROM doctor_services WHERE doctor_id=? AND active=1 ORDER BY id'] as $key => $sql) {
        $table = $key === 'clinics' ? 'clinics' : 'doctor_services';
        $limit = doctor_limit($doctorId, $key);
        $ids = query($sql, [$doctorId])->fetchAll(PDO::FETCH_COLUMN);
        $keep = $limit === null ? $ids : array_slice($ids, 0, $limit === 0 ? 1 : $limit);
        $lock = array_values(array_diff($ids, $keep));
        query("UPDATE $table SET plan_locked=0 WHERE doctor_id=?", [$doctorId]);
        if ($lock) {
            query("UPDATE $table SET plan_locked=1 WHERE doctor_id=? AND id IN (" . placeholders($lock) . ')', array_merge([$doctorId], $lock));
        }
    }
}

function entitlement_sync_all(): int
{
    $ids = query('SELECT id FROM doctors')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        entitlement_sync((int) $id);
    }
    return count($ids);
}

/** Secretaries allowed to work for this doctor now (the first ones added, up to the limit). */
function doctor_secretary_allowed(int $doctorId, int $userId): bool
{
    $limit = doctor_limit($doctorId, 'secretaries');
    if ($limit === null) {
        return true;
    }
    if ($limit === 0) {
        return false;
    }
    $ids = query(
        "SELECT s.user_id FROM staff_scopes s JOIN clinics c ON c.id=s.clinic_id WHERE c.doctor_id=? AND s.role='secretary' GROUP BY s.user_id ORDER BY MIN(s.id) LIMIT " . $limit,
        [$doctorId],
    )->fetchAll(PDO::FETCH_COLUMN);
    return in_array($userId, array_map('intval', $ids), true);
}

/** Blue tick next to the doctor's name (Pro + «تیک آبی» feature). */
function pro_badge(array $doctor): string
{
    if (empty($doctor['id']) || !doctor_has((int) $doctor['id'], 'verified_badge', $doctor)) {
        return '';
    }
    return '<span class="pro-tick" title="نشان ویژه پزشک"><img class="doctor-verified-icon" src="assets/doctor-verified.png?v=' . esc(APP_VERSION) . '" alt="نشان ویژه پزشک" width="22" height="22"></span>';
}

/** SQL expression (1/0) for the search ranking boost of Pro doctors. */
function pro_boost_sql(string $alias = 'd'): string
{
    $base = feature_is_pro('search_boost') ? '(' . $alias . '.subscription_until IS NOT NULL AND DATE_ADD(' . $alias . '.subscription_until,INTERVAL ' . grace_days() . ' DAY)>=CURDATE())' : '1';
    $json = "COALESCE($alias.feature_overrides,'{}')";
    return feature_is_pro('search_boost') ? "COALESCE(JSON_EXTRACT($json,'$.search_boost'),JSON_EXTRACT($json,'$.pro'),$base)" : "COALESCE(JSON_EXTRACT($json,'$.search_boost'),1)";
}

/** Whether one more clinic / service / secretary would pass the plan limit. */
function pro_limit_reached(int $doctorId, string $key, int $current): bool
{
    $limit = doctor_limit($doctorId, $key);
    return $limit !== null && $current >= $limit;
}

/** Card shown instead of an «add» form when the plan limit is reached. */
function pro_limit_card(int $doctorId, string $key): string
{
    $limit = doctor_limit($doctorId, $key);
    $unit = pro_catalog()[$key]['unit'] ?? '';
    $text = doctor_is_pro($doctorId)
        ? 'پلن فعلی شما تا ' . fa_number((int) $limit) . ' ' . $unit . ' دارد.'
        : ($limit === 0 ? 'افزودن ' . $unit . ' ویژه اشتراک پرو است.' : 'نسخه رایگان تا ' . fa_number((int) $limit) . ' ' . $unit . ' دارد؛ با اشتراک پرو نامحدود می‌شود.');
    return '<div class="pro-upsell">' . icon('lock') . '<p>' . esc($text) . '</p>' . pro_lock($doctorId, $key, 'فعال‌سازی پرو') . '</div>';
}

/** Card shown in place of a Pro feature the doctor does not have yet. */
function pro_feature_card(int $doctorId, string $feature, string $why = ''): string
{
    $label = pro_catalog()[$feature]['label'] ?? $feature;
    return '<div class="pro-upsell">' . icon('lock') . '<p><strong>' . esc($label) . '</strong> ویژه اشتراک پرو است.' . ($why !== '' ? ' ' . esc($why) : '') . '</p>' .
        pro_lock($doctorId, $feature, 'فعال‌سازی پرو') . '</div>';
}

/** «ویژه پرو» soft lock: a button that opens the Pro page instead of the action. */
function pro_lock(int $doctorId, string $feature, string $label): string
{
    return '<a class="button secondary pro-lock" href="doctor-panel.php?doctor=' . $doctorId . '&amp;tab=subscription&amp;want=' . esc($feature) . '">' .
        icon('lock') . '<span>' . esc($label) . '</span><b class="pro-chip">پرو</b></a>';
}

/** Lowest monthly-equivalent price of active doctor Pro plans, or null. */
function pro_price_from(): ?array
{
    $best = null;
    foreach (plans_list(true, 'doctor') as $p) {
        $monthly = (int) round((int) $p['price_toman'] * 30 / max(1, (int) $p['duration_days']));
        if ($best === null || $monthly < $best['monthly']) {
            $best = ['monthly' => $monthly, 'plan' => $p];
        }
    }
    return $best;
}

/** Counts one view of a doctor's public page per visitor session and day (not the doctor or staff). */
function profile_view_count(int $doctorId): void
{
    $key = 'pv_' . $doctorId . '_' . date('Ymd');
    if (!empty($_SESSION[$key]) || preg_match('/bot|crawl|spider|slurp|preview/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
        return;
    }
    $_SESSION[$key] = 1;
    query('INSERT INTO doctor_profile_views(doctor_id,day,views) VALUES(?,CURDATE(),1) ON DUPLICATE KEY UPDATE views=views+1', [$doctorId]);
}

/* ---------- End-of-day summary SMS for doctors ---------- */

function daily_summary_hour(): int
{
    return setting_int('daily_summary_hour', 21, 0, 23);
}

/**
 * Once a day, at or after the configured hour: tomorrow's confirmed bookings, the first time, and today's
 * completed / cancelled counts, to every approved doctor with the feature who has not turned it off and
 * has something to report. One SMS per doctor and day (dedupe key).
 */
function cron_daily_summary(): string
{
    if ((int) date('G') < daily_summary_hour()) {
        return 'هنوز به ساعت ارسال (' . fa_number(daily_summary_hour()) . ') نرسیده است.';
    }
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $sent = 0;
    $rows = query("SELECT id,name,mobile,subscription_until FROM doctors WHERE review_status='approved' AND active=1 AND daily_summary_off=0 AND mobile<>''")->fetchAll();
    foreach ($rows as $d) {
        if (!doctor_has((int) $d['id'], 'daily_summary_sms', $d)) {
            continue;
        }
        $t = query(
            "SELECT COUNT(*) n,MIN(s.starts_at) first FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND b.status='confirmed' AND DATE(s.starts_at)=?",
            [$d['id'], $tomorrow],
        )->fetch();
        $day = query(
            "SELECT SUM(b.status IN ('completed','confirmed')) done,SUM(b.status='cancelled') cancelled,SUM(b.status='no_show') no_show FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND DATE(s.starts_at)=?",
            [$d['id'], $today],
        )->fetch();
        if ((int) $t['n'] === 0 && (int) $day['done'] + (int) $day['cancelled'] + (int) $day['no_show'] === 0) {
            continue;
        }
        if (!notice_once('daily-summary:' . $d['id'] . ':' . $today)) {
            continue;
        }
        $first = $t['first'] ? fa_number(substr((string) $t['first'], 11, 5)) : '—';
        sms_enqueue('doctor_daily_summary', $d['mobile'], [
            'token' => fa_number((int) $t['n']),
            'token2' => $first,
            'token3' => fa_number((int) $day['done']),
            'token10' => (string) $d['name'],
            'token20' => fa_number((int) $day['cancelled']),
        ], 'daily-summary:' . $d['id'] . ':' . $today, ['doctor_id' => (int) $d['id']]);
        notify_doctor((int) $d['id'], 'خلاصه نوبت‌های فردا', 'فردا ' . fa_number((int) $t['n']) . ' نوبت قطعی دارید' . ($t['first'] ? '؛ اولین نوبت ساعت ' . $first : '') . '. امروز: ' . fa_number((int) $day['done']) . ' نوبت، ' . fa_number((int) $day['cancelled']) . ' لغو.');
        $sent++;
    }
    return fa_number($sent) . ' خلاصه روزانه برای پزشکان ارسال شد.';
}

/* ---------- Doctor report (Pro) ---------- */

/** Bookings, completion, no-shows, cancellations, busiest hours and profile views for the last $days days. */
function doctor_report(int $doctorId, int $days = 30): array
{
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $to = date('Y-m-d');
    $r = query(
        "SELECT COUNT(*) total,SUM(b.status='completed') completed,SUM(b.status='no_show') no_show,SUM(b.status='cancelled') cancelled,SUM(b.status='confirmed') upcoming,SUM(b.booking_channel IS NULL OR b.booking_channel='online') online FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND b.status IN ('confirmed','completed','no_show','cancelled') AND DATE(s.starts_at) BETWEEN ? AND ?",
        [$doctorId, $from, $to],
    )->fetch();
    $hours = query(
        "SELECT HOUR(s.starts_at) h,COUNT(*) n FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND b.status IN ('confirmed','completed','no_show') AND DATE(s.starts_at) BETWEEN ? AND ? GROUP BY h ORDER BY n DESC,h LIMIT 3",
        [$doctorId, $from, $to],
    )->fetchAll();
    $views = (int) query('SELECT COALESCE(SUM(views),0) FROM doctor_profile_views WHERE doctor_id=? AND day BETWEEN ? AND ?', [$doctorId, $from, $to])->fetchColumn();
    $byDay = query('SELECT day,views FROM doctor_profile_views WHERE doctor_id=? AND day BETWEEN ? AND ? ORDER BY day', [$doctorId, $from, $to])->fetchAll(PDO::FETCH_KEY_PAIR);
    $online = (int) query(
        "SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND b.created_by_user_id IS NULL AND b.status IN ('confirmed','completed','no_show','cancelled') AND DATE(b.created_at) BETWEEN ? AND ?",
        [$doctorId, $from, $to],
    )->fetchColumn();
    $seen = (int) $r['completed'] + (int) $r['no_show'];
    return [
        'from' => $from,
        'to' => $to,
        'total' => (int) $r['total'],
        'completed' => (int) $r['completed'],
        'no_show' => (int) $r['no_show'],
        'cancelled' => (int) $r['cancelled'],
        'upcoming' => (int) $r['upcoming'],
        'no_show_rate' => $seen ? (int) round((int) $r['no_show'] * 100 / $seen) : 0,
        'hours' => $hours,
        'views' => $views,
        'views_by_day' => $byDay,
        'online' => $online,
        'conversion' => $views ? (int) round($online * 100 / $views) : 0,
    ];
}

/* ---------- Doctor announcement (Pro) ---------- */

/** Active announcement text for the public doctor page, or ''. */
function doctor_notice(array $d): string
{
    if (empty($d['notice_text']) || (!empty($d['notice_until']) && $d['notice_until'] < date('Y-m-d'))) {
        return '';
    }
    return doctor_has((int) $d['id'], 'doctor_notice', $d) ? (string) $d['notice_text'] : '';
}

/** Saves the announcement shown on the doctor's page; doctors no longer message patients directly (1.0.27). */
function doctor_notice_save(int $doctorId, array $data): string
{
    doctor_actor($doctorId, 'owner', 0, true);
    require_feature($doctorId, 'doctor_notice');
    $text = trim(strtr(field($data, 'notice_text', 500, false), ["\r\n" => ' ', "\n" => ' ']));
    $until = trim((string) ($data['notice_until'] ?? '')) !== '' ? parse_jdate(field($data, 'notice_until', 20)) : null;
    if ($until !== null && ($until < date('Y-m-d') || (strtotime($until) - time()) / 86400 > 90)) {
        throw new AuthError('تاریخ پایان اطلاعیه از امروز تا حداکثر ۹۰ روز بعد باشد.');
    }
    query('UPDATE doctors SET notice_text=?,notice_until=? WHERE id=?', [$text !== '' ? $text : null, $text !== '' ? $until : null, $doctorId]);
    event_record($doctorId, 'doctor.notice', ['until' => $until, 'length' => mb_strlen($text)]);
    return $text === '' ? 'اطلاعیه برداشته شد.' : 'اطلاعیه در صفحه شما نمایش داده می‌شود.';
}

/** Per-doctor administrator overrides; absent keys inherit the plan. */
function doctor_overrides(int $id): array {
    if (!array_key_exists($id, $GLOBALS['doctor_overrides_cache'] ?? [])) {
        $v = query('SELECT feature_overrides FROM doctors WHERE id=?', [$id])->fetchColumn();
        $GLOBALS['doctor_overrides_cache'][$id] = json_decode((string) $v, true) ?: [];
    }
    return $GLOBALS['doctor_overrides_cache'][$id];
}
function doctor_display_name(string $name): string {
    return 'دکتر ' . doctor_bare_name($name);
}
function doctor_bare_name(string $name): string {
    return trim(preg_replace('/^(?:(?:دکتر|دكتر|دکتور|doctor|dr\.?)[\s\x{200c}]+)+/iu', '', trim($name)));
}
function validate_doctor_name(string $name): void {
    if (preg_match('/^(?:دکتر|دكتر|دکتور|doctor|dr\.?)(?:[\s\x{200c}]|$)/iu', trim($name))) {
        throw new AuthError('نام و نام خانوادگی را بدون «دکتر» وارد کنید؛ سامانه این عنوان را خودکار نمایش می‌دهد.');
    }
}
function doctor_avatar_markup(array $d, bool $panel = false): string {
    $pro = doctor_is_pro((int) $d['id'], $d);
    $photo = !empty($d['photo_file']) ? '<img src="photo.php?id=' . (int)$d['id'] . '" alt="' . esc(doctor_display_name($d['name'])) . '">' : '<span aria-hidden="true">' . esc(mb_substr(doctor_bare_name($d['name']),0,1)) . '</span>';
    return '<div class="doctor-avatar' . ($pro ? ' doctor-avatar-pro' : '') . '">' . $photo . ($pro && $panel ? '<span class="avatar-pro-label">Pro</span>' : '') . '</div>';
}
