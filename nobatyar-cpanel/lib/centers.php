<?php
declare(strict_types=1);

/**
 * Clinics and medical centers (phase 9).
 *
 * Model: a center (reviewed by the administrator) has branches and rooms. Doctors join a center through a
 * link that one side proposes and the other accepts; the doctor alone decides what the center may see
 * (schedule, bookings and patient contacts, visit history, statistical reports). A doctor's presence in a
 * branch is an ordinary clinics row with center_id/branch_id, so booking, capacity and reception logic is
 * shared with personal practices. A doctor's personal clinics (center_id NULL) are never visible to a center.
 * Center staff receive reception access to center clinic rows through staff_scopes rows tagged with
 * center_id; center_sync_scopes() rebuilds them whenever staff, links, branches or subscription change.
 */

function center_kinds(): array
{
    return [
        'clinic' => 'کلینیک تخصصی',
        'polyclinic' => 'درمانگاه',
        'medical_center' => 'مرکز جامع سلامت',
        'hospital' => 'بیمارستان',
        'dental' => 'کلینیک دندان‌پزشکی',
        'imaging' => 'مرکز تصویربرداری',
        'lab' => 'آزمایشگاه',
        'rehab' => 'توان‌بخشی و فیزیوتراپی',
        'other' => 'سایر مراکز درمانی',
    ];
}

function center_status_labels(): array
{
    return ['pending' => 'در انتظار بررسی', 'approved' => 'تأییدشده', 'rejected' => 'ردشده', 'suspended' => 'تعلیق‌شده'];
}

function link_status_labels(): array
{
    return [
        'invited' => 'دعوت مرکز — منتظر پاسخ پزشک',
        'requested' => 'درخواست پزشک — منتظر پاسخ مرکز',
        'active' => 'فعال',
        'rejected' => 'ردشده',
        'ended' => 'پایان‌یافته',
    ];
}

/** Permissions a doctor grants a center; nothing outside center branches is ever covered. */
function link_permissions(): array
{
    return [
        'perm_schedule' => 'مدیریت برنامه کاری و اتاق در شعبه‌های مرکز',
        'perm_bookings' => 'مشاهده و مدیریت نوبت‌ها و تماس با بیماران در شعبه‌های مرکز',
        'perm_history' => 'مشاهده سوابق مراجعه گذشته در شعبه‌های مرکز',
        'perm_reports' => 'گزارش آماری عملکرد (بدون نام و تماس بیمار)',
    ];
}

function center_permissions(): array
{
    return [
        'perm_center' => 'ویرایش مشخصات مرکز، شعبه‌ها و اتاق‌ها',
        'perm_doctors' => 'دعوت و مدیریت پزشکان',
        'perm_staff' => 'مدیریت کارکنان و دسترسی‌ها',
        'perm_schedule' => 'برنامه کاری پزشکان و اتاق‌ها',
        'perm_bookings' => 'مدیریت نوبت‌ها و پذیرش',
        'perm_history' => 'مشاهده سوابق گذشته',
        'perm_reports' => 'گزارش‌ها',
        'perm_announce' => 'ارسال اطلاعیه به بیماران',
        'perm_billing' => 'اشتراک و پرداخت',
    ];
}

/** Role presets for new staff; every permission can still be adjusted individually. */
function center_roles(): array
{
    $all = array_fill_keys(array_keys(center_permissions()), 1);
    $none = array_fill_keys(array_keys(center_permissions()), 0);
    return [
        'owner' => ['label' => 'مالک', 'perms' => $all],
        'manager' => ['label' => 'مدیر', 'perms' => $all],
        'secretary' => ['label' => 'منشی پذیرش', 'perms' => ['perm_bookings' => 1, 'perm_announce' => 1] + $none],
        'scheduler' => ['label' => 'مسئول برنامه‌ریزی', 'perms' => ['perm_schedule' => 1] + $none],
        'reporter' => ['label' => 'گزارش‌گیر', 'perms' => ['perm_reports' => 1] + $none],
        'staff' => ['label' => 'کارمند', 'perms' => $none],
    ];
}

function center_grace_days(): int
{
    return setting_int('center_grace_days', 3, 0, 60);
}

/** SQL condition: center usable for operations (approved, active, subscription within grace). */
function center_open_sql(string $alias = 'ce'): string
{
    return "($alias.review_status='approved' AND $alias.active=1 AND $alias.subscription_until IS NOT NULL AND $alias.subscription_until>=DATE_SUB(CURDATE(),INTERVAL " .
        center_grace_days() .
        ' DAY))';
}

function center_is_open(array $c): bool
{
    return $c['review_status'] === 'approved' &&
        (int) $c['active'] === 1 &&
        !empty($c['subscription_until']) &&
        $c['subscription_until'] >= date('Y-m-d', strtotime('-' . center_grace_days() . ' days'));
}

function center_row(int $id): array
{
    $c = query('SELECT ce.*,ci.name city,ci.province_id,p.name province FROM centers ce JOIN cities ci ON ci.id=ce.city_id JOIN provinces p ON p.id=ci.province_id WHERE ce.id=?', [
        $id,
    ])->fetch();
    if (!$c) {
        throw new AuthError('مرکز پیدا نشد.', 404);
    }
    return $c;
}

function center_event(int $center, string $type, array $details = []): void
{
    query('INSERT INTO center_events(center_id,actor_user_id,actor_admin_id,event_type,details) VALUES(?,?,?,?,?)', [
        $center,
        $_SESSION['user_id'] ?? null,
        $_SESSION['admin_id'] ?? null,
        $type,
        json_encode($details, JSON_UNESCAPED_UNICODE),
    ]);
    if($type==='subscription.paid')admin_event('payment','پرداخت اشتراک مرکز','فاکتور اشتراک مرکز ثبت شد.','center-payment:'.(int)db()->lastInsertId(),'admin.php?tab=payments');
}

function center_event_label(string $type): string
{
    return [
        'center.registered' => 'ثبت درخواست مرکز',
        'center.profile' => 'ویرایش مشخصات مرکز',
        'center.review' => 'تغییر وضعیت بررسی توسط مدیر',
        'branch.save' => 'ثبت یا ویرایش شعبه',
        'room.save' => 'ثبت یا ویرایش اتاق',
        'staff.save' => 'تعریف یا ویرایش کارمند',
        'staff.remove' => 'حذف کارمند',
        'link.invited' => 'دعوت پزشک',
        'link.requested' => 'درخواست همکاری پزشک',
        'link.accepted' => 'پذیرش همکاری',
        'link.rejected' => 'رد همکاری',
        'link.ended' => 'پایان همکاری',
        'link.permissions' => 'تغییر مجوزهای پزشک',
        'presence.add' => 'افزودن پزشک به شعبه',
        'presence.remove' => 'غیرفعال‌سازی حضور پزشک در شعبه',
        'presence.settings' => 'تنظیم مدت و ظرفیت نوبت',
        'hours.add' => 'افزودن ساعت کاری',
        'hours.remove' => 'حذف ساعت کاری',
        'announcement.sent' => 'ارسال اطلاعیه',
        'subscription.order' => 'صدور پیش‌فاکتور اشتراک',
        'subscription.paid' => 'پرداخت اشتراک',
        'subscription.trial' => 'فعال‌سازی دوره آزمایشی',
        'subscription.grant' => 'تمدید یا هدیه مدیر',
        'subscription.cancel' => 'لغو پیش‌فاکتور',
        'report.export' => 'خروجی اکسل گزارش',
    ][$type] ?? $type;
}

/** In-app notification for active staff of a center that hold a permission. */
function center_notify(int $center, string $permission, string $title, string $body): void
{
    $column = array_key_exists($permission, center_permissions()) ? $permission : 'perm_center';
    foreach (
        query("SELECT user_id FROM center_staff WHERE center_id=? AND active=1 AND (role='owner' OR $column=1)", [$center])->fetchAll(PDO::FETCH_COLUMN)
        as $uid
    ) {
        notify_user((int) $uid, $title, $body, null, 'general', 'center.php?center=' . $center);
    }
}

