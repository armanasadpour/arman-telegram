<?php
declare(strict_types=1);

/**
 * Doctor profile and booking steps: day strip, clinic hours, patient details (optionally the national ID,
 * stored encrypted and shown masked), family member details and booking texts.
 */

/** «۲۶ مهر» */
function jalali_day_label(string $date): string
{
    static $names = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    [, $m, $d] = jalali_parts($date);
    return fa_number($d) . ' ' . $names[$m - 1];
}

/** «سه‌شنبه ۲۸ مهر ۱۴۰۵، ساعت ۱۰:۳۰» */
function jalali_long(string $datetime): string
{
    [$y] = jalali_parts($datetime);
    return jalali_weekday(substr($datetime, 0, 10)) . ' ' . jalali_day_label($datetime) . ' ' . fa_number($y) . (strlen($datetime) > 10 ? '، ساعت ' . fa_number(substr($datetime, 11, 5)) : '');
}

function booking_days(): int
{
    return in_array((int) setting('booking_days', '14'), [7, 14, 21, 30], true) ? (int) setting('booking_days', '14') : 14;
}

/**
 * Next $days days for one clinic and service: date, weekday, label, free (number of free times),
 * state: free | full | closed (no working hours or a whole-day block) | over (beyond the subscription).
 */
function booking_day_strip(int $clinic, int $service, int $days, int $move = 0): array
{
    $c = query('SELECT cl.doctor_id,d.subscription_until FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id WHERE cl.id=?', [$clinic])->fetch();
    if (!$c) {
        return [];
    }
    $weekdays = array_map('intval', query('SELECT DISTINCT weekday FROM working_hours WHERE clinic_id=? AND active=1', [$clinic])->fetchAll(PDO::FETCH_COLUMN));
    $until = bookable_until($c['subscription_until']);
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $day = date('Y-m-d', strtotime('+' . $i . ' days'));
        $open = in_array((int) date('w', strtotime($day)), $weekdays, true);
        $wholeBlock = $open && blocked((int) $c['doctor_id'], $clinic, $day . ' 00:00:00', $day . ' 23:59:59') &&
            (bool) query('SELECT id FROM schedule_blocks WHERE doctor_id=? AND active=1 AND (clinic_id IS NULL OR clinic_id=?) AND starts_at<=? AND ends_at>=? LIMIT 1', [$c['doctor_id'], $clinic, $day . ' 00:00:00', $day . ' 23:59:00'])->fetch();
        $free = $open && !$wholeBlock && $day <= $until && $service ? count(availability($clinic, $service, $day, $move)) : 0;
        $state = $day > $until ? 'over' : (!$open || $wholeBlock ? 'closed' : ($free > 0 ? 'free' : 'full'));
        $out[] = [
            'date' => $day,
            'weekday' => $i === 0 ? 'امروز' : ($i === 1 ? 'فردا' : jalali_weekday($day)),
            'label' => jalali_day_label($day),
            'free' => $free,
            'state' => $state,
            'today' => $i === 0,
        ];
    }
    return $out;
}

