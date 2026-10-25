<?php
// 1.0.30 — saved doctors, doctor replies to reviews, off-server backup package, admin login limits.
// CLI only, on the disposable test install after the HTTP suites (needs an approved doctor and a patient).
if (PHP_SAPI !== 'cli' || getenv('NOBATYAR_TEST') !== '1') {
    exit("Test opt-in required\n");
}
$c = require __DIR__ . '/../config.php';
if ($c['database'] !== 'nobatyar_test_http') {
    exit("Disposable database required\n");
}
ob_start();
require __DIR__ . '/../core.php';
require_once __DIR__ . '/../lib/admin_panel.php';
ob_end_clean();
$checks = 0;
function t(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo 'PASS ' . $label . "\n";
}
function rejects(callable $fn, string $label, string $contains = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        t($contains === '' || str_contains($e->getMessage(), $contains), $label . ' [' . $e->getMessage() . ']');
        return;
    }
    t(false, $label . ' (no error)');
}
query('DELETE FROM request_limits');
query('DELETE FROM auth_rate_limits');
query("DELETE FROM reviews WHERE comment='برخورد خوب'"); // a previous run of this file
query("DELETE FROM settings WHERE `key` LIKE 'backup_offsite%' OR `key` IN ('secret.backup_zip_password','secret.backup_ftp_password')");

// ---------- Saved doctors ----------
$doctor = (int) query('SELECT d.id FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id WHERE ' . visible_doctor_sql() . ' AND EXISTS(SELECT 1 FROM clinics cl JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE cl.doctor_id=d.id AND ' . public_clinic_sql() . ') ORDER BY d.id LIMIT 1')->fetchColumn();
$hidden = (int) query("SELECT id FROM doctors WHERE review_status<>'approved' OR active=0 ORDER BY id LIMIT 1")->fetchColumn();
$patient = (int) query("SELECT u.id FROM users u WHERE u.active=1 AND NOT EXISTS(SELECT 1 FROM doctor_accounts a WHERE a.user_id=u.id) ORDER BY u.id LIMIT 1")->fetchColumn();
t($doctor > 0 && $patient > 0, 'fixtures: a public doctor and a patient');
$_SESSION = ['user_id' => $patient, 'user_version' => (int) query('SELECT session_version FROM users WHERE id=?', [$patient])->fetchColumn(), 'login_at' => time()];
query('DELETE FROM favorite_doctors WHERE user_id=?', [$patient]);
t(favorite_toggle($doctor) === true && favorite_saved($patient, $doctor), 'heart saves the doctor');
$cards = favorite_cards($patient);
t(count($cards) === 1 && (int) $cards[0]['id'] === $doctor && isset($cards[0]['clinic_id']) && array_key_exists('first_available', $cards[0]), 'saved list returns a doctor card with clinic and first free time');
t(favorite_toggle($doctor) === false && !favorite_saved($patient, $doctor) && favorite_cards($patient) === [], 'second tap removes it');
if ($hidden) {
    rejects(fn() => favorite_toggle($hidden), 'a hidden or unapproved doctor cannot be saved', 'پیدا نشد');
}
rejects(fn() => favorite_toggle(999999999), 'unknown doctor rejected', 'پیدا نشد');
favorite_toggle($doctor);
query("UPDATE doctors SET active=0 WHERE id=?", [$doctor]);
t(favorite_cards($patient) === [], 'a doctor that leaves the public site drops off the saved list');
query("UPDATE doctors SET active=1 WHERE id=?", [$doctor]);

// ---------- Doctor reply to a review ----------
$owner = (int) query('SELECT user_id FROM doctor_accounts WHERE doctor_id=?', [$doctor])->fetchColumn();
if (!$owner) {
    query('INSERT INTO users(mobile,name,active,session_version) VALUES(?,?,1,0)', ['0912' . random_int(1000000, 9999999), 'مالک آزمون']);
    $owner = (int) db()->lastInsertId();
    query('INSERT INTO doctor_accounts(doctor_id,user_id) VALUES(?,?)', [$doctor, $owner]);
}
query("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'doctor')", [$owner]);
$booking = (int) query("SELECT b.id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND NOT EXISTS(SELECT 1 FROM reviews r WHERE r.booking_id=b.id) LIMIT 1", [$doctor])->fetchColumn();
t($booking > 0, 'fixture: a booking of the doctor without a review');
query("INSERT INTO reviews(booking_id,doctor_id,user_id,rating,recommend,comment,status) VALUES(?,?,?,4,1,'برخورد خوب','approved')", [$booking, $doctor, $patient]);
$review = (int) db()->lastInsertId();
rejects(fn() => review_reply_save($doctor, ['review_id' => $review, 'reply' => 'پاسخ']), 'a patient cannot reply as the doctor');
$_SESSION = ['user_id' => $owner, 'user_version' => (int) query('SELECT session_version FROM users WHERE id=?', [$owner])->fetchColumn(), 'login_at' => time()];
review_reply_save($doctor, ['review_id' => $review, 'reply' => "سپاسگزارم\r\nسلامت باشید"]);
$r = query('SELECT reply,reply_status FROM reviews WHERE id=?', [$review])->fetch();
t($r['reply'] === "سپاسگزارم\nسلامت باشید" && $r['reply_status'] === 'pending', 'reply saved and waits for the administrator');
$public = fn() => array_values(array_filter(query("SELECT IF(r.reply_status='approved',r.reply,NULL) reply FROM reviews r WHERE r.id=?", [$review])->fetchAll(), fn($x) => $x['reply'] !== null));
t($public() === [], 'pending reply not public');
review_reply_moderate($review, 'approved');
t(count($public()) === 1, 'approved reply is public');
review_reply_save($doctor, ['review_id' => $review, 'reply' => 'متن ویرایش‌شده']);
t($public() === [], 'editing an approved reply sends it back for review');
review_reply_save($doctor, ['review_id' => $review, 'reply' => '']);
t(query('SELECT reply FROM reviews WHERE id=?', [$review])->fetchColumn() === null, 'empty text removes the reply');
$other = (int) query('SELECT id FROM doctors WHERE id<>? ORDER BY id LIMIT 1', [$doctor])->fetchColumn();
rejects(fn() => review_reply_save($other, ['review_id' => $review, 'reply' => 'x']), "another doctor's panel cannot reply to this review");
rejects(fn() => review_reply_moderate($review, 'approved'), 'moderating a removed reply is rejected', 'پیدا نشد');

