<?php
declare(strict_types=1);
function digits(string $value): string
{
    return strtr(
        $value,
        array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789'),
        ),
    );
}
function field(array $data, string $key, int $max, bool $required = true): string
{
    if (isset($data[$key]) && !is_scalar($data[$key])) {
        throw new RuntimeException('مقدار فیلد نامعتبر است.');
    }
    $v = trim((string) ($data[$key] ?? ''));
    $v = strtr($v, ['ي' => 'ی', 'ك' => 'ک']);
    if (($required && $v === '') || mb_strlen($v) > $max) {
        throw new RuntimeException(
            'فیلد «' .
                ([
                    'name' => 'نام',
                    'mobile' => 'موبایل',
                    'medical_code' => 'نظام پزشکی',
                    'bio' => 'معرفی',
                    'clinic_name' => 'نام مطب',
                    'address' => 'نشانی',
                    'phone' => 'تلفن',
                    'email' => 'ایمیل',
                    'password' => 'رمز عبور',
                    'until' => 'پایان اشتراک',
                    'starts_at' => 'زمان نوبت',
                    'fee' => 'کارمزد',
                    'code' => 'کد تأیید',
                    'template' => 'الگو',
                    'review_note' => 'یادداشت بررسی',
                    'subject' => 'موضوع',
                    'body' => 'متن',
                    'title' => 'عنوان',
                    'subtitle' => 'توضیح',
                    'link_label' => 'متن دکمه',
                ][$key] ??
                    'درخواستی') .
                '» خالی یا طول آن نامعتبر است.',
        );
    }
    return $v;
}
function positive_id($value): int
{
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/', (string) $value)) {
        throw new RuntimeException('شناسه نامعتبر است.');
    }
    return (int) $value;
}
function active_city(int $id, int $province): array
{
    $c = query(
        'SELECT c.* FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.id=? AND p.id=? AND c.active=1 AND p.active=1',
        [$id, $province],
    )->fetch();
    if (!$c) {
        throw new RuntimeException('شهر انتخاب‌شده در استان فعال انتخابی وجود ندارد.');
    }
    return $c;
}
function throttle(string $kind, int $limit, int $seconds): void
{
    $bucket = $kind . ':' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local');
    $now = time();
    query(
        'INSERT INTO request_limits(bucket,window_start,hits) VALUES(?,?,1) ON DUPLICATE KEY UPDATE hits=IF(window_start<=?,1,hits+1),window_start=IF(window_start<=?,VALUES(window_start),window_start)',
        [$bucket, $now, $now - $seconds, $now - $seconds],
    );
    $hits = (int) query('SELECT hits FROM request_limits WHERE bucket=?', [$bucket])->fetchColumn();
    if ($hits > $limit) {
        if ($hits === $limit + 1) {
            app_log('security', 'rate_limit', ['kind' => $kind]);
        }
        throw new RuntimeException('تعداد درخواست‌ها زیاد است. کمی بعد دوباره تلاش کنید.');
    }
}
function doctor_data(array $data, bool $allowOther = false): array
{
    $name = field($data, 'name', 190);
    validate_doctor_name($name);
    if (mb_strlen($name) < 3) {
        throw new RuntimeException('نام کامل را وارد کنید.');
    }
    $mobile = digits(field($data, 'mobile', 20));
    if (!preg_match('/^09[0-9]{9}$/', $mobile)) {
        throw new RuntimeException('شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.');
    }
    $code = normalize_medical_code(field($data, 'medical_code', 30));
    $specialty = null;
    if ($allowOther && (int) ($data['specialty_id'] ?? 0) <= 0) {
        // 1.0.0-rc.16: registration never stops at the specialty list — a specialty that is not listed (or an
        // installation without a list yet) is typed in; the administrator assigns a listed one at review.
        $other = field($data, 'specialty_other', 190, false);
        if (mb_strlen($other) < 2) {
            throw new RuntimeException('تخصص را از فهرست انتخاب کنید یا اگر در فهرست نیست، نام آن را بنویسید.');
        }
        $specialty = ['id' => null, 'name' => $other];
    }
    $specialty ??= active_specialty(positive_id($data['specialty_id'] ?? 0));
    return [$name, $mobile, $code, $specialty, field($data, 'bio', 5000, false)];
}
/**
 * Medical council numbers are digits only (Persian digits accepted). Leading zeros are removed so
 * "012345" and "12345" cannot be registered twice; the unique index enforces the rest.
 */
