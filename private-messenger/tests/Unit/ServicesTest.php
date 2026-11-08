<?php
declare(strict_types=1);

use PM\Admin\AdminService;
use PM\Audit\AuditLog;
use PM\Auth\OtpService;
use PM\Auth\PasswordLogin;
use PM\Auth\RateLimiter;
use PM\Auth\UserSessions;
use PM\Infra\Database;
use PM\Infra\Migrator;
use PM\Infra\Sms\SmsProvider;
use PM\Settings\Settings;
use PM\Support\Clock;
use PM\Support\UserError;
use PM\Users\UserService;

/** Fresh, migrated database per test. */
function tdb(): Database {
    if (!getenv('TEST_DB_NAME')) { throw new SkipTest('TEST_DB_NAME not set'); }
    $db = Database::connect(getenv('TEST_DB_HOST') ?: '127.0.0.1', 3306, getenv('TEST_DB_NAME'), (string)getenv('TEST_DB_USER'), (string)getenv('TEST_DB_PASS'));
    $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($db->all('SHOW TABLES') as $row) { $db->pdo->exec('DROP TABLE `' . array_values($row)[0] . '`'); }
    $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    (new Migrator($db, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
    Clock::freeze(null);
    return $db;
}

final class CaptureSms implements SmsProvider {
    public array $sent = [];
    public function sendOtp(string $phoneE164, string $code, string $message): string { $this->sent[] = [$phoneE164, $code, $message]; return 'x'; }
}

/** @return array{Database, UserService, AuditLog, UserSessions, Settings} */
function svc(): array {
    $db = tdb();
    $audit = new AuditLog($db);
    $sessions = new UserSessions($db);
    return [$db, new UserService($db, $audit, $sessions), $audit, $sessions, new Settings($db, str_repeat('k', 32))];
}

test('DB: user create stores only an Argon2id hash; temp password must be changed', function () {
    [$db, $users] = svc();
    [$id, $temp] = $users->create(1, 'Ali.Rezaei', 'علی رضایی', '09121234567', false, '1.2.3.4');
    $u = $users->get($id);
    eq('ali.rezaei', $u['username']);
    eq('+989121234567', $u['phone_e164']);
    eq('pending', $u['status']);
    eq(1, (int)$u['must_change_password']);
    ok(str_starts_with($u['password_hash'], '$argon2id$'));
    $dump = json_encode($db->all('SELECT * FROM users')) . json_encode($db->all('SELECT * FROM audit_logs'));
    ok(!str_contains($dump, $temp), 'temporary password must never be stored in clear');
    ok(!str_contains(json_encode($db->all('SELECT details FROM audit_logs')), '0912'), 'phone not copied into audit');
    throws(fn () => $users->create(1, 'ali.rezaei', 'x', '', false, ''), UserError::class);
    throws(fn () => $users->create(1, 'other', 'x', '+989121234567', false, ''), UserError::class);
    throws(fn () => $users->create(1, 'a b', 'x', '', false, ''), UserError::class);
});

test('DB: password login — pending is inactive, wrong password generic, lockout after N failures', function () {
    [$db, $users, $audit, , $settings] = svc();
    $settings->set('login.user_fail_lock', 3);
    [$id, $temp] = $users->create(1, 'sara', 'سارا', '', false, '');
    $login = new PasswordLogin($db, $settings, new RateLimiter($db), $audit);
    eq(PasswordLogin::INACTIVE, $login->attempt('sara', $temp, '10.0.0.1')[0]);
    $users->setStatus(1, $id, 'active', '');
    eq(PasswordLogin::OK, $login->attempt('SARA ', $temp, '10.0.0.1')[0], 'username case/space-insensitive');
    eq(PasswordLogin::INVALID, $login->attempt('nobody', 'x', '10.0.0.1')[0]);
    for ($i = 0; $i < 3; $i++) { eq(PasswordLogin::INVALID, $login->attempt('sara', 'wrong-password', '10.0.0.2')[0]); }
    eq(PasswordLogin::INVALID, $login->attempt('sara', $temp, '10.0.0.3')[0], 'locked: even the right password fails');
    ok((int)$db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.login_locked'") === 1);
    $users->unlock(1, $id, '');
    eq(PasswordLogin::OK, $login->attempt('sara', $temp, '10.0.0.3')[0]);
});

test('DB: per-IP login rate limit', function () {
    [$db, $users, $audit, , $settings] = svc();
    $settings->set('login.per_ip_15min', 5);
    $login = new PasswordLogin($db, $settings, new RateLimiter($db), $audit);
    for ($i = 0; $i < 5; $i++) { eq(PasswordLogin::INVALID, $login->attempt("u$i", 'x', '9.9.9.9')[0]); }
    eq(PasswordLogin::RATE_LIMITED, $login->attempt('u', 'x', '9.9.9.9')[0]);
    eq(PasswordLogin::INVALID, $login->attempt('u', 'x', '9.9.9.8')[0], 'other IPs unaffected');
});

test('DB: suspending a user revokes all sessions; deleted frees username', function () {
    [$db, $users, , $sessions] = svc();
    [$id] = $users->create(1, 'reza', 'رضا', '', true, '');
    $t1 = $sessions->create($id, '1.1.1.1', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130', 30);
    $t2 = $sessions->create($id, '1.1.1.2', 'Mozilla/5.0 (iPhone) Safari/605', 30);
    ok($sessions->validate($t1) !== null && $sessions->validate($t2) !== null);
    ok($sessions->validate($t1 . 'x') === null);
    eq(64, strlen(bin2hex((string)$db->scalar('SELECT token_hash FROM user_sessions LIMIT 1'))), 'only hash stored');
    ok(!str_contains(json_encode(array_map('bin2hex', array_column($db->all('SELECT token_hash FROM user_sessions'), 'token_hash'))), $t1));
    $users->setStatus(1, $id, 'suspended', '');
    ok($sessions->validate($t1) === null && $sessions->validate($t2) === null, 'sessions revoked on suspend');
    $users->setStatus(1, $id, 'deleted', '');
    [$id2] = $users->create(1, 'reza', 'رضا ۲', '', true, '');
    ok($id2 !== $id);
});

test('DB: change own password enforces policy and revokes other sessions', function () {
    [$db, $users, , $sessions] = svc();
    [$id, $temp] = $users->create(1, 'mina', 'مینا', '', true, '');
    $keep = $sessions->create($id, '1.1.1.1', 'x', 30);
    $other = $sessions->create($id, '1.1.1.2', 'y', 30);
    $u = $sessions->validate($keep);
    throws(fn () => $users->changeOwnPassword($u, 'bad', 'new password ok', 'new password ok', (int)$u['session_id']), UserError::class);
    throws(fn () => $users->changeOwnPassword($u, $temp, 'short', 'short', (int)$u['session_id']), UserError::class);
    throws(fn () => $users->changeOwnPassword($u, $temp, 'new password ok', 'different', (int)$u['session_id']), UserError::class);
    $users->changeOwnPassword($u, $temp, 'new password ok', 'new password ok', (int)$u['session_id']);
    eq(0, (int)$users->get($id)['must_change_password']);
    ok($sessions->validate($keep) !== null, 'current session kept');
    ok($sessions->validate($other) === null, 'other session revoked');
});

test('DB: OTP — known number gets SMS; unknown gets identical response, no SMS; attempts limited', function () {
    [$db, $users, , , $settings] = svc();
    [$id] = $users->create(1, 'nima', 'نیما', '09120000001', true, '');
    $sms = new CaptureSms();
    $otp = new OtpService($db, $settings, new RateLimiter($db), str_repeat('k', 32), $sms);
    $c1 = $otp->start('+989120000001', $id, 'login', '5.5.5.5');
    $c2 = $otp->start('+989120000099', null, 'login', '5.5.5.5');
    eq(1, count($sms->sent), 'SMS only for the known number');
    eq(strlen($c1), strlen($c2));
    ok($otp->pending($c2) !== null, 'unknown number still has an indistinguishable pending challenge');
    eq(null, $otp->verify($c2, '000000'));
    [$phone, $code, $msg] = $sms->sent[0];
    ok(!preg_match('/پیام‌رسان|messenger/iu', $msg), 'SMS text carries no app name');
    ok(!str_contains(implode(',', array_map('bin2hex', array_column($db->all('SELECT code_hash FROM otp_requests'), 'code_hash'))), bin2hex($code)));
    $wrong = $code === '111111' ? '222222' : '111111';
    eq(null, $otp->verify($c1, $wrong));
    eq($id, $otp->verify($c1, $code));
    eq(null, $otp->verify($c1, $code), 'single use');
    // attempts exhaustion
    Clock::freeze(Clock::now()->modify('+2 minutes'));
    $c3 = $otp->start('+989120000001', $id, 'login', '5.5.5.6');
    $code3 = $sms->sent[1][1];
    for ($i = 0; $i < 5; $i++) { eq(null, $otp->verify($c3, $code3 === '123456' ? '654321' : '123456')); }
    eq(null, $otp->verify($c3, $code3), 'locked after max attempts');
    // expiry
    Clock::freeze(Clock::now()->modify('+2 minutes'));
    $c4 = $otp->start('+989120000001', $id, 'login', '5.5.5.7');
    Clock::freeze(Clock::now()->modify('+3 minutes'));
    eq(null, $otp->verify($c4, $sms->sent[2][1]), 'expired');
    Clock::freeze(null);
});

test('DB: OTP resend cooldown and missing provider', function () {
    [$db, $users, , , $settings] = svc();
    [$id] = $users->create(1, 'leila', 'لیلا', '09120000002', true, '');
    $otp = new OtpService($db, $settings, new RateLimiter($db), str_repeat('k', 32), new CaptureSms());
    $otp->start('+989120000002', $id, 'login', '1.1.1.1');
    throws(fn () => $otp->start('+989120000002', $id, 'login', '1.1.1.1'), UserError::class);
    $none = new OtpService($db, $settings, new RateLimiter($db), str_repeat('k', 32), null);
    throws(fn () => $none->start('+989120000003', null, 'login', '1.1.1.9'), UserError::class); // unknown number: same error
});

test('DB: audit chain verifies and detects tampering', function () {
    [$db, , $audit] = svc();
    $audit->record('admin', 1, 'user.create', 'user', 5, ['b' => 2, 'a' => 'ب'], '1.2.3.4');
    $audit->record('admin', 1, 'user.status', 'user', 5, ['from' => 'pending', 'to' => 'active']);
    $audit->record('system', null, 'user.login_locked', 'user', 5);
    $v = $audit->verify();
    ok($v['ok']);
    eq(3, $v['checked']);
    $db->run("UPDATE audit_logs SET action = 'user.update' WHERE id = 2");
    $v = $audit->verify();
    ok(!$v['ok']);
    eq(2, $v['broken_at']);
});

test('DB: permanent retention — enable keeps live files, revoke gives a fresh 7-day window', function () {
    [$db, $users] = svc();
    [$id] = $users->create(1, 'farid', 'فرید', '', true, '');
    $ins = fn (string $exp) => $db->insert("INSERT INTO attachments (public_id, storage_key, uploader_user_id, uploader_device_id, size_bytes, chunk_size, chunk_count, upload_status, created_at, uploaded_at, expires_at)
        VALUES (?, ?, ?, 1, 10, 10, 1, 'complete', NOW(3), NOW(3), ?)", [bin2hex(random_bytes(16)), bin2hex(random_bytes(32)), $id, $exp]);
    $live = $ins(Clock::dbIn('+3 days'));
    $dead = $ins(Clock::dbIn('-1 day'));
    $users->setPermanentRetention(1, $id, true, '');
    $a = $db->one('SELECT retention_permanent, expires_at FROM attachments WHERE id = ?', [$live]);
    eq(1, (int)$a['retention_permanent']);
    eq(null, $a['expires_at']);
    eq(0, (int)$db->scalar('SELECT retention_permanent FROM attachments WHERE id = ?', [$dead]), 'expired file not revived');
    $users->setPermanentRetention(1, $id, false, '');
    $exp = strtotime($db->scalar('SELECT expires_at FROM attachments WHERE id = ?', [$live]) . ' UTC');
    ok(abs($exp - (time() + 7 * 86400)) < 120, '7 days from revocation');
    eq(2, (int)$db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'user.retention.%'"));
});

test('DB: admin roles — super-only permissions never leak to other roles; last super cannot be disabled', function () {
    $db = tdb();
    $audit = new AuditLog($db);
    $svc = new AdminService($db, $audit, new RateLimiter($db), str_repeat('k', 32));
    $roles = array_column($svc->roles(), 'id', 'name');
    $super = $svc->createAdmin(0, 'root', 'root', 'correct horse battery', (int)$roles['مدیرکل'], '');
    $mgr = $svc->createAdmin($super, 'manager', 'm', 'correct horse battery', (int)$roles['مدیر کاربران'], '');
    // Even if someone grants a super-only permission to a normal role in the DB, it is ignored.
    $db->run("INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = 'users.retention.manage'", [$roles['مدیر کاربران']]);
    $m = $db->one('SELECT a.*, r.is_super FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [$mgr]);
    $perms = $svc->permissionsFor($m);
    ok(in_array('users.create', $perms, true));
    ok(!in_array('users.retention.manage', $perms, true));
    ok(!in_array('admins.manage', $perms, true));
    foreach ($perms as $p) { ok(!preg_match('/message|content|read/i', $p), "no content permission: $p"); }
    throws(fn () => $svc->setAdminStatus($mgr, $super, 'disabled', ''), UserError::class);
    throws(fn () => $svc->setAdminStatus($super, $super, 'disabled', ''), UserError::class);
    eq('ok', $svc->attemptLogin('root', 'correct horse battery', '', '1.1.1.1')[0]);
    eq('invalid', $svc->attemptLogin('root', 'nope', '', '1.1.1.1')[0]);
});

test('DB: admin TOTP enrolment, login requires code, replay rejected', function () {
    $db = tdb();
    $svc = new AdminService($db, new AuditLog($db), new RateLimiter($db), str_repeat('k', 32));
    $id = $svc->createAdmin(0, 'root', 'root', 'correct horse battery', 1, '');
    $a = ['id' => $id, 'session_id' => 0];
    $secret = $svc->startTotp($a);
    ok(!str_contains(json_encode(array_map('bin2hex', $db->one('SELECT totp_secret_enc FROM admin_users WHERE id = ?', [$id]))), $secret));
    $now = time();
    throws(fn () => $svc->confirmTotp($a, '000000', ''), UserError::class);
    $svc->confirmTotp($a, \PM\Support\Totp::code($secret, intdiv($now, 30) - 1), '');
    eq('totp', $svc->attemptLogin('root', 'correct horse battery', '', '2.2.2.2')[0]);
    $code = \PM\Support\Totp::code($secret, intdiv($now, 30));
    eq('ok', $svc->attemptLogin('root', 'correct horse battery', $code, '2.2.2.2')[0]);
    eq('invalid', $svc->attemptLogin('root', 'correct horse battery', $code, '2.2.2.2')[0], 'replayed code');
});
