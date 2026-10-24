<?php
declare(strict_types=1);

/** Admin navigation, login and the read-side data each admin view needs. */

function admin_sections(): array
{
    return [
        'dashboard' => ['label' => 'داشبورد', 'icon' => 'chart', 'group' => 'نمای کلی', 'view' => 'dashboard', 'perm' => 'dashboard'],
        'users' => ['label' => 'کاربران و دسترسی', 'icon' => 'users', 'group' => 'کاربران', 'view' => 'users', 'perm' => 'users'],
        'admins' => ['label' => 'مدیران و نقش‌ها', 'icon' => 'shield', 'group' => 'کاربران', 'view' => 'admins', 'perm' => 'admins'],
        'doctors' => ['label' => 'پزشکان و احراز هویت', 'icon' => 'doctor', 'group' => 'پزشکان و خدمات', 'view' => 'doctors', 'perm' => 'doctors'],
        'centers' => ['label' => 'کلینیک‌ها و مراکز', 'icon' => 'building', 'group' => 'پزشکان و خدمات', 'view' => 'centers', 'perm' => 'centers'],
        'specialties' => ['label' => 'تخصص‌ها و خدمات', 'icon' => 'sp-stethoscope', 'group' => 'پزشکان و خدمات', 'view' => 'specialties', 'perm' => 'catalog'],
        'booking' => ['label' => 'قوانین رزرو', 'icon' => 'calendar', 'group' => 'پزشکان و خدمات', 'view' => 'booking', 'perm' => 'catalog'],
        'provinces' => ['label' => 'استان‌ها', 'icon' => 'map', 'group' => 'جغرافیا', 'view' => 'provinces', 'perm' => 'catalog'],
        'cities' => ['label' => 'شهرها', 'icon' => 'pin', 'group' => 'جغرافیا', 'view' => 'cities', 'perm' => 'catalog'],
        'subscriptions' => ['label' => 'اشتراک پرو', 'icon' => 'receipt', 'group' => 'مالی', 'view' => 'subscriptions', 'perm' => 'finance'],
        'settings' => ['label' => 'کارمزد رزرو', 'icon' => 'wallet', 'group' => 'مالی', 'view' => 'settings', 'perm' => 'finance'],
        'payments' => ['label' => 'تراکنش‌ها و استرداد', 'icon' => 'receipt', 'group' => 'مالی', 'view' => 'payments', 'perm' => 'finance'],
        'gateways' => ['label' => 'درگاه‌های پرداخت', 'icon' => 'card', 'group' => 'مالی', 'view' => 'gateways', 'perm' => 'gateways'],
        'support' => ['label' => 'تیکت‌های پشتیبانی', 'icon' => 'chat', 'group' => 'ارتباطات', 'view' => 'support', 'perm' => 'support'],
        'announcements' => ['label' => 'اطلاعیه‌های سراسری', 'icon' => 'megaphone', 'group' => 'ارتباطات', 'view' => 'announcements', 'perm' => 'communications'],
        'notifications' => ['label' => 'یادآوری، اعلان و صف', 'icon' => 'bell', 'group' => 'ارتباطات', 'view' => 'notifications', 'perm' => 'communications'],
        'sms_templates' => ['label'=>'الگوهای پیامک','icon'=>'sms','group'=>'ارتباطات','view'=>'sms_templates','perm'=>'communications'],
        'sms_send' => ['label'=>'ارسال پیامک','icon'=>'sms','group'=>'ارتباطات','view'=>'sms_send','perm'=>'communications'],
        'wallets' => ['label'=>'کیف پول و درخواست واریز','icon'=>'wallet','group'=>'مالی','view'=>'wallets','perm'=>'finance'],
        'admin_notify' => ['label'=>'اعلان‌های مدیریت','icon'=>'bell','group'=>'ارتباطات','view'=>'admin_notify','perm'=>'dashboard'],
        'sms' => ['label' => 'کاوه‌نگار و پیامک', 'icon' => 'sms', 'group' => 'ارتباطات', 'view' => 'sms', 'perm' => 'communications'],
        'home' => ['label' => 'مدیریت صفحه اصلی', 'icon' => 'home', 'group' => 'محتوا', 'view' => 'home', 'perm' => 'content'],
        'content' => ['label' => 'اسلایدر و نوار اطلاع‌رسانی', 'icon' => 'image', 'group' => 'محتوا', 'view' => 'content', 'perm' => 'content'],
        'articles' => ['label' => 'مقالات سلامت', 'icon' => 'article', 'group' => 'محتوا', 'view' => 'articles', 'perm' => 'content'],
        'search' => ['label' => 'تنظیمات جستجو و کارت پزشک', 'icon' => 'filter', 'group' => 'محتوا', 'view' => 'search', 'perm' => 'content'],
        'reviews' => ['label' => 'نظرات بیماران', 'icon' => 'star', 'group' => 'محتوا', 'view' => 'reviews', 'perm' => 'content'],
        'general' => ['label' => 'تنظیمات عمومی', 'icon' => 'settings', 'group' => 'سامانه', 'view' => 'general', 'perm' => 'system'],
        'cron' => ['label' => 'زمان‌بندی (Cron)', 'icon' => 'clock', 'group' => 'سامانه', 'view' => 'cron', 'perm' => 'system'],
        'audit' => ['label' => 'گزارش فعالیت', 'icon' => 'shield', 'group' => 'سامانه', 'view' => 'audit', 'perm' => 'system'],
        'security' => ['label' => 'امنیت و پشتیبان', 'icon' => 'lock', 'group' => 'سامانه', 'view' => 'security', 'perm' => 'system'],
        'update' => ['label' => 'به‌روزرسانی سامانه', 'icon' => 'download', 'group' => 'سامانه', 'view' => 'update', 'perm' => 'admins'],
        'account' => ['label' => 'حساب من', 'icon' => 'user', 'group' => '', 'view' => 'account', 'perm' => null],
    ];
}

/** Sections the signed-in administrator may open. */
function admin_visible_sections(): array
{
    return array_filter(admin_sections(), fn($s) => $s['perm'] === null || admin_can($s['perm']));
}

