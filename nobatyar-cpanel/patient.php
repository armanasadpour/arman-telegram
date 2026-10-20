<?php
require __DIR__ . '/core.php';
require_phase2();
if (!user_now()) {
    redirect('login.php?next=patient.php');
}
$u = require_user();
$tab = (string) ($_GET['tab'] ?? 'upcoming');
$tabs = [
    'upcoming' => 'نوبت‌های آینده',
    'past' => 'سوابق نوبت‌ها',
    'held' => 'رزروهای موقت',
    'waitlist' => 'صف انتظار',
    'family' => 'اعضای خانواده',
    'notifications' => 'اعلان‌ها',
];
if (search_config()['reviews']) {
    $tabs['reviews'] = 'نظر درباره پزشک';
}
if (!isset($tabs[$tab])) {
    $tab = 'upcoming';
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = field($_POST, 'action', 30);
        if ($action === 'family') {
            if (($_POST['operation'] ?? 'save') === 'archive') {
                family_action($_POST);
            } else {
                family_member_save((int) $u['id'], $_POST);
            }
        } elseif ($action === 'change') {
            patient_change($_POST);
        } elseif ($action === 'waitlist_cancel') {
            waitlist_cancel(positive_id($_POST['id'] ?? 0));
        } elseif ($action === 'notification_read') {
            read_notification(positive_id($_POST['id'] ?? 0));
        } elseif ($action === 'review') {
            $_SESSION['flash'] = review_save($u, $_POST);
            redirect('patient.php?tab=reviews');
        } else {
            throw new AuthError('عملیات نامعتبر است.');
        }
        $_SESSION['flash'] = 'تغییرات ثبت شد.';
        redirect('patient.php?tab=' . $tab);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
require __DIR__ . '/lib/doctor_views.php';
layout_start('نوبت‌های من', ['body_class' => 'account-page', 'robots' => 'noindex,nofollow']);
echo '<div class="page-head"><div><span class="eyebrow">' . esc($u['name'] ?: fa_number($u['mobile'])) . '</span><h1>نوبت‌های من</h1></div><a class="button small" href="index.php?view=doctors">' . icon('plus') . ' نوبت جدید</a></div>';
echo page_tabs($tabs, $tab, fn($k) => 'patient.php?tab=' . $k);
if ($error) {
    echo '<p class="error">' . esc($error) . '</p>';
}
if ($tab === 'family') {
    echo '<p class="muted">نوبت اعضای خانواده در همین حساب و با موبایل تأییدشده شما ثبت می‌شود. حذف عضو، سابقه رزرو او را پاک نمی‌کند.</p><div class="cards">';
    $family = query('SELECT * FROM family_members WHERE user_id=? AND active=1 ORDER BY id', [
        $u['id'],
    ])->fetchAll();
    $family[] = ['id' => 0, 'name' => '', 'relationship' => 'فرزند', 'birth_date' => null, 'gender' => null, 'insurance' => null, 'id_type' => null, 'national_id_enc' => null];
    $posted = ($_POST['action'] ?? '') === 'family' && $error ? $_POST : [];
    foreach ($family as $f) {
        $p = $posted && (int) ($posted['id'] ?? 0) === (int) $f['id'] ? $posted : [];
        $missing = $f['id'] ? member_missing($f) : [];
        echo '<article class="card family-card' . ($missing ? ' is-incomplete' : '') . '"><h2>' . ($f['id'] ? esc($f['name']) : 'عضو جدید') . '</h2>';
        if ($missing) {
            echo '<p class="who-warn">' . icon('alert') . ' اطلاعات ناقص است؛ برای رزرو نوبت تکمیل کنید.</p>';
        }
        echo '<form method="post" class="bk-form" novalidate data-validate>';
        form_token('family');
        if ($f['id']) {
            echo '<input type="hidden" name="id" value="' . (int) $f['id'] . '">';
        }
        echo '<label class="field"><span>نام و نام خانوادگی <span class="req" aria-hidden="true">*</span></span><input name="name" value="' . esc($p['name'] ?? $f['name']) . '" maxlength="190" required></label>';
        echo '<label class="field"><span>نسبت <span class="req" aria-hidden="true">*</span></span><select name="relationship" required>';
        foreach (['همسر', 'فرزند', 'پدر', 'مادر', 'سایر'] as $rel) {
            echo '<option' . selected_attr(($p['relationship'] ?? $f['relationship']) === $rel) . '>' . $rel . '</option>';
        }
        echo '</select></label>';
        echo national_id_field(identity_hint($f['id_type'], $f['national_id_enc']), !empty($p['foreign']) || (!$p && $f['id_type'] === 'foreign'), 'other', patient_id_mode() === 'required');
        echo birth_select($f['birth_date'], $p);
        $g = $p['gender'] ?? $f['gender'];
        echo '<fieldset class="field"><legend>جنسیت <span class="req" aria-hidden="true">*</span></legend><div class="seg">';
        foreach (['male' => 'آقا', 'female' => 'خانم'] as $k => $l) {
            echo '<label><input type="radio" name="gender" value="' . $k . '"' . checked_attr($g === $k) . ' required><span>' . $l . '</span></label>';
        }
        echo '</div></fieldset>';
        echo insurance_select($p['insurance'] ?? $f['insurance']);
        echo '<button>ذخیره عضو</button></form>';
        if ($f['id']) {
            dp_form('family', 0, (int) $f['id']);
            echo '<input type="hidden" name="operation" value="archive"><button class="secondary">حذف از فهرست فعال</button></form>';
        }
        echo '</article>';
    }
    echo '</div><script src="assets/booking.js?v=' . esc(APP_VERSION) . '" defer></script>';
} elseif ($tab === 'notifications') {
    render_notifications();
} elseif ($tab === 'reviews') {
    render('public/patient-reviews', ['open' => reviewable_bookings((int) $u['id']), 'mine' => query('SELECT r.rating,r.recommend,r.comment,r.status,r.created_at,d.name doctor FROM reviews r JOIN doctors d ON d.id=r.doctor_id WHERE r.user_id=? ORDER BY r.id DESC LIMIT 30', [$u['id']])->fetchAll()]);
} elseif ($tab === 'waitlist') {
    render('public/waitlist', ['entries' => my_waitlist((int) $u['id'])]);
} else {
    $list = patient_bookings($tab, max(1, (int) ($_GET['page'] ?? 1)));
    echo '<p class="muted">' . fa_number($list['total']) . ' نوبت • همه زمان‌ها به وقت تهران</p>';
    echo '<div class="appt-list">';
    foreach ($list['items'] as $b) {
        echo booking_card($b);
    }
    echo '</div>';
    if (!$list['items']) {
        echo '<div class="empty-state">' . icon('calendar') . '<p>در این بخش نوبتی ندارید.</p>' . ($tab === 'upcoming' ? '<a class="button" href="index.php?view=doctors">' . icon('search') . ' رزرو نوبت</a>' : '') . '</div>';
    }
    echo pager($list['total'], 20, max(1, (int) ($_GET['page'] ?? 1)), fn($p) => 'patient.php?tab=' . $tab . '&page=' . $p);
}
echo '<a class="acc-back" href="panel.php">' . icon('user') . ' حساب من، تنظیمات و خروج' . icon('chevron-left') . '</a>';
layout_end();