/* ---------- Access ---------- */

/** Centers the signed-in user works in (any active staff row). */
function my_centers(): array
{
    $u = user_now();
    if (!$u) {
        return [];
    }
    return query(
        'SELECT ce.*,s.role staff_role FROM centers ce JOIN center_staff s ON s.center_id=ce.id WHERE s.user_id=? AND s.active=1 ORDER BY ce.name',
        [$u['id']],
    )->fetchAll();
}

/**
 * Checks the signed-in staff member. Permissions:
 *   view — any active staff; center/billing — also allowed before approval (to complete the file or pay);
 *   doctors, staff, schedule, bookings, history, reports, announce — need an approved, open center.
 * $branch limits the check to the staff member's branch scope (0 = no specific branch).
 */
function center_actor(int $center, string $permission = 'view', int $branch = 0): array
{
    $u = require_user();
    $c = center_row($center);
    $s = query('SELECT * FROM center_staff WHERE center_id=? AND user_id=? AND active=1', [$center, $u['id']])->fetch();
    if (!$s) {
        throw new AuthError('به این مرکز دسترسی ندارید.', 403);
    }
    if ($permission !== 'view') {
        $column = 'perm_' . $permission;
        if (!array_key_exists($column, center_permissions()) || empty($s[$column])) {
            throw new AuthError('این عملیات در سطح دسترسی شما نیست.', 403);
        }
        if ($c['review_status'] === 'suspended' || ($c['review_status'] === 'rejected' && $permission !== 'center')) {
            throw new AuthError('مرکز ' . center_status_labels()[$c['review_status']] . ' است؛ فقط مشاهده ممکن است.', 403);
        }
        if (!in_array($permission, ['center', 'billing', 'staff'], true) && !center_is_open($c)) {
            throw new AuthError(
                $c['review_status'] !== 'approved'
                    ? 'این امکان پس از تأیید مرکز توسط مدیر سامانه فعال می‌شود.'
                    : 'اشتراک مرکز به پایان رسیده است؛ برای ادامه از بخش اشتراک تمدید کنید.',
                403,
            );
        }
    }
    if ($branch && $s['branch_id'] && (int) $s['branch_id'] !== $branch) {
        throw new AuthError('این شعبه در محدوده دسترسی شما نیست.', 403);
    }
    return ['center' => $c, 'staff' => $s, 'user' => $u];
}

function center_branch(int $center, int $branch): array
{
    $b = query('SELECT b.*,ci.name city,ci.province_id FROM center_branches b JOIN cities ci ON ci.id=b.city_id WHERE b.id=? AND b.center_id=?', [
        $branch,
        $center,
    ])->fetch();
    if (!$b) {
        throw new AuthError('شعبه پیدا نشد.', 404);
    }
    return $b;
}

/** Branches visible to a staff member (all, or only the assigned branch). */
function center_branches(int $center, ?array $staff = null, bool $activeOnly = false): array
{
    $sql = 'SELECT b.*,ci.name city,ci.province_id,(SELECT COUNT(*) FROM center_rooms r WHERE r.branch_id=b.id AND r.active=1) rooms FROM center_branches b JOIN cities ci ON ci.id=b.city_id WHERE b.center_id=?';
    $args = [$center];
    if ($staff && $staff['branch_id']) {
        $sql .= ' AND b.id=?';
        $args[] = $staff['branch_id'];
    }
    if ($activeOnly) {
        $sql .= ' AND b.active=1';
    }
    return query($sql . ' ORDER BY b.is_main DESC,b.sort_order,b.id', $args)->fetchAll();
}

/* ---------- Registration and profile ---------- */

function center_profile_data(array $data): array
{
    $kind = (string) ($data['kind'] ?? '');
    if (!isset(center_kinds()[$kind])) {
        throw new AuthError('نوع مرکز را انتخاب کنید.');
    }
    $name = field($data, 'center_name', 190);
    if (mb_strlen($name) < 3) {
        throw new AuthError('نام مرکز را کامل وارد کنید.');
    }
    $license = strtoupper(trim(digits(field($data, 'license_number', 40))));
    if (!preg_match('/^[0-9A-Z\/-]{4,40}$/', $license)) {
        throw new AuthError('شماره پروانه بهره‌برداری فقط شامل عدد، حروف لاتین، خط تیره و / است.');
    }
    $national = trim(digits((string) ($data['national_id'] ?? '')));
    if ($national !== '' && !preg_match('/^[0-9]{11}$/', $national)) {
        throw new AuthError('شناسه ملی شخص حقوقی ۱۱ رقم است.');
    }
    $phone = digits(field($data, 'phone', 20));
    if (!preg_match('/^0[0-9]{10}$/', $phone)) {
        throw new AuthError('تلفن مرکز را با پیش‌شماره و ۱۱ رقم وارد کنید.');
    }
    $city = active_city(positive_id($data['city_id'] ?? 0), positive_id($data['province_id'] ?? 0));
    [$lat, $lng] = coordinates_from($data);
    $techCode = trim(digits((string) ($data['technical_code'] ?? '')));
    if ($techCode !== '' && !preg_match('/^[A-Za-z0-9-]{3,30}$/', $techCode)) {
        throw new AuthError('شماره نظام مسئول فنی معتبر نیست.');
    }
    return [
        'name' => $name,
        'kind' => $kind,
        'license_number' => $license,
        'national_id' => $national !== '' ? $national : null,
        'manager_name' => field($data, 'manager_name', 190),
        'technical_manager' => field($data, 'technical_manager', 190, false),
        'technical_code' => $techCode,
        'city_id' => (int) $city['id'],
        'address' => field($data, 'address', 2000),
        'phone' => $phone,
        'latitude' => $lat,
        'longitude' => $lng,
        'description' => field($data, 'description', 3000, false),
    ];
}

/**
 * Stores a scanned document (license) as a re-encoded JPEG outside the web root. Only the administrator
 * and the center owner can open it (center-document.php).
 */
function store_document_image(array $upload): string
{
    if (!extension_loaded('gd')) {
        throw new AuthError('افزونه GD برای پردازش تصویر باید فعال باشد.');
    }
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) > 4 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        throw new AuthError('تصویر پروانه را با فرمت JPEG، PNG یا WebP و حجم حداکثر ۴ مگابایت انتخاب کنید.');
    }
    $info = @getimagesize($upload['tmp_name']);
    $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
    if (defined('IMAGETYPE_WEBP')) {
        $types[] = IMAGETYPE_WEBP;
    }
    if (!$info || !in_array($info[2], $types, true) || $info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > 24000000) {
        throw new AuthError('فرمت یا ابعاد تصویر پروانه مجاز نیست.');
    }
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($upload['tmp_name']),
        IMAGETYPE_PNG => @imagecreatefrompng($upload['tmp_name']),
        default => @imagecreatefromwebp($upload['tmp_name']),
    };
    if (!$src) {
        throw new AuthError('تصویر پروانه قابل پردازش نیست.');
    }
    $ratio = min(1, 1800 / max($info[0], $info[1]));
    $w = max(1, (int) round($info[0] * $ratio));
    $h = max(1, (int) round($info[1] * $ratio));
    $dst = imagecreatetruecolor($w, $h);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
    $dir = dirname(__DIR__) . '/private/media/centerdocs';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new AuthError('ذخیره تصویر ممکن نیست.');
    }
    $file = bin2hex(random_bytes(20)) . '.jpg';
    if (!imagejpeg($dst, $dir . '/' . $file, 85)) {
        throw new AuthError('ذخیره تصویر ممکن نیست.');
    }
    return $file;
}