/* ---------- Administrator sign-in (1.0.0-rc.13): SMS code to the administrator's mobile, then e-mail and password ---------- */

/** Two-step sign-in is on whenever SMS can be sent (Kavenegar configured, or test mode on a development copy). */
function admin_sms_login_on(): bool
{
    return sms_enabled() || sms_dev_mode();
}

/** Where the visitor is in the sign-in: mobile → code → password, or (no mobile yet) password → register → code. */
function admin_auth_state(): array
{
    $st = $_SESSION['admin_auth'] ?? null;
    if (!is_array($st) || time() - (int) ($st['at'] ?? 0) > 600) {
        unset($_SESSION['admin_auth']);
        return ['step' => 'mobile'];
    }
    return $st;
}

function admin_auth_reset(): void
{
    unset($_SESSION['admin_auth']);
}

/**
 * Sends the sign-in code. The answer is the same whether or not the number belongs to an administrator
 * (no number enumeration); a code is only sent to an active administrator's own mobile.
 * $purpose 'register': an administrator without a mobile (after the password) confirms a new number.
 */
function admin_otp_start(string $mobile, string $purpose = 'login'): void
{
    if (!admin_sms_login_on()) {
        throw new RuntimeException('ارسال پیامک فعال نیست؛ با ایمیل و رمز وارد شوید.');
    }
    throttle('admin_otp', 10, 900);
    $mobile = normalize_mobile($mobile);
    $st = admin_auth_state();
    if ($purpose === 'register') {
        if (($st['step'] ?? '') !== 'register' && ($st['purpose'] ?? '') !== 'register') {
            throw new RuntimeException('ابتدا با ایمیل و رمز وارد شوید.');
        }
        $adminId = (int) $st['admin'];
        if (query('SELECT id FROM admins WHERE mobile=? AND id<>?', [$mobile, $adminId])->fetch()) {
            throw new RuntimeException('این شماره برای مدیر دیگری ثبت شده است.');
        }
        $challenge = otp_request($mobile)['challenge_id'];
        $_SESSION['admin_auth'] = ['step' => 'code', 'purpose' => 'register', 'admin' => $adminId, 'mobile' => $mobile, 'challenge' => $challenge, 'at' => time()];
        return;
    }
    $admin = query('SELECT id FROM admins WHERE mobile=? AND active=1', [$mobile])->fetch();
    $challenge = '';
    if ($admin) {
        $challenge = otp_request($mobile)['challenge_id'];
    } else {
        // Same visible result as for a real administrator; nothing is sent and the code can never match.
        rate_limit('admin-otp-unknown:' . client_ip(), 10, 3600);
        security_event('admin.otp_unknown', mask_mobile($mobile));
    }
    $_SESSION['admin_auth'] = ['step' => 'code', 'purpose' => 'login', 'mobile' => $mobile, 'challenge' => $challenge, 'misses' => 0, 'at' => time()];
}

/** Checks the code. Sign-in: the next step asks for the e-mail and password. Register: saves the number and signs in. */
function admin_otp_confirm(string $code): void
{
    $st = admin_auth_state();
    if (($st['step'] ?? '') !== 'code') {
        throw new RuntimeException('ابتدا شماره موبایل را وارد کنید.');
    }
    if ($st['challenge'] === '') {
        // Unknown number: behave like a wrong code, and start over after the usual number of attempts.
        rate_limit('otp:verify-ip:' . client_ip(), 40, 900);
        $_SESSION['admin_auth']['misses'] = (int) ($st['misses'] ?? 0) + 1;
        $left = otp_settings()['attempts'] - $_SESSION['admin_auth']['misses'];
        if ($left <= 0) {
            admin_auth_reset();
            throw new RuntimeException('کد تأیید اشتباه است. کد جدید دریافت کنید.');
        }
        throw new RuntimeException('کد تأیید اشتباه است. ' . fa_number($left) . ' بار دیگر می‌توانید تلاش کنید.');
    }
    $mobile = otp_consume($st['challenge'], $code);
    if ($st['purpose'] === 'register') {
        $a = query('SELECT * FROM admins WHERE id=? AND active=1', [(int) $st['admin']])->fetch();
        if (!$a || $mobile !== $st['mobile']) {
            admin_auth_reset();
            throw new RuntimeException('ورود دوباره را از ابتدا انجام دهید.');
        }
        query('UPDATE admins SET mobile=? WHERE id=?', [$mobile, $a['id']]);
        audit('admin.mobile_registered', (int) $a['id'], mask_mobile($mobile));
        admin_login_finish($a);
        return;
    }
    $a = query('SELECT id FROM admins WHERE mobile=? AND active=1', [$mobile])->fetch();
    if (!$a) {
        admin_auth_reset();
        throw new RuntimeException('ورود دوباره را از ابتدا انجام دهید.');
    }
    $_SESSION['admin_auth'] = ['step' => 'password', 'admin' => (int) $a['id'], 'mobile' => $mobile, 'at' => time()];
}

/**
 * E-mail and password. With SMS on, only after the code of the same administrator's mobile (10 minutes);
 * an administrator who has no mobile yet goes on to register one. Without SMS: password only, as before.
 * Returns 'ok' (signed in) or 'register'.
 */
