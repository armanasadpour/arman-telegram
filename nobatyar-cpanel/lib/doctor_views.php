<?php
function doctor_avatar(array $d): void { echo doctor_avatar_markup($d, true); }
function check_field(string $name, string $label, bool $checked = false): void
{
    echo '<label class="check"><input type="checkbox" name="' .
        esc($name) .
        '" value="1" ' .
        ($checked ? 'checked' : '') .
        '>' .
        esc($label) .
        '</label>';
}
function dp_form(string $action, int $clinic = 0, int $id = 0): void
{
    echo '<form method="post">';
    form_token($action);
    if ($clinic) {
        echo '<input type="hidden" name="clinic_id" value="' . $clinic . '">';
    }
    if ($id) {
        echo '<input type="hidden" name="id" value="' . $id . '">';
    }
}
function clinic_picker(array $d, string $tab, array $clinics, int $selected): void
{
    echo '<form method="get" class="filter-bar"><input type="hidden" name="doctor" value="' .
        $d['id'] .
        '"><input type="hidden" name="tab" value="' .
        $tab .
        '">';
    select_field('مطب', 'clinic', $clinics, $selected);
    echo '<button>نمایش مطب</button></form>';
}
function doctor_view(string $tab, array $d, array $clinics, ?array $cl): void
{
    $id = (int) $d['id'];
    $clinic = (int) ($cl['id'] ?? 0);
    $services = query('SELECT * FROM doctor_services WHERE doctor_id=? ORDER BY id', [$id])->fetchAll();
    switch ($tab) {
        case 'dashboard':
            echo doctor_welcome_box($d, $id); // rc.21
            $ids = array_map(fn($c) => (int) $c['id'], $clinics);
            $counts = ['today' => 0, 'future' => 0, 'completed' => 0, 'cancelled' => 0];
            if ($ids) {
                $counts = query(
                    "SELECT COALESCE(SUM(DATE(s.starts_at)=CURDATE() AND b.status='confirmed'),0) today,COALESCE(SUM(s.starts_at>=CURDATE()+INTERVAL 1 DAY AND b.status='confirmed'),0) future,COALESCE(SUM(b.status='completed'),0) completed,COALESCE(SUM(b.status='cancelled'),0) cancelled FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id IN (" .
                        implode(',', $ids) .
                        ')',
                )->fetch();
            }
            echo '<div class="metric-grid">';
            foreach (
                [
                    'today' => 'نوبت قطعی امروز',
                    'future' => 'نوبت‌های آینده',
                    'completed' => 'تکمیل‌شده',
                    'cancelled' => 'لغوشده',
                ]
                as $key => $label
            ) {
                echo '<article class="metric metric-' .
                    $key .
                    '"><span>' .
                    esc($label) .
                    '</span><strong>' .
                    fa_number($counts[$key]) .
                    '</strong><small>' .
                    ($key === 'today' ? 'برنامه امروز شما' : 'در مطب‌های مجاز') .
                    '</small></article>';
            }
            echo '</div>';
            echo '<div class="section-heading"><h2>برنامه امروز</h2><div class="section-actions"><a href="doctor-panel.php?doctor=' .
                $id .
                '&tab=calendar">مشاهده تقویم ←</a></div></div>';
            require_once __DIR__ . '/day_export.php';
            echo '<div class="day-export-bar">' . day_export_button('doctor-panel.php?doctor=' . $id . '&export=day', date('Y-m-d'), $id) . '<small>ردیف، جنسیت، نام، کد ملی، تاریخ تولد، بیمه، تماس، زمان نوبت، استان و شهر</small></div>';
            booking_cards(doctor_bookings($id, date('Y-m-d'), date('Y-m-d')), $d, $clinics, false);
            echo '<article class="card"><h2>آماده‌سازی رزرو آنلاین</h2><p>مطب را ثبت کنید، خدمات را تعریف کنید و در برنامه کاری، ساعت حضور هر مطب را تعیین کنید. نوبت‌های جدید تا حداکثر ۹۰ روز آینده به بیماران ارائه می‌شوند.</p><a href="doctor-panel.php?doctor=' .
                $id .
                '&tab=schedule">تنظیم برنامه کاری</a></article>';
            break;
        case 'profile':
            echo '<div class="two-column"><article class="card"><h2>اطلاعات حرفه‌ای</h2><p class="muted">تغییر نام یا تخصص نیازمند تأیید مجدد مدیر است. کد نظام پزشکی و موبایل از طریق مدیر اصلاح می‌شوند.</p>';
            dp_form('profile');
            echo '<div class="form-grid">';
            input('نام پزشک (بدون دکتر)', 'name', doctor_bare_name($d['name']));
            specialty_select('تخصص', 'specialty_id', (int) $d['specialty_id']);
            input('عنوان حرفه‌ای', 'professional_title', $d['professional_title'], 'text', false);
            input('سابقه فعالیت (سال)', 'experience_years', $d['experience_years'], 'number');
            gender_select($d['gender'] ?? '');
            echo '</div>';
            input('تحصیلات و مدارک', 'education', $d['education'], 'text', false, 1000);
            echo '<label>درباره پزشک<textarea name="bio" maxlength="5000">' .
                esc($d['bio']) .
                '</textarea></label><button>ذخیره پروفایل</button></form></article><article class="card photo-card">';
            doctor_avatar($d);
            echo '<h2>تصویر پروفایل</h2><p>JPEG یا PNG، حداکثر ۳ مگابایت و ۱۲ مگاپیکسل. تصویر پس از پردازش مجدد ذخیره می‌شود.</p><form method="post" enctype="multipart/form-data">';
            form_token('photo');
            echo '<label>انتخاب تصویر<input type="file" name="photo" accept="image/jpeg,image/png" required></label><button>بارگذاری تصویر</button></form>';
            if ($d['photo_file']) {
                dp_form('photo_remove');
                echo '<button class="secondary">حذف تصویر پروفایل</button></form>';
            }
            echo '<p>نظام پزشکی: ' .
                esc(fa_number($d['medical_code'])) .
                '</p><p>موبایل: <bdi>' .
                esc(fa_number($d['mobile'])) .
                '</bdi></p></article></div>';
            break;
        case 'clinics':
            echo '<p class="muted">تغییر نشانی مطب دارای رزرو مجاز نیست؛ برای جابه‌جایی محل، مطب جدید بسازید. غیرفعال‌کردن مطب نوبت‌های قبلی را حذف نمی‌کند.</p>';
            foreach ($clinics as $c) {
                if ($c['center_id']) {
                    echo '<article class="card center-presence"><h3>' .
                        icon('building') .
                        ' ' .
                        esc($c['name']) .
                        ' <span class="badge">' .
                        ($c['active'] ? 'فعال' : 'غیرفعال') .
                        '</span></h3><p class="muted">حضور در شعبه مرکز درمانی؛ نشانی و تلفن را مرکز تعیین می‌کند. برنامه کاری را در «برنامه کاری» و همکاری را در <a href="doctor-panel.php?doctor=' .
                        $id .
                        '&tab=centers">مراکز درمانی</a> مدیریت کنید.</p></article>';
                    continue;
                }
                echo '<details class="card"><summary>' .
                    esc($c['name'] . ' • ' . $c['city']) .
                    ' <span class="badge">' .
                    ($c['active'] ? 'فعال' : 'غیرفعال') .
                    ($c['is_primary'] ? ' • اصلی' : '') .
                    '</span>' . (!empty($c['plan_locked']) ? ' <span class="badge pro-locked">' . icon('lock') . ' بدون رزرو جدید تا فعال‌سازی پرو</span>' : '') . '</summary>';
                dp_form('clinic', 0, (int) $c['id']);
                clinic_fields($c);
                check_field('active', 'پذیرش رزرو جدید', (bool) $c['active']);
                check_field('is_primary', 'مطب اصلی', (bool) $c['is_primary']);
                echo '<button>ذخیره مطب</button></form></details>';
            }
            echo '<article class="card"><h2>افزودن مطب جدید</h2>';
            if (pro_limit_reached($id, 'clinics', (int) query('SELECT COUNT(*) FROM clinics WHERE doctor_id=? AND active=1 AND center_id IS NULL', [$id])->fetchColumn())) {
                echo pro_limit_card($id, 'clinics') . '</article>';
                break;
            }
            dp_form('clinic');
            clinic_fields();
            check_field('active', 'فعال', true);
            check_field('is_primary', 'مطب اصلی');
            echo '<button>ثبت مطب</button></form></article>';
            break;
        case 'services':
            render('doctor/services', ['services' => $services, 'doctorId' => $id]);
            break;
        case 'schedule':
            clinic_picker($d, $tab, $clinics, $clinic);
            if (!$cl) {
                break;
            }
            doctor_actor($id, 'schedule', $clinic, true);
            render('doctor/schedule', [
                'd' => $d,
                'cl' => $cl,
                'clinic' => $clinic,
                'hours' => query('SELECT * FROM working_hours WHERE clinic_id=? AND active=1 ORDER BY (weekday+1)%7,opens_at', [$clinic])->fetchAll(),
                'blocks' => query('SELECT * FROM schedule_blocks WHERE doctor_id=? AND (clinic_id=? OR clinic_id IS NULL) AND active=1 AND ends_at>NOW() ORDER BY starts_at LIMIT 100', [$id, $clinic])->fetchAll(),
                'rooms' => $cl['branch_id'] ? center_rooms((int) $cl['branch_id'], true) : [],
                'services' => $services,
            ]);
            break;
        case 'checkin':
            if (!$clinic) {
                echo '<p class="empty-state">ابتدا یک مطب ثبت کنید.</p>';
                break;
            }
            checkin_page($clinic, $clinics, 'doctor-panel.php', ['doctor' => $id, 'tab' => 'checkin']);
            break;
        case 'calendar':
        case 'bookings':
        case 'history':
            calendar_view($d, $clinics, $tab, $clinic);
            break;
        case 'staff':
            $staffCount = (int) query("SELECT COUNT(DISTINCT s.user_id) FROM staff_scopes s JOIN clinics c ON c.id=s.clinic_id WHERE c.doctor_id=? AND s.role='secretary' AND s.center_id IS NULL", [$id])->fetchColumn();
            echo '<article class="card"><h2>تعریف یا ویرایش دسترسی منشی</h2><p>منشی باید قبلاً با موبایل خود وارد سامانه شده باشد. ثبت دوباره شماره برای همان مطب، دسترسی‌های او را به‌روزرسانی می‌کند. مشاهده نوبت‌های امروز و آینده دسترسی پایه است.</p>';
            if (pro_limit_reached($id, 'secretaries', $staffCount)) {
                // rc.20: existing secretaries can still be edited or removed below; adding one more needs Pro.
                echo pro_limit_card($id, 'secretaries');
                if ($staffCount === 0) {
                    echo '</article>';
                    break;
                }
            }
            dp_form('staff');
            select_field('مطب', 'clinic_id', $clinics, $clinic);
            input('موبایل منشی', 'mobile', '', 'tel', true, 11);
            check_field('can_manage_schedule', 'تغییر برنامه کاری و مرخصی');
            check_field('can_manage_bookings', 'لغو، جابه‌جایی و ثبت وضعیت نوبت');
            check_field('can_view_history', 'مشاهده سوابق مراجعه');
            echo '<button>ذخیره دسترسی منشی</button></form></article><div class="cards">';
            foreach (
                query(
                    "SELECT u.id,u.name,u.mobile,c.name clinic,c.id clinic_id,s.* FROM staff_scopes s JOIN users u ON u.id=s.user_id JOIN clinics c ON c.id=s.clinic_id WHERE c.doctor_id=? AND s.role='secretary' AND s.center_id IS NULL",
                    [$id],
                )->fetchAll()
                as $s
            ) {
                echo '<article class="card"><h3>' .
                    esc($s['name'] ?: 'منشی') .
                    (!doctor_secretary_allowed($id, (int) $s['user_id']) ? ' <span class="badge pro-locked">' . icon('lock') . ' معلق تا فعال‌سازی پرو</span>' : '') .
                    '</h3><p>' .
                    esc($s['clinic']) .
                    ' • <bdi>' .
                    esc(fa_number($s['mobile'])) .
                    '</bdi></p><p>برنامه کاری: ' .
                    ($s['can_manage_schedule'] ? 'بله' : 'خیر') .
                    ' • مدیریت نوبت: ' .
                    ($s['can_manage_bookings'] ? 'بله' : 'خیر') .
                    ' • سوابق: ' .
                    ($s['can_view_history'] ? 'بله' : 'خیر') .
                    '</p>';
                dp_form('staff_remove', (int) $s['clinic_id'], (int) $s['user_id']);
                echo '<button class="secondary">حذف دسترسی این مطب</button></form></article>';
            }
            echo '</div>';
            break;
        case 'notifications':
            render_notifications();
            break;
        case 'centers':
            render('doctor/centers', ['d' => $d] + doctor_center_data($id));
            break;
        case 'report':
            if (!doctor_has($id, 'monthly_report', $d)) {
                echo pro_feature_card($id, 'monthly_report', 'تعداد نوبت، نرخ عدم مراجعه، ساعت‌های پرتقاضا، بازدید پروفایل و تبدیل بازدید به نوبت در ۳۰ روز اخیر.');
                break;
            }
            render('doctor/report', ['r' => doctor_report($id), 'id' => $id]);
            break;
        case 'promote':
            render('doctor/promote', [
                'd' => $d,
                'link' => app_base_url() . doctor_url($id, (string) $d['name']),
                'qr' => doctor_has($id, 'booking_qr', $d),
                'notice' => doctor_has($id, 'doctor_notice', $d),
            ]);
            break;
        case 'subscription':
            render('doctor/subscription', [
                'd' => $d,
                'status' => subscription_status($d),
                'plans' => plans_list(),
                'open' => query("SELECT * FROM subscription_orders WHERE doctor_id=? AND status='proforma' AND expires_at>NOW() ORDER BY id DESC LIMIT 1", [$id])->fetch() ?: null,
                'orders' => query('SELECT * FROM subscription_orders WHERE doctor_id=? ORDER BY id DESC LIMIT 30', [$id])->fetchAll(),
                'periods' => query('SELECT s.*,p.name plan_name FROM subscriptions s LEFT JOIN subscription_plans p ON p.id=s.plan_id WHERE s.doctor_id=? ORDER BY s.starts_on DESC,s.id DESC LIMIT 30', [$id])->fetchAll(),
                'gatewayReady' => payment_active_gateway() !== null,
            ]);
            break;
        case 'events':
            $page = max(1, min(100000, (int) ($_GET['page'] ?? 1)));
            $offset = ($page - 1) * 30;
            $events = query(
                'SELECT * FROM appointment_events WHERE doctor_id=? ORDER BY id DESC LIMIT 31 OFFSET ' .
                    $offset,
                [$id],
            )->fetchAll();
            echo '<p class="muted">سابقه آخرین تغییرات به همراه عامل و جزئیات ثبت‌شده. حذف یا ویرایش گزارش از پنل ممکن نیست.</p>';
            foreach (array_slice($events, 0, 30) as $e) {
                echo '<details class="card"><summary>' .
                    esc(event_label($e['event_type'])) .
                    ' • ' .
                    esc(jdate($e['created_at'], true)) .
                    '</summary><p>شناسه عامل: ' .
                    esc(fa_number($e['actor_user_id'] ?? ($e['actor_admin_id'] ?? 0))) .
                    ($e['actor_admin_id'] ? ' (مدیرکل)' : ' (کاربر)') .
                    '</p><pre dir="rtl">' .
                    esc(
                        json_encode(
                            localize_event(json_decode($e['details'], true) ?? []),
                            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
                        ),
                    ) .
                    '</pre></details>';
            }
            if ($page > 1) {
                echo '<a class="page-link" href="doctor-panel.php?doctor=' .
                    $id .
                    '&tab=events&page=' .
                    ($page - 1) .
                    '">قبلی</a>';
            }
            if (count($events) > 30) {
                echo '<a class="page-link" href="doctor-panel.php?doctor=' .
                    $id .
                    '&tab=events&page=' .
                    ($page + 1) .
                    '">بعدی</a>';
            }
            break;
    }
}
function localize_event(array $data): array
{
    $out = [];
    $labels = [
        'before' => 'قبل',
        'after' => 'بعد',
        'reason' => 'علت',
        'starts_at' => 'زمان شروع',
        'start' => 'شروع',
        'end' => 'پایان',
        'status' => 'وضعیت',
        'clinic_id' => 'شناسه مطب',
        'slot_id' => 'شناسه زمان',
        'patient_contacted' => 'تأیید اطلاع بیمار',
        'preserved_bookings' => 'نوبت حفظ‌شده',
        'duration' => 'مدت',
        'active' => 'فعال',
        'name' => 'نام',
        'user_id' => 'شناسه کاربر',
        'service_id' => 'شناسه خدمت',
        'permissions' => 'دسترسی‌ها',
        'weekday' => 'روز هفته',
        'review_required' => 'نیازمند بررسی',
        'changed' => 'فیلدهای تغییرکرده',
        'file' => 'شناسه فایل',
        'default_duration' => 'مدت پیش‌فرض',
        'gap_minutes' => 'فاصله',
        'daily_capacity' => 'ظرفیت روزانه',
    ];
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $v = localize_event($v);
        } elseif (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            $v = jdate($v, strlen($v) > 10);
        } elseif ($k === 'status') {
            $v = booking_status_label((string) $v);
        }
        $out[$labels[$k] ?? $k] = $v;
    }
    return $out;
}
function render_notifications(): void
{
    $rows = my_notifications();
    echo '<p class="muted">آخرین ۱۰۰ اعلان • <a href="notifications.php">مرکز اعلان‌ها، اعلان مرورگر و تنظیمات دریافت ←</a></p>';
    foreach ($rows as $n) {
        echo '<article class="card notification ' .
            ($n['read_at'] ? 'read' : 'unread') .
            '"><div class="between"><h3>' .
            esc($n['title']) .
            '</h3><small>' .
            esc(jdate($n['created_at'], true)) .
            '</small></div><p>' .
            esc($n['body']) .
            '</p>' .
            (!empty($n['url']) ? '<p><a href="' . esc($n['url']) . '">مشاهده ←</a></p>' : '');
        if (!$n['read_at']) {
            dp_form('notification_read', 0, (int) $n['id']);
            echo '<button class="secondary">خواندم</button></form>';
        }
        echo '</article>';
    }
    if (!$rows) {
        echo '<p class="empty-state">اعلانی ثبت نشده است.</p>';
    }
}
function calendar_view(array $d, array $clinics, string $tab, int $clinic): void
{
    $id = (int) $d['id'];
    $day = isset($_GET['date']) ? parse_jdate(field($_GET, 'date', 10)) : date('Y-m-d');
    $mode = (string) ($_GET['view'] ?? 'day');
    if (!in_array($mode, ['day', 'week', 'month'], true)) {
        $mode = 'day';
    }
    if ($tab !== 'calendar') {
        $mode = 'day';
    }
    $from = $day;
    $to = $day;
    if ($mode === 'week') {
        $from = date('Y-m-d', strtotime($day . ' -' . ((int) date('w', strtotime($day)) + 1) % 7 . ' days'));
        $to = date('Y-m-d', strtotime($from . ' +6 days'));
    }
    if ($mode === 'month') {
        $parts = explode('/', digits(jdate($day)));
        $from = parse_jdate($parts[0] . '/' . $parts[1] . '/01');
        $next = date('Y-m-d', strtotime($from . ' +32 days'));
        $parts2 = explode('/', digits(jdate($next)));
        $next = parse_jdate($parts2[0] . '/' . $parts2[1] . '/01');
        $to = date('Y-m-d', strtotime($next . ' -1 day'));
    }
    if ($tab === 'history') {
        $from = isset($_GET['from'])
            ? parse_jdate(field($_GET, 'from', 10))
            : date('Y-m-d', strtotime('-30 days'));
        $to = $day;
    }
    echo '<form method="get" class="filter-bar"><input type="hidden" name="doctor" value="' .
        $id .
        '"><input type="hidden" name="tab" value="' .
        $tab .
        '">';
    select_field('مطب', 'clinic', $clinics, $clinic);
    input($tab === 'history' ? 'تا تاریخ شمسی' : 'تاریخ شمسی', 'date', jdate($day), 'text', true, 10, 'data-jdate');
    if ($tab === 'history') {
        input('از تاریخ شمسی', 'from', jdate($from), 'text', true, 10, 'data-jdate');
    }
    if ($tab === 'calendar') {
        echo '<label>نمای تقویم<select name="view">';
        foreach (['day' => 'روزانه', 'week' => 'هفتگی', 'month' => 'ماهانه'] as $v => $label) {
            echo '<option value="' .
                $v .
                '" ' .
                ($v === $mode ? 'selected' : '') .
                '>' .
                $label .
                '</option>';
        }
        echo '</select></label>';
    }
    echo '<button>نمایش نوبت‌ها</button></form>';
    if ($tab === 'history') {
        doctor_actor($id, 'history', $clinic, true);
    }
    $rows = doctor_bookings($id, $from, $to, $clinic, $tab === 'history');
    $prev = date('Y-m-d', strtotime($from . ' -1 day'));
    $next = date('Y-m-d', strtotime($to . ' +1 day'));
    $base = 'doctor-panel.php?doctor=' . $id . '&tab=' . $tab . '&clinic=' . $clinic . '&view=' . $mode;
    echo '<div class="calendar-toolbar"><a href="' .
        $base .
        '&date=' .
        rawurlencode(jdate($prev)) .
        '">→ قبلی</a><strong>' .
        esc(jdate($from) . ($from !== $to ? ' تا ' . jdate($to) : '')) .
        '</strong><a href="' .
        $base .
        '&date=' .
        rawurlencode(jdate($next)) .
        '">بعدی ←</a></div>';
    if ($from === $to && $tab !== 'history') {
        require_once __DIR__ . '/day_export.php';
        echo '<div class="day-export-bar">' . day_export_button('doctor-panel.php?doctor=' . $id . '&export=day&clinic=' . $clinic . '&date=' . rawurlencode(jdate($from)), $from, $id) . '<small>نوبت‌های قطعی، انجام‌شده و عدم مراجعه؛ بدون نوبت‌های لغوشده</small></div>';
    }
    if ($mode === 'month') {
        $group = [];
        foreach ($rows as $r) {
            $key = substr($r['starts_at'], 0, 10);
            $group[$key] = ($group[$key] ?? 0) + 1;
        }
        $first = ((int) date('w', strtotime($from)) + 1) % 7;
        echo '<div class="month-scroll"><div class="month-grid">';
        foreach (['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'] as $label) {
            echo '<div class="weekday">' . $label . '</div>';
        }
        for ($i = 0; $i < $first; $i++) {
            echo '<div class="calendar-blank"></div>';
        }
        for ($date = $from; $date <= $to; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
            echo '<a class="calendar-day ' .
                ($date === date('Y-m-d') ? 'is-today' : '') .
                '" href="doctor-panel.php?doctor=' .
                $id .
                '&tab=calendar&clinic=' .
                $clinic .
                '&view=day&date=' .
                rawurlencode(jdate($date)) .
                '"><strong>' .
                esc(substr(jdate($date), -4)) .
                '</strong><span>' .
                fa_number($group[$date] ?? 0) .
                ' نوبت</span></a>';
        }
        echo '</div></div><p class="muted">برای جزئیات و مدیریت نوبت‌ها، یک روز را انتخاب کنید. شمارش شامل همه وضعیت‌هاست.</p>';
    } elseif ($mode === 'week') {
        echo '<div class="week-grid">';
        for ($date = $from; $date <= $to; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
            echo '<section class="week-day"><h3>' . esc(jdate($date)) . '</h3>';
            booking_cards(
                array_values(array_filter($rows, fn($r) => substr($r['starts_at'], 0, 10) === $date)),
                $d,
                $clinics,
                false,
            );
            echo '</section>';
        }
        echo '</div>';
    } else {
        echo '<p class="muted">' . fa_number(count($rows)) . ' نوبت • زمان‌ها به وقت تهران</p>';
        booking_cards($rows, $d, $clinics, $tab !== 'history');
    }
}
function booking_cards(array $rows, array $d, array $clinics, bool $manage): void
{
    if (!$rows) {
        echo '<p class="empty-state">در این بازه نوبتی ثبت نشده است.</p>';
        return;
    }
    $ids = [];
    foreach ($clinics as $c) {
        if ($d['is_owner'] || !empty($c['can_manage_bookings'])) {
            $ids[] = (int) $c['id'];
        }
    }
    foreach ($rows as $b) {
        echo '<article class="appointment-card"><div class="appointment-time"><strong>' .
            fa_number(substr($b['starts_at'], 11, 5)) .
            '</strong><small>' .
            esc(jdate($b['starts_at'])) .
            '</small></div><div class="appointment-info"><h3>' .
            esc($b['patient_name']) .
            '</h3><p>' .
            esc($b['service_name'] . ' • ' . $b['clinic']) .
            '</p><small>موبایل: <bdi>' .
            esc(fa_number($b['phone'])) .
            '</bdi> • رهگیری: <bdi>' .
            esc(tracking_label($b['tracking_code'])) .
            '</bdi></small></div><span class="badge status-' .
            esc($b['status']) .
            '">' .
            esc(booking_status_label($b['status'])) .
            '</span>';
        if ($manage && $b['status'] === 'confirmed' && in_array((int) $b['clinic_id'], $ids, true)) {
            echo '<details class="booking-controls"><summary>مدیریت نوبت</summary>';
            dp_form('booking', 0, (int) $b['id']);
            echo '<input type="hidden" name="revision" value="' .
                (int) $b['revision'] .
                '"><label>عملیات<select name="operation"><option value="cancelled">لغو نوبت</option><option value="reschedule">جابه‌جایی نوبت</option><option value="completed">ثبت مراجعه تکمیل‌شده</option><option value="no_show">ثبت عدم مراجعه</option></select></label>';
            input('علت یا توضیح تغییر', 'reason', '', 'text', true, 500);
            check_field('patient_contacted', 'برای لغو یا جابه‌جایی، بیمار را قبلاً مطلع کرده‌ام');
            echo '<fieldset><legend>فقط برای جابه‌جایی نوبت</legend>';
            select_field(
                'مطب جدید',
                'target_clinic_id',
                array_values(array_filter($clinics, fn($c) => in_array((int) $c['id'], $ids, true))),
                $b['clinic_id'],
            );
            $svc = query('SELECT * FROM doctor_services WHERE doctor_id=? AND active=1', [
                $d['id'],
            ])->fetchAll();
            echo '<label>خدمت جدید<select name="service_id"><option value="">انتخاب خدمت</option>';
            foreach ($svc as $s) {
                echo '<option value="' . $s['id'] . '">' . esc($s['name']) . '</option>';
            }
            echo '</select></label>';
            input('زمان جدید شمسی (از زمان‌های آزاد صفحه عمومی)', 'starts_at', '', 'text', false, 30, 'data-jdate="time"');
            echo '<a href="doctor.php?id=' .
                $d['id'] .
                '">مشاهده زمان‌های قابل رزرو</a></fieldset><button>ثبت تغییر نوبت</button></form></details>';
        }
        echo '</article>';
    }
}

