<?php
require __DIR__ . '/core.php';
require_phase2();
$error = '';
$user = user_now();
if (!$user) {
    redirect('login.php?next=register.php');
}
$_POST['mobile'] = $user['mobile'];
// 1.0.0-rc.14: a doctor who already has a profile goes to it (approved) or sees its status (pending,
// rejected, suspended) instead of a second form that would only end in a duplicate error.
$mine = my_doctor((int) $user['id']);
if ($mine && $mine['review_status'] === 'approved' && (int) $mine['active'] === 1 && empty($_GET['received'])) {
    redirect('doctor-panel.php');
}
$claim = $mine ? null : doctor_claimable($user);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim') {
    verify_csrf();
    try {
        doctor_claim_request();
        redirect('register.php');
    } catch (Throwable $e) {
        $error = error_message($e);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$mine && !$claim) {
    verify_csrf();
    try {
        // Counts every attempt (spam guard); high enough that a few corrected mistakes never lock a doctor out.
        throttle('doctor_register', 20, 3600);
        if (!empty($_POST['website']) || !isset($_POST['consent'])) {
            throw new RuntimeException('تأیید صحت اطلاعات و رضایت برای بررسی ضروری است.');
        }
        // 1.0.0-rc.19: optional profile photo, checked before anything is saved.
        $photo = $_FILES['photo'] ?? null;
        $hasPhoto = is_array($photo) && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($hasPhoto) {
            doctor_photo_check($photo);
        }
        $doctorId = register_doctor($_POST);
        // rc.20: plan picked on the form (free | pro); Pro is activated after approval (trial or payment).
        $choice = ($_POST['plan_choice'] ?? 'free') === 'pro' && subscriptions_on() ? 'pro' : 'free';
        query('UPDATE doctors SET plan_choice=? WHERE id=?', [$choice, $doctorId]);
        $_SESSION['registration_plan'] = $choice;
        if ($hasPhoto) {
            try {
                save_doctor_photo($doctorId, $photo);
            } catch (Throwable $e) {
                app_log('error', 'register_photo', ['doctor' => $doctorId, 'error' => error_message($e)]);
                flash('درخواست ثبت شد ولی تصویر ذخیره نشد؛ آن را از «پروفایل پزشک» در پنل بارگذاری کنید.');
            }
        }
        $_SESSION['registration_received'] = true;
        redirect('register.php?received=1');
    } catch (Throwable $e) {
        $error =
            $e instanceof PDOException && $e->getCode() === '23000'
                ? 'امکان ثبت این درخواست نیست. در صورت ثبت قبلی با مدیر سامانه تماس بگیرید.'
                : error_message($e);
    }
}
$wizard = !$mine && !$claim;
layout_start('ثبت‌نام پزشک', ['body_class' => $wizard ? 'auth-page doctor-register-page' : '']);
if (!$wizard) echo '<h1>درخواست عضویت پزشک</h1>';
if (!empty($_GET['received']) && !empty($_SESSION['registration_received'])) {
    unset($_SESSION['registration_received']);
    $pickedPro = ($_SESSION['registration_plan'] ?? '') === 'pro';
    unset($_SESSION['registration_plan']);
    echo '<article class="card"><h2>درخواست شما ثبت شد</h2><p>اطلاعات پزشک و مشخصات مطب برای بررسی مدیر ارسال شد. ' . 'پس از تأیید مدیر، پروفایل شما منتشر می‌شود و نوبت آنلاین می‌گیرید (نسخه رایگان؛ بدون نیاز به اشتراک).' . '</p><p>وضعیت درخواست از پنل پزشک در حساب شما قابل مشاهده است.</p>' . ($pickedPro ? '<p class="notice">' . icon('verified') . ' نسخه پرو را انتخاب کردید. پس از تأیید مدیر' . (setting('subscription_trial_enabled', '0') === '1' ? '، دوره آزمایشی پرو خودکار فعال می‌شود و پس از آن' : '') . ' از بخش «اشتراک پرو» در پنل پزشک پرداخت و فعال‌سازی را انجام دهید.</p><p><a class="button" href="doctor-panel.php?tab=subscription">' . icon('verified') . ' بخش اشتراک پرو</a></p>' : '') . '</article>';
} elseif ($mine) {
    $states = [
        'pending' => ['در انتظار بررسی مدیر', 'درخواست عضویت شما ثبت شده و در صف بررسی است. پس از تأیید مدیر، پروفایل منتشر می‌شود. تا آن زمان می‌توانید پروفایل، مطب‌ها و برنامه کاری را در پنل پزشک کامل کنید.'],
        'approved' => ['تأییدشده', 'پروفایل شما تأیید و منتشر شده است.'],
        'rejected' => ['ردشده', 'درخواست شما تأیید نشد. توضیح مدیر را ببینید، اطلاعات را در پنل پزشک اصلاح کنید و از پشتیبانی بررسی دوباره را بخواهید.'],
        'suspended' => ['معلق', 'حساب پزشک شما معلق شده است. برای پیگیری با پشتیبانی تماس بگیرید.'],
    ];
    [$label, $text] = $states[$mine['review_status']] ?? $states['pending'];
    echo '<article class="card register-status"><h2>' . esc($mine['name']) . ' <span class="badge">' . esc($label) . '</span></h2><p>' . esc($text) . '</p>';
    if (!empty($mine['review_note']) && in_array($mine['review_status'], ['rejected', 'suspended'], true)) {
        echo '<p class="notice">توضیح مدیر: ' . esc($mine['review_note']) . '</p>';
    }
    echo '<p><a class="button" href="doctor-panel.php">ورود به پنل پزشک</a> <a class="button secondary" href="support.php">پشتیبانی</a></p></article>';
} elseif ($claim) {
    if ($error) {
        echo '<p class="error" role="alert">' . esc($error) . '</p>';
    }
    $ticket = doctor_claim_ticket((int) $user['id'], (int) $claim['id']);
    echo '<article class="card register-claim"><h2>پروفایل شما در نوبت‌یار وجود دارد</h2><p>پروفایل <strong>' . esc($claim['name']) . '</strong>' .
        ($claim['specialty'] ? ' (' . esc($claim['specialty']) . ')' : '') .
        ' با همین شماره موبایل ثبت شده است. نیازی به ثبت‌نام دوباره نیست.</p>';
    if ($ticket) {
        echo '<p class="notice">درخواست اتصال شما ثبت شده است. پس از تأیید پشتیبانی، پنل پزشک از «ورود پزشکان» باز می‌شود.</p><p><a class="button secondary" href="support.php?id=' . $ticket . '">پیگیری درخواست</a></p>';
    } else {
        echo '<p>برای امنیت، اتصال پروفایل به حساب شما پس از تأیید پشتیبانی انجام می‌شود.</p><form method="post">';
        form_token('register');
        echo '<input type="hidden" name="action" value="claim"><button>درخواست اتصال این پروفایل به حساب من</button></form><p class="muted small">اگر این پروفایل متعلق به شما نیست با پشتیبانی تماس بگیرید.</p>';
    }
    echo '</article>';
} else {
    if (doctor_mobile_owned_elsewhere($user)) {
        echo '<p class="notice">پروفایل پزشکی با این شماره به حساب دیگری متصل است. برای انتقال آن با پشتیبانی تماس بگیرید.</p>';
    }

    if ($error) {
        echo '<p class="error" role="alert">' . esc($error) . '</p>';
    }
    // 1.0.0-rc.16: only a site with no active city at all stops here (the specialty can always be typed in).
    if (!query('SELECT c.id FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.active=1 AND p.active=1 LIMIT 1')->fetch()) {
        echo '<article class="card"><h2>ثبت‌نام موقتاً در دسترس نیست</h2><p>هنوز هیچ شهری در نوبت‌یار فعال نشده است. لطفاً از پشتیبانی پیگیری کنید.</p><p><a class="button secondary" href="support.php">پشتیبانی</a></p></article>';
    } else {
        render('public/doctor-register', ['values' => $_POST, 'hasError' => $error !== '']);
    }
}
layout_end();