function center_register(array $data, array $files): int
{
    $u = require_user();
    if (setting('center_registration_enabled', '1') !== '1') {
        throw new AuthError('ثبت مرکز جدید در حال حاضر غیرفعال است.', 403);
    }
    throttle('center_register', 5, 86400);
    $p = center_profile_data($data);
    if ((int) query("SELECT COUNT(*) FROM centers WHERE owner_user_id=? AND review_status='pending'", [$u['id']])->fetchColumn() >= 3) {
        throw new AuthError('سه درخواست در انتظار بررسی دارید؛ تا بررسی آن‌ها صبر کنید.', 409);
    }
    if (query('SELECT id FROM centers WHERE license_number=?', [$p['license_number']])->fetch()) {
        throw new AuthError('برای این شماره پروانه قبلاً مرکزی ثبت شده است. در صورت اشتباه با مدیر سامانه تماس بگیرید.', 409);
    }
    if (empty($data['accept_terms'])) {
        throw new AuthError('صحت اطلاعات و مدارک را تأیید کنید.');
    }
    $document = store_document_image($files['document'] ?? []);
    $logo = !empty($files['logo']['name']) ? store_square_image($files['logo'], 'centers', 256) : null;
    return db_transaction(function () use ($u, $p, $document, $logo) {
        query(
            'INSERT INTO centers(name,kind,license_number,national_id,manager_name,technical_manager,technical_code,city_id,address,phone,latitude,longitude,description,logo_file,document_file,owner_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $p['name'],
                $p['kind'],
                $p['license_number'],
                $p['national_id'],
                $p['manager_name'],
                $p['technical_manager'],
                $p['technical_code'],
                $p['city_id'],
                $p['address'],
                $p['phone'],
                $p['latitude'],
                $p['longitude'],
                $p['description'],
                $logo,
                $document,
                $u['id'],
            ],
        );
        $id = (int) db()->lastInsertId();
        query(
            'INSERT INTO center_branches(center_id,name,city_id,address,phone,latitude,longitude,is_main,sort_order) VALUES(?,?,?,?,?,?,?,1,10)',
            [$id, 'شعبه مرکزی', $p['city_id'], $p['address'], $p['phone'], $p['latitude'], $p['longitude']],
        );
        $perms = center_roles()['owner']['perms'];
        query(
            'INSERT INTO center_staff(center_id,user_id,role,' . implode(',', array_keys($perms)) . ') VALUES(?,?,\'owner\'' . str_repeat(',?', count($perms)) . ')',
            [$id, $u['id'], ...array_values($perms)],
        );
        query("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'clinic')", [$u['id']]);
        center_event($id, 'center.registered', ['license' => $p['license_number']]);
        audit('center.register', $id);
        admin_event('registration','درخواست ثبت کلینیک','درخواست مرکز جدید برای بررسی ثبت شد.','center:'.$id,'admin.php?tab=centers');
        return $id;
    });
}

function center_save_profile(int $center, array $data, array $files): string
{
    $a = center_actor($center, 'center');
    $p = center_profile_data($data);
    $old = $a['center'];
    if ($p['license_number'] !== $old['license_number'] && query('SELECT id FROM centers WHERE license_number=? AND id<>?', [$p['license_number'], $center])->fetch()) {
        throw new AuthError('این شماره پروانه برای مرکز دیگری ثبت شده است.', 409);
    }
    $document = !empty($files['document']['name']) ? store_document_image($files['document']) : $old['document_file'];
    $logo = !empty($files['logo']['name']) ? store_square_image($files['logo'], 'centers', 256) : $old['logo_file'];
    // Identity changes (name, license, kind or a new license image) go back to review.
    $review =
        $old['review_status'] === 'approved' &&
        ($p['name'] !== $old['name'] || $p['license_number'] !== $old['license_number'] || $p['kind'] !== $old['kind'] || $document !== $old['document_file']);
    $status = $review || $old['review_status'] === 'rejected' ? 'pending' : $old['review_status'];
    db_transaction(function () use ($center, $p, $document, $logo, $status, $review) {
        query(
            'UPDATE centers SET name=?,kind=?,license_number=?,national_id=?,manager_name=?,technical_manager=?,technical_code=?,city_id=?,address=?,phone=?,latitude=?,longitude=?,description=?,logo_file=?,document_file=?,review_status=? WHERE id=?',
            [
                $p['name'],
                $p['kind'],
                $p['license_number'],
                $p['national_id'],
                $p['manager_name'],
                $p['technical_manager'],
                $p['technical_code'],
                $p['city_id'],
                $p['address'],
                $p['phone'],
                $p['latitude'],
                $p['longitude'],
                $p['description'],
                $logo,
                $document,
                $status,
                $center,
            ],
        );
        center_refresh_clinic_names($center);
        center_event($center, 'center.profile', ['review_required' => $review]);
        center_sync_scopes($center);
    });
    return $review
        ? 'تغییر نام، نوع، پروانه یا تصویر مدرک برای بررسی مجدد ارسال شد؛ تا تأیید، امکانات عملیاتی مرکز متوقف است. نوبت‌های پزشکان حفظ می‌شوند.'
        : 'مشخصات مرکز ذخیره شد.';
}

/** Admin review: approve, reject or suspend with a note. */
function center_review(int $center, string $status, string $note): void
{
    if (!admin()) {
        throw new AuthError('دسترسی مجاز نیست.', 403);
    }
    if (!in_array($status, ['approved', 'rejected', 'suspended', 'pending'], true)) {
        throw new AuthError('وضعیت نامعتبر است.');
    }
    if ($status !== 'approved' && mb_strlen(trim($note)) < 5) {
        throw new AuthError('علت رد یا تعلیق را بنویسید.');
    }
    $c = center_row($center);
    db_transaction(function () use ($c, $center, $status, $note) {
        query('UPDATE centers SET review_status=?,review_note=?,status_changed_at=NOW() WHERE id=?', [$status, mb_substr(trim($note), 0, 2000), $center]);
        center_event($center, 'center.review', ['from' => $c['review_status'], 'to' => $status, 'note' => $note]);
        center_sync_scopes($center);
        audit('center.review', $center);
        notify_user((int) $c['owner_user_id'], 'وضعیت مرکز: ' . center_status_labels()[$status], $c['name'] . ($note !== '' ? ' — ' . $note : ''), null, 'general', 'center.php?center=' . $center);
    });
}

function center_set_active(int $center, bool $active): void
{
    if (!admin()) {
        throw new AuthError('دسترسی مجاز نیست.', 403);
    }
    query('UPDATE centers SET active=? WHERE id=?', [$active ? 1 : 0, $center]);
    center_event($center, 'center.review', ['active' => $active]);
    center_sync_scopes($center);
    audit('center.active', $center);
}

/* ---------- Branches and rooms ---------- */

function center_clinic_name(string $center, string $branch): string
{
    return mb_substr($center . ' — ' . $branch, 0, 190);
}

/** Keeps the clinic rows of every branch in step with the center and branch details. */
function center_refresh_clinic_names(int $center): void
{
    query(
        "UPDATE clinics cl JOIN center_branches b ON b.id=cl.branch_id JOIN centers ce ON ce.id=b.center_id SET cl.name=LEFT(CONCAT(ce.name,' — ',b.name),190),cl.city_id=b.city_id,cl.address=b.address,cl.phone=IF(b.phone<>'',b.phone,ce.phone),cl.latitude=b.latitude,cl.longitude=b.longitude WHERE ce.id=?",
        [$center],
    );
}

