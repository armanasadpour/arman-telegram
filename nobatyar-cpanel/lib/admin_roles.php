<?php
declare(strict_types=1);

/**
 * Administrator roles. Every admin section and every admin action needs one permission; a role is a
 * fixed set of permissions. "sensitive" unlocks full mobile numbers and identity details, "export"
 * unlocks Excel downloads. Unknown actions require the owner-only "admins" permission (deny by default).
 */

function admin_permissions(): array
{
    return [
        'dashboard' => 'داشبورد و گزارش‌ها',
        'doctors' => 'پزشکان و احراز هویت',
        'centers' => 'کلینیک‌ها و مراکز',
        'catalog' => 'تخصص‌ها، جغرافیا و قوانین رزرو',
        'users' => 'کاربران و دسترسی‌ها',
        'finance' => 'اشتراک، کارمزد، تراکنش و استرداد',
        'gateways' => 'درگاه‌های پرداخت',
        'communications' => 'پیامک، اعلان و اطلاعیه',
        'content' => 'بنرها و محتوای صفحه اصلی',
        'support' => 'تیکت‌های پشتیبانی',
        'system' => 'تنظیمات عمومی، Cron و گزارش فعالیت',
        'admins' => 'مدیریت مدیران و پشتیبان دیتابیس',
        'export' => 'خروجی اکسل',
        'sensitive' => 'مشاهده کامل شماره‌ها و اطلاعات هویتی',
    ];
}

function admin_roles(): array
{
    $all = array_keys(admin_permissions());
    return [
        'owner' => ['label' => 'مالک سامانه', 'perms' => $all, 'note' => 'همه دسترسی‌ها، از جمله درگاه‌ها و مدیران.'],
        'manager' => [
            'label' => 'مدیر اجرایی',
            'perms' => array_values(array_diff($all, ['admins', 'gateways'])),
            'note' => 'همه بخش‌ها به‌جز درگاه پرداخت و مدیریت مدیران.',
        ],
        'finance' => [
            'label' => 'مالی',
            'perms' => ['dashboard', 'finance', 'export', 'sensitive'],
            'note' => 'اشتراک‌ها، کارمزد، تراکنش‌ها و استرداد.',
        ],
        'support' => [
            'label' => 'پشتیبانی',
            'perms' => ['dashboard', 'support', 'users', 'doctors', 'centers'],
            'note' => 'تیکت‌ها و بررسی حساب‌ها؛ شماره‌ها به‌صورت پوشیده نمایش داده می‌شود.',
        ],
        'content' => [
            'label' => 'محتوا',
            'perms' => ['dashboard', 'content', 'catalog', 'communications'],
            'note' => 'بنرها، محتوای صفحه اصلی، تخصص‌ها، تنظیمات جستجو، نظرات بیماران و اطلاعیه‌ها.',
        ],
        'viewer' => ['label' => 'ناظر (فقط مشاهده)', 'perms' => ['dashboard'], 'note' => 'فقط داشبورد؛ بدون خروجی و بدون تغییر.'],
    ];
}

/** The signed-in administrator row, or null. Cached per request. */
function admin_current(): ?array
{
    static $cache = [];
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if (!$id) {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        $row = query('SELECT * FROM admins WHERE id=?', [$id])->fetch() ?: null;
        // Before the 0.12 upgrade the table has no role column: every administrator was an owner.
        if ($row && !isset($row['role'])) {
            $row += ['role' => 'owner', 'active' => 1, 'session_version' => 1, 'name' => ''];
        }
        $cache[$id] = $row;
    }
    return $cache[$id];
}

function admin_can(string $perm): bool
{
    if (!admin()) {
        return false;
    }
    $role = admin_roles()[admin_current()['role'] ?? ''] ?? null;
    return $role !== null && in_array($perm, $role['perms'], true);
}

function admin_require(string $perm): void
{
    if (!admin_can($perm)) {
        throw new AuthError('دسترسی به این بخش برای نقش شما فعال نیست.', 403);
    }
}

function admin_sensitive(): bool
{
    return admin_can('sensitive');
}

/** Mobile number shown in admin pages: full for roles with "sensitive", masked otherwise. */
function admin_mobile(?string $mobile): string
{
    $mobile = (string) $mobile;
    if ($mobile === '') {
        return '—';
    }
    return admin_sensitive() ? fa_number($mobile) : mask_mobile($mobile);
}