function admin_login(string $email, string $password): string
{
    throttle('admin_login', 10, 900);
    $a = query('SELECT * FROM admins WHERE email=?', [mb_strtolower(field(['email' => $email], 'email', 190))])->fetch();
    // A dummy hash keeps the response time the same whether or not the e-mail exists.
    $ok = password_verify($password, $a ? $a['password_hash'] : '$2y$10$WXz5Ff7jFtrYPxwTn6lnyeBcywDYa9PnIoPDwdAO1142aNLUWB3oW');
    if (!$a || !$ok) {
        security_event('admin.login_failed', mb_substr(mb_strtolower(trim($email)), 0, 120));
        throw new RuntimeException('اطلاعات ورود اشتباه است.');
    }
    if (empty($a['active'])) {
        throw new RuntimeException('این حساب مدیریت غیرفعال است.');
    }
    if (admin_sms_login_on()) {
        $st = admin_auth_state();
        $confirmed = ($st['step'] ?? '') === 'password' && (int) $st['admin'] === (int) $a['id'];
        if (!$confirmed) {
            if (trim((string) ($a['mobile'] ?? '')) === '') {
                $_SESSION['admin_auth'] = ['step' => 'register', 'admin' => (int) $a['id'], 'at' => time()];
                audit('admin.mobile_required', (int) $a['id']);
                return 'register';
            }
            // Same message as a wrong password: the second step only counts after this administrator's own code.
            security_event('admin.login_without_code', mb_substr(mb_strtolower(trim($email)), 0, 120));
            throw new RuntimeException('اطلاعات ورود اشتباه است.');
        }
    }
    if (password_needs_rehash($a['password_hash'], PASSWORD_DEFAULT)) {
        query('UPDATE admins SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $a['id']]);
    }
    admin_login_finish($a);
    return 'ok';
}

/** New session for the signed-in administrator (prevents session fixation), fresh CSRF token. */
function admin_login_finish(array $a): void
{
    admin_auth_reset();
    session_regenerate_id(true);
    // rc.18: a patient/doctor sign-in in the same browser is independent and is kept.
    $_SESSION['admin_id'] = (int) $a['id'];
    $_SESSION['admin_version'] = (int) $a['session_version'];
    $_SESSION['admin_login_at'] = time();
    query('UPDATE admins SET last_login_at=NOW() WHERE id=?', [$a['id']]);
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    audit('admin.login', null, admin_sms_login_on() ? 'sms+password' : 'password');
}

/** After a successful POST return to the same section, keeping the item being edited. */
function admin_return_url(string $tab): string
{
    $keep = array_intersect_key($_GET, array_flip(['doctor', 'user', 'province', 'q', 'status', 'page', 'edit', 'clinic', 'view', 'from', 'to', 'gateway', 'purpose', 'center', 'city', 'ticket', 'category', 'range', 'admin']));
    if ($tab === 'home' && isset($_POST['return_view'])) {
        $keep['view'] = (string) $_POST['return_view'];
    }
    $keep = array_filter($keep, fn($v) => is_scalar($v) && $v !== '');
    return 'admin.php?' . http_build_query(['tab' => $tab] + $keep);
}

function admin_view_data(string $tab, string $error): array
{
    $data = ['error' => $error, 'post' => $error ? $_POST : []];
    switch ($tab) {
        case 'dashboard':
            return $data + admin_dashboard_data();
        case 'doctors':
            return $data + admin_doctors_data();
        case 'specialties':
            $edit = !empty($_GET['edit']) ? query('SELECT * FROM specialties WHERE id=?', [(int) $_GET['edit']])->fetch() : [];
            return $data + [
                'edit' => $error && ($_POST['action'] ?? '') === 'taxonomy' ? $_POST : ($edit ?: []),
                'rows' => specialties_list(false),
                'archived' => query('SELECT * FROM specialties WHERE deleted_at IS NOT NULL ORDER BY name')->fetchAll(),
                'parents' => query(
                    "SELECT id,name FROM specialties WHERE deleted_at IS NULL AND level IN ('specialty','general') ORDER BY sort_order,name",
                )->fetchAll(),
                'usage' => query(
                    'SELECT specialty_id,COUNT(*) n FROM doctors GROUP BY specialty_id',
                )->fetchAll(PDO::FETCH_KEY_PAIR),
                // Services are defined by each doctor; this is a read-only overview across the platform.
                'services' => query(
                    'SELECT s.name,COUNT(DISTINCT s.doctor_id) doctors,MIN(s.duration_minutes) min_d,MAX(s.duration_minutes) max_d,SUM(s.active) active FROM doctor_services s GROUP BY s.name ORDER BY doctors DESC,s.name LIMIT 60',
                )->fetchAll(),
            ];
        case 'provinces':
            $edit = !empty($_GET['edit']) ? query('SELECT * FROM provinces WHERE id=?', [(int) $_GET['edit']])->fetch() : [];
            return $data + [
                'edit' => $error && ($_POST['action'] ?? '') === 'taxonomy' ? $_POST : ($edit ?: []),
                'rows' => query(
                    'SELECT p.*,(SELECT COUNT(*) FROM cities c WHERE c.province_id=p.id) cities,(SELECT COUNT(*) FROM cities c WHERE c.province_id=p.id AND c.active=1) active_cities FROM provinces p ORDER BY p.sort_order,p.name',
                )->fetchAll(),
            ];
        case 'cities':
            return $data + admin_cities_data($error);
        case 'users':
            return $data + admin_users_data();
        case 'sms':
            return $data + admin_sms_data();
        case 'settings':
            return $data + admin_fee_data();
        case 'gateways':
            return $data + admin_gateways_data();
        case 'payments':
            return $data + admin_payments_data();
        case 'subscriptions':
            return $data + admin_subscriptions_data();
        case 'centers':
            return $data + admin_centers_data();
        case 'notifications':
            return $data + [
                'pushStats' => query("SELECT status,COUNT(*) n FROM push_messages WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR),
                'devices' => (int) query('SELECT COUNT(*) FROM push_subscriptions WHERE active=1')->fetchColumn(),
                'pushFailures' => query("SELECT m.created_at,m.http_status,m.error FROM push_messages m WHERE m.status='failed' ORDER BY m.id DESC LIMIT 10")->fetchAll(),
                'waitlist' => query('SELECT status,COUNT(*) n FROM waitlist_entries GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR),
                'reminders7' => (int) query("SELECT COUNT(*) FROM sms_messages WHERE event='booking_reminder' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn(),
                'templates' => query("SELECT event,template,enabled,delivery_mode,body FROM sms_templates WHERE event IN ('booking_reminder','waitlist_available','visit_time_changed','delay')")->fetchAll(PDO::FETCH_UNIQUE),
            ];
        case 'cron':
            return $data + [
                'tasks' => cron_tasks(),
                'status' => cron_status(),
                'token' => (string) (config()['cron_token'] ?? ''),
                'lastRun' => setting('cron_last_run', ''),
            ];
        case 'audit':
            return $data + admin_audit_data();
        case 'support':
            return $data + admin_tickets_data();
        case 'security':
            return $data + [
                'checks' => security_checks(),
                'backups' => admin_can('admins') ? backup_list() : [],
                'canBackup' => admin_can('admins'),
                'auto' => setting('backup_auto', '0') === '1',
                'keep' => setting('backup_keep', '7'),
                'securityLog' => log_tail('security', 25),
                'errorLog' => log_tail('error', 25),
                'events' => query("SELECT l.*,a.email admin_email FROM audit_log l LEFT JOIN admins a ON a.id=l.actor_admin_id WHERE l.action IN ('admin.denied','auth.suspicious_ip','backup.restore_denied','admin.login_failed','auth.locked','auth.inactive_login') ORDER BY l.id DESC LIMIT 20")->fetchAll(),
            ];
        case 'update':
            $pending = update_pending();
            return $data + [
                'checks' => update_checks(),
                'ready' => update_ready(),
                'limit' => update_upload_limit(),
                'pending' => $pending,
                'plan' => $pending ? update_plan($pending) : null,
                'rollbacks' => update_rollbacks(),
                'history' => update_history(),
            ];
        case 'content':
            return $data + admin_content_data();
        case 'home':
            return $data + admin_home_data();
        case 'articles':
            return $data + admin_articles_data();
        case 'search':
            return $data + [
                'cfg' => search_config(),
                'cache' => query('SELECT COUNT(*) n,SUM(first_at IS NOT NULL) free,MIN(computed_at) oldest FROM clinic_free_cache')->fetch(),
                'genders' => query("SELECT COUNT(*) total,SUM(gender IS NOT NULL) known FROM doctors WHERE review_status='approved'")->fetch(),
                'districts' => (int) query("SELECT COUNT(*) FROM clinics WHERE district<>''")->fetchColumn(),
            ];
        case 'reviews':
            $status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'hidden'], true) ? $_GET['status'] : 'pending';
            return $data + [
                'status' => $status,
                'counts' => query('SELECT status,COUNT(*) FROM reviews GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR),
                'rows' => query('SELECT r.*,d.name doctor,u.first_name,u.mobile FROM reviews r JOIN doctors d ON d.id=r.doctor_id JOIN users u ON u.id=r.user_id WHERE r.status=? ORDER BY r.id DESC LIMIT 100', [$status])->fetchAll(),
            ];
        case 'announcements':
        case 'sms_send':
            // 1.0.27: both pages target the same audiences; a city segment is counted once its city is chosen.
            $keys = array_diff(array_keys(announcement_audiences()), ['doctors_city']);
            return $data + [
                'rows' => $tab === 'announcements' ? query('SELECT a.*,ad.name admin_name,ad.email admin_email FROM admin_announcements a LEFT JOIN admins ad ON ad.id=a.created_by_admin ORDER BY a.id DESC LIMIT 50')->fetchAll() : [],
                'audiences' => announcement_audiences(),
                'segments' => bulk_sms_segments(),
                'provinces' => provinces_list(),
                'counts' => array_combine($keys, array_map(fn($k) => (int) query('SELECT COUNT(*) FROM (' . announcement_audience_sql($k) . ') x', [0])->fetchColumn(), $keys)),
            ];
        case 'general':
            return $data + [
                'values' => array_combine(
                    $keys = ['site_name', 'support_phone', 'support_email', 'support_hours', 'footer_text', 'ticket_max_open', 'support_fab'],
                    array_map(fn($k) => $error && isset($_POST[$k]) ? (string) $_POST[$k] : setting($k), $keys),
                ),
            ];
        case 'admins':
            $edit = !empty($_GET['admin']) ? (query('SELECT id,email,name,role,active,mobile FROM admins WHERE id=?', [(int) $_GET['admin']])->fetch() ?: []) : [];
            return $data + [
                'rows' => query('SELECT id,email,name,role,active,mobile,last_login_at,created_at FROM admins ORDER BY active DESC,id')->fetchAll(),
                'edit' => $edit,
                'roles' => admin_roles(),
                'permissions' => admin_permissions(),
            ];
        case 'account':
            return $data + ['me' => admin_current(), 'role' => admin_roles()[admin_current()['role']] ?? null, 'permissions' => admin_permissions()];
        default:
            return $data;
    }
}

function admin_doctors_data(): array
{
    $doctorId = (int) ($_GET['doctor'] ?? 0);
    if ($doctorId) {
        $doctor = query('SELECT * FROM doctors WHERE id=?', [$doctorId])->fetch();
        if (!$doctor) {
            return ['doctor' => null, 'notFound' => true];
        }
        $clinics = query(
            'SELECT cl.*,c.province_id,c.name city FROM clinics cl JOIN cities c ON c.id=cl.city_id WHERE cl.doctor_id=? ORDER BY cl.is_primary DESC,cl.id',
            [$doctorId],
        )->fetchAll();
        $editClinic = [];
        foreach ($clinics as $cl) {
            if ((int) $cl['id'] === (int) ($_GET['clinic'] ?? 0)) {
                $editClinic = $cl;
            }
        }
        return [
            'doctor' => $doctor,
            'clinics' => $clinics,
            'editClinic' => $editClinic,
            'account' => query(
                'SELECT u.id,u.mobile,u.name FROM doctor_accounts a JOIN users u ON u.id=a.user_id WHERE a.doctor_id=?',
                [$doctorId],
            )->fetch() ?: null,
            'slots' => query(
                'SELECT s.*,cl.name clinic FROM slots s JOIN clinics cl ON cl.id=s.clinic_id WHERE s.doctor_id=? AND s.starts_at>NOW() ORDER BY s.starts_at LIMIT 100',
                [$doctorId],
            )->fetchAll(),
            'events' => query(
                'SELECT * FROM appointment_events WHERE doctor_id=? AND booking_id IS NULL ORDER BY id DESC LIMIT 20',
                [$doctorId],
            )->fetchAll(),
        ];
    }
    $filters = [
        'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 190),
        'status' => (string) ($_GET['status'] ?? ''),
        'specialty' => (int) ($_GET['specialty'] ?? 0),
        'province' => (int) ($_GET['province'] ?? 0),
        'city' => (int) ($_GET['city'] ?? 0),
        'subscription' => (string) ($_GET['subscription'] ?? ''),
    ];
    $deletedView = $filters['status'] === 'deleted';
    if (!isset(doctor_status_labels()[$filters['status']])) {
        $filters['status'] = '';
    }
    // rc.21: deleted doctors are not listed; 1.0.26: «حذف‌شده» lists only them (for «حذف دائمی»).
    $where = [$deletedView ? 'd.deleted_at IS NOT NULL' : 'd.deleted_at IS NULL'];
    if ($deletedView) {
        $filters['status'] = 'deleted';
    }
    $args = [];
    if ($filters['q'] !== '') {
        $q = fa_normalize($filters['q']);
        $where[] = '(d.name LIKE ? OR d.mobile LIKE ? OR d.medical_code LIKE ?)';
        array_push($args, '%' . $q . '%', '%' . $q . '%', '%' . ltrim($q, '0') . '%');
    }
    if ($filters['status'] && !$deletedView) {
        $where[] = 'd.review_status=?';
        $args[] = $filters['status'];
    }
    if ($filters['specialty']) {
        $where[] = 'd.specialty_id=?';
        $args[] = $filters['specialty'];
    }
    if ($filters['city']) {
        $where[] = 'EXISTS(SELECT 1 FROM clinics x WHERE x.doctor_id=d.id AND x.city_id=?)';
        $args[] = $filters['city'];
    } elseif ($filters['province']) {
        $where[] = 'EXISTS(SELECT 1 FROM clinics x JOIN cities xc ON xc.id=x.city_id WHERE x.doctor_id=d.id AND xc.province_id=?)';
        $args[] = $filters['province'];
    }
    if ($filters['subscription'] === 'active') {
        $where[] = 'd.subscription_until>=CURDATE()';
    } elseif ($filters['subscription'] === 'expired') {
        $where[] = 'd.subscription_until<CURDATE()';
    } elseif ($filters['subscription'] === 'none') {
        $where[] = 'd.subscription_until IS NULL';
    } else {
        $filters['subscription'] = '';
    }
    $sql = ' FROM doctors d LEFT JOIN specialties sp ON sp.id=d.specialty_id WHERE ' . implode(' AND ', $where);
    $total = (int) query('SELECT COUNT(*)' . $sql, $args)->fetchColumn();
    $page = max(1, min((int) ($_GET['page'] ?? 1), max(1, (int) ceil($total / 30))));
    return [
        'doctor' => null,
        'filters' => $filters,
        'total' => $total,
        'page' => $page,
        'rows' => query(
            'SELECT d.*,sp.name specialty_name,(SELECT GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR \'، \') FROM clinics x JOIN cities c ON c.id=x.city_id WHERE x.doctor_id=d.id) city_names' .
                $sql .
                ' ORDER BY d.id DESC LIMIT 30 OFFSET ' .
                ($page - 1) * 30,
            $args,
        )->fetchAll(),
        'statusCounts' => query('SELECT review_status,COUNT(*) n FROM doctors WHERE deleted_at IS NULL GROUP BY review_status')->fetchAll(PDO::FETCH_KEY_PAIR) + ['deleted' => (int) query('SELECT COUNT(*) FROM doctors WHERE deleted_at IS NOT NULL')->fetchColumn()],
    ];
}

function admin_cities_data(string $error): array
{
    $province = (int) ($_GET['province'] ?? 0);
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
    $edit = !empty($_GET['edit']) ? query('SELECT * FROM cities WHERE id=?', [(int) $_GET['edit']])->fetch() : [];
    if ($error && ($_POST['action'] ?? '') === 'taxonomy') {
        $edit = $_POST;
    }
    if ($edit && !$province) {
        $province = (int) ($edit['province_id'] ?? 0);
    }
    $rows = [];
    if ($q !== '') {
        $rows = query(
            'SELECT c.*,p.name province_name,(SELECT COUNT(*) FROM clinics x WHERE x.city_id=c.id) clinics FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.name LIKE ? ORDER BY p.sort_order,c.sort_order LIMIT 100',
            ['%' . fa_normalize($q) . '%'],
        )->fetchAll();
    } elseif ($province) {
        $rows = query(
            'SELECT c.*,p.name province_name,(SELECT COUNT(*) FROM clinics x WHERE x.city_id=c.id) clinics FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.province_id=? ORDER BY c.sort_order,c.name',
            [$province],
        )->fetchAll();
    }
    return [
        'province' => $province,
        'q' => $q,
        'edit' => $edit ?: [],
        'rows' => $rows,
        'provinces' => provinces_list(false),
        'geo' => [
            'radius' => setting('nearby_radius_km', '60'),
            'tiles' => setting('map_tile_url', ''),
            'attribution' => setting('map_attribution', ''),
        ],
    ];
}

function admin_users_data(): array
{
    $uid = (int) ($_GET['user'] ?? 0);
    if ($uid) {
        $u = query('SELECT * FROM users WHERE id=?', [$uid])->fetch();
        if (!$u) {
            return ['user' => null, 'notFound' => true];
        }
        return [
            'user' => $u,
            'roles' => roles($uid),
            'doctor' => my_doctor($uid),
            'linkable' => query(
                'SELECT d.id,d.name FROM doctors d LEFT JOIN doctor_accounts a ON a.doctor_id=d.id WHERE d.mobile=? AND a.user_id IS NULL',
                [$u['mobile']],
            )->fetchAll(),
            'scopes' => query(
                'SELECT s.*,cl.name clinic,d.name doctor FROM staff_scopes s JOIN clinics cl ON cl.id=s.clinic_id JOIN doctors d ON d.id=cl.doctor_id WHERE s.user_id=?',
                [$uid],
            )->fetchAll(),
            'clinics' => query(
                "SELECT cl.id,CONCAT(d.name,' — ',cl.name) name FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id ORDER BY d.name,cl.name",
            )->fetchAll(),
        ];
    }
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 190);
    $role = (string) ($_GET['role'] ?? '');
    $where = 'u.deleted_at IS NULL AND (u.name LIKE ? OR u.mobile LIKE ?)'; // rc.21: deleted accounts are not listed
    $args = ['%' . $q . '%', '%' . digits($q) . '%'];
    if (in_array($role, ['patient', 'doctor', 'secretary', 'clinic'], true)) {
        $where .= ' AND EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND r.role=?)';
        $args[] = $role;
    } else {
        $role = '';
    }
    $total = (int) query('SELECT COUNT(*) FROM users u WHERE ' . $where, $args)->fetchColumn();
    $page = max(1, (int) ($_GET['page'] ?? 1));
    return [
        'user' => null,
        'q' => $q,
        'role' => $role,
        'page' => $page,
        'total' => $total,
        'rows' => query(
            "SELECT u.*,(SELECT GROUP_CONCAT(role) FROM user_roles r WHERE r.user_id=u.id) role_list FROM users u WHERE $where ORDER BY u.id DESC LIMIT 30 OFFSET " .
                ($page - 1) * 30,
            $args,
        )->fetchAll(),
    ];
}

function admin_sms_data(): array
{
    $status = (string) ($_GET['status'] ?? '');
    $event = (string) ($_GET['event'] ?? '');
    $where = ['1=1'];
    $args = [];
    if ($status !== '' && sms_status_label($status) !== 'نامشخص') {
        $where[] = 'status=?';
        $args[] = $status;
    } else {
        $status = '';
    }
    if (isset(sms_events()[$event])) {
        $where[] = 'event=?';
        $args[] = $event;
    } else {
        $event = '';
    }
    $templates = [];
    foreach (query('SELECT * FROM sms_templates')->fetchAll() as $t) {
        $templates[$t['event']] = $t;
    }
    return [
        'settings' => [
            'enabled' => sms_enabled(),
            'dev' => sms_dev_mode(),
            'environment' => app_environment(),
            'keyHint' => secret_hint('kavenegar_api_key'),
            'daily_limit' => setting('sms_daily_limit', '500'),
            'otp' => otp_settings(),
            'max_attempts' => setting('sms_max_attempts', '3'),
            'legacyFile' => is_file(dirname(__DIR__) . '/private/sms.php'),
        ],
        'account' => sms_account_cached(),
        'events' => sms_events(),
        'templates' => $templates,
        'filters' => ['status' => $status, 'event' => $event],
        'today' => query(
            "SELECT status,COUNT(*) n FROM sms_messages WHERE created_at>=CURDATE() GROUP BY status",
        )->fetchAll(PDO::FETCH_KEY_PAIR),
        'logs' => query(
            'SELECT * FROM sms_messages WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 60',
            $args,
        )->fetchAll(),
        'otps' => query(
            'SELECT mobile,delivery_status,created_at,attempts,consumed_at FROM otp_challenges ORDER BY created_at DESC LIMIT 20',
        )->fetchAll(),
        // Sign-in errors and sign-ups; details hold masked numbers only, never codes.
        'authEvents' => query(
            "SELECT action,detail,ip,created_at FROM audit_log WHERE action IN ('auth.otp_failed','auth.otp_rejected','auth.inactive_login','auth.suspicious_ip','auth.signup') ORDER BY id DESC LIMIT 40",
        )->fetchAll(),
    ];
}

/** Jalali date-range filter: ?from=۱۴۰۵/۰۷/۰۱&to=... ; defaults to the start of the current Jalali month. */
function admin_date_range(): array
{
    $today = date('Y-m-d');
    $parts = explode('/', digits(jdate($today)));
    $monthStart = parse_jdate($parts[0] . '/' . $parts[1] . '/01');
    try {
        $from = trim((string) ($_GET['from'] ?? '')) !== '' ? parse_jdate((string) $_GET['from']) : $monthStart;
        $to = trim((string) ($_GET['to'] ?? '')) !== '' ? parse_jdate((string) $_GET['to']) : $today;
    } catch (RuntimeException $e) {
        [$from, $to] = [$monthStart, $today];
    }
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

function admin_subscriptions_data(): array
{
    $view = (string) ($_GET['view'] ?? 'overview');
    if (!in_array($view, ['overview', 'manual', 'pro', 'plans', 'codes', 'orders', 'report', 'settings'], true)) {
        $view = 'overview';
    }
    $data = ['view' => $view];
    if ($view === 'manual') {
        $data['doctors'] = query('SELECT id,name FROM doctors ORDER BY name')->fetchAll();
        $id = (int) ($_GET['doctor'] ?? 0);
        $data['d'] = $id ? (query('SELECT * FROM doctors WHERE id=?', [$id])->fetch() ?: null) : null;
    }
    if ($view === 'pro') {
        // rc.20: feature catalog (which features are Pro-only), free-plan limits, end-of-day SMS hour.
        $data['proStats'] = query("SELECT COUNT(*) total,SUM(subscription_until IS NOT NULL AND DATE_ADD(subscription_until,INTERVAL " . grace_days() . " DAY)>=CURDATE()) pro,SUM(plan_choice='pro') picked FROM doctors WHERE review_status='approved'")->fetch();
    }
    if ($view === 'overview') {
        $data['expiring'] = query(
            "SELECT d.id,d.name,d.mobile,d.subscription_until,DATEDIFF(d.subscription_until,CURDATE()) days_left FROM doctors d WHERE d.review_status='approved' AND d.subscription_until BETWEEN DATE_SUB(CURDATE(),INTERVAL ? DAY) AND DATE_ADD(CURDATE(),INTERVAL 14 DAY) ORDER BY d.subscription_until",
            [grace_days()],
        )->fetchAll();
        $data['counts'] = query(
            "SELECT SUM(subscription_until>=CURDATE()) active,SUM(subscription_until<CURDATE() AND DATE_ADD(subscription_until,INTERVAL " . grace_days() . " DAY)>=CURDATE()) grace,SUM(subscription_until<DATE_SUB(CURDATE(),INTERVAL " . grace_days() . " DAY)) expired,SUM(subscription_until IS NULL) none FROM doctors WHERE review_status='approved'",
        )->fetch();
        $data['month'] = subscription_revenue(...admin_date_range())['totals'];
        $data['doctors'] = query("SELECT id,name FROM doctors WHERE review_status IN ('approved','suspended') ORDER BY name")->fetchAll();
    } elseif ($view === 'plans') {
        $data['plans'] = plans_list(false, null);
        $data['edit'] = !empty($_GET['edit']) ? (query('SELECT * FROM subscription_plans WHERE id=?', [(int) $_GET['edit']])->fetch() ?: []) : [];
    } elseif ($view === 'codes') {
        $data['codes'] = query(
            "SELECT c.*,d.name doctor_name,p.name plan_name,(SELECT COUNT(*) FROM discount_redemptions r WHERE r.code_id=c.id AND r.status='used') used,(SELECT COUNT(*) FROM discount_redemptions r WHERE r.code_id=c.id AND r.status='reserved') reserved FROM discount_codes c LEFT JOIN doctors d ON d.id=c.doctor_id LEFT JOIN subscription_plans p ON p.id=c.plan_id ORDER BY c.id DESC",
        )->fetchAll();
        $data['edit'] = !empty($_GET['edit']) ? (query('SELECT * FROM discount_codes WHERE id=?', [(int) $_GET['edit']])->fetch() ?: []) : [];
        $data['plans'] = plans_list(false, null);
        $data['doctors'] = query("SELECT id,name FROM doctors ORDER BY name")->fetchAll();
    } elseif ($view === 'orders') {
        $status = (string) ($_GET['status'] ?? '');
        $where = in_array($status, ['proforma', 'paid', 'cancelled', 'expired'], true) ? 'WHERE o.status=?' : '';
        $data['status'] = $where ? $status : '';
        $data['orders'] = query(
            'SELECT o.*,d.name doctor_name FROM subscription_orders o JOIN doctors d ON d.id=o.doctor_id ' . $where . ' ORDER BY o.id DESC LIMIT 100',
            $where ? [$status] : [],
        )->fetchAll();
        $data['plans'] = plans_list(false);
        $data['doctors'] = query("SELECT id,name FROM doctors WHERE review_status='approved' ORDER BY name")->fetchAll();
    } elseif ($view === 'report') {
        [$from, $to] = admin_date_range();
        $province = (int) ($_GET['province'] ?? 0);
        $data += ['from' => $from, 'to' => $to, 'province' => $province, 'report' => subscription_revenue($from, $to, $province), 'provinces' => provinces_list(false)];
    }
    return $data;
}

/* ---------- Payments: fees, gateways, transactions ---------- */

function admin_fee_data(): array
{
    return [
        'rules' => query(
            "SELECT r.*,CASE r.scope WHEN 'city' THEN (SELECT CONCAT(p.name,' / ',c.name) FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.id=r.target_id) ELSE (SELECT d.name FROM doctors d WHERE d.id=r.target_id) END target FROM booking_fee_rules r ORDER BY r.scope,r.id DESC",
        )->fetchAll(),
        'doctors' => query("SELECT id,name FROM doctors WHERE review_status='approved' ORDER BY name")->fetchAll(),
        'provinces' => provinces_list(),
        'available' => payment_available(),
    ];
}

function admin_gateways_data(): array
{
    $rows = [];
    foreach (payment_gateways() as $name => $meta) {
        $row = gateway_row($name);
        $rows[$name] = [
            'meta' => $meta,
            'row' => $row,
            'config' => gateway_config($name),
            'configured' => gateway_configured($name),
            'verified' => gateway_verified($row),
            'usable' => gateway_usable($name),
        ];
    }
    return [
        'gateways' => $rows,
        'default' => setting('payment_default_gateway'),
        'fallback' => setting('payment_fallback_gateway'),
        'development' => app_environment() === 'development',
        'callback' => app_base_url() . 'pay-return.php',
        'tests' => query(
            "SELECT * FROM payment_transactions WHERE purpose='gateway_test' ORDER BY id DESC LIMIT 10",
        )->fetchAll(),
    ];
}

/** Filters shared by the transactions list and its Excel export. */
function admin_payment_filters(): array
{
    [$from, $to] = admin_date_range();
    $where = ['t.created_at BETWEEN ? AND ?'];
    $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $filters = ['from' => $from, 'to' => $to, 'status' => '', 'gateway' => '', 'purpose' => '', 'q' => ''];
    foreach (['status' => array_keys(payment_status_labels()), 'gateway' => array_keys(payment_gateways()), 'purpose' => ['booking', 'subscription', 'gateway_test']] as $key => $allowed) {
        $value = (string) ($_GET[$key] ?? '');
        if (in_array($value, $allowed, true)) {
            $where[] = "t.$key=?";
            $args[] = $value;
            $filters[$key] = $value;
        }
    }
    $q = trim(digits((string) ($_GET['q'] ?? '')));
    if ($q !== '') {
        $where[] = '(t.ref_num=? OR t.order_code=? OR t.gateway_order_id=?)';
        array_push($args, $q, strtoupper($q), $q);
        $filters['q'] = $q;
    }
    return [$filters, implode(' AND ', $where), $args];
}

function admin_payment_rows(string $where, array $args, int $limit, int $offset = 0): array
{
    return query(
        "SELECT t.*,u.mobile,d.name doctor FROM payment_transactions t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN doctors d ON d.id=t.doctor_id WHERE $where ORDER BY t.id DESC LIMIT $limit OFFSET $offset",
        $args,
    )->fetchAll();
}

function admin_payments_data(): array
{
    [$filters, $where, $args] = admin_payment_filters();
    $page = max(1, (int) ($_GET['page'] ?? 1));
    return [
        'filters' => $filters,
        'page' => $page,
        'total' => (int) query("SELECT COUNT(*) FROM payment_transactions t WHERE $where", $args)->fetchColumn(),
        'rows' => admin_payment_rows($where, $args, 50, ($page - 1) * 50),
        'revenue' => payment_revenue($filters['from'], $filters['to']),
        'refunds' => query(
            "SELECT r.*,t.gateway,t.ref_num,t.card_mask,i.reference legacy_ref FROM refunds r LEFT JOIN payment_transactions t ON t.id=r.tx_id LEFT JOIN payment_intents i ON i.id=r.legacy_intent_id WHERE r.status='pending' ORDER BY r.id",
        )->fetchAll(),
        'handled' => query("SELECT * FROM refunds WHERE status<>'pending' ORDER BY handled_at DESC LIMIT 20")->fetchAll(),
    ];
}

/* ---------- Activity log ---------- */

/** Filters shared by the activity-log page and its export: admin, action prefix, Jalali dates. */
function admin_audit_filters(): array
{
    [$from, $to] = admin_date_range();
    if (!isset($_GET['from'])) {
        $from = date('Y-m-d', strtotime('-29 days'));
    }
    $where = ['l.created_at BETWEEN ? AND ?'];
    $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $actor = (string) ($_GET['actor'] ?? '');
    if ($actor === 'admins') {
        $where[] = 'l.actor_admin_id IS NOT NULL';
    } elseif ($actor === 'users') {
        $where[] = 'l.actor_admin_id IS NULL AND l.actor_user_id IS NOT NULL';
    } elseif (ctype_digit($actor) && $actor !== '0') {
        $where[] = 'l.actor_admin_id=?';
        $args[] = (int) $actor;
    } else {
        $actor = '';
    }
    $action = preg_replace('/[^a-z_.]/', '', (string) ($_GET['action_prefix'] ?? ''));
    if ($action !== '') {
        $where[] = 'l.action LIKE ?';
        $args[] = $action . '%';
    }
    return [['from' => $from, 'to' => $to, 'actor' => $actor, 'action_prefix' => $action], implode(' AND ', $where), $args];
}

function admin_audit_rows(string $where, array $args, int $limit, int $offset = 0): array
{
    return query(
        "SELECT l.*,a.email admin_email,a.name admin_name,u.mobile user_mobile FROM audit_log l LEFT JOIN admins a ON a.id=l.actor_admin_id LEFT JOIN users u ON u.id=l.actor_user_id WHERE $where ORDER BY l.id DESC LIMIT $limit OFFSET $offset",
        $args,
    )->fetchAll();
}

function admin_audit_data(): array
{
    [$filters, $where, $args] = admin_audit_filters();
    $page = max(1, (int) ($_GET['page'] ?? 1));
    return [
        'filters' => $filters,
        'page' => $page,
        'total' => (int) query("SELECT COUNT(*) FROM audit_log l WHERE $where", $args)->fetchColumn(),
        'rows' => admin_audit_rows($where, $args, 50, ($page - 1) * 50),
        'admins' => query('SELECT id,email,name FROM admins ORDER BY id')->fetchAll(),
        'prefixes' => audit_prefixes(),
    ];
}

/* ---------- Centers ---------- */

function admin_centers_data(): array
{
    $id = (int) ($_GET['center'] ?? 0);
    if ($id) {
        $c = query('SELECT ce.*,ci.name city,p.name province,u.name owner_name,u.mobile owner_mobile FROM centers ce JOIN cities ci ON ci.id=ce.city_id JOIN provinces p ON p.id=ci.province_id JOIN users u ON u.id=ce.owner_user_id WHERE ce.id=?', [$id])->fetch();
        if (!$c) {
            return ['center' => null, 'notFound' => true];
        }
        return [
            'center' => $c,
            'sub' => center_subscription_state($c),
            'branches' => center_branches($id),
            'links' => center_doctor_links($id),
            'staffList' => center_staff_list($id),
            'orders' => query('SELECT * FROM center_orders WHERE center_id=? ORDER BY id DESC LIMIT 30', [$id])->fetchAll(),
            'periods' => query('SELECT * FROM center_subscriptions WHERE center_id=? ORDER BY starts_on DESC LIMIT 30', [$id])->fetchAll(),
            'events' => query('SELECT e.*,u.name actor FROM center_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.center_id=? ORDER BY e.id DESC LIMIT 40', [$id])->fetchAll(),
            'plans' => center_plans(false),
        ];
    }
    $status = (string) ($_GET['status'] ?? '');
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = ['1=1'];
    $args = [];
    if (isset(center_status_labels()[$status])) {
        $where[] = 'ce.review_status=?';
        $args[] = $status;
    }
    if ($q !== '') {
        $where[] = '(ce.name LIKE ? OR ce.license_number LIKE ? OR ce.id=?)';
        array_push($args, '%' . $q . '%', '%' . digits($q) . '%', (int) digits($q));
    }
    return [
        'center' => null,
        'status' => $status,
        'q' => $q,
        'rows' => query(
            'SELECT ce.*,ci.name city,(SELECT COUNT(*) FROM center_branches b WHERE b.center_id=ce.id AND b.active=1) branches,(SELECT COUNT(*) FROM center_doctors l WHERE l.center_id=ce.id AND l.status=\'active\') doctors FROM centers ce JOIN cities ci ON ci.id=ce.city_id WHERE ' .
                implode(' AND ', $where) .
                " ORDER BY FIELD(ce.review_status,'pending','approved','suspended','rejected'),ce.id DESC LIMIT 200",
            $args,
        )->fetchAll(),
        'counts' => query('SELECT review_status,COUNT(*) n FROM centers GROUP BY review_status')->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
}

/* ---------- 1.0.0-rc.10: administration shell ---------- */

function admin_theme(): string
{
    return ($_COOKIE['ny_admin_theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
}

function admin_theme_set(string $theme): void
{
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    setcookie('ny_admin_theme', $theme === 'light' ? 'light' : 'dark', [
        'expires' => time() + 365 * 86400,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['ny_admin_theme'] = $theme;
}

/** Small counters next to menu items: work waiting for the signed-in administrator (only for permitted sections). */
function admin_nav_badges(): array
{
    $one = fn(string $sql) => (int) query($sql)->fetchColumn();
    $b = [];
    if (admin_can('doctors')) {
        $b['doctors'] = $one("SELECT COUNT(*) FROM doctors WHERE review_status='pending'");
    }
    if (admin_can('centers')) {
        $b['centers'] = $one("SELECT COUNT(*) FROM centers WHERE review_status='pending'");
    }
    if (admin_can('finance')) {
        $b['payments'] = $one("SELECT COUNT(*) FROM refunds WHERE status='pending'");
    }
    if (admin_can('support')) {
        $b['support'] = $one("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','waiting')");
    }
    if (admin_can('content')) {
        $b['reviews'] = $one("SELECT COUNT(*) FROM reviews WHERE status='pending'");
    }
    return array_filter($b);
}