function center_branch_save(int $center, array $data): int
{
    $id = empty($data['id']) ? 0 : positive_id($data['id']);
    $a = center_actor($center, 'center', $id);
    if (!$id && $a['staff']['branch_id']) {
        throw new AuthError('افزودن شعبه فقط برای کارکنان کل مرکز مجاز است.', 403);
    }
    $name = field($data, 'name', 190);
    $city = active_city(positive_id($data['city_id'] ?? 0), positive_id($data['province_id'] ?? 0));
    $phone = digits(field($data, 'phone', 20, false));
    if ($phone !== '' && !preg_match('/^0[0-9]{10}$/', $phone)) {
        throw new AuthError('تلفن شعبه را با پیش‌شماره و ۱۱ رقم وارد کنید.');
    }
    [$lat, $lng] = coordinates_from($data);
    $address = field($data, 'address', 2000);
    $active = !empty($data['active']) ? 1 : 0;
    return db_transaction(function () use ($center, $id, $name, $city, $phone, $lat, $lng, $address, $active) {
        if ($id) {
            $old = center_branch($center, $id);
            if (!$active && $old['is_main']) {
                throw new AuthError('شعبه اصلی را نمی‌توان غیرفعال کرد؛ ابتدا شعبه دیگری را اصلی کنید.');
            }
            query('UPDATE center_branches SET name=?,city_id=?,address=?,phone=?,latitude=?,longitude=?,active=? WHERE id=?', [
                $name,
                $city['id'],
                $address,
                $phone,
                $lat,
                $lng,
                $active,
                $id,
            ]);
            if (!$active) {
                // Bookings already confirmed stay; only new bookings at this branch stop.
                query('UPDATE clinics SET active=0 WHERE branch_id=?', [$id]);
            }
        } else {
            $sort = (int) query('SELECT COALESCE(MAX(sort_order),0)+10 FROM center_branches WHERE center_id=?', [$center])->fetchColumn();
            query('INSERT INTO center_branches(center_id,name,city_id,address,phone,latitude,longitude,active,sort_order) VALUES(?,?,?,?,?,?,?,?,?)', [
                $center,
                $name,
                $city['id'],
                $address,
                $phone,
                $lat,
                $lng,
                $active,
                $sort,
            ]);
            $id = (int) db()->lastInsertId();
        }
        center_refresh_clinic_names($center);
        center_event($center, 'branch.save', ['branch_id' => $id, 'name' => $name, 'active' => $active]);
        center_sync_scopes($center);
        return $id;
    });
}

function center_branch_main(int $center, int $branch): void
{
    center_actor($center, 'center');
    $b = center_branch($center, $branch);
    if (!$b['active']) {
        throw new AuthError('شعبه غیرفعال را نمی‌توان اصلی کرد.');
    }
    query('UPDATE center_branches SET is_main=(id=?) WHERE center_id=?', [$branch, $center]);
    center_event($center, 'branch.save', ['branch_id' => $branch, 'main' => true]);
}

function center_rooms(int $branch, bool $activeOnly = false): array
{
    return query('SELECT * FROM center_rooms WHERE branch_id=?' . ($activeOnly ? ' AND active=1' : '') . ' ORDER BY active DESC,name', [$branch])->fetchAll();
}

function center_room_save(int $center, array $data): int
{
    $branch = positive_id($data['branch_id'] ?? 0);
    center_actor($center, 'center', $branch);
    center_branch($center, $branch);
    $id = empty($data['id']) ? 0 : positive_id($data['id']);
    $name = field($data, 'name', 120);
    $active = !empty($data['active']) ? 1 : 0;
    return db_transaction(function () use ($center, $branch, $id, $name, $active, $data) {
        $dup = query('SELECT id FROM center_rooms WHERE branch_id=? AND name=? AND id<>?', [$branch, $name, $id])->fetch();
        if ($dup) {
            throw new AuthError('اتاقی با این نام در این شعبه وجود دارد.', 409);
        }
        if ($id) {
            if (!query('SELECT id FROM center_rooms WHERE id=? AND branch_id=?', [$id, $branch])->fetch()) {
                throw new AuthError('اتاق پیدا نشد.', 404);
            }
            if (!$active && query('SELECT id FROM working_hours WHERE room_id=? AND active=1', [$id])->fetch()) {
                throw new AuthError('این اتاق در برنامه هفتگی پزشکان استفاده می‌شود؛ ابتدا آن ساعت‌ها را حذف یا به اتاق دیگری منتقل کنید.', 409);
            }
            query('UPDATE center_rooms SET name=?,note=?,active=? WHERE id=?', [$name, field($data, 'note', 255, false), $active, $id]);
        } else {
            query('INSERT INTO center_rooms(branch_id,name,note,active) VALUES(?,?,?,?)', [$branch, $name, field($data, 'note', 255, false), $active]);
            $id = (int) db()->lastInsertId();
        }
        center_event($center, 'room.save', ['room_id' => $id, 'branch_id' => $branch, 'name' => $name, 'active' => $active]);
        return $id;
    });
}

/* ---------- Staff ---------- */

function center_staff_list(int $center): array
{
    return query(
        'SELECT s.*,u.name,u.mobile,b.name branch FROM center_staff s JOIN users u ON u.id=s.user_id LEFT JOIN center_branches b ON b.id=s.branch_id WHERE s.center_id=? ORDER BY s.active DESC,s.role=\'owner\' DESC,u.name',
        [$center],
    )->fetchAll();
}

function center_staff_save(int $center, array $data): void
{
    $a = center_actor($center, 'staff');
    $role = (string) ($data['role'] ?? 'staff');
    if (!isset(center_roles()[$role]) || $role === 'owner') {
        throw new AuthError('نقش کارمند نامعتبر است.');
    }
    $branch = empty($data['branch_id']) ? null : positive_id($data['branch_id']);
    if ($branch) {
        center_branch($center, $branch);
    }
    if ($a['staff']['branch_id'] && $branch !== (int) $a['staff']['branch_id']) {
        throw new AuthError('فقط برای شعبه خودتان می‌توانید کارمند تعریف کنید.', 403);
    }
    $mobile = normalize_mobile(digits(field($data, 'mobile', 20)));
    $user = query('SELECT id FROM users WHERE mobile=? AND active=1 AND verified_at IS NOT NULL', [$mobile])->fetch();
    if (!$user) {
        throw new AuthError('کارمند باید ابتدا یک بار با همین موبایل وارد سامانه شود.');
    }
    if ((int) $user['id'] === (int) $a['user']['id']) {
        throw new AuthError('دسترسی خودتان را از این بخش تغییر ندهید.');
    }
    $perms = [];
    foreach (array_keys(center_permissions()) as $p) {
        $perms[$p] = !empty($data[$p]) ? 1 : 0;
        // Nobody can hand out a permission they do not hold.
        if ($perms[$p] && empty($a['staff'][$p])) {
            throw new AuthError('نمی‌توانید دسترسی «' . center_permissions()[$p] . '» را که خودتان ندارید، واگذار کنید.', 403);
        }
    }
    db_transaction(function () use ($center, $user, $role, $branch, $perms) {
        $old = query('SELECT * FROM center_staff WHERE center_id=? AND user_id=?', [$center, $user['id']])->fetch();
        if ($old && $old['role'] === 'owner') {
            throw new AuthError('دسترسی مالک مرکز قابل تغییر نیست.', 403);
        }
        $cols = array_keys($perms);
        query(
            'INSERT INTO center_staff(center_id,user_id,role,branch_id,active,' . implode(',', $cols) . ') VALUES(?,?,?,?,1' . str_repeat(',?', count($cols)) .
                ') ON DUPLICATE KEY UPDATE role=VALUES(role),branch_id=VALUES(branch_id),active=1,' .
                implode(',', array_map(fn($c) => "$c=VALUES($c)", $cols)),
            [$center, $user['id'], $role, $branch, ...array_values($perms)],
        );
        query("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'clinic')", [$user['id']]);
        center_event($center, 'staff.save', ['user_id' => $user['id'], 'role' => $role, 'branch_id' => $branch, 'permissions' => array_keys(array_filter($perms))]);
        center_sync_scopes($center);
    });
}

