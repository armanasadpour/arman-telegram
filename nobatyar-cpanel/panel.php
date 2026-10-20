<?php
/**
 * «حساب من»: the account hub. Patients see their profile, shortcuts, upcoming appointments and account
 * settings; work panels (doctor, secretary, center) appear only for accounts that really have those roles.
 * panel.php?role=doctor|secretary|clinic keeps the older staff view: allowed clinics and their appointments.
 */
require __DIR__ . '/core.php';
require_phase2();
$u = user_now();
if (!$u) {
    redirect('login.php?next=panel.php');
}
$labels = ['patient' => 'بیمار', 'doctor' => 'پزشک', 'secretary' => 'منشی', 'clinic' => 'مرکز درمانی'];
$role = (string) ($_GET['role'] ?? 'patient');
try {
    if (!isset($labels[$role])) {
        throw new AuthError('پنل پیدا نشد.', 404);
    }
    require_role($role);
} catch (AuthError $e) {
    http_response_code($e->status);
    layout_start('دسترسی محدود');
    echo '<div class="empty-state wide">' . icon('lock') . '<p>' . esc($e->getMessage()) . '</p><a class="button secondary" href="panel.php">بازگشت به حساب من</a></div>';
    layout_end();
    exit();
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'logout') {
            audit('auth.logout', (int) $u['id']);
            logout_user();
            redirect('login.php');
        }
        if ($action === 'logout_all') {
            logout_everywhere();
            redirect('login.php');
        }
        if ($action === 'notification_read') {
            read_notification(positive_id($_POST['id'] ?? 0));
        } elseif ($action === 'profile') {
            update_profile($_POST);
        } else {
            throw new AuthError('عملیات نامعتبر است.');
        }
        $_SESSION['flash'] = 'اطلاعات ذخیره شد.';
        redirect('panel.php' . ($role !== 'patient' ? '?role=' . $role : ''));
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
$u = require_user();
$name = trim((string) $u['name']) !== '' ? $u['name'] : 'کاربر نوبت‌یار';
$initial = mb_substr(trim(preg_replace('/^(دکتر|سید|سیده)\s+/u', '', $name)), 0, 1);
$unread = unread_notifications((int) $u['id']);
$work = account_workspaces($u);
layout_start($role === 'patient' ? 'حساب من' : 'پنل ' . $labels[$role], ['body_class' => 'account-page', 'robots' => 'noindex,nofollow']);
?>
<div class="acc">
  <section class="acc-hero">
    <span class="acc-avatar" aria-hidden="true"><?= esc($initial) ?></span>
    <div class="acc-id">
      <h1><?= esc($name) ?></h1>
      <p><bdi dir="ltr"><?= esc(fa_number($u['mobile'])) ?></bdi> <span class="acc-ok"><?= icon('check') ?> تأییدشده</span></p>
    </div>
    <form method="post" class="acc-logout"><?php form_token('logout'); ?><button class="button ghost small"><?= icon('logout') ?> خروج</button></form>
  </section>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= icon('alert') ?><?= esc($error) ?></p><?php endif; ?>

<?php if ($role === 'patient'):
    $upcoming = patient_bookings('upcoming', 1);
    $held = patient_bookings('held', 1);
    $family = (int) query('SELECT COUNT(*) FROM family_members WHERE user_id=? AND active=1', [$u['id']])->fetchColumn();
    $tiles = [
        ['patient.php', 'calendar-check', 'نوبت‌های من', $upcoming['total'] ? fa_number($upcoming['total']) . ' نوبت پیش رو' : 'نوبت پیش رو ندارید'],
        ['patient.php?tab=family', 'users', 'اعضای خانواده', $family ? fa_number($family) . ' نفر' : 'افزودن عضو'],
        ['notifications.php', 'bell', 'اعلان‌ها', $unread ? fa_number($unread) . ' خوانده‌نشده' : 'همه خوانده شده'],
        ['patient.php?tab=past', 'clock', 'سوابق نوبت‌ها', 'نوبت‌های گذشته و لغوشده'],
        ['patient.php?tab=waitlist', 'calendar-clock', 'صف انتظار', 'خبر آزاد شدن نوبت'],
        ['support.php', 'chat', 'پشتیبانی', 'تیکت و راه‌های تماس'],
    ];
    ?>
  <a class="acc-cta" href="index.php?view=doctors"><?= icon('search') ?><span><strong>رزرو نوبت جدید</strong><small>جستجوی پزشک، تخصص یا مرکز درمانی</small></span><?= icon('chevron-left') ?></a>

  <?php if ($held['items']): $h = $held['items'][0]; ?>
    <a class="acc-held" href="checkout.php?id=<?= (int) $h['id'] ?>"><?= icon('clock') ?><span>یک رزرو موقت در انتظار تکمیل دارید (<?= esc($h['doctor']) ?>)</span><b>تکمیل</b></a>
  <?php endif; ?>

  <nav class="acc-tiles" aria-label="دسترسی سریع">
    <?php foreach ($tiles as [$url, $ic, $title, $sub]): ?>
      <a href="<?= esc($url) ?>"><span class="at-icon"><?= icon($ic) ?></span><strong><?= esc($title) ?></strong><small><?= esc($sub) ?></small></a>
    <?php endforeach; ?>
  </nav>

  <section class="acc-section">
    <div class="acc-head"><h2>نوبت‌های پیش رو</h2><?php if ($upcoming['total'] > 3): ?><a href="patient.php">همه (<?= fa_number($upcoming['total']) ?>)</a><?php endif; ?></div>
    <?php if ($upcoming['items']): ?>
      <div class="appt-list"><?php foreach (array_slice($upcoming['items'], 0, 3) as $b): ?><?= booking_card($b) ?><?php endforeach; ?></div>
    <?php else: ?>
      <div class="empty-state"><?= icon('calendar') ?><p>نوبت پیش رویی ندارید.</p><a class="button" href="index.php?view=doctors"><?= icon('search') ?> رزرو نوبت</a></div>
    <?php endif; ?>
  </section>
