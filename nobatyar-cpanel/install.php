<?php
declare(strict_types=1);

/**
 * Web installer for cPanel. Works only while config.php does not exist and only on an empty database.
 * Fresh installs run the same migration list as upgrades, so both end with identical schemas.
 */

require __DIR__ . '/core.php';
require __DIR__ . '/lib/requirements.php';

if (is_file(__DIR__ . '/config.php')) {
    http_response_code(403);
    exit('سامانه قبلاً نصب شده است. برای نسخه قبلی از upgrade.php استفاده کنید.');
}

$error = '';
if (is_post()) {
    verify_csrf();
    $lock = null;
    try {
        if (!requirements_met()) {
            throw new RuntimeException('پیش‌نیازهای ضروری هاست کامل نیست؛ موارد قرمز فهرست را برطرف کنید.');
        }
        $lock = fopen(__DIR__ . '/install.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('نصب دیگری در حال اجراست.');
        }
        if (is_file(__DIR__ . '/config.php')) {
            throw new RuntimeException('نصب قبلاً انجام شده است.');
        }
        $host = trim((string) ($_POST['host'] ?? 'localhost'));
        $database = trim((string) ($_POST['database'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $email = trim((string) ($_POST['email'] ?? ''));
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        // rc.13: the owner's mobile (optional here) — used for the SMS step of the administrator sign-in.
        $adminMobile = strtr(preg_replace('/[\s\-]/u', '', (string) ($_POST['admin_mobile'] ?? '')), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if ($adminMobile !== '' && !preg_match('/^09[0-9]{9}$/', $adminMobile)) {
            throw new RuntimeException('شماره موبایل مدیرکل را ۱۱ رقمی با ۰۹ وارد کنید (یا خالی بگذارید).');
        }
        if (
            !preg_match('/^[a-zA-Z0-9_.-]+$/', $host) ||
            !preg_match('/^[a-zA-Z0-9_]+$/', $database) ||
            !preg_match('/^[a-zA-Z0-9_]+$/', $username)
        ) {
            throw new RuntimeException('میزبان، دیتابیس یا نام کاربری نامعتبر است.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($adminPassword) < 12) {
            throw new RuntimeException('ایمیل معتبر و رمز مدیر با حداقل ۱۲ کاراکتر وارد کنید.');
        }
        if (!is_writable(__DIR__)) {
            throw new RuntimeException('پوشه نصب برای نوشتن config.php دسترسی ندارد.');
        }
        $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET time_zone='+03:30'");
        $tables = (int) $pdo
            ->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')
            ->fetchColumn();
        if ($tables > 0) {
            throw new RuntimeException(
                'نصب جدید فقط روی دیتابیس خالی مجاز است. برای نسخه قبلی ارتقا را اجرا کنید. اگر نصب ناموفق بوده، از دیتابیس خالی جدید استفاده کنید.',
            );
        }
        ensure_app_key();
        install_schema($pdo);
        $pdo->prepare('INSERT INTO admins(email,password_hash,mobile) VALUES(?,?,?)')->execute([
            $email,
            password_hash($adminPassword, PASSWORD_DEFAULT),
            $adminMobile !== '' ? $adminMobile : null,
        ]);
        $config = [
            'host' => $host,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'admin_email' => $email,
            'environment' => 'production',
            'cron_token' => bin2hex(random_bytes(24)),
        ];
        $temp = __DIR__ . '/config.php.tmp';
        if (file_put_contents($temp, '<?php return ' . var_export($config, true) . ';', LOCK_EX) === false) {
            throw new RuntimeException('نوشتن تنظیمات ممکن نیست.');
        }
        chmod($temp, 0600);
        if (!rename($temp, __DIR__ . '/config.php')) {
            throw new RuntimeException('ذخیره تنظیمات ممکن نیست.');
        }
        redirect('admin.php?installed=1');
    } catch (Throwable $e) {
        $error = error_message($e);
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

layout_start('نصب نوبت‌یار', ['body_class' => 'auth-page']);
?>
<section class="setup-card">
  <h1>نصب نوبت‌یار — نسخه <?= esc(APP_VERSION_FA) ?></h1>
  <p>در cPanel یک دیتابیس خالی بسازید و کاربر آن را با ALL PRIVILEGES متصل کنید. MySQL 5.7+ یا MariaDB 10.4+ لازم است.</p>
  <h2>بررسی هاست</h2>
  <?php render_requirements(); ?>
  <?php if ($error): ?><p class="error" role="alert"><?= esc($error) ?></p><?php endif; ?>
  <form method="post" class="stack">
    <?php form_token('install'); ?>
    <div class="form-grid">
      <?php input('میزبان دیتابیس', 'host', $_POST['host'] ?? 'localhost'); ?>
      <?php input('نام دیتابیس', 'database', $_POST['database'] ?? ''); ?>
      <?php input('نام کاربر دیتابیس', 'username', $_POST['username'] ?? ''); ?>
      <?php input('رمز دیتابیس', 'password', '', 'password', false, 255); ?>
    </div>
    <div class="form-grid">
      <?php input('ایمیل مدیرکل', 'email', $_POST['email'] ?? '', 'email'); ?>
      <?php input('رمز مدیرکل (حداقل ۱۲ کاراکتر)', 'admin_password', '', 'password', true, 255); ?>
      <label>شماره موبایل مدیرکل (اختیاری؛ برای ورود با کد پیامکی)<input class="mobile-input" name="admin_mobile" type="tel" inputmode="numeric" maxlength="13" dir="ltr" placeholder="۰۹۱۲۳۴۵۶۷۸۹" value="<?= esc($_POST['admin_mobile'] ?? '') ?>"></label>
    </div>
    <button>نصب سامانه</button>
  </form>
  <p class="muted">پس از نصب، فایل‌های install.php و upgrade.php را حذف یا دسترسی آن‌ها را محدود کنید و Cron Job را طبق راهنما تنظیم کنید.</p>
</section>
<?php
layout_end();