/**
 * 1.0.0-rc.21: welcome box at the top of the doctor dashboard — «آقای/خانم دکتر … خوش آمدید», the plan
 * (رایگان / پرو) and, on the free plan, an invitation to upgrade with a button to the Pro page.
 * Secretaries see the greeting for the doctor they work for, without the plan.
 */
function doctor_welcome_box(array $d, int $id): string
{
    $name = trim((string) preg_replace('/^\s*(دکتر|دکترِ|dr\.?)\s+/iu', '', (string) $d['name']));
    $title = ['male' => 'آقای دکتر ', 'female' => 'خانم دکتر '][$d['gender'] ?? ''] ?? 'دکتر ';
    if (empty($d['is_owner'])) {
        return '<section class="dr-welcome is-staff"><div class="dr-welcome-main"><span class="dr-welcome-eyebrow">پنل منشی</span><h2>نوبت‌های ' . esc($title . $name) . '</h2></div></section>';
    }
    $html = '<section class="dr-welcome dr-welcome-sky" aria-labelledby="dr-welcome-title"><div class="dr-welcome-main">' .
        '<div class="dr-welcome-identity">' . doctor_avatar_markup($d, true) .
        '<h2 id="dr-welcome-title">' . esc($title . $name) . '، خوش آمدید</h2></div>';
    if (!subscriptions_on()) {
        return $html . '<p class="dr-welcome-plan">' . icon('check') . ' همه امکانات نوبت‌یار برای شما فعال است.</p></div></section>';
    }
    $isPro = doctor_is_pro($id, $d);
    $sub = subscription_status($d);
    $html .= '<p class="dr-welcome-plan">نوع اشتراک: <strong class="plan-chip ' . ($isPro ? 'is-pro' : 'is-free') . '">' .
        ($isPro ? icon('verified') . ' پرو' : 'رایگان') . '</strong>' .
        ($isPro && $sub['until'] ? '<span class="dr-welcome-until">اعتبار تا ' . esc(jdate((string) $sub['until'])) . ($sub['state'] === 'grace' ? ' • دوره فرجه' : '') . '</span>' : '') . '</p>';
    if ($isPro) {
        return $html . '</div><a class="button dr-welcome-btn ghost-on-blue" href="doctor-panel.php?doctor=' . $id . '&amp;tab=subscription">' . icon('receipt') . ' مدیریت اشتراک</a></section>';
    }
    $html .= '<p class="dr-welcome-text">برای استفاده از امکانات بیشتر پلتفرم، حساب خود را به <strong>پرو</strong> ارتقا دهید: تیک آبی، بالاتر در جستجو، منشی، مطب و خدمت نامحدود، اکسل روزانه، پیامک‌های خودکار و گزارش عملکرد.</p></div>';
    $price = pro_price_from();
    return $html . '<a class="button dr-welcome-btn" href="doctor-panel.php?doctor=' . $id . '&amp;tab=subscription">' . icon('verified') . ' خرید اشتراک پرو' .
        ($price && $price['monthly'] > 0 ? '<small>از ' . esc(fa_number(number_format($price['monthly']))) . ' تومان در ماه</small>' : '') . '</a></section>';
}