/** Generic identity value (national id, license number): masked except the last three characters. */
function admin_identity(?string $value): string
{
    $value = (string) $value;
    if ($value === '' || admin_sensitive()) {
        return $value === '' ? '—' : fa_number($value);
    }
    return fa_number(str_repeat('•', max(0, mb_strlen($value) - 3)) . mb_substr($value, -3));
}

function admin_action_permission(string $action): string
{
    static $map = null;
    if ($map === null) {
        $groups = [
            'doctors' => ['create_doctor', 'edit_doctor', 'review', 'clinic', 'delete_clinic', 'slot', 'doctor_suspend', 'doctor_unsuspend', 'doctor_delete', 'doctor_purge'],
            'centers' => ['center_review', 'center_active', 'center_settings'],
            'catalog' => ['taxonomy', 'delete_taxonomy', 'restore_specialty', 'specialty_starter', 'specialty_default_icon', 'move', 'booking_config', 'booking_page', 'geo_bulk', 'geo_settings'],
            'users' => ['user_status', 'link_doctor', 'unlink_doctor', 'grant_scope', 'revoke_scope', 'user_suspend', 'user_unsuspend', 'user_delete'],
            'finance' => [
                'wallet_payout_handle', 'wallet_refund_settings', 'subscription', 'settings', 'revenue_settings', 'fee_rule_save', 'fee_rule_delete', 'refund_handle', 'plan_save',
                'discount_save', 'sub_grant', 'sub_offline', 'sub_admin_order', 'sub_admin_cancel',
                'subscription_settings', 'pro_catalog', 'doctor_features', 'center_grant', 'center_order', 'center_offline',
            ],
            'gateways' => ['gateway_save', 'gateway_enable', 'gateway_roles', 'gateway_test', 'gateway_logo'],
            'communications' => ['manual_sms', 'sms_settings', 'sms_templates', 'sms_test', 'sms_account', 'notify_settings', 'push_regenerate', 'announce_create', 'announce_cancel'],
            'content' => ['banner_save', 'banner_delete', 'banner_move', 'home_content', 'home_draft', 'home_publish', 'home_discard', 'home_featured', 'home_featured_order', 'home_specialties', 'faq_save', 'faq_delete', 'faq_order', 'page_save', 'footer_save', 'drawer_banner_save', 'article_save', 'article_delete', 'search_settings', 'search_cache', 'review_moderate'],
            'support' => ['ticket_reply', 'ticket_update'],
            'system' => ['cron_run', 'general_settings'],
            'admins' => ['admin_save', 'admin_status', 'admin_reset_password', 'update_upload', 'update_cancel', 'update_apply', 'update_rollback', 'backup_create', 'backup_delete', 'backup_upload', 'backup_restore', 'backup_settings'],
        ];
        $map = [];
        foreach ($groups as $perm => $actions) {
            foreach ($actions as $a) {
                $map[$a] = $perm;
            }
        }
    }
    return $map[$action] ?? 'admins';
}

/* ---------- Administrator accounts (owner only) ---------- */

function admin_password_valid(string $password): void
{
    if (mb_strlen($password) < 12 || !preg_match('/[0-9]/', $password) || !preg_match('/[^0-9]/', $password)) {
        throw new RuntimeException('رمز عبور باید حداقل ۱۲ نویسه و ترکیبی از حرف و عدد باشد.');
    }
}

function admin_owner_count(?int $except = null): int
{
    return (int) query(
        "SELECT COUNT(*) FROM admins WHERE role='owner' AND active=1" . ($except ? ' AND id<>' . (int) $except : ''),
    )->fetchColumn();
}

