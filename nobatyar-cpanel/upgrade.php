<?php
declare(strict_types=1);

/**
 * Browser upgrader. Keeps config.php and all data; applies every missing migration in order.
 * Login works even on a 0.1 database (session-based attempt counter, no new tables needed).
 */

require __DIR__ . '/core.php';
require __DIR__ . '/lib/requirements.php';

$error = '';
$ran = null;

// Second half of a one-click update from «به‌روزرسانی سامانه»: this request already runs the new files.
if (isset($_GET['finish']) && is_string($_GET['finish'])) {
    require __DIR__ . '/lib/updater.php';
    try {
        $done = update_finish($_GET['finish']);
    } catch (Throwable $e) {
        $done = null;
        $error = error_message($e);
    }
    layout_start('به‌روزرسانی نوبت‌یار', ['body_class' => 'setup-page', 'robots' => 'noindex,nofollow']);
    ?>
    <section class="setup-card update-done">
      <?php if ($done): ?>
        <span class="success-mark" aria-hidden="true"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-16"/></svg></span>
        <h1>به‌روزرسانی انجام شد</h1>
        <p>نوبت‌یار اکنون نسخه <b><?= esc(APP_VERSION_FA) ?></b> است. <?= fa_number($done['files']) ?> فایل نصب شد<?= $done['ran'] ? ' و مراحل دیتابیس ' . esc(fa_number(implode('، ', $done['ran']))) . ' اجرا شد' : '؛ دیتابیس تغییری لازم نداشت' ?>. همه اطلاعات قبلی حفظ شده است.</p>
        <p class="muted">پشتیبان دیتابیس پیش از به‌روزرسانی: <bdi><?= esc($done['backup']) ?></bdi>. اگر مشکلی دیدید، در همان صفحه «بازگرداندن نسخه قبلی» را بزنید.</p>
        <a class="button" href="admin.php?tab=update">بازگشت به مدیریت</a>
      <?php else: ?>
        <h1>تکمیل به‌روزرسانی</h1>
        <p class="error" role="alert"><?= esc($error) ?></p>
        <p>اگر فایل‌ها نصب شده‌اند، با حساب مالک وارد شوید و ارتقای دیتابیس را از همین صفحه (بدون پارامتر) اجرا کنید.</p>
        <a class="button" href="upgrade.php">ارتقای دیتابیس</a>
      <?php endif; ?>
    </section>
    <?php
    layout_end();
    exit();
}

if (is_post()) {
    verify_csrf();
    try {
        if (!admin()) {
            $_SESSION['upgrade_attempts'] = array_filter(
                $_SESSION['upgrade_attempts'] ?? [],
                fn($t) => $t > time() - 900,
            );
            if (count($_SESSION['upgrade_attempts']) >= 10) {
                throw new RuntimeException('تعداد تلاش‌ها زیاد است؛ ۱۵ دقیقه بعد امتحان کنید.');
            }
            $_SESSION['upgrade_attempts'][] = time();
            $a = query('SELECT * FROM admins WHERE email=?', [trim((string) ($_POST['email'] ?? ''))])->fetch();
            if (!$a || !password_verify((string) ($_POST['password'] ?? ''), $a['password_hash'])) {
                throw new RuntimeException('اطلاعات ورود مدیر اشتباه است.');
            }
            if (isset($a['active']) && (!(int) $a['active'] || $a['role'] !== 'owner')) {
                throw new RuntimeException('ارتقای سامانه فقط با حساب فعال «مالک سامانه» ممکن است.');
            }
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $a['id'];
            $_SESSION['admin_version'] = (int) ($a['session_version'] ?? 1);
            $_SESSION['admin_login_at'] = time();
        } elseif (schema_is_current() && !admin_can('admins')) {
            throw new RuntimeException('ارتقای سامانه فقط با حساب «مالک سامانه» ممکن است.');
        }
        if (!isset($_POST['backup'])) {
            throw new RuntimeException('ابتدا از فایل‌ها و دیتابیس نسخه پشتیبان تهیه کنید.');
        }
        if (!requirements_met()) {
            throw new RuntimeException('پیش‌نیازهای ضروری هاست کامل نیست؛ موارد قرمز فهرست را برطرف کنید.');
        }
        $ran = run_migrations(db());
        $cfg = config();
        if (empty($cfg['cron_token'])) {
            config_add(['cron_token' => bin2hex(random_bytes(24))]);
        }
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}

$current = schema_version(db());
layout_start('ارتقای نوبت‌یار', ['body_class' => 'setup-page']);
?>
<section class="setup-card">
  <h1>ارتقا به نسخه <?= esc(APP_VERSION_FA) ?></h1>
  <?php if ($ran !== null): ?>
    <p class="notice">ارتقا کامل شد. <?= $ran ? 'مراحل اجراشده: ' . esc(fa_number(implode('، ', $ran))) : 'پایگاه داده از قبل به‌روز بود.' ?> اجرای دوباره تغییر تکراری ایجاد نمی‌کند.</p>
    <a class="button" href="admin.php">ورود به مدیریت</a>
    <p>اکنون upgrade.php و install.php را از هاست حذف یا دسترسی آن‌ها را محدود کنید.</p>
  <?php else: ?>
    <p>فایل config.php قبلی باید حفظ شود. قبل از اجرا از فایل‌ها و دیتابیس پشتیبان بگیرید و سایت را موقتاً از دسترس کاربران خارج کنید. شهرها، پزشکان، اشتراک‌ها، پرداخت‌ها و رزروهای قبلی حفظ می‌شوند.</p>
    <p class="muted">نسخه فعلی پایگاه داده: مرحله <?= fa_number($current) ?> • مرحله مقصد: <?= fa_number(SCHEMA_VERSION) ?></p>
    <h2>بررسی هاست</h2>
    <?php render_requirements(); ?>
    <?php if ($error): ?><p class="error" role="alert"><?= esc($error) ?></p><?php endif; ?>
    <form method="post" class="stack narrow">
      <?php form_token('upgrade'); ?>
      <?php if (!admin()): ?>
        <?php input('ایمیل مدیر فعلی', 'email', '', 'email'); ?>
        <?php input('رمز مدیر فعلی', 'password', '', 'password'); ?>
      <?php endif; ?>
      <label class="check"><input type="checkbox" name="backup" required> پشتیبان کامل دارم و سایت را برای ارتقا از دسترس کاربران خارج کرده‌ام.</label>
      <button>اجرای ارتقا به نسخه <?= esc(APP_VERSION_FA) ?></button>
    </form>
  <?php endif; ?>
</section>
<?php
layout_end();