// ---------- Off-server backup package ----------
query("INSERT INTO admins(email,password_hash,name,role,active,session_version) VALUES('offsite@example.test',?,'مالک','owner',1,1) ON DUPLICATE KEY UPDATE role='owner',active=1", [password_hash('Test-password-012345', PASSWORD_DEFAULT)]);
$admin = query("SELECT * FROM admins WHERE email='offsite@example.test'")->fetch();
$_SESSION = ['admin_id' => (int) $admin['id'], 'admin_version' => (int) $admin['session_version'], 'admin_login_at' => time()];
rejects(fn() => backup_offsite_save(['mode' => 'email', 'email' => 'a@b.test']), 'a destination needs a backup password first', 'رمز فایل پشتیبان');
rejects(fn() => backup_offsite_save(['mode' => 'email', 'zip_password' => 'short']), 'short backup password rejected', '۱۰');
rejects(fn() => backup_offsite_save(['mode' => 'email', 'zip_password' => 'Long-enough-pass', 'email' => "a@b.test\r\nBcc: x@y.test"]), 'e-mail header injection rejected', 'ایمیل');
rejects(fn() => backup_offsite_save(['mode' => 'ftp', 'zip_password' => 'Long-enough-pass', 'ftp_host' => 'ftp://host', 'ftp_user' => 'u', 'ftp_password' => 'p']), 'FTP host with a scheme rejected');
rejects(fn() => backup_offsite_save(['mode' => 'ftp', 'zip_password' => 'Long-enough-pass', 'ftp_host' => 'backup.example.test', 'ftp_user' => 'u', 'ftp_password' => 'p', 'ftp_dir' => '../etc']), 'FTP folder outside the account rejected');
backup_offsite_save(['mode' => 'ftp', 'zip_password' => 'Long-enough-pass', 'ftp_host' => '127.0.0.1', 'ftp_port' => '1', 'ftp_user' => 'u', 'ftp_password' => 'secret-ftp', 'ftp_tls' => '']);
t(!str_contains((string) setting('secret.backup_zip_password', ''), 'Long-enough-pass') && secret_get('backup_zip_password') === 'Long-enough-pass', 'backup password stored encrypted');
$name = backup_create('manual');
$zipPath = backup_offsite_package($name);
$zip = new ZipArchive();
$zip->open($zipPath);
t($zip->statName($name)['encryption_method'] === ZipArchive::EM_AES_256, 'package entry is AES-256 encrypted');
t(@$zip->getFromName($name) === false, 'unreadable without the password');
$zip->setPassword('Long-enough-pass');
t(gzdecode((string) $zip->getFromName($name)) === gzdecode((string) file_get_contents(backup_path($name))), 'the password opens the exact backup');
$zip->close();
unlink($zipPath);
t(str_contains(backup_offsite_send($name), 'ناموفق') && backup_offsite_settings()['last']['ok'] === false, 'an unreachable server is reported, not hidden');
t(!glob(backup_dir() . '/offsite-*.zip'), 'no temporary package left behind');
backup_offsite_save(['mode' => 'off']);

// ---------- Administrator sign-in limit per account ----------
query('DELETE FROM request_limits');
query('DELETE FROM auth_rate_limits');
$_SESSION = [];
for ($i = 0; $i < 20; $i++) {
    try {
        query('DELETE FROM request_limits'); // a new network address every time
        admin_login('offsite@example.test', 'wrong-password-' . $i);
    } catch (Throwable $e) {
    }
}
query('DELETE FROM request_limits');
rejects(fn() => admin_login('offsite@example.test', 'Test-password-012345'), 'one account is limited across networks after 20 failures', 'تعداد درخواست‌ها زیاد است');
query('DELETE FROM auth_rate_limits');

echo 'SUCCESS ', $checks, " 1.0.30 feature checks\n";