/** Working hours of a clinic grouped by weekday (Saturday first): [['day' => 'شنبه', 'ranges' => ['۰۹:۰۰ تا ۱۳:۰۰']]] */
function clinic_hours(int $clinic): array
{
    $names = [6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه'];
    $rows = query('SELECT weekday,opens_at,closes_at FROM working_hours WHERE clinic_id=? AND active=1 ORDER BY opens_at', [$clinic])->fetchAll();
    $out = [];
    foreach ($names as $w => $label) {
        $ranges = [];
        foreach ($rows as $r) {
            if ((int) $r['weekday'] === $w) {
                $ranges[] = fa_number(substr($r['opens_at'], 0, 5)) . ' تا ' . fa_number(substr($r['closes_at'], 0, 5));
            }
        }
        if ($ranges) {
            $out[] = ['day' => $label, 'ranges' => $ranges];
        }
    }
    return $out;
}

/* ---------- Patient details ---------- */

/**
 * National ID of every patient (account holder and anyone booked for): required (default since rc.11) or
 * optional. The earlier "off" value is treated as required.
 */
function patient_id_mode(): string
{
    return setting('patient_id_mode', 'required') === 'optional' ? 'optional' : 'required';
}

/** Basic insurance types asked of every patient; the label appears on lists and in the daily Excel file. */
function insurance_types(): array
{
    return [
        'tamin' => 'تأمین اجتماعی',
        'salamat' => 'خدمات درمانی',
        'armed' => 'نیروهای مسلح',
        'rural' => 'روستایی',
        'free' => 'آزاد (بدون بیمه)',
    ];
}

function insurance_label(?string $key): string
{
    return insurance_types()[(string) $key] ?? '';
}

function insurance_from_input(array $d, bool $required): ?string
{
    $v = (string) ($d['insurance'] ?? '');
    if ($v === '') {
        if ($required) {
            throw new AuthError('نوع بیمه را انتخاب کنید.');
        }
        return null;
    }
    if (!isset(insurance_types()[$v])) {
        throw new AuthError('نوع بیمه نامعتبر است.');
    }
    return $v;
}

function jalali_month_names(): array
{
    return [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
}

/**
 * Birth date from the three Jalali selects (birth_d, birth_m, birth_y) → Y-m-d, or null when left empty
 * and not required. Impossible dates (31 Mehr, 30 Esfand in a common year), future dates and ages above
 * 120 are rejected.
 */
function birth_from_input(array $d, bool $required): ?string
{
    $day = (int) digits((string) ($d['birth_d'] ?? ''));
    $month = (int) digits((string) ($d['birth_m'] ?? ''));
    $year = (int) digits((string) ($d['birth_y'] ?? ''));
    if (!$day && !$month && !$year) {
        if ($required) {
            throw new AuthError('تاریخ تولد (روز، ماه و سال) را انتخاب کنید.');
        }
        return null;
    }
    if (!$day || !$month || !$year) {
        throw new AuthError('روز، ماه و سال تولد را کامل انتخاب کنید.');
    }
    $now = jalali_year_now();
    if ($year < $now - 120 || $year > $now || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
        throw new AuthError('تاریخ تولد معتبر نیست.');
    }
    try {
        $date = parse_jdate(sprintf('%04d/%02d/%02d', $year, $month, $day));
    } catch (Throwable $e) {
        throw new AuthError('تاریخ تولد معتبر نیست؛ ' . jalali_month_names()[$month] . ' ' . fa_number($year) . ' روز ' . fa_number($day) . ' ندارد.');
    }
    if ($date > date('Y-m-d')) {
        throw new AuthError('تاریخ تولد نمی‌تواند در آینده باشد.');
    }
    return $date;
}

/**
 * Day / month / year selects for a Jalali birth date. $date is the stored Y-m-d (or null); values posted
 * back after an error take precedence.
 */
function birth_select(?string $date, array $post = [], bool $required = true, string $label = 'تاریخ تولد'): string
{
    [$y, $m, $d] = $date ? jalali_parts($date) : [0, 0, 0];
    if (isset($post['birth_y']) || isset($post['birth_m']) || isset($post['birth_d'])) {
        $y = (int) digits((string) ($post['birth_y'] ?? ''));
        $m = (int) digits((string) ($post['birth_m'] ?? ''));
        $d = (int) digits((string) ($post['birth_d'] ?? ''));
    }
    $req = $required ? ' required' : '';
    $star = $required ? ' <span class="req" aria-hidden="true">*</span>' : ' <small>(اختیاری)</small>';
    $out = '<fieldset class="field jdate" data-jdate><legend>' . esc($label) . $star . '</legend><div class="jdate-row">';
    $out .= '<label class="jdate-part"><span class="sr-only">روز</span><select name="birth_d" aria-label="روز تولد"' . $req . '><option value="">روز</option>';
    for ($i = 1; $i <= 31; $i++) {
        $out .= '<option value="' . $i . '"' . selected_attr($d === $i) . '>' . fa_number($i) . '</option>';
    }
    $out .= '</select></label><label class="jdate-part jdate-month"><span class="sr-only">ماه</span><select name="birth_m" aria-label="ماه تولد"' . $req . '><option value="">ماه</option>';
    foreach (jalali_month_names() as $i => $name) {
        $out .= '<option value="' . $i . '"' . selected_attr($m === $i) . '>' . esc($name) . '</option>';
    }
    $out .= '</select></label><label class="jdate-part"><span class="sr-only">سال</span><select name="birth_y" aria-label="سال تولد"' . $req . '><option value="">سال</option>';
    $now = jalali_year_now();
    for ($i = $now; $i >= $now - 120; $i--) {
        $out .= '<option value="' . $i . '"' . selected_attr($y === $i) . '>' . fa_number($i) . '</option>';
    }
    return $out . '</select></label></div></fieldset>';
}

/**
 * National ID input with the «اتباع خارجی هستم» tick. Ticked: no national ID is needed and the input becomes an
 * optional passport / residency number (booking.js switches the label live; the server applies the same rule).
 * $hint is the masked stored value; $who: self | other.
 */
function national_id_field(string $hint, bool $foreign, string $who, bool $required): string
{
    $star = '<span class="req" aria-hidden="true">*</span>';
    $nidNote = $required && $hint === '' ? $star : ($hint !== '' ? '<small>(ثبت شده)</small>' : '<small>(اختیاری)</small>');
    $label = $foreign
        ? 'شماره گذرنامه یا شناسه اتباع <small>(اختیاری)</small>'
        : 'کد ملی ' . $nidNote;
    $placeholder = $hint !== '' && $hint !== 'اتباع خارجی'
        ? $hint . ' — برای تغییر، مقدار جدید را وارد کنید'
        : ($foreign ? 'در صورت تمایل' : 'کد ملی ۱۰ رقمی');
    $tick = $who === 'self' ? 'اتباع خارجی هستم (کد ملی ندارم)' : 'اتباع خارجی است (کد ملی ندارد)';
    return '<div class="nid-block" data-nid>' .
        '<label class="field"><span data-nid-label data-nid-text="' . esc('کد ملی ' . $nidNote) . '" data-foreign-text="' . esc('شماره گذرنامه یا شناسه اتباع <small>(اختیاری)</small>') . '">' . $label . '</span>' .
        '<input name="national_id" inputmode="' . ($foreign ? 'text' : 'numeric') . '" dir="ltr" maxlength="20" autocomplete="off" placeholder="' . esc($placeholder) . '" data-nid-placeholder="' . esc($hint !== '' && $hint !== 'اتباع خارجی' ? $placeholder : 'کد ملی ۱۰ رقمی') . '"' . ($required && $hint === '' && !$foreign ? ' required data-nid-required' : ($required && $hint === '' ? ' data-nid-required' : '')) . '></label>' .
        '<label class="nid-foreign"><input type="checkbox" name="foreign" value="1" data-nid-foreign' . checked_attr($foreign) . '><span>' . icon('user-round') . esc($tick) . '</span></label>' .
        '</div>';
}

/** Insurance select shared by the patient, family and secretary forms. */
function insurance_select(?string $value, bool $required = true): string
{
    $out = '<label class="field"><span>نوع بیمه' . ($required ? ' <span class="req" aria-hidden="true">*</span>' : ' <small>(اختیاری)</small>') . '</span><select name="insurance"' . ($required ? ' required' : '') . '><option value="">انتخاب کنید</option>';
    foreach (insurance_types() as $k => $l) {
        $out .= '<option value="' . $k . '"' . selected_attr($value === $k) . '>' . esc($l) . '</option>';
    }
    return $out . '</select></label>';
}

/** Details still missing for a family member before booking for them (same rules as the account holder). */
function member_missing(array $m): array
{
    $miss = [];
    if (!$m['birth_date']) {
        $miss[] = 'birth_date';
    }
    if (empty($m['gender'])) {
        $miss[] = 'gender';
    }
    if (empty($m['insurance'])) {
        $miss[] = 'insurance';
    }
    if (patient_id_mode() === 'required' && empty($m['national_id_enc']) && ($m['id_type'] ?? '') !== 'foreign') {
        $miss[] = 'national_id';
    }
    return $miss;
}

/**
 * Adds or edits a family member with every booking detail (name, relationship, Jalali birth date, gender,
 * insurance and national ID). All fields are validated before anything is written. Returns the member id.
 */
function family_member_save(int $userId, array $d): int
{
    $id = (int) ($d['id'] ?? 0);
    $existing = $id ? query('SELECT * FROM family_members WHERE id=? AND user_id=? AND active=1', [$id, $userId])->fetch() : null;
    if ($id && !$existing) {
        throw new AuthError('عضو پیدا نشد.', 404);
    }
    $birth = birth_from_input($d, true);
    if (!in_array($d['gender'] ?? '', ['male', 'female'], true)) {
        throw new AuthError('جنسیت مراجعه‌کننده را انتخاب کنید.');
    }
    $insurance = insurance_from_input($d, true);
    $keepId = $existing && identity_keep($existing, $d);
    $ident = $keepId ? null : patient_identity($d, patient_id_mode() === 'required');
    family_action(['operation' => 'save', 'id' => $id, 'name' => $d['name'] ?? '', 'relationship' => $d['relationship'] ?? '', 'birth_date' => jdate($birth)]);
    if (!$id) {
        $id = (int) query('SELECT id FROM family_members WHERE user_id=? AND name=? AND active=1 ORDER BY id DESC LIMIT 1', [$userId, field($d, 'name', 190)])->fetchColumn();
    }
    query('UPDATE family_members SET gender=?,insurance=? WHERE id=? AND user_id=?', [$d['gender'], $insurance, $id, $userId]);
    if ($ident && $ident['id_type'] !== null) {
        query('UPDATE family_members SET id_type=?,national_id_enc=? WHERE id=? AND user_id=?', [$ident['id_type'], $ident['enc'], $id, $userId]);
    }
    return $id;
}

/** Iranian national ID with its check digit. */
function national_id_valid(string $code): bool
{
    if (!preg_match('/^\d{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) {
        return false;
    }
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int) $code[$i] * (10 - $i);
    }
    $r = $sum % 11;
    $check = (int) $code[9];
    return ($r < 2 && $check === $r) || ($r >= 2 && $check === 11 - $r);
}

/**
 * Validated identity from a form: ['id_type' => national|foreign|null, 'enc' => encrypted value|null].
 * Foreign nationals give their passport or residency (FIDA) number instead.
 */
function patient_identity(array $d, bool $required): array
{
    $foreign = !empty($d['foreign']);
    $value = strtoupper(preg_replace('/[\s\-]/', '', digits(trim(field($d, 'national_id', 30, false)))));
    if ($value === '') {
        // Foreign nationals have no national ID: the tick alone is enough (passport number is optional).
        if ($foreign) {
            return ['id_type' => 'foreign', 'enc' => null];
        }
        if ($required) {
            throw new AuthError('کد ملی را وارد کنید (یا اگر از اتباع خارجی هستید، تیک «اتباع خارجی هستم» را بزنید).');
        }
        return ['id_type' => null, 'enc' => null];
    }
    if ($foreign) {
        if (!preg_match('/^[A-Z0-9]{6,20}$/', $value)) {
            throw new AuthError('شماره گذرنامه یا شناسه اتباع باید ۶ تا ۲۰ حرف لاتین یا رقم باشد.');
        }
        return ['id_type' => 'foreign', 'enc' => encrypt_secret($value)];
    }
    if (!national_id_valid($value)) {
        throw new AuthError('کد ملی معتبر نیست؛ ۱۰ رقم را بدون خط تیره وارد کنید.');
    }
    return ['id_type' => 'national', 'enc' => encrypt_secret($value)];
}

/**
 * True when an edit leaves the saved identity as it is: the field is empty and the foreign-national tick
 * matches what is stored (so ticking or unticking it is a real change).
 */
function identity_keep(array $row, array $d): bool
{
    if (trim((string) ($d['national_id'] ?? '')) !== '' || !$row['id_type']) {
        return false;
    }
    return ($row['id_type'] === 'foreign') === !empty($d['foreign']) && ($row['national_id_enc'] || $row['id_type'] === 'foreign');
}

/** Masked identity for display («کد ملی: ******۴۵۶۷»); the full value is never printed. */
function identity_hint(?string $type, ?string $enc): string
{
    if ($type === 'foreign' && !$enc) {
        return 'اتباع خارجی';
    }
    if (!$type || !$enc) {
        return '';
    }
    try {
        $v = decrypt_secret($enc);
    } catch (Throwable $e) {
        return '';
    }
    return ($type === 'foreign' ? 'شناسه اتباع' : 'کد ملی') . ': ' . str_repeat('•', max(0, strlen($v) - 4)) . fa_number(substr($v, -4));
}

/** Fields still needed from this account before booking (none are asked twice). */
function patient_missing(array $u): array
{
    $m = [];
    if (trim((string) $u['first_name']) === '' || trim((string) $u['last_name']) === '') {
        $m[] = 'name';
    }
    if (setting('booking_profile_fields', '1') === '1') {
        if (empty($u['birth_date'])) {
            $m[] = 'birth_date';
        }
        if (!$u['gender']) {
            $m[] = 'gender';
        }
        if (empty($u['insurance'])) {
            $m[] = 'insurance';
        }
        if (!$u['city_id']) {
            $m[] = 'city';
        }
    }
    if (patient_id_mode() === 'required' && !$u['national_id_enc'] && ($u['id_type'] ?? '') !== 'foreign') {
        $m[] = 'national_id';
    }
    return $m;
}

function jalali_year_now(): int
{
    return jalali_parts(date('Y-m-d'))[0];
}

/** Saves the booking profile: name, national ID, Jalali birth date, gender, insurance and city. */
function patient_profile_save(array $u, array $d): void
{
    $first = person_name_part($d, 'first_name', 'نام');
    $last = person_name_part($d, 'last_name', 'نام خانوادگی');
    $mode = patient_id_mode();
    $id = identity_keep($u, $d) ? null : patient_identity($d, $mode === 'required');
    $birth = birth_from_input($d, true);
    if (!in_array($d['gender'] ?? '', ['male', 'female'], true)) {
        throw new AuthError('جنسیت را انتخاب کنید.');
    }
    $insurance = insurance_from_input($d, true);
    $city = city_row((int) ($d['city_id'] ?? 0));
    if (!$city) {
        throw new AuthError('استان و شهر را انتخاب کنید.');
    }
    $sets = 'first_name=?,last_name=?,name=?,birth_date=?,birth_year=?,gender=?,insurance=?,city_id=?';
    $args = [$first, $last, $first . ' ' . $last, $birth, jalali_parts($birth)[0], $d['gender'], $insurance, $city['id']];
    if ($id && $id['id_type'] !== null) {
        $sets .= ',id_type=?,national_id_enc=?';
        array_push($args, $id['id_type'], $id['enc']);
    }
    $args[] = $u['id'];
    query('UPDATE users SET ' . $sets . ' WHERE id=?', $args);
    audit('profile.update', (int) $u['id']);
}

/** Text shown above the payment button; the default explains that only the booking service is paid online. */
function booking_payment_note(): string
{
    $t = trim(setting('booking_payment_note', ''));
    return $t !== '' ? $t : 'مبلغ پرداختی فقط بابت خدمات رزرو نوبت در ' . site_name() . ' است. هزینه ویزیت طبق تعرفه مطب، هنگام مراجعه پرداخت می‌شود.';
}

/** Cancellation and fee-refund rules in plain words, from the live settings. */
function booking_cancel_rules(): array
{
    $hours = (int) setting('patient_cancel_hours', '2');
    $rules = ['لغو اینترنتی تا ' . fa_number($hours) . ' ساعت پیش از زمان نوبت از «نوبت‌های من» ممکن است.'];
    if (setting('wallet_refund_enabled','0')==='1') {
        $rules[] = 'اگر حداقل '.fa_number(setting('wallet_refund_hours','24')).' ساعت پیش از نوبت لغو کنید، مبلغ پرداختی پس از کسر '.fa_number(setting('wallet_refund_penalty','0')).' درصد جریمه از کارمزد رزرو به کیف پول شما برمی‌گردد. مالیات و هزینه پیامک از پایه جریمه خارج‌اند. خارج از این بازه بازگشت خودکار ندارید.';
        $rules[] = 'لغو توسط پزشک یا سامانه بدون جریمه است. بازگشت پرداخت‌های قدیمی نیاز به بررسی مالی دارد.';
    } else $rules[] = setting('booking_fee_refund_on_cancel', '1') === '1'
        ? 'با لغو نوبت در مهلت مجاز، پرداخت از کیف پول به همان کیف پول برمی‌گردد؛ پرداخت بانکی برای بررسی بازگشت وجه ثبت می‌شود.'
        : 'کارمزد رزرو پس از قطعی شدن نوبت بازگردانده نمی‌شود؛ پیش از پرداخت، زمان را با دقت انتخاب کنید.';
    $rules[] = 'تا قطعی شدن نوبت، زمان انتخابی فقط ' . fa_number(setting('hold_minutes', '10')) . ' دقیقه برای شما نگه داشته می‌شود.';
    return $rules;
}

/** Gateway logo uploaded by the administrator (the bank's official mark), or ''. */
function gateway_logo_url(string $gateway): string
{
    $f = setting('gateway_logo_' . $gateway, '');
    return $f !== '' ? home_image_url($f) : '';
}

/** Approved review counts per star (5 → 1) for the profile. */
function review_distribution(int $doctor): array
{
    $rows = query("SELECT rating,COUNT(*) n FROM reviews WHERE doctor_id=? AND status='approved' GROUP BY rating", [$doctor])->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    for ($i = 5; $i >= 1; $i--) {
        $out[$i] = (int) ($rows[$i] ?? 0);
    }
    return $out;
}