function center_staff_remove(int $center, int $userId): void
{
    $a = center_actor($center, 'staff');
    $s = query('SELECT * FROM center_staff WHERE center_id=? AND user_id=?', [$center, $userId])->fetch();
    if (!$s) {
        throw new AuthError('کارمند پیدا نشد.', 404);
    }
    if ($s['role'] === 'owner') {
        throw new AuthError('مالک مرکز را نمی‌توان حذف کرد.', 403);
    }
    if ($a['staff']['branch_id'] && (int) $s['branch_id'] !== (int) $a['staff']['branch_id']) {
        throw new AuthError('این کارمند در شعبه شما نیست.', 403);
    }
    db_transaction(function () use ($center, $userId) {
        query('UPDATE center_staff SET active=0 WHERE center_id=? AND user_id=?', [$center, $userId]);
        if (!query('SELECT id FROM center_staff WHERE user_id=? AND active=1', [$userId])->fetch()) {
            query("DELETE FROM user_roles WHERE user_id=? AND role='clinic'", [$userId]);
        }
        center_event($center, 'staff.remove', ['user_id' => $userId]);
        center_sync_scopes($center);
    });
}

/* ---------- Reception access for center staff ---------- */

/**
 * Rebuilds the reception scopes (staff_scopes rows tagged with this center) from: center open state,
 * active staff with booking permission, active doctor links with booking permission, active center
 * clinic rows and the staff member's branch. A scope the doctor granted directly (center_id NULL) is
 * never touched.
 */
function center_sync_scopes(int $center): void
{
    $users = query('SELECT DISTINCT user_id FROM staff_scopes WHERE center_id=?', [$center])->fetchAll(PDO::FETCH_COLUMN);
    query('DELETE FROM staff_scopes WHERE center_id=?', [$center]);
    $c = query('SELECT * FROM centers WHERE id=?', [$center])->fetch();
    if ($c && center_is_open($c)) {
        $rows = query(
            "SELECT s.user_id,cl.id clinic_id,(s.perm_schedule AND l.perm_schedule) sch,(s.perm_history AND l.perm_history) hist FROM center_staff s JOIN users u ON u.id=s.user_id AND u.active=1 JOIN center_doctors l ON l.center_id=s.center_id AND l.status='active' AND l.perm_bookings=1 JOIN clinics cl ON cl.center_id=s.center_id AND cl.doctor_id=l.doctor_id AND cl.active=1 JOIN center_branches b ON b.id=cl.branch_id AND b.active=1 WHERE s.center_id=? AND s.active=1 AND s.perm_bookings=1 AND (s.branch_id IS NULL OR s.branch_id=cl.branch_id)",
            [$center],
        )->fetchAll();
        foreach ($rows as $r) {
            query(
                "INSERT IGNORE INTO staff_scopes(user_id,clinic_id,role,can_manage_schedule,can_manage_bookings,can_view_history,center_id) VALUES(?,?,'secretary',?,1,?,?)",
                [$r['user_id'], $r['clinic_id'], (int) $r['sch'], (int) $r['hist'], $center],
            );
            query("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'secretary')", [$r['user_id']]);
            $users[] = $r['user_id'];
        }
    }
    foreach (array_unique(array_map('intval', $users)) as $uid) {
        if (!query("SELECT user_id FROM staff_scopes WHERE user_id=? AND role='secretary'", [$uid])->fetch()) {
            query("DELETE FROM user_roles WHERE user_id=? AND role='secretary'", [$uid]);
        }
    }
}

/** Daily: withdraws access of centers whose subscription ran out and restores renewed ones. */
function center_sync_all(): int
{
    $n = 0;
    foreach (query('SELECT id FROM centers')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        db_transaction(fn() => center_sync_scopes((int) $id));
        $n++;
    }
    return $n;
}

/* ---------- Doctor links ---------- */

function link_permissions_from(array $data): array
{
    $out = [];
    foreach (array_keys(link_permissions()) as $p) {
        $out[$p] = !empty($data[$p]) ? 1 : 0;
    }
    if ($out['perm_history'] && !$out['perm_bookings']) {
        throw new AuthError('دسترسی سوابق بدون دسترسی نوبت‌ها معنا ندارد.');
    }
    return $out;
}

function center_link(int $id): array
{
    $l = query('SELECT l.*,ce.name center_name,d.name doctor_name FROM center_doctors l JOIN centers ce ON ce.id=l.center_id JOIN doctors d ON d.id=l.doctor_id WHERE l.id=?', [$id])->fetch();
    if (!$l) {
        throw new AuthError('ارتباط پیدا نشد.', 404);
    }
    return $l;
}

/** Center invites an approved doctor by medical council number; the doctor decides the permissions. */
function center_invite_doctor(int $center, array $data): void
{
    center_actor($center, 'doctors');
    $code = trim(digits(field($data, 'medical_code', 30)));
    $doctor = query("SELECT id,name FROM doctors WHERE medical_code=? AND review_status='approved' AND active=1", [$code])->fetch();
    if (!$doctor) {
        throw new AuthError('پزشک تأییدشده‌ای با این شماره نظام پزشکی پیدا نشد.', 404);
    }
    $perms = link_permissions_from($data);
    $message = field($data, 'message', 500, false);
    db_transaction(function () use ($center, $doctor, $perms, $message) {
        $old = query('SELECT * FROM center_doctors WHERE center_id=? AND doctor_id=? FOR UPDATE', [$center, $doctor['id']])->fetch();
        if ($old && in_array($old['status'], ['active', 'invited'], true)) {
            throw new AuthError($old['status'] === 'active' ? 'این پزشک هم‌اکنون عضو مرکز است.' : 'دعوت قبلی هنوز در انتظار پاسخ پزشک است.', 409);
        }
        if ($old && $old['status'] === 'requested') {
            throw new AuthError('این پزشک قبلاً درخواست همکاری داده است؛ درخواست او را از فهرست بپذیرید.', 409);
        }
        $cols = array_keys($perms);
        query(
            "INSERT INTO center_doctors(center_id,doctor_id,status,initiated_by,message,created_by_user," . implode(',', $cols) . ") VALUES(?,?,'invited','center',?,?" . str_repeat(',?', count($cols)) .
                ") ON DUPLICATE KEY UPDATE status='invited',initiated_by='center',message=VALUES(message),created_by_user=VALUES(created_by_user),responded_at=NULL,ended_at=NULL," .
                implode(',', array_map(fn($c) => "$c=VALUES($c)", $cols)),
            [$center, $doctor['id'], $message, $_SESSION['user_id'] ?? null, ...array_values($perms)],
        );
        $c = center_row($center);
        notify_doctor((int) $doctor['id'], 'دعوت همکاری از ' . $c['name'], 'برای پاسخ و تعیین دسترسی‌ها به بخش «مراکز درمانی» پنل پزشک بروید.' . ($message !== '' ? ' پیام: ' . $message : ''));
        center_event($center, 'link.invited', ['doctor_id' => $doctor['id'], 'permissions' => array_keys(array_filter($perms))]);
    });
}

/** Doctor asks to join an approved center (by its public code) with the permissions he chooses. */
function doctor_center_request(int $doctor, array $data): void
{
    doctor_actor($doctor, 'owner');
    $center = positive_id(digits((string) ($data['center_code'] ?? '')));
    $c = query("SELECT * FROM centers WHERE id=? AND review_status='approved' AND active=1", [$center])->fetch();
    if (!$c) {
        throw new AuthError('مرکز فعالی با این کد پیدا نشد.', 404);
    }
    $perms = link_permissions_from($data);
    $message = field($data, 'message', 500, false);
    db_transaction(function () use ($c, $doctor, $perms, $message) {
        $old = query('SELECT * FROM center_doctors WHERE center_id=? AND doctor_id=? FOR UPDATE', [$c['id'], $doctor])->fetch();
        if ($old && in_array($old['status'], ['active', 'requested', 'invited'], true)) {
            throw new AuthError(
                ['active' => 'شما هم‌اکنون عضو این مرکز هستید.', 'requested' => 'درخواست قبلی شما در انتظار پاسخ مرکز است.', 'invited' => 'این مرکز شما را دعوت کرده است؛ دعوت را بپذیرید.'][$old['status']],
                409,
            );
        }
        $cols = array_keys($perms);
        query(
            "INSERT INTO center_doctors(center_id,doctor_id,status,initiated_by,message,created_by_user," . implode(',', $cols) . ") VALUES(?,?,'requested','doctor',?,?" . str_repeat(',?', count($cols)) .
                ") ON DUPLICATE KEY UPDATE status='requested',initiated_by='doctor',message=VALUES(message),created_by_user=VALUES(created_by_user),responded_at=NULL,ended_at=NULL," .
                implode(',', array_map(fn($x) => "$x=VALUES($x)", $cols)),
            [$c['id'], $doctor, $message, $_SESSION['user_id'] ?? null, ...array_values($perms)],
        );
        $name = query('SELECT name FROM doctors WHERE id=?', [$doctor])->fetchColumn();
        center_notify((int) $c['id'], 'perm_doctors', 'درخواست همکاری ' . $name, 'برای بررسی به بخش پزشکان پنل مرکز بروید.');
        center_event((int) $c['id'], 'link.requested', ['doctor_id' => $doctor]);
    });
}

