<?php
declare(strict_types=1);

/** Center panel: navigation, POST dispatch and the read-side data of each view. */

function center_tabs(): array
{
    $tabs = [
        'dashboard' => ['label' => 'نمای کلی', 'icon' => 'chart', 'perm' => 'view'],
        'calendar' => ['label' => 'تقویم تجمیعی', 'icon' => 'calendar', 'perm' => 'view'],
        'doctors' => ['label' => 'پزشکان', 'icon' => 'doctor', 'perm' => 'view'],
        'schedule' => ['label' => 'برنامه و اتاق‌ها', 'icon' => 'clock', 'perm' => 'view'],
        'branches' => ['label' => 'شعبه‌ها و اتاق‌ها', 'icon' => 'map', 'perm' => 'view'],
        'reports' => ['label' => 'گزارش عملکرد', 'icon' => 'chart', 'perm' => 'reports'],
        'announcements' => ['label' => 'اطلاعیه به بیماران', 'icon' => 'megaphone', 'perm' => 'announce'],
        'staff' => ['label' => 'کارکنان و دسترسی', 'icon' => 'users', 'perm' => 'view'],
        'subscription' => ['label' => 'اشتراک مرکز', 'icon' => 'receipt', 'perm' => 'billing'],
        'profile' => ['label' => 'مشخصات و احراز هویت', 'icon' => 'shield', 'perm' => 'view'],
        'events' => ['label' => 'گزارش تغییرات', 'icon' => 'eye', 'perm' => 'view'],
    ];
    // 1.0.0-rc.17: no subscription menu while subscription sales are off.
    if (!subscriptions_on()) {
        unset($tabs['subscription']);
    }
    return $tabs;
}

/** Handles a POST in the center panel; returns a flash message, or ['redirect' => ...] for payment. */
function center_dispatch(int $center, string $action, array $data, array $files): array
{
    $id = fn(string $k = 'id') => positive_id($data[$k] ?? 0);
    switch ($action) {
        case 'profile':
            return ['message' => center_save_profile($center, $data, $files)];
        case 'branch':
            center_branch_save($center, $data);
            return ['message' => 'شعبه ذخیره شد.'];
        case 'branch_main':
            center_branch_main($center, $id('branch_id'));
            return ['message' => 'شعبه اصلی تغییر کرد.'];
        case 'room':
            center_room_save($center, $data);
            return ['message' => 'اتاق ذخیره شد.'];
        case 'invite':
            center_invite_doctor($center, $data);
            return ['message' => 'دعوت برای پزشک ارسال شد؛ پس از پذیرش او، همکاری فعال می‌شود.'];
        case 'link_accept':
        case 'link_reject':
            center_link_respond($center, $id('link_id'), $action === 'link_accept');
            return ['message' => $action === 'link_accept' ? 'درخواست پزشک پذیرفته شد.' : 'درخواست رد شد.'];
        case 'link_end':
            $l = center_link($id('link_id'));
            if ((int) $l['center_id'] !== $center) {
                throw new AuthError('ارتباط پیدا نشد.', 404);
            }
            $future = link_end((int) $l['id'], 'center', field($data, 'reason', 500));
            return ['message' => 'همکاری پایان یافت.' . ($future ? ' ' . fa_number($future) . ' نوبت قطعی آینده حفظ شده است.' : '')];
        case 'presence':
            presence_add($center, $id('doctor_id'), $id('branch_id'));
            return ['message' => 'پزشک به شعبه اضافه شد؛ ساعت کاری و اتاق را تعیین کنید.'];
        case 'presence_remove':
            $future = presence_remove($center, $id('clinic_id'));
            return ['message' => 'نوبت‌دهی پزشک در این شعبه متوقف شد.' . ($future ? ' ' . fa_number($future) . ' نوبت قطعی آینده حفظ شده است.' : '')];
        case 'presence_settings':
            presence_settings($center, $id('clinic_id'), $data);
            return ['message' => 'تنظیمات نوبت ذخیره شد.'];
        case 'hours':
            center_hours_add($center, $id('clinic_id'), $data);
            return ['message' => 'ساعت کاری ثبت شد.'];
        case 'hours_remove':
            center_hours_remove($center, $id('hours_id'));
            return ['message' => 'ساعت کاری حذف شد؛ نوبت‌های قطعی قبلی حفظ شدند.'];
        case 'staff':
            center_staff_save($center, $data);
            return ['message' => 'دسترسی کارمند ذخیره شد.'];
        case 'staff_remove':
            center_staff_remove($center, $id('user_id'));
            return ['message' => 'دسترسی کارمند لغو شد.'];
        case 'announce':
            $r = center_announce($center, $data);
            return ['message' => 'اطلاعیه برای ' . fa_number($r['bookings']) . ' نوبت ثبت شد: ' . fa_number($r['in_app']) . ' اعلان داخل برنامه' . ($r['sms'] ? ' و ' . fa_number($r['sms']) . ' پیامک در صف ارسال' : '') . '.'];
        case 'sub_order':
            center_order_create($center, $id('plan_id'), (string) ($data['discount_code'] ?? ''));
            return ['message' => 'پیش‌فاکتور صادر شد.'];
        case 'sub_cancel':
            center_order_cancel($center, $id('order_id'));
            return ['message' => 'پیش‌فاکتور لغو شد.'];
        case 'sub_checkout':
            if(!empty($data['use_wallet'])){wallet_pay_center_subscription($id('order_id'),$center);return ['message'=>'اشتراک مرکز با کیف پول پرداخت و فعال شد.'];}
            $r = center_checkout($center, $id('order_id'));
            return $r['done'] ? ['message' => 'اشتراک فعال شد.'] : ['redirect' => $r['redirect']];
        case 'sub_trial':
            center_start_trial($center);
            return ['message' => 'دوره آزمایشی فعال شد.'];
    }
    throw new AuthError('عملیات نامعتبر است.');
}

