<?php
require __DIR__ . '/core.php';
require_phase2();
if (!user_now()) {
    redirect('login.php?next=secretary.php');
}
require __DIR__ . '/lib/doctor_views.php';
$error = '';
$tabs = [
    'dashboard' => 'داشبورد امروز',
    'calendar' => 'تقویم نوبت‌ها',
    'patients' => 'بیماران و خانواده',
    'book' => 'ثبت نوبت',
    'queue' => 'صف پذیرش و زمان تقریبی',
    'checkin' => 'چک نوبت',
    'delays' => 'تأخیر و اطلاع‌رسانی',
    'notifications' => 'اعلان‌ها',
    'audit' => 'گزارش تغییرات',
];
$tab = (string) ($_GET['tab'] ?? 'dashboard');
if (!isset($tabs[$tab])) {
    $tab = 'dashboard';
}
try {
    $clinics = reception_clinics();
    if (!$clinics) {
        throw new AuthError('هیچ مطب مجازی به حساب شما متصل نیست.', 403);
    }
    $clinic = (int) ($_GET['clinic'] ?? $clinics[0]['id']);
    $c = reception_scope($clinic);
    $doctor = (int) ($_GET['doctor'] ?? $c['doctor_id']);
    if ($doctor !== (int) $c['doctor_id']) {
        foreach ($clinics as $cl) {
            if ((int) $cl['doctor_id'] === $doctor) {
                $clinic = (int) $cl['id'];
                $c = reception_scope($clinic);
                break;
            }
        }
    }
    $actor = doctor_actor((int) $c['doctor_id'], 'view', $clinic, true);
    $canManage =
        $actor['is_owner'] ||
        (bool) query(
            "SELECT can_manage_bookings FROM staff_scopes WHERE user_id=? AND clinic_id=? AND role='secretary'",
            [require_user()['id'], $clinic],
        )->fetchColumn();
    $canHistory =
        $actor['is_owner'] ||
        (bool) query(
            "SELECT can_view_history FROM staff_scopes WHERE user_id=? AND clinic_id=? AND role='secretary'",
            [require_user()['id'], $clinic],
        )->fetchColumn();
} catch (Throwable $e) {
    http_response_code($e instanceof AuthError ? $e->status : 422);
    layout_start('پنل منشی');
    echo '<p class="error">' . esc(error_message($e)) . '</p>';
    layout_end();
    exit();
}
// 1.0.0-rc.11: the selected day's appointments of this clinic as an Excel file.
if (($_GET['export'] ?? '') === 'day') {
    require_once __DIR__ . '/lib/day_export.php';
    try {
        $day = isset($_GET['date']) && $_GET['date'] !== '' ? parse_jdate(field($_GET, 'date', 10)) : date('Y-m-d');
        day_export_send((int) $c['doctor_id'], $day, $clinic);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $data = $_POST;
        $data['clinic_id'] = $clinic;
        $action = field($data, 'action', 30);
        if ($action === 'patient') {
            $id = reception_patient($data);
            $_SESSION['flash'] = 'بیمار در دفتر پذیرش ثبت شد؛ حساب آنلاین یا احراز موبایل ایجاد نشده است.';
            redirect('secretary.php?clinic=' . $clinic . '&tab=book&patient=' . $id);
        } elseif ($action === 'reserve') {
            $id = reception_reserve($data);
            redirect('secretary-receipt.php?id=' . $id);
        } elseif ($action === 'delay') {
            reception_delay($data);
        } elseif ($action === 'call_next') {
            $n = queue_call_next($clinic);
            $_SESSION['flash'] = $n ? 'شماره ' . fa_number($n) . ' به اتاق پزشک فراخوانده شد.' : 'کسی در صف انتظار نیست.';
            redirect('secretary.php?clinic=' . $clinic . '&tab=queue');
        } elseif ($action === 'queue_token') {
            queue_token($clinic, true);
            $_SESSION['flash'] = 'نشانی جدید نمایشگر صف ساخته شد؛ نشانی قبلی دیگر کار نمی‌کند.';
            redirect('secretary.php?clinic=' . $clinic . '&tab=queue');
        } elseif ($action === 'estimate') {
            reception_estimate($data);
            $_SESSION['flash'] = 'زمان تقریبی ثبت و به بیمار اطلاع داده شد.';
            redirect('secretary.php?clinic=' . $clinic . '&tab=queue');
        } elseif ($action === 'contact') {
            reception_contact($data);
        } elseif ($action === 'sms') {
            throttle('reception_sms', 100, 3600);
            reception_sms(positive_id($data['id'] ?? 0));
        } else {
            throw new AuthError('عملیات نامعتبر است.');
        }
        $_SESSION['flash'] = 'تغییر ثبت شد. وضعیت اطلاع‌رسانی در فهرست مشخص است.';
        redirect('secretary.php?clinic=' . $clinic . '&tab=' . $tab);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
$panelTabs = $tabs;
if (!$canManage) { unset($panelTabs['book'], $panelTabs['delays']); }
set_panel_navigation('secretary.php', $panelTabs, $tab, (int)$c['doctor_id'], $clinic, 'پنل منشی');
layout_start('پنل منشی');
echo '<div class="workspace-heading"><div><span class="eyebrow">پذیرش و هماهنگی مطب</span><h1>پنل منشی</h1><p>' .
    esc($actor['name'] . ' • ' . $c['name']) .
    '</p></div><span class="badge">فضای کار منشی</span></div><form method="get" class="filter-bar no-print"><input type="hidden" name="tab" value="' .
    esc($tab) .
    '"><label>پزشک<select name="doctor" data-reception-doctor>';
$seen = [];
foreach ($clinics as $cl) {
    if (!isset($seen[$cl['doctor_id']])) {
        $seen[$cl['doctor_id']] = true;
        echo '<option value="' .
            $cl['doctor_id'] .
            '" ' .
            ((int) $c['doctor_id'] === (int) $cl['doctor_id'] ? 'selected' : '') .
            '>' .
            esc($cl['doctor']) .
            '</option>';
    }
}
echo '</select></label><label>مطب<select name="clinic" data-reception-clinic>';
foreach ($clinics as $cl) {
    echo '<option value="' .
        $cl['id'] .
        '" data-doctor="' .
        $cl['doctor_id'] .
        '" ' .
        ($clinic === (int) $cl['id'] ? 'selected' : '') .
        '>' .
        esc($cl['name'] . ' • ' . $cl['doctor']) .
        '</option>';
}
echo '</select></label><button>نمایش مطب</button></form><nav class="admin-nav">';
foreach ($tabs as $k => $v) {
    echo '<a ' .
        ($k === $tab ? 'class="selected"' : '') .
        ' href="secretary.php?clinic=' .
        $clinic .
        '&tab=' .
        $k .
        '">' .
        $v .
        '</a>';
}
echo '</nav>';
if ($error) {
    echo '<p class="error" role="alert">' . esc($error) . '</p>';
}
try {
    if (in_array($tab, ['dashboard', 'calendar'], true)) {
        $day =
            $tab === 'dashboard'
                ? date('Y-m-d')
                : parse_jdate(field($_GET, 'date', 10, false) ?: jdate(date('Y-m-d')));
        $view = $tab === 'dashboard' ? 'day' : (string) ($_GET['view'] ?? 'day');
        $status = $tab === 'dashboard' ? '' : (string) ($_GET['status'] ?? '');
        $rows = reception_list($clinic, $day, $view, $status);
        $labels = [
            'waiting' => 'در انتظار',
            'arrived' => 'مراجعه‌کرده',
            'in_visit' => 'در حال ویزیت',
            'completed' => 'تکمیل‌شده',
            'cancelled' => 'لغوشده',
            'no_show' => 'عدم مراجعه',
            'held' => 'منتظر پرداخت',
            'expired' => 'منقضی‌شده',
        ];
        if ($tab === 'dashboard') {
            echo '<div class="metric-grid">';
            foreach ($labels as $k => $label) {
                $count = count(array_filter($rows, fn($b) => $b['reception_status'] === $k));
                echo '<article class="metric"><span>' .
                    $label .
                    '</span><strong>' .
                    fa_number($count) .
                    '</strong></article>';
            }
            echo '</div>';
        } else {
            echo '<form method="get" class="filter-bar no-print"><input type="hidden" name="tab" value="calendar"><input type="hidden" name="clinic" value="' .
                $clinic .
                '">';
            input('تاریخ شمسی شروع', 'date', jdate($day), 'text', true, 10, 'data-jdate');
            echo '<label>نما<select name="view"><option value="day">روزانه</option><option value="week" ' .
                ($view === 'week' ? 'selected' : '') .
                '>هفتگی از تاریخ انتخابی</option></select></label><label>وضعیت<select name="status"><option value="">همه وضعیت‌ها</option>';
            foreach ($labels as $k => $label) {
                echo '<option value="' .
                    $k .
                    '" ' .
                    ($status === $k ? 'selected' : '') .
                    '>' .
                    $label .
                    '</option>';
            }
            echo '</select></label><button>اعمال فیلتر</button></form>';
        }
        echo '<h2>برنامه ' .
            esc(jdate($day)) .
            ($view === 'week' ? ' تا ' . esc(jdate(date('Y-m-d', strtotime($day . ' +6 days')))) : '') .
            '</h2>' .
            ($view === 'day'
                ? (function () use ($clinic, $day, $doctor) {
                    require_once __DIR__ . '/lib/day_export.php';
                    return '<div class="day-export-bar no-print">' . (doctor_has($doctor, 'day_export') ? day_export_button('secretary.php?clinic=' . $clinic . '&export=day&date=' . rawurlencode(jdate($day)), $day) : '<span class="muted small">' . icon('lock') . ' اکسل نوبت‌های روز با اشتراک پرو پزشک فعال می‌شود.</span>') . '<small>ردیف، جنسیت، نام، کد ملی، تاریخ تولد، بیمه، تماس، زمان نوبت، استان و شهر</small></div>';
                })()
                : '') .
            '<button type="button" data-print hidden>چاپ برنامه نوبت‌ها</button><div class="table-wrap"><table class="reception-table"><thead><tr><th>تاریخ و ساعت</th><th>بیمار</th><th>موبایل</th><th>خدمت</th><th>ثبت</th><th>وضعیت</th><th class="no-print">پذیرش</th></tr></thead><tbody>';
        foreach ($rows as $b) {
            echo '<tr><td>' .
                esc($b['starts_at_jalali']) .
                '</td><td>' .
                esc($b['patient_name']) .
                '</td><td><bdi>' .
                esc(fa_number($b['phone'])) .
                '</bdi></td><td>' .
                esc($b['service_name']) .
                '</td><td>' .
                (['online' => 'آنلاین', 'phone' => 'تلفنی', 'in_person' => 'حضوری'][$b['booking_channel']] ??
                    'قبلی') .
                '</td><td><span class="badge">' .
                esc($b['status_label']) .
                '</span>' .
                (!empty($b['queue_number']) ? ' <span class="badge queue-badge">شماره ' . fa_number($b['queue_number']) . '</span>' : '') .
                (!empty($b['estimated_at']) ? '<br><small>تقریبی ' . esc(fa_number(substr($b['estimated_at'], 11, 5))) . '</small>' : '') .
                '</td><td class="no-print"><a href="secretary-receipt.php?id=' .
                $b['id'] .
                '">رسید و تغییر وضعیت</a></td></tr>';
        }
        echo '</tbody></table></div>';
        if (!$rows) {
            echo '<p class="empty-state">نوبتی در این بازه وجود ندارد.</p>';
        }
    } elseif ($tab === 'checkin') {
        checkin_page($clinic, $clinics, 'secretary.php', ['tab' => 'checkin']);
    } elseif ($tab === 'queue') {
        render('reception/queue', ['clinic' => $clinic, 'c' => $c, 'canManage' => $canManage]);
    } elseif ($tab === 'notifications') {
        render_notifications();
    } elseif ($tab === 'patients') {
        reception_scope($clinic, 'bookings');
        $term = field($_GET, 'q', 190, false);
        echo '<form method="get" class="filter-bar"><input type="hidden" name="tab" value="patients"><input type="hidden" name="clinic" value="' .
            $clinic .
            '">';
        input('نام یا موبایل بیمار', 'q', $term);
        echo '<button>جستجوی بیمار</button></form>';
        if ($term !== '') {
            $result = reception_search($clinic, $term);
            echo '<h2>دفتر پذیرش این مطب</h2><div class="cards">';
            foreach ($result['patients'] as $p) {
                echo '<article class="card"><h3>' .
                    esc($p['name']) .
                    '</h3><p>' .
                    esc(fa_number($p['mobile'])) .
                    '</p><a class="button" href="secretary.php?clinic=' .
                    $clinic .
                    '&tab=book&patient=' .
                    $p['id'] .
                    '">ثبت نوبت</a>';
                if (!$p['parent_id']) {
                    echo '<a href="secretary.php?clinic=' .
                        $clinic .
                        '&tab=patients&parent=' .
                        $p['id'] .
                        '">افزودن عضو خانواده</a>';
                }
                echo '</article>';
            }
            echo '</div><h2>مراجعه‌کنندگان دارای سابقه قابل دسترس</h2>';
            foreach ($result['visitors'] as $p) {
                echo '<p class="card">' .
                    esc($p['name'] . ' • ' . fa_number($p['mobile'])) .
                    ' <a href="secretary.php?clinic=' .
                    $clinic .
                    '&tab=patients&name=' .
                    rawurlencode($p['name']) .
                    '&mobile=' .
                    rawurlencode($p['mobile']) .
                    '">انتقال اطلاعات به فرم بیمار جدید</a></p>';
            }
            echo '<p class="muted">حداکثر ۵۰ نتیجه در هر بخش؛ برای نتیجه دقیق‌تر نام کامل یا موبایل وارد کنید.</p>';
        }
        $parent = (int) ($_GET['parent'] ?? 0);
        $guardian = $parent
            ? query('SELECT * FROM reception_patients WHERE id=? AND clinic_id=? AND parent_id IS NULL', [
                $parent,
                $clinic,
            ])->fetch()
            : null;
        if ($parent && !$guardian) {
            throw new AuthError('سرپرست مجاز پیدا نشد.', 403);
        }
        echo '<article class="card narrow"><h2>' .
            ($guardian ? 'عضو خانواده ' . esc($guardian['name']) : 'ثبت بیمار جدید') .
            '</h2><p>نام و موبایل تماس برای پذیرش کافی است. این ثبت، احراز هویت آنلاین نیست.</p><form method="post">';
        form_token('patient');
        echo '<input type="hidden" name="parent_id" value="' . $parent . '">';
        input('نام و نام خانوادگی بیمار', 'name', $_POST['name'] ?? ($_GET['name'] ?? ''));
        input(
            $guardian ? 'موبایل سرپرست' : 'موبایل تماس',
            'mobile',
            $guardian['mobile'] ?? ($_POST['mobile'] ?? ($_GET['mobile'] ?? '')),
            'tel',
        );
        // Optional details: they fill the daily Excel list (national ID, birth date, insurance, city).
        $p = ($_POST['action'] ?? '') === 'patient' ? $_POST : [];
        echo '<details class="reception-more"' . ($p && (($p['national_id'] ?? '') !== '' || ($p['insurance'] ?? '') !== '') ? ' open' : '') . '><summary>' . icon('id-card') . ' کد ملی، تاریخ تولد، بیمه و شهر (اختیاری؛ برای فایل اکسل روزانه)</summary>';
        echo '<fieldset class="field"><legend>جنسیت</legend><div class="seg">';
        foreach (['male' => 'آقا', 'female' => 'خانم'] as $k => $l) {
            echo '<label><input type="radio" name="gender" value="' . $k . '"' . checked_attr(($p['gender'] ?? '') === $k) . '><span>' . $l . '</span></label>';
        }
        echo '</div></fieldset>';
        echo national_id_field('', !empty($p['foreign']), 'other', false);
        echo birth_select(null, $p, false);
        echo insurance_select($p['insurance'] ?? null, false);
        echo '<div class="field-geo">';
        location_fields(['city_id' => (int) ($p['city_id'] ?? 0), 'province_id' => (int) ($p['province_id'] ?? 0), 'optional' => true]);
        echo '</div></details>';
        echo '<button>ثبت بیمار و انتخاب نوبت</button></form></article>';
    } elseif ($tab === 'book') {
        reception_scope($clinic, 'bookings');
        $pid = (int) ($_GET['patient'] ?? 0);
        $service = (int) ($_GET['service'] ?? 0);
        $day = parse_jdate(field($_GET, 'date', 10, false) ?: jdate(date('Y-m-d')));
        $patients = query('SELECT * FROM reception_patients WHERE clinic_id=? ORDER BY id DESC LIMIT 200', [
            $clinic,
        ])->fetchAll();
        if ($pid && !array_filter($patients, fn($p) => (int) $p['id'] === $pid)) {
            $selected = query('SELECT * FROM reception_patients WHERE id=? AND clinic_id=?', [
                $pid,
                $clinic,
            ])->fetch();
            if (!$selected) {
                throw new AuthError('بیمار مجاز پیدا نشد.', 403);
            }
            array_unshift($patients, $selected);
        }
        $services = query('SELECT * FROM doctor_services WHERE doctor_id=? AND active=1 ORDER BY name', [
            $c['doctor_id'],
        ])->fetchAll();
        if (!$service && $services) {
            $service = (int) $services[0]['id'];
        }
        if (!$pid && $patients) {
            $pid = (int) $patients[0]['id'];
        }
        echo '<p class="notice">سیاست کارمزد پذیرش: ' .
            (setting('secretary_fee_policy') === 'online'
                ? 'پرداخت آنلاین توسط بیمار؛ مبلغ فعلی ' . toman(booking_fee_for($clinic)[0])
                : 'معاف از کارمزد') .
            '</p><form method="get" class="filter-bar"><input type="hidden" name="tab" value="book"><input type="hidden" name="clinic" value="' .
            $clinic .
            '"><label>بیمار<select name="patient">';
        foreach ($patients as $p) {
            echo '<option value="' .
                $p['id'] .
                '" ' .
                ((int) $p['id'] === $pid ? 'selected' : '') .
                '>' .
                esc($p['name'] . ' • ' . fa_number($p['mobile'])) .
                '</option>';
        }
        echo '</select></label><label>خدمت<select name="service">';
        foreach ($services as $s) {
            echo '<option value="' .
                $s['id'] .
                '" ' .
                ((int) $s['id'] === $service ? 'selected' : '') .
                '>' .
                esc($s['name']) .
                '</option>';
        }
        echo '</select></label>';
        input('تاریخ شمسی', 'date', jdate($day), 'text', true, 10, 'data-jdate');
        echo '<button>نمایش زمان‌های آزاد</button></form><p><a href="secretary.php?clinic=' .
            $clinic .
            '&tab=patients">جستجو یا ثبت بیمار و خانواده</a></p>';
        $slots = $service ? availability($clinic, $service, $day) : [];
        if ($pid && $slots) {
            echo '<article class="card narrow"><h2>ثبت نوبت پذیرش</h2><form method="post">';
            form_token('reserve');
            echo '<input type="hidden" name="patient_id" value="' .
                $pid .
                '"><input type="hidden" name="service_id" value="' .
                $service .
                '"><label>زمان آزاد<select name="starts_at">';
            foreach ($slots as $s) {
                echo '<option value="' .
                    esc($s['starts_at']) .
                    '">' .
                    esc($s['label']) .
                    ' • ' .
                    fa_number($s['remaining']) .
                    ' ظرفیت</option>';
            }
            echo '</select></label><label>روش ثبت<select name="channel"><option value="phone">تلفنی</option><option value="in_person">حضوری</option></select></label><button>ثبت نوبت</button></form></article>';
        } else {
            echo '<p class="empty-state">بیمار و خدمت را ثبت کنید و روز دارای ظرفیت آزاد را انتخاب کنید.</p>';
        }

        $legacy = query(
            "SELECT s.*,d.subscription_until FROM slots s JOIN doctors d ON d.id=s.doctor_id WHERE s.clinic_id=? AND s.source='legacy' AND s.starts_at>=? AND s.starts_at<? ORDER BY s.starts_at",
            [$clinic, $day . ' 00:00:00', date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00'],
        )->fetchAll();
        $legacy = array_values(array_filter($legacy, fn($s) => legacy_slot_open($s)));
        if ($pid && $legacy) {
            echo '<article class="card narrow"><h2>زمان‌های دستی قبلی</h2><form method="post">';
            form_token('reserve');
            echo '<input type="hidden" name="patient_id" value="' .
                $pid .
                '"><label>زمان دستی<select name="slot_id">';
            foreach ($legacy as $slot) {
                echo '<option value="' .
                    $slot['id'] .
                    '">' .
                    esc(jdate($slot['starts_at'], true)) .
                    '</option>';
            }
            echo '</select></label><label>روش ثبت<select name="channel"><option value="phone">تلفنی</option><option value="in_person">حضوری</option></select></label><button>ثبت زمان دستی</button></form></article>';
        }
    } elseif ($tab === 'delays') {
        reception_scope($clinic, 'bookings');
        echo '<article class="card"><h2>اعلام تأخیر پزشک</h2><p>زمان نوبت‌ها جابه‌جا نمی‌شود. برای نوبت‌های قطعی بازه، اعلان داخلی و فهرست پیگیری ساخته می‌شود. برای بیمار بدون حساب متصل، پیامک یا تماس لازم است.</p><form method="post">';
        form_token('delay');
        input('شروع بازه شمسی', 'starts_at', jdate(date('Y-m-d H:i:s'), true), 'text', true, 190, 'data-jdate="time"');
        input('پایان بازه شمسی', 'ends_at', jdate(date('Y-m-d H:i:s', time() + 3600), true), 'text', true, 190, 'data-jdate="time"');
        input('دقیقه تأخیر', 'minutes', '30', 'number');
        input('علت', 'reason', '');
        echo '<label class="check"><input type="checkbox" name="send_sms" value="1"> پیامک تأخیر هم برای همه بیماران این بازه در صف ارسال قرار گیرد (با رعایت تنظیمات بیمار و فعال‌بودن الگو)</label><p class="muted">زمان تقریبی مراجعه هر نوبت به اندازه تأخیر جلو می‌رود و در پنل بیمار نمایش داده می‌شود.</p>';
        echo '<button>ثبت تأخیر و ایجاد اعلان‌ها</button></form></article><h2>پیگیری اطلاع‌رسانی</h2>';
        $page = max(1, min(100000, (int) ($_GET['page'] ?? 1)));
        $rows = query(
            'SELECT r.*,d.minutes,d.starts_at,d.reason,b.patient_name,b.patient_user_id FROM reception_delay_recipients r JOIN reception_delays d ON d.id=r.delay_id JOIN bookings b ON b.id=r.booking_id JOIN slots s ON s.id=b.slot_id WHERE d.clinic_id=?' .
                ($canHistory ? '' : ' AND s.starts_at>=CURDATE()') .
                ' ORDER BY r.id DESC LIMIT 51 OFFSET ' .
                ($page - 1) * 50,
            [$clinic],
        )->fetchAll();
        $hasNext = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as $r) {
            echo '<article class="card"><h3>' .
                esc($r['patient_name']) .
                ' • ' .
                fa_number($r['minutes']) .
                ' دقیقه</h3><p>' .
                esc(jdate($r['starts_at'], true) . ' • ' . $r['reason']) .
                '</p><p>موبایل: ' .
                esc(fa_number($r['mobile'])) .
                ' • اعلان بیمار: ' .
                ($r['patient_user_id'] ? 'در حساب ثبت شد' : 'حساب متصل ندارد') .
                '</p><p>پیامک: ' .
                ([
                    'pending' => 'ارسال نشده',
                    'sending' => 'در حال ارسال یا نیازمند پیگیری',
                    'sent' => 'پذیرفته‌شده توسط سرویس؛ تحویل تأیید نشده',
                    'unknown' => 'نامشخص؛ پیگیری از پنل پیامک',
                ][$r['sms_state']] ??
                    'نامشخص') .
                '</p>';
            if (
                $r['sms_state'] === 'pending' &&
                sms_config()['enabled'] &&
                setting('delay_sms_template') !== ''
            ) {
                echo '<form method="post">';
                form_token('sms');
                echo '<input type="hidden" name="id" value="' .
                    $r['id'] .
                    '"><button>ارسال پیامک تأخیر</button></form>';
            }
            if ($r['contacted_at']) {
                echo '<p class="notice">تماس ثبت‌شده: ' .
                    esc(jdate($r['contacted_at'], true) . ' • ' . $r['contact_note']) .
                    '</p>';
            } else {
                echo '<form method="post">';
                form_token('contact');
                echo '<input type="hidden" name="id" value="' . $r['id'] . '">';
                input('توضیح تماس', 'note', '');
                echo '<label><input type="checkbox" name="contacted" value="1" required> شخصاً بیمار یا سرپرست را مطلع کردم</label><button class="secondary">ثبت نتیجه تماس</button></form>';
            }
            echo '</article>';
        }
        if (!$rows) {
            echo '<p class="empty-state">موردی برای پیگیری نیست.</p>';
        }
        echo '<nav class="no-print">';
        if ($page > 1) {
            echo '<a class="button secondary" href="secretary.php?clinic=' .
                $clinic .
                '&tab=delays&page=' .
                ($page - 1) .
                '">صفحه قبل</a>';
        }
        if ($hasNext) {
            echo '<a class="button secondary" href="secretary.php?clinic=' .
                $clinic .
                '&tab=delays&page=' .
                ($page + 1) .
                '">صفحه بعد</a>';
        }
        echo '</nav>';
    } elseif ($tab === 'audit') {
        $rows = query(
            "SELECT e.*,u.name actor FROM appointment_events e LEFT JOIN users u ON u.id=e.actor_user_id LEFT JOIN bookings b ON b.id=e.booking_id LEFT JOIN slots s ON s.id=b.slot_id WHERE e.doctor_id=? AND (s.clinic_id=? OR JSON_UNQUOTE(JSON_EXTRACT(e.details,'$.clinic_id'))=?)" .
                ($canHistory
                    ? ''
                    : ' AND e.created_at>=CURDATE() AND (s.starts_at IS NULL OR s.starts_at>=CURDATE())') .
                ' ORDER BY e.id DESC LIMIT 200',
            [$c['doctor_id'], $clinic, (string) $clinic],
        )->fetchAll();
        echo '<h2>۲۰۰ تغییر اخیر در محدوده مجاز</h2>';
        foreach ($rows as $e) {
            $details = json_decode($e['details'], true) ?: [];
            echo '<article class="card"><strong>' .
                esc(event_label($e['event_type'])) .
                '</strong><p>' .
                esc(($e['actor'] ?: 'کاربر سامانه') . ' • ' . jdate($e['created_at'], true)) .
                '</p>';
            if ($e['booking_id']) {
                echo '<a href="secretary-receipt.php?id=' .
                    $e['booking_id'] .
                    '">نوبت ' .
                    fa_number($e['booking_id']) .
                    '</a>';
            }
            if (isset($details['reason'])) {
                echo '<p>' . esc($details['reason']) . '</p>';
            }
            echo '</article>';
        }
    }
} catch (Throwable $e) {
    echo '<p class="error" role="alert">' . esc(error_message($e)) . '</p>';
}
layout_end();