/** Doctor answers an invitation; on acceptance he confirms (or narrows) the permissions. */
function doctor_link_respond(int $doctor, int $linkId, bool $accept, array $data = []): void
{
    doctor_actor($doctor, 'owner');
    db_transaction(function () use ($doctor, $linkId, $accept, $data) {
        $l = query('SELECT * FROM center_doctors WHERE id=? AND doctor_id=? FOR UPDATE', [$linkId, $doctor])->fetch();
        if (!$l || $l['status'] !== 'invited') {
            throw new AuthError('دعوت فعالی پیدا نشد.', 404);
        }
        if ($accept) {
            $perms = link_permissions_from($data);
            query(
                "UPDATE center_doctors SET status='active',responded_at=NOW()," . implode(',', array_map(fn($c) => "$c=?", array_keys($perms))) . ' WHERE id=?',
                [...array_values($perms), $linkId],
            );
        } else {
            query("UPDATE center_doctors SET status='rejected',responded_at=NOW() WHERE id=?", [$linkId]);
        }
        $name = query('SELECT name FROM doctors WHERE id=?', [$doctor])->fetchColumn();
        center_notify((int) $l['center_id'], 'perm_doctors', ($accept ? 'پذیرش دعوت: ' : 'رد دعوت: ') . $name, $accept ? 'پزشک به مرکز پیوست؛ حضور او را در شعبه‌ها تعریف کنید.' : 'پزشک دعوت همکاری را نپذیرفت.');
        center_event((int) $l['center_id'], $accept ? 'link.accepted' : 'link.rejected', ['doctor_id' => $doctor, 'by' => 'doctor']);
        center_sync_scopes((int) $l['center_id']);
    });
}

/** Center answers a doctor's request; the doctor's chosen permissions stay as he set them. */
function center_link_respond(int $center, int $linkId, bool $accept): void
{
    center_actor($center, 'doctors');
    db_transaction(function () use ($center, $linkId, $accept) {
        $l = query('SELECT * FROM center_doctors WHERE id=? AND center_id=? FOR UPDATE', [$linkId, $center])->fetch();
        if (!$l || $l['status'] !== 'requested') {
            throw new AuthError('درخواست فعالی پیدا نشد.', 404);
        }
        query('UPDATE center_doctors SET status=?,responded_at=NOW() WHERE id=?', [$accept ? 'active' : 'rejected', $linkId]);
        $c = center_row($center);
        notify_doctor((int) $l['doctor_id'], ($accept ? 'پذیرش درخواست همکاری: ' : 'رد درخواست همکاری: ') . $c['name'], $accept ? 'اکنون می‌توانید در شعبه‌های مرکز نوبت‌دهی کنید.' : 'مرکز درخواست همکاری را نپذیرفت.');
        center_event($center, $accept ? 'link.accepted' : 'link.rejected', ['doctor_id' => $l['doctor_id'], 'by' => 'center']);
        center_sync_scopes($center);
    });
}

/** Only the doctor changes what the center may access. */
function doctor_link_permissions(int $doctor, int $linkId, array $data): void
{
    doctor_actor($doctor, 'owner');
    $perms = link_permissions_from($data);
    db_transaction(function () use ($doctor, $linkId, $perms) {
        $l = query("SELECT * FROM center_doctors WHERE id=? AND doctor_id=? AND status IN ('active','requested') FOR UPDATE", [$linkId, $doctor])->fetch();
        if (!$l) {
            throw new AuthError('ارتباط فعالی پیدا نشد.', 404);
        }
        query('UPDATE center_doctors SET ' . implode(',', array_map(fn($c) => "$c=?", array_keys($perms))) . ' WHERE id=?', [...array_values($perms), $linkId]);
        center_event((int) $l['center_id'], 'link.permissions', ['doctor_id' => $doctor, 'permissions' => array_keys(array_filter($perms))]);
        center_sync_scopes((int) $l['center_id']);
    });
}

/**
 * Ends a link (either side). New bookings at the center's branches stop; confirmed future bookings stay
 * and are reported so they can be handled with the patients.
 */
function link_end(int $linkId, string $by, string $reason): int
{
    $l = center_link($linkId);
    if ($by === 'doctor') {
        doctor_actor((int) $l['doctor_id'], 'owner');
    } else {
        center_actor((int) $l['center_id'], 'doctors');
    }
    if (mb_strlen(trim($reason)) < 3) {
        throw new AuthError('علت پایان همکاری را بنویسید.');
    }
    return db_transaction(function () use ($l, $by, $reason) {
        $row = query('SELECT status FROM center_doctors WHERE id=? FOR UPDATE', [$l['id']])->fetchColumn();
        if (!in_array($row, ['active', 'invited', 'requested'], true)) {
            throw new AuthError('این ارتباط فعال نیست.', 409);
        }
        query("UPDATE center_doctors SET status='ended',ended_at=NOW() WHERE id=?", [$l['id']]);
        query('UPDATE clinics SET active=0 WHERE center_id=? AND doctor_id=?', [$l['center_id'], $l['doctor_id']]);
        $future = (int) query(
            "SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN clinics cl ON cl.id=s.clinic_id WHERE cl.center_id=? AND cl.doctor_id=? AND b.status='confirmed' AND s.starts_at>NOW()",
            [$l['center_id'], $l['doctor_id']],
        )->fetchColumn();
        $text = 'همکاری ' . $l['doctor_name'] . ' و ' . $l['center_name'] . ' پایان یافت. علت: ' . $reason . ($future ? ' • ' . fa_number($future) . ' نوبت قطعی آینده حفظ شده است.' : '');
        if ($by === 'doctor') {
            center_notify((int) $l['center_id'], 'perm_doctors', 'پایان همکاری پزشک', $text);
        } else {
            notify_doctor((int) $l['doctor_id'], 'پایان همکاری مرکز', $text);
        }
        center_event((int) $l['center_id'], 'link.ended', ['doctor_id' => $l['doctor_id'], 'by' => $by, 'reason' => $reason, 'future_bookings' => $future]);
        center_sync_scopes((int) $l['center_id']);
        return $future;
    });
}

function center_doctor_links(int $center): array
{
    return query(
        'SELECT l.*,d.name doctor_name,d.medical_code,d.specialty,d.review_status,d.active doctor_active,d.subscription_until FROM center_doctors l JOIN doctors d ON d.id=l.doctor_id WHERE l.center_id=? ORDER BY FIELD(l.status,\'requested\',\'invited\',\'active\',\'rejected\',\'ended\'),d.name',
        [$center],
    )->fetchAll();
}

function active_link(int $center, int $doctor): array
{
    $l = query("SELECT * FROM center_doctors WHERE center_id=? AND doctor_id=? AND status='active'", [$center, $doctor])->fetch();
    if (!$l) {
        throw new AuthError('همکاری فعالی با این پزشک وجود ندارد.', 403);
    }
    return $l;
}

/* ---------- Doctor presence in branches (clinics rows) ---------- */

/**
 * Who may manage a doctor's presence/schedule in a branch: the doctor himself, or center staff with
 * schedule permission when the doctor granted schedule management.
 */