<?php else:
    $clinic = max(0, (int) ($_GET['clinic'] ?? 0));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $clinics = scope_clinics($role, (int) $u['id']);
    try {
        $list = scoped_bookings($role, $page, $clinic);
    } catch (AuthError $e) {
        http_response_code($e->status);
        echo '<p class="alert alert-error">' . icon('alert') . esc($e->getMessage()) . '</p></div>';
        layout_end();
        exit();
    }
    ?>
  <section class="acc-section">
    <div class="acc-head"><h2>مطب‌های در دسترس (<?= esc($labels[$role]) ?>)</h2></div>
    <?php if ($clinics): ?>
      <div class="acc-clinics">
        <?php foreach ($clinics as $cl): ?>
          <a class="acc-clinic<?= (int) $cl['id'] === $clinic ? ' current' : '' ?>" href="panel.php?role=<?= esc($role) ?>&amp;clinic=<?= (int) $cl['id'] ?>">
            <strong><?= esc($cl['name']) ?></strong><small><?= esc($cl['doctor']) ?></small><small><?= esc($cl['address']) ?></small>
            <span class="badge <?= $cl['active'] ? 'status-confirmed' : '' ?>"><?= $cl['active'] ? 'فعال' : 'غیرفعال' ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="notice">مطب مجاز و پزشک تأییدشده‌ای به این حساب متصل نیست. اتصال و دسترسی توسط مدیرکل تعیین می‌شود.</p>
    <?php endif; ?>
  </section>
  <section class="acc-section">
    <div class="acc-head"><h2>نوبت‌های محدوده دسترسی <small>(<?= fa_number($list['total']) ?>)</small></h2><?php if ($clinic): ?><a href="panel.php?role=<?= esc($role) ?>">همه مطب‌ها</a><?php endif; ?></div>
    <?php if ($list['items']): ?>
      <div class="appt-list"><?php foreach ($list['items'] as $b): ?><?= booking_card($b + ['service_name' => ''], true) ?><?php endforeach; ?></div>
    <?php else: ?>
      <div class="empty-state"><?= icon('calendar') ?><p>نوبتی برای نمایش وجود ندارد.</p></div>
    <?php endif; ?>
    <?= pager($list['total'], 30, $page, fn($p) => 'panel.php?role=' . $role . '&clinic=' . $clinic . '&page=' . $p) ?>
  </section>
<?php endif; ?>

  <?php if ($work): ?>
    <section class="acc-section">
      <div class="acc-head"><h2>پنل‌های کاری</h2></div>
      <div class="acc-work">
        <?php foreach ($work as $w): ?>
          <article class="acc-work-card"><span class="at-icon"><?= icon($w['icon']) ?></span><div><strong><?= esc($w['title']) ?></strong><small><?= esc($w['text']) ?></small></div><a class="button small" href="<?= esc($w['url']) ?>"><?= esc($w['cta']) ?></a></article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="acc-section" id="settings">
    <div class="acc-head"><h2>تنظیمات حساب</h2></div>
    <div class="acc-settings">
      <form method="post" class="acc-form">
        <?php form_token('profile'); ?>
        <label class="field"><span>نام و نام خانوادگی</span><input name="name" value="<?= esc((string) $u['name']) ?>" maxlength="190" required autocomplete="name"></label>
        <p class="field-help"><?= icon('lock') ?> شماره موبایل <bdi dir="ltr"><?= esc(fa_number($u['mobile'])) ?></bdi> با کد پیامکی تأیید شده و قابل تغییر نیست.</p>
        <button class="button"><?= icon('check') ?> ذخیره نام</button>
      </form>
      <form method="post" class="acc-form acc-danger" data-confirm="از همه دستگاه‌ها و مرورگرها خارج می‌شوید. ادامه می‌دهید؟">
        <?php form_token('logout_all'); ?>
        <strong>خروج از همه دستگاه‌ها</strong>
        <p class="muted">اگر حساب را روی دستگاه دیگری باز گذاشته‌اید یا گوشی را گم کرده‌اید، همه نشست‌ها را باطل کنید.</p>
        <button class="button secondary"><?= icon('logout') ?> خروج از همه دستگاه‌ها</button>
      </form>
    </div>
  </section>
</div>
<?php
layout_end();