function center_view_data(string $tab, array $a): array
{
    $center = (int) $a['center']['id'];
    $staff = $a['staff'];
    $branches = center_branches($center, $staff);
    $data = ['a' => $a, 'c' => $a['center'], 's' => $staff, 'branches' => $branches, 'open' => center_is_open($a['center'])];
    switch ($tab) {
        case 'dashboard':
            $branchSql = $staff['branch_id'] ? ' AND cl.branch_id=' . (int) $staff['branch_id'] : '';
            $count = fn(string $where) => (int) query(
                "SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN clinics cl ON cl.id=s.clinic_id JOIN center_doctors l ON l.center_id=cl.center_id AND l.doctor_id=cl.doctor_id AND l.status='active' AND (l.perm_reports=1 OR l.perm_bookings=1) WHERE cl.center_id=?$branchSql AND $where",
                [$center],
            )->fetchColumn();
            return $data + [
                'stats' => [
                    'today' => $count("DATE(s.starts_at)=CURDATE() AND b.status IN ('confirmed','completed','no_show')"),
                    'upcoming' => $count("s.starts_at>NOW() AND b.status='confirmed'"),
                    'completed' => $count("b.status='completed' AND s.starts_at>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)"),
                    'no_show' => $count("b.status='no_show' AND s.starts_at>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)"),
                ],
                'doctors' => (int) query("SELECT COUNT(*) FROM center_doctors WHERE center_id=? AND status='active'", [$center])->fetchColumn(),
                'pending' => query("SELECT l.*,d.name doctor_name FROM center_doctors l JOIN doctors d ON d.id=l.doctor_id WHERE l.center_id=? AND l.status IN ('requested','invited') ORDER BY l.id DESC", [$center])->fetchAll(),
                'sub' => center_subscription_state($a['center']),
            ];
        case 'calendar':
            $f = center_date_filters($_GET, 1);
            return $data + ['f' => $f, 'rows' => center_calendar($a, $f), 'doctors' => center_presence_doctors($center), 'schedule' => center_schedule($center, (int) ($staff['branch_id'] ?: $f['branch']))];
        case 'doctors':
            return $data + ['links' => center_doctor_links($center), 'presences' => center_presences($center, (int) ($staff['branch_id'] ?? 0), false)];
        case 'schedule':
            $branch = (int) ($staff['branch_id'] ?: ($_GET['branch'] ?? 0));
            $rooms = [];
            foreach ($branches as $b) {
                $rooms[$b['id']] = center_rooms((int) $b['id'], true);
            }
            return $data + ['branch' => $branch, 'presences' => center_presences($center, $branch), 'schedule' => center_schedule($center, $branch), 'rooms' => $rooms];
        case 'branches':
            $rooms = [];
            foreach ($branches as $b) {
                $rooms[$b['id']] = center_rooms((int) $b['id']);
            }
            return $data + ['rooms' => $rooms, 'provinces' => provinces_list()];
        case 'reports':
            $f = center_date_filters($_GET + ['from' => jdate(date('Y-m-d', strtotime('-29 days'))), 'to' => jdate(date('Y-m-d'))], 30);
            return $data + ['f' => $f, 'report' => center_report($a, $f), 'doctors' => center_presence_doctors($center)];
        case 'announcements':
            return $data + [
                'doctors' => center_presence_doctors($center, true),
                'history' => query('SELECT a.*,u.name sender,b.name branch,d.name doctor FROM center_announcements a LEFT JOIN users u ON u.id=a.created_by_user LEFT JOIN center_branches b ON b.id=a.branch_id LEFT JOIN doctors d ON d.id=a.doctor_id WHERE a.center_id=? ORDER BY a.id DESC LIMIT 50', [$center])->fetchAll(),
                'smsReady' => sms_dev_mode() || (sms_enabled() && (sms_template('center_announcement')['enabled'] ?? false)),
            ];
        case 'staff':
            return $data + ['staffList' => center_staff_list($center)];
        case 'subscription':
            return $data + [
                'sub' => center_subscription_state($a['center']),
                'plans' => center_plans(),
                'orders' => query('SELECT * FROM center_orders WHERE center_id=? ORDER BY id DESC LIMIT 50', [$center])->fetchAll(),
                'periods' => query('SELECT * FROM center_subscriptions WHERE center_id=? ORDER BY starts_on DESC LIMIT 50', [$center])->fetchAll(),
                'payable' => payment_available(),
            ];
        case 'profile':
            return $data + ['provinces' => provinces_list()];
        case 'events':
            return $data + ['events' => query('SELECT e.*,u.name actor FROM center_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.center_id=? ORDER BY e.id DESC LIMIT 200', [$center])->fetchAll()];
    }
    return $data;
}

/** Doctors with an active link (optionally only those who allow patient contact). */
function center_presence_doctors(int $center, bool $contactOnly = false): array
{
    return query(
        "SELECT d.id,d.name FROM center_doctors l JOIN doctors d ON d.id=l.doctor_id WHERE l.center_id=? AND l.status='active'" . ($contactOnly ? ' AND l.perm_bookings=1' : '') . ' ORDER BY d.name',
        [$center],
    )->fetchAll();
}