/** Creates or edits an administrator. The last active owner cannot be demoted. */
function admin_account_save(array $d): string
{
    $id = (int) ($d['id'] ?? 0);
    $role = (string) ($d['role'] ?? '');
    if (!isset(admin_roles()[$role])) {
        throw new RuntimeException('نقش انتخاب‌شده معتبر نیست.');
    }
    $name = field($d, 'name', 120);
    // rc.13: every administrator signs in with an SMS code to this mobile (unique among administrators).
    $mobileRaw = trim((string) ($d['mobile'] ?? ''));
    $mobile = $mobileRaw === '' ? null : normalize_mobile($mobileRaw);
    if ($mobile !== null && query('SELECT id FROM admins WHERE mobile=? AND id<>?', [$mobile, $id])->fetch()) {
        throw new RuntimeException('این شماره موبایل برای مدیر دیگری ثبت شده است.');
    }
    if ($id) {
        $row = query('SELECT * FROM admins WHERE id=?', [$id])->fetch();
        if (!$row) {
            throw new RuntimeException('مدیر پیدا نشد.');
        }
        if ($row['role'] === 'owner' && $role !== 'owner' && !admin_owner_count($id)) {
            throw new RuntimeException('حداقل یک مالک فعال باید باقی بماند.');
        }
        // A role change signs the account out everywhere so the new limits apply at once.
        // session_version is assigned first: MySQL evaluates SET left to right, so role still holds the old value.
        query('UPDATE admins SET session_version=session_version+IF(role<>?,1,0),name=?,role=? WHERE id=?', [$role, $name, $role, $id]);
        if (array_key_exists('mobile', $d) && $mobile !== ($row['mobile'] ?? null)) {
            query('UPDATE admins SET mobile=? WHERE id=?', [$mobile, $id]);
            audit('admin.mobile_changed', $id, $mobile ? mask_mobile($mobile) : 'removed');
        }
        audit('admin.update', $id, $role);
        return 'اطلاعات مدیر ذخیره شد.';
    }
    $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        throw new RuntimeException('ایمیل معتبر وارد کنید.');
    }
    if (query('SELECT id FROM admins WHERE email=?', [$email])->fetch()) {
        throw new RuntimeException('این ایمیل قبلاً ثبت شده است.');
    }
    if ($mobile === null) {
        throw new RuntimeException('شماره موبایل مدیر را وارد کنید؛ ورود با کد پیامکی به همین شماره است.');
    }
    $password = (string) ($d['password'] ?? '');
    admin_password_valid($password);
    query('INSERT INTO admins(email,password_hash,name,role,mobile) VALUES(?,?,?,?,?)', [$email, password_hash($password, PASSWORD_DEFAULT), $name, $role, $mobile]);
    audit('admin.create', (int) db()->lastInsertId(), $role);
    return 'مدیر جدید ساخته شد.';
}

function admin_account_status(int $id, bool $active): string
{
    if ($id === (int) ($_SESSION['admin_id'] ?? 0)) {
        throw new RuntimeException('حساب خودتان را نمی‌توانید غیرفعال کنید.');
    }
    $row = query('SELECT * FROM admins WHERE id=?', [$id])->fetch();
    if (!$row) {
        throw new RuntimeException('مدیر پیدا نشد.');
    }
    if (!$active && $row['role'] === 'owner' && !admin_owner_count($id)) {
        throw new RuntimeException('حداقل یک مالک فعال باید باقی بماند.');
    }
    query('UPDATE admins SET active=?,session_version=session_version+1 WHERE id=?', [$active ? 1 : 0, $id]);
    audit($active ? 'admin.enable' : 'admin.disable', $id);
    return $active ? 'حساب مدیر فعال شد.' : 'حساب مدیر غیرفعال شد و از همه دستگاه‌ها خارج شد.';
}

function admin_reset_password(int $id, string $password): string
{
    admin_password_valid($password);
    if (!query('SELECT id FROM admins WHERE id=?', [$id])->fetch()) {
        throw new RuntimeException('مدیر پیدا نشد.');
    }
    query('UPDATE admins SET password_hash=?,session_version=session_version+1 WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    audit('admin.password_reset', $id);
    return 'رمز عبور تغییر کرد و نشست‌های قبلی آن حساب بسته شد.';
}

/** Any administrator may change their own password after confirming the current one. */
function admin_change_own_password(string $current, string $new): string
{
    $me = admin_current();
    if (!$me || !password_verify($current, $me['password_hash'])) {
        throw new RuntimeException('رمز عبور فعلی اشتباه است.');
    }
    admin_password_valid($new);
    query('UPDATE admins SET password_hash=?,session_version=session_version+1 WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
    $_SESSION['admin_version'] = (int) $me['session_version'] + 1;
    session_regenerate_id(true);
    audit('admin.password_change', (int) $me['id']);
    return 'رمز عبور شما تغییر کرد؛ نشست‌های دیگر بسته شدند.';
}