function normalize_medical_code(string $value): string
{
    $code = ltrim(preg_replace('/[\s\-]+/u', '', digits(trim($value))), '0');
    if (!preg_match('/^[0-9]{3,10}$/', $code)) {
        throw new RuntimeException('کد نظام پزشکی باید عددی و بین ۳ تا ۱۰ رقم باشد.');
    }
    return $code;
}

function medical_code_taken(string $code, int $exceptDoctor = 0): bool
{
    return (bool) query('SELECT id FROM doctors WHERE medical_code=? AND id<>?', [$code, $exceptDoctor])->fetch();
}

function clinic_data(array $data): array
{
    $city = active_city(positive_id($data['city_id'] ?? 0), positive_id($data['province_id'] ?? 0));
    $phone = digits(field($data, 'phone', 20, false));
    if ($phone !== '' && !preg_match('/^0[0-9]{10}$/', $phone)) {
        throw new RuntimeException('تلفن مطب را با پیش‌شماره و ۱۱ رقم وارد کنید.');
    }
    [$lat, $lng] = coordinates_from($data);
    return [field($data, 'clinic_name', 190), $city, field($data, 'address', 2000), $phone, $lat, $lng];
}
function register_doctor(array $data, bool $byAdmin = false): int
{
    [$name, $mobile, $code, $spec, $bio] = doctor_data($data, !$byAdmin);
    [$clinic, $city, $address, $phone, $lat, $lng] = clinic_data($data);
    if (medical_code_taken($code)) {
        // Public registration must not reveal which council numbers exist.
        throw new RuntimeException(
            $byAdmin
                ? 'این کد نظام پزشکی قبلاً برای پزشک دیگری ثبت شده است.'
                : 'امکان ثبت این درخواست نیست. در صورت ثبت قبلی با مدیر سامانه تماس بگیرید.',
        );
    }
    db()->beginTransaction();
    try {
        query(
            'INSERT INTO doctors(name,specialty,specialty_id,mobile,medical_code,bio,city_id,clinic_address,active,review_status) VALUES(?,?,?,?,?,?,?,?,?,?)',
            [
                $name,
                $spec['name'],
                $spec['id'],
                $mobile,
                $code,
                $bio,
                $city['id'],
                $address,
                $byAdmin ? 1 : 0,
                $byAdmin ? 'approved' : 'pending',
            ],
        );
        $id = (int) db()->lastInsertId();
        if (!$byAdmin) {
            $u = require_user();
            if ($u['mobile'] !== $mobile) {
                throw new RuntimeException('شماره حساب تأییدشده را وارد کنید.');
            }
            query('INSERT INTO doctor_accounts(doctor_id,user_id) VALUES(?,?)', [$id, $u['id']]);
            admin_event('registration','درخواست عضویت پزشک','درخواست جدید برای بررسی ثبت شد.','doctor:'.$id,'admin.php?tab=doctors&doctor='.$id);
            query("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'doctor')", [$u['id']]);
        }
        query(
            'INSERT INTO clinics(doctor_id,name,city_id,address,phone,is_primary,latitude,longitude) VALUES(?,?,?,?,?,1,?,?)',
            [$id, $clinic, $city['id'], $address, $phone, $lat, $lng],
        );
        clinic_set_district((int) db()->lastInsertId(), $data);
        doctor_set_extra($id, $data, $byAdmin);
        event_record($id, 'doctor.register', ['by_admin' => $byAdmin]);
        db()->commit();
        return $id;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}
/**
 * 1.0.0-rc.14: a doctor profile the administrator already created with this verified mobile and that no
 * account owns yet. Instead of a duplicate error the doctor asks for the link; numbers entered by the
 * administrator (often a clinic or secretary phone) are never linked automatically, so the request goes to
 * support and the administrator links it with «اتصال به پزشک» in Users.
 */
function doctor_claimable(array $u): ?array
{
    if (empty($u['verified_at']) || empty($u['mobile'])) {
        return null;
    }
    $d = query(
        'SELECT d.* FROM doctors d WHERE d.mobile=? AND NOT EXISTS(SELECT 1 FROM doctor_accounts a WHERE a.doctor_id=d.id)',
        [$u['mobile']],
    )->fetch();
    return $d ?: null;
}

/** Whether another account already owns the doctor profile registered with this mobile. */
function doctor_mobile_owned_elsewhere(array $u): bool
{
    return (bool) query(
        'SELECT 1 FROM doctors d JOIN doctor_accounts a ON a.doctor_id=d.id WHERE d.mobile=? AND a.user_id<>?',
        [(string) $u['mobile'], (int) $u['id']],
    )->fetch();
}

function doctor_claim_subject(int $doctor): string
{
    return 'درخواست اتصال پروفایل پزشک #' . $doctor;
}

/** The open link request of this account for this doctor, if any. */
function doctor_claim_ticket(int $user, int $doctor): ?int
{
    $id = query("SELECT id FROM support_tickets WHERE user_id=? AND subject=? AND status<>'closed' ORDER BY id DESC LIMIT 1", [
        $user,
        doctor_claim_subject($doctor),
    ])->fetchColumn();
    return $id ? (int) $id : null;
}

/** Sends the link request to support (once while it is open); returns the ticket id. */
function doctor_claim_request(): int
{
    require_once __DIR__ . '/tickets.php';
    $u = require_user();
    if (query('SELECT 1 FROM doctor_accounts WHERE user_id=?', [$u['id']])->fetch()) {
        throw new RuntimeException('حساب شما قبلاً به یک پزشک متصل شده است.');
    }
    $d = doctor_claimable($u);
    if (!$d) {
        throw new RuntimeException('پروفایل پزشکی با شماره تأییدشده شما پیدا نشد.');
    }
    if ($open = doctor_claim_ticket((int) $u['id'], (int) $d['id'])) {
        return $open;
    }
    $id = ticket_create((int) $u['id'], [
        'subject' => doctor_claim_subject((int) $d['id']),
        'category' => 'doctor',
        'body' =>
            'پزشک «' . $d['name'] . '» (شناسه ' . $d['id'] . ') با شماره ' . $u['mobile'] .
            " وارد شده و درخواست اتصال این پروفایل به حسابش را دارد.\nشماره حساب با شماره ثبت‌شده برای پزشک یکسان و با کد پیامکی تأیید شده است.\n" .
            'برای تأیید: پنل مدیر ← کاربران ← این کاربر ← «اتصال به پزشک».',
    ]);
    audit('doctor.claim_requested', (int) $d['id']);
    return $id;
}

function sync_primary(int $doctor): void
{
    query(
        'UPDATE doctors d JOIN clinics c ON c.doctor_id=d.id AND c.is_primary=1 SET d.city_id=c.city_id,d.clinic_address=c.address WHERE d.id=?',
        [$doctor],
    );
    entitlement_sync($doctor); // rc.20: clinics beyond the plan limit stay without new bookings
}
function form_token(string $action): void
{
    echo '<input type="hidden" name="csrf" value="' .
        esc(csrf()) .
        '"><input type="hidden" name="action" value="' .
        esc($action) .
        '">';
}
function input(
    string $label,
    string $name,
    $value = '',
    string $type = 'text',
    bool $required = true,
    int $max = 190,
    string $attrs = '',
): void {
    echo '<label>' .
        esc($label) .
        '<input' . ($attrs !== '' ? ' ' . $attrs : '') . ' type="' .
        esc($type) .
        '" name="' .
        esc($name) .
        '" value="' .
        esc($value) .
        '" maxlength="' .
        $max .
        '" ' .
        ($required ? 'required' : '') .
        '></label>';
}
function select_field(string $label, string $name, array $rows, $selected = 0): void
{
    echo '<label>' .
        esc($label) .
        '<select name="' .
        esc($name) .
        '" required><option value="">انتخاب کنید</option>';
    foreach ($rows as $r) {
        echo '<option value="' .
            (int) $r['id'] .
            '" ' .
            ((string) $r['id'] === (string) $selected ? 'selected' : '') .
            '>' .
            esc($r['name']) .
            '</option>';
    }
    echo '</select></label>';
}
/**
 * Province + city selectors. Only the chosen province's cities are rendered; app.js reloads the list
 * from api.php?route=geo/cities when the province changes (1,250+ cities would be too heavy inline).
 */
function location_fields(array $values = []): void
{
    $province = (int) ($values['province_id'] ?? 0);
    $city = (int) ($values['city_id'] ?? 0);
    if (!$province && $city) {
        $province = (int) query('SELECT province_id FROM cities WHERE id=?', [$city])->fetchColumn();
    }
    $optional = !empty($values['optional']);
    echo '<div class="location-fields form-grid" data-geo>';
    if ($optional) {
        // Same markup without "required" (secretary's optional patient details).
        ob_start();
        select_field('استان', 'province_id', provinces_list(), $province);
        echo str_replace('" required><option', '"><option', (string) ob_get_clean());
    } else {
        select_field('استان', 'province_id', provinces_list(), $province);
    }
    echo '<label>شهر<select name="city_id"' . ($optional ? '' : ' required') . ' data-selected="' . $city . '"><option value="">' .
        ($province ? 'انتخاب کنید' : 'ابتدا استان را انتخاب کنید') .
        '</option>';
    if ($province) {
        foreach (cities_of_province($province) as $c) {
            echo '<option value="' .
                (int) $c['id'] .
                '" data-province="' .
                (int) $c['province_id'] .
                '"' .
                selected_attr((int) $c['id'] === $city) .
                '>' .
                esc($c['name']) .
                '</option>';
        }
    }
    echo '</select></label></div>';
}

/** Optional map position; app.js turns the pair into a draggable map picker when tiles load. */
/**
 * Clinic location: map with «موقعیت فعلی من». Since 1.0.0-rc.19 the coordinates are hidden fields filled by
 * the map; only the administration panel ($manual) still shows the two number boxes (for when map tiles do
 * not load).
 */
function map_fields(array $values = [], bool $manual = false): void
{
    $lat = (string) ($values['latitude'] ?? '');
    $lng = (string) ($values['longitude'] ?? '');
    echo '<fieldset class="map-fields" data-map-picker data-tiles="' .
        esc(setting('map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png')) .
        '" data-attribution="' .
        esc(setting('map_attribution', '© OpenStreetMap')) .
        '"><legend>موقعیت روی نقشه (اختیاری)</legend><p class="muted">اگر الان در مطب هستید «موقعیت فعلی من» را بزنید؛ وگرنه روی نقشه، محل مطب را لمس کنید. نشانگر را می‌توانید جابه‌جا کنید.</p><div class="map-locate"><button type="button" class="button secondary small" data-locate hidden>' . icon('pin') . ' موقعیت فعلی من</button><small class="muted" data-locate-status role="status" aria-live="polite"></small></div><div class="map-canvas" hidden></div>';
    if ($manual) {
        echo '<div class="form-grid"><label>عرض جغرافیایی<input name="latitude" inputmode="decimal" dir="ltr" value="' . esc($lat) . '" placeholder="27.1832"></label><label>طول جغرافیایی<input name="longitude" inputmode="decimal" dir="ltr" value="' . esc($lng) . '" placeholder="56.2666"></label></div>';
    } else {
        echo '<input type="hidden" name="latitude" value="' . esc($lat) . '"><input type="hidden" name="longitude" value="' . esc($lng) . '">';
    }
    echo '</fieldset>';
}

/** $maskedMobile: admin roles without "sensitive" see a masked, non-editable number (the server keeps the stored one). */
function doctor_fields(array $v = [], bool $verifiedMobile = false, bool $maskedMobile = false): void
{
    input('نام و نام خانوادگی (بدون دکتر)', 'name', doctor_bare_name($v['name'] ?? ''));
    if ($maskedMobile) {
        echo '<label>موبایل<input value="' . esc(mask_mobile((string) ($v['mobile'] ?? ''))) . '" readonly disabled></label>';
    } elseif ($verifiedMobile) {
        echo '<label>موبایل تأییدشده<input name="mobile" value="' .
            esc($v['mobile'] ?? '') .
            '" readonly></label>';
    } else {
        input('موبایل', 'mobile', $v['mobile'] ?? '', 'tel', true, 11);
    }
    input('کد نظام پزشکی', 'medical_code', $v['medical_code'] ?? '', 'text', true, 20);
    if ($verifiedMobile) {
        // Self-registration: pick from the list, or type a specialty that is not listed.
        $listed = (bool) query('SELECT 1 FROM specialties WHERE active=1 AND deleted_at IS NULL LIMIT 1')->fetch();
        if ($listed) {
            specialty_select('تخصص پزشکی', 'specialty_id', (int) ($v['specialty_id'] ?? 0), false);
        }
        echo '<label>' . ($listed ? 'تخصص شما در فهرست نیست؟ نام آن را بنویسید' : 'تخصص پزشکی') .
            '<input name="specialty_other" maxlength="190" value="' . esc($v['specialty_other'] ?? '') . '"' . ($listed ? '' : ' required') .
            ' placeholder="مثلاً: طب سنتی"></label>';
        if ($listed) {
            echo '<p class="muted small field-hint">یکی از این دو کافی است؛ تخصص نوشته‌شده هنگام بررسی توسط مدیر به فهرست اضافه یا جایگزین می‌شود.</p>';
        }
    } else {
        specialty_select('تخصص پزشکی', 'specialty_id', (int) ($v['specialty_id'] ?? 0));
    }
    gender_select($v['gender'] ?? '');
    echo '<label>معرفی پزشک<textarea name="bio" maxlength="5000">' .
        esc($v['bio'] ?? '') .
        '</textarea></label>';
}
/** Doctor gender for the search filter; empty means «not stated» and such doctors are left out of that filter. */
function gender_select(?string $value): void
{
    echo '<label>جنسیت پزشک (برای فیلتر جستجو)<select name="gender"><option value="">ثبت نشده</option>';
    foreach (['female' => 'خانم', 'male' => 'آقا'] as $k => $l) {
        echo '<option value="' . $k . '"' . selected_attr($value === $k) . '>' . $l . '</option>';
    }
    echo '</select></label>';
}

/** Saves gender (doctor or admin) and, for administrators, the special badge shown on result cards. */
function doctor_set_extra(int $doctor, array $data, bool $byAdmin = false): void
{
    if ($doctor < 1) {
        return;
    }
    if (array_key_exists('gender', $data)) {
        $g = in_array($data['gender'], ['male', 'female'], true) ? $data['gender'] : null;
        query('UPDATE doctors SET gender=? WHERE id=?', [$g, $doctor]);
    }
    if ($byAdmin && array_key_exists('badge_text', $data)) {
        query('UPDATE doctors SET badge_text=? WHERE id=?', [mb_substr(preg_replace('/\s+/u', ' ', trim(field($data, 'badge_text', 40, false))), 0, 40), $doctor]);
    }
}

/** Optional district of a clinic (search filter). Free text, normalized, at most 80 characters. */
function clinic_set_district(int $clinic, array $data): void
{
    if ($clinic < 1 || !array_key_exists('district', $data)) {
        return;
    }
    $d = preg_replace('/\s+/u', ' ', fa_normalize(trim(field($data, 'district', 80, false))));
    query('UPDATE clinics SET district=? WHERE id=?', [$d, $clinic]);
    clinic_set_notes($clinic, $data);
}

/**
 * Clinic notes shown on the time picker ("ملاحظات مطب") and documents to bring, written by the doctor
 * or the administrator. Plain text (escaped on output), at most 2,000 and 1,000 characters.
 */
function clinic_set_notes(int $clinic, array $data): void
{
    if ($clinic < 1) {
        return;
    }
    foreach (['notes' => 2000, 'required_docs' => 1000] as $col => $max) {
        if (array_key_exists($col, $data) && is_scalar($data[$col])) {
            $t = trim(preg_replace("/\r\n?/", "\n", (string) $data[$col]));
            if (mb_strlen($t) > $max) {
                throw new AuthError(($col === 'notes' ? 'ملاحظات مطب' : 'مدارک لازم') . ' حداکثر ' . fa_number($max) . ' نویسه است.');
            }
            query("UPDATE clinics SET $col=? WHERE id=?", [$t === '' ? null : $t, $clinic]);
        }
    }
}

function textarea_field(string $label, string $name, ?string $value, int $max, string $help = ''): void
{
    echo '<label>' . esc($label) . '<textarea name="' . esc($name) . '" rows="3" maxlength="' . $max . '">' . esc((string) $value) . '</textarea>' .
        ($help !== '' ? '<small class="muted">' . esc($help) . '</small>' : '') . '</label>';
}

function clinic_fields(array $v = [], bool $manualCoords = false): void
{
    input('نام مطب', 'clinic_name', $v['clinic_name'] ?? ($v['name'] ?? ''));
    location_fields($v);
    input('محله یا محدوده (اختیاری؛ برای فیلتر جستجو، مثلاً «سعادت‌آباد»)', 'district', $v['district'] ?? '', 'text', false, 80);
    input('آدرس کامل', 'address', $v['address'] ?? '', 'text', true, 2000);
    input('تلفن مطب با پیش‌شماره', 'phone', $v['phone'] ?? '', 'tel', false, 11);
    map_fields($v, $manualCoords);
    textarea_field('ملاحظات مطب (اختیاری؛ بالای صفحه انتخاب زمان نمایش داده می‌شود)', 'notes', $v['notes'] ?? '', 2000, 'مثلاً زمان حضور، نحوه پذیرش، پارکینگ یا پوشش بیمه.');
    textarea_field('مدارک لازم برای مراجعه (اختیاری)', 'required_docs', $v['required_docs'] ?? '', 1000, 'مثلاً دفترچه بیمه، جواب آزمایش‌های قبلی.');
}

function specialty_select(string $label, string $name, int $selected = 0, bool $required = true): void
{
    echo '<label>' . esc($label) . '<select name="' . esc($name) . '"' . ($required ? ' required' : '') . '><option value="">انتخاب کنید</option>';
    foreach (specialties_grouped() as $group => $rows) {
        echo '<optgroup label="' . esc($group) . '">';
        foreach ($rows as $r) {
            echo '<option value="' . (int) $r['id'] . '"' . selected_attr((int) $r['id'] === $selected) . '>' .
                esc(specialty_display_title($r['name'], $r['level'] ?? null) . ($r['parent_name'] ? ' (' . $r['parent_name'] . ')' : '')) .
                '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select></label>';
}
/** Public listing rule: approved, active, valid subscription, active specialty. */
function visible_doctor_sql(): string
{
    return "d.active=1 AND d.review_status='approved' AND " . subscription_open_sql('d') . ' AND sp.active=1';
}

function doctor_status_labels(): array
{
    return [
        'pending' => 'در انتظار بررسی',
        'approved' => 'تأییدشده',
        'rejected' => 'ردشده',
        'suspended' => 'تعلیق‌شده',
    ];
}
function public_clinic_sql(): string
{
    // A center clinic row is public only while the doctor's link with an approved, active center lasts.
    // rc.20: plan_locked = above the doctor's current plan limit (kept, but no new bookings until Pro).
    return "cl.active=1 AND cl.plan_locked=0 AND c.active=1 AND p.active=1 AND (cl.center_id IS NULL OR EXISTS(SELECT 1 FROM centers ce_pub JOIN center_doctors cd_pub ON cd_pub.center_id=ce_pub.id AND cd_pub.doctor_id=cl.doctor_id AND cd_pub.status='active' WHERE ce_pub.id=cl.center_id AND ce_pub.review_status='approved' AND ce_pub.active=1))";
}