function presence_actor(int $center, int $doctor, int $branch): string
{
    $mine = has_role('doctor') && query('SELECT doctor_id FROM doctor_accounts WHERE doctor_id=? AND user_id=?', [$doctor, require_user()['id']])->fetch();
    active_link($center, $doctor);
    if ($mine) {
        $c = center_row($center);
        if ($c['review_status'] !== 'approved' || !$c['active']) {
            throw new AuthError('مرکز فعال نیست.', 403);
        }
        return 'doctor';
    }
    center_actor($center, 'schedule', $branch);
    if (!active_link($center, $doctor)['perm_schedule']) {
        throw new AuthError('پزشک مدیریت برنامه کاری خود را به مرکز نسپرده است.', 403);
    }
    return 'center';
}

function presence_add(int $center, int $doctor, int $branch): int
{
    $b = center_branch($center, $branch);
    if (!$b['active']) {
        throw new AuthError('شعبه غیرفعال است.');
    }
    presence_actor($center, $doctor, $branch);
    return db_transaction(function () use ($center, $doctor, $b) {
        lock_doctor($doctor);
        $c = center_row($center);
        $existing = query('SELECT id,active FROM clinics WHERE doctor_id=? AND branch_id=?', [$doctor, $b['id']])->fetch();
        if ($existing && $existing['active']) {
            throw new AuthError('این پزشک در این شعبه تعریف شده است.', 409);
        }
        if ($existing) {
            query('UPDATE clinics SET active=1 WHERE id=?', [$existing['id']]);
            $id = (int) $existing['id'];
        } else {
            query(
                'INSERT INTO clinics(doctor_id,name,city_id,address,phone,active,is_primary,latitude,longitude,center_id,branch_id) VALUES(?,?,?,?,?,1,0,?,?,?,?)',
                [$doctor, center_clinic_name($c['name'], $b['name']), $b['city_id'], $b['address'], $b['phone'] !== '' ? $b['phone'] : $c['phone'], $b['latitude'], $b['longitude'], $center, $b['id']],
            );
            $id = (int) db()->lastInsertId();
        }
        event_record($doctor, 'center.presence', ['clinic_id' => $id, 'center_id' => $center, 'branch_id' => $b['id']]);
        center_event($center, 'presence.add', ['doctor_id' => $doctor, 'branch_id' => $b['id'], 'clinic_id' => $id]);
        center_sync_scopes($center);
        return $id;
    });
}

function center_clinic(int $center, int $clinic): array
{
    $c = query('SELECT * FROM clinics WHERE id=? AND center_id=?', [$clinic, $center])->fetch();
    if (!$c) {
        throw new AuthError('حضور پزشک در شعبه پیدا نشد.', 404);
    }
    return $c;
}

function presence_remove(int $center, int $clinic): int
{
    $cl = center_clinic($center, $clinic);
    presence_actor($center, (int) $cl['doctor_id'], (int) $cl['branch_id']);
    return db_transaction(function () use ($center, $cl) {
        query('UPDATE clinics SET active=0 WHERE id=?', [$cl['id']]);
        $future = (int) query("SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id=? AND b.status='confirmed' AND s.starts_at>NOW()", [$cl['id']])->fetchColumn();
        event_record((int) $cl['doctor_id'], 'center.presence_removed', ['clinic_id' => $cl['id'], 'future_bookings' => $future]);
        center_event($center, 'presence.remove', ['clinic_id' => $cl['id'], 'doctor_id' => $cl['doctor_id'], 'future_bookings' => $future]);
        center_sync_scopes($center);
        return $future;
    });
}

function presence_settings(int $center, int $clinic, array $data): void
{
    $cl = center_clinic($center, $clinic);
    presence_actor($center, (int) $cl['doctor_id'], (int) $cl['branch_id']);
    query('UPDATE clinics SET default_duration=?,gap_minutes=?,daily_capacity=?,slot_capacity=? WHERE id=?', [
        number_range($data, 'default_duration', 5, 180),
        number_range($data, 'gap_minutes', 0, 120),
        number_range($data, 'daily_capacity', 1, 500),
        number_range($data, 'slot_capacity', 1, 20),
        $clinic,
    ]);
    center_event($center, 'presence.settings', ['clinic_id' => $clinic]);
}

/**
 * Adds a weekly working period. Shared by the doctor panel and the center panel so the same rules hold:
 * no overlap with the doctor's other clinics, and in a center no two doctors in one room at once.
 */
function working_hours_add(int $doctor, int $clinic, array $data): array
{
    $day = number_range($data, 'weekday', 0, 6);
    $start = digits(field($data, 'opens_at', 5));
    $end = digits(field($data, 'closes_at', 5));
    foreach ([$start, $end] as $t) {
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $t)) {
            throw new AuthError('ساعت به شکل ۰۹:۳۰ وارد شود.');
        }
    }
    if ($start >= $end) {
        throw new AuthError('پایان حضور باید بعد از شروع و در همان روز باشد.');
    }
    if (query('SELECT h.id FROM working_hours h JOIN clinics c ON c.id=h.clinic_id WHERE c.doctor_id=? AND h.active=1 AND h.weekday=? AND h.opens_at<? AND h.closes_at>?', [$doctor, $day, $end, $start])->fetch()) {
        throw new AuthError('این ساعت با برنامه هفتگی یکی از مطب‌ها یا شعبه‌های پزشک تداخل دارد.');
    }
    $cl = query('SELECT * FROM clinics WHERE id=?', [$clinic])->fetch();
    $room = empty($data['room_id']) ? null : positive_id($data['room_id']);
    if ($room) {
        if (!$cl['branch_id'] || !query('SELECT id FROM center_rooms WHERE id=? AND branch_id=? AND active=1', [$room, $cl['branch_id']])->fetch()) {
            throw new AuthError('اتاق انتخاب‌شده در این شعبه فعال نیست.');
        }
        query('SELECT id FROM center_rooms WHERE id=? FOR UPDATE', [$room]);
        $clash = query(
            'SELECT d.name FROM working_hours h JOIN clinics c ON c.id=h.clinic_id JOIN doctors d ON d.id=c.doctor_id WHERE h.room_id=? AND h.active=1 AND c.active=1 AND h.weekday=? AND h.opens_at<? AND h.closes_at>? LIMIT 1',
            [$room, $day, $end, $start],
        )->fetchColumn();
        if ($clash !== false) {
            throw new AuthError('این اتاق در همین ساعت به ' . $clash . ' اختصاص دارد؛ اتاق یا ساعت دیگری انتخاب کنید.', 409);
        }
    }
    $len = empty($data['slot_minutes']) ? null : number_range($data, 'slot_minutes', 5, 180);
    query('INSERT INTO working_hours(clinic_id,weekday,opens_at,closes_at,room_id,slot_minutes) VALUES(?,?,?,?,?,?)', [$clinic, $day, $start, $end, $room, $len]);
    return ['clinic_id' => $clinic, 'weekday' => $day, 'start' => $start, 'end' => $end, 'room_id' => $room, 'slot_minutes' => $len, 'hours_id' => (int) db()->lastInsertId()];
}

function center_hours_add(int $center, int $clinic, array $data): void
{
    $cl = center_clinic($center, $clinic);
    presence_actor($center, (int) $cl['doctor_id'], (int) $cl['branch_id']);
    if (!$cl['active']) {
        throw new AuthError('حضور پزشک در این شعبه غیرفعال است.');
    }
    db_transaction(function () use ($center, $cl, $data) {
        lock_doctor((int) $cl['doctor_id']);
        $details = working_hours_add((int) $cl['doctor_id'], (int) $cl['id'], $data);
        event_record((int) $cl['doctor_id'], 'schedule.hours', $details + ['by_center' => $center]);
        center_event($center, 'hours.add', $details + ['doctor_id' => $cl['doctor_id']]);
    });
}

function center_hours_remove(int $center, int $hoursId): void
{
    $h = query('SELECT h.*,c.doctor_id,c.branch_id,c.center_id FROM working_hours h JOIN clinics c ON c.id=h.clinic_id WHERE h.id=? AND c.center_id=? AND h.active=1', [$hoursId, $center])->fetch();
    if (!$h) {
        throw new AuthError('ساعت کاری پیدا نشد.', 404);
    }
    presence_actor($center, (int) $h['doctor_id'], (int) $h['branch_id']);
    db_transaction(function () use ($center, $h) {
        lock_doctor((int) $h['doctor_id']);
        query('UPDATE working_hours SET active=0 WHERE id=?', [$h['id']]);
        event_record((int) $h['doctor_id'], 'schedule.hours_removed', ['hours_id' => $h['id'], 'by_center' => $center]);
        center_event($center, 'hours.remove', ['hours_id' => $h['id'], 'doctor_id' => $h['doctor_id']]);
    });
}

/** Weekly schedule of a center: every active period with doctor, branch and room. */
function center_schedule(int $center, int $branch = 0): array
{
    $sql = 'SELECT h.*,cl.id clinic_id,cl.branch_id,cl.doctor_id,d.name doctor,b.name branch,r.name room FROM working_hours h JOIN clinics cl ON cl.id=h.clinic_id JOIN doctors d ON d.id=cl.doctor_id JOIN center_branches b ON b.id=cl.branch_id LEFT JOIN center_rooms r ON r.id=h.room_id WHERE cl.center_id=? AND cl.active=1 AND h.active=1';
    $args = [$center];
    if ($branch) {
        $sql .= ' AND cl.branch_id=?';
        $args[] = $branch;
    }
    return query($sql . ' ORDER BY h.weekday,h.opens_at,b.sort_order,d.name', $args)->fetchAll();
}

function center_presences(int $center, int $branch = 0, bool $activeOnly = true): array
{
    $sql = "SELECT cl.*,d.name doctor,d.specialty,b.name branch,l.perm_schedule,l.perm_bookings,l.perm_history,l.perm_reports FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id JOIN center_branches b ON b.id=cl.branch_id LEFT JOIN center_doctors l ON l.center_id=cl.center_id AND l.doctor_id=cl.doctor_id AND l.status='active' WHERE cl.center_id=?";
    $args = [$center];
    if ($branch) {
        $sql .= ' AND cl.branch_id=?';
        $args[] = $branch;
    }
    if ($activeOnly) {
        $sql .= ' AND cl.active=1 AND l.id IS NOT NULL';
    }
    return query($sql . ' ORDER BY b.sort_order,d.name', $args)->fetchAll();
}

/* ---------- Public ---------- */

/** Centers shown publicly: approved, active and with an open subscription. */
function public_center(int $id): array
{
    $c = query('SELECT ce.*,ci.name city,p.name province FROM centers ce JOIN cities ci ON ci.id=ce.city_id JOIN provinces p ON p.id=ci.province_id WHERE ce.id=? AND ' . center_open_sql('ce'), [$id])->fetch();
    if (!$c) {
        throw new AuthError('مرکز پیدا نشد یا در حال حاضر فعال نیست.', 404);
    }
    return $c;
}

function public_center_doctors(int $center): array
{
    return query(
        "SELECT DISTINCT d.id,d.name,d.specialty,d.professional_title,d.photo_file,cl.id clinic_id,b.id branch_id,b.name branch FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id JOIN specialties sp ON sp.id=d.specialty_id JOIN center_branches b ON b.id=cl.branch_id AND b.active=1 JOIN center_doctors l ON l.center_id=cl.center_id AND l.doctor_id=d.id AND l.status='active' JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE cl.center_id=? AND " .
            visible_doctor_sql() .
            ' AND ' .
            public_clinic_sql() .
            ' ORDER BY b.sort_order,d.name',
        [$center],
    )->fetchAll();
}

function public_centers_in_city(int $city, int $limit = 12): array
{
    return query(
        'SELECT ce.id,ce.name,ce.kind,ce.logo_file,ce.address,(SELECT COUNT(DISTINCT l.doctor_id) FROM center_doctors l WHERE l.center_id=ce.id AND l.status=\'active\') doctors FROM centers ce WHERE ' .
            center_open_sql('ce') .
            ' AND (ce.city_id=? OR EXISTS(SELECT 1 FROM center_branches b WHERE b.center_id=ce.id AND b.active=1 AND b.city_id=?)) ORDER BY ce.name LIMIT ' .
            max(1, min(50, $limit)),
        [$city, $city],
    )->fetchAll();
}

/* ---------- Doctor panel side ---------- */

function doctor_center_data(int $doctor): array
{
    $links = query(
        'SELECT l.*,ce.name center_name,ce.kind,ce.review_status center_status,ce.active center_active,ci.name city FROM center_doctors l JOIN centers ce ON ce.id=l.center_id JOIN cities ci ON ci.id=ce.city_id WHERE l.doctor_id=? ORDER BY FIELD(l.status,\'invited\',\'requested\',\'active\',\'rejected\',\'ended\'),ce.name',
        [$doctor],
    )->fetchAll();
    foreach ($links as &$l) {
        $l['branches'] = $l['status'] === 'active' ? center_branches((int) $l['center_id'], null, true) : [];
        $l['presences'] = query('SELECT id,branch_id,active FROM clinics WHERE doctor_id=? AND center_id=?', [$doctor, $l['center_id']])->fetchAll();
    }
    unset($l);
    return ['links' => $links];
}

/** POST actions of the doctor panel "centers" tab (owner only). */
function doctor_center_action(int $doctor, string $action, array $data): string
{
    doctor_actor($doctor, 'owner');
    $link = fn() => positive_id($data['link_id'] ?? 0);
    switch ($action) {
        case 'center_request':
            require_feature($doctor, 'centers'); // rc.20: free by default; Pro-only if the admin ticks it
            doctor_center_request($doctor, $data);
            return 'درخواست همکاری برای مرکز ارسال شد.';
        case 'center_accept':
            doctor_link_respond($doctor, $link(), true, $data);
            return 'همکاری فعال شد. حضور در شعبه‌ها را تعریف کنید.';
        case 'center_reject':
            doctor_link_respond($doctor, $link(), false);
            return 'دعوت رد شد.';
        case 'center_perms':
            doctor_link_permissions($doctor, $link(), $data);
            return 'دسترسی‌های مرکز به‌روز شد.';
        case 'center_end':
            $l = center_link($link());
            if ((int) $l['doctor_id'] !== $doctor) {
                throw new AuthError('ارتباط پیدا نشد.', 404);
            }
            $future = link_end((int) $l['id'], 'doctor', field($data, 'reason', 500));
            return 'همکاری پایان یافت.' . ($future ? ' ' . fa_number($future) . ' نوبت قطعی آینده در شعبه‌های مرکز باقی است؛ آن‌ها را با بیماران هماهنگ کنید.' : '');
        case 'center_presence':
            $l = center_link($link());
            if ((int) $l['doctor_id'] !== $doctor) {
                throw new AuthError('ارتباط پیدا نشد.', 404);
            }
            presence_add((int) $l['center_id'], $doctor, positive_id($data['branch_id'] ?? 0));
            return 'حضور در شعبه ثبت شد؛ ساعت کاری آن را در «برنامه کاری» تعیین کنید.';
        case 'center_presence_remove':
            $cl = query('SELECT * FROM clinics WHERE id=? AND doctor_id=? AND center_id IS NOT NULL', [positive_id($data['clinic_id'] ?? 0), $doctor])->fetch();
            if (!$cl) {
                throw new AuthError('حضور در شعبه پیدا نشد.', 404);
            }
            $future = presence_remove((int) $cl['center_id'], (int) $cl['id']);
            return 'نوبت‌دهی در این شعبه متوقف شد.' . ($future ? ' ' . fa_number($future) . ' نوبت قطعی آینده حفظ شده است.' : '');
    }
    throw new AuthError('عملیات نامعتبر است.');
}
