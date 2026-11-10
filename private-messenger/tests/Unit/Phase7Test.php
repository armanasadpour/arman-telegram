<?php
declare(strict_types=1);

use PM\Admin\AdminService;
use PM\Admin\Backup;
use PM\Admin\Stats;
use PM\Audit\AuditLog;
use PM\Auth\RateLimiter;
use PM\Infra\Database;
use PM\Support\UserError;

test('P7: roles — custom role, super-only permissions refused, super role immutable, last super kept', function () {
    $db = tdb();
    $svc = new AdminService($db, new AuditLog($db), new RateLimiter($db), str_repeat('k', 32));
    $roles = array_column($svc->roles(), 'id', 'name');
    $super = $svc->createAdmin(0, 'root', 'root', 'correct horse battery', (int)$roles['مدیرکل'], '');
    $rid = $svc->saveRole($super, null, 'گزارشگر', ['reports.view', 'audit.view', 'backup.download', 'admins.manage', 'bogus'], '');
    $r = array_values(array_filter($svc->rolesDetailed(), fn ($x) => (int)$x['id'] === $rid))[0];
    sort($r['permissions']);
    eq(['audit.view', 'reports.view'], $r['permissions'], 'super-only and unknown codes dropped');
    throws(fn () => $svc->saveRole($super, (int)$roles['مدیرکل'], 'x y', [], ''), UserError::class);
    throws(fn () => $svc->saveRole($super, null, 'گزارشگر', [], ''), UserError::class); // duplicate name
    $rep = $svc->createAdmin($super, 'rep', 'r', 'correct horse battery', $rid, '');
    throws(fn () => $svc->deleteRole($super, $rid, ''), UserError::class); // still used
    $svc->setAdminRole($super, $rep, (int)$roles['ناظر'], '');
    $svc->deleteRole($super, $rid, '');
    eq(0, (int)$db->scalar('SELECT COUNT(*) FROM roles WHERE id = ?', [$rid]));
    $other = $svc->createAdmin($super, 'root2', 'r', 'correct horse battery', (int)$roles['مدیرکل'], '');
    $svc->setAdminRole($other, $super, (int)$roles['ناظر'], ''); // another super exists → allowed
    throws(fn () => $svc->setAdminRole($super, $other, (int)$roles['ناظر'], ''), UserError::class); // last active super
    throws(fn () => $svc->setAdminRole($other, $other, (int)$roles['ناظر'], ''), UserError::class); // not yourself
    ok((int)$db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action IN ('role.save','role.delete','admin.role')") >= 4);
    ok((new AuditLog($db))->verify()['ok']);
});

test('P7: stats count metadata per day and never touch ciphertext', function () {
    [$db, $users] = svc();
    [$a] = $users->create(1, 'alice', 'A', '', true, '');
    [$b] = $users->create(1, 'bob', 'B', '', true, '');
    $db->run("INSERT INTO conversations (public_id, type, created_by, created_at) VALUES ('c1', 'direct', ?, NOW(3))", [$a]);
    $cid = (int)$db->scalar("SELECT id FROM conversations WHERE public_id = 'c1'");
    for ($i = 0; $i < 3; $i++) {
        $db->run('INSERT INTO messages (conversation_id, sender_user_id, sender_device_id, client_msg_id, envelope_count, created_at) VALUES (?, ?, 1, ?, 1, UTC_TIMESTAMP(3))', [$cid, $i ? $a : $b, random_bytes(16)]);
    }
    Stats::bump($db, 'login.failed'); Stats::bump($db, 'login.failed', 2);
    $s = new Stats($db);
    $today = $s->daily(7)[0];
    eq(gmdate('Y-m-d'), $today['day']);
    eq(3, $today['messages']);
    eq(2, $today['senders']);
    eq(3, $today['login_failed']);
    eq(2, $today['new_users']);
    eq(7, count($s->daily(7)));
    $o = $s->overview();
    eq(3, $o['messages']['d1']);
    eq(2, $o['users_total']);
    eq(2, $s->forUser($a)['messages']);
});

test('P7: PHP error log counter only counts recent lines', function () {
    $f = tempnam(sys_get_temp_dir(), 'pmlog');
    file_put_contents($f, '[' . gmdate('d-M-Y H:i:s', time() - 3 * 86400) . " UTC] PHP Warning: old\n"
        . '[' . gmdate('d-M-Y H:i:s', time() - 60) . " UTC] PHP Warning: new\n  continuation line\n"
        . '[' . gmdate('d-M-Y H:i:s', time() - 30) . " UTC] PHP Fatal error: new2\n");
    eq(2, (new Stats(tdb()))->recentErrors($f, 86400));
    unlink($f);
});

test('P7: backup is a complete, restorable SQL script (binary-safe)', function () {
    [$db, $users] = svc();
    [$a] = $users->create(1, 'alice', 'آلیس', '', true, '');
    $bin = random_bytes(64) . "\0'\\\";";
    $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $db->run('INSERT INTO key_backups (user_id, master_key_id, version, algorithm, ciphertext, created_at) VALUES (?, 1, 1, ?, ?, NOW(3))', [$a, 'x', $bin]);
    $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    (new AuditLog($db))->record('admin', 1, 'test.entry', null, null, ['k' => 'v'], '1.2.3.4');
    foreach ([false, true] as $gzip) {
        $buf = '';
        (new Backup($db, dirname(__DIR__, 2) . '/database/migrations'))->write(function (string $s) use (&$buf): void { $buf .= $s; }, $gzip);
        $sql = $gzip ? gzdecode($buf) : $buf;
        ok(is_string($sql) && str_ends_with($sql, "-- end of backup\n"), 'complete');
        ok(!str_contains($sql, 'INSERT INTO `rate_limits`'), 'volatile rows skipped');
        // Restore into a scratch database and compare.
        $name = getenv('TEST_DB_NAME') . '_restore';
        $db->pdo->exec("DROP DATABASE IF EXISTS `$name`");
        $db->pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
        $r = Database::connect(getenv('TEST_DB_HOST') ?: '127.0.0.1', 3306, $name, (string)getenv('TEST_DB_USER'), (string)getenv('TEST_DB_PASS'));
        foreach (\PM\Infra\Migrator::split($sql) as $st) { $r->pdo->exec($st); }
        eq($bin, $r->scalar('SELECT ciphertext FROM key_backups WHERE user_id = ?', [$a]), 'binary round-trip');
        eq('آلیس', $r->scalar('SELECT display_name FROM users WHERE id = ?', [$a]));
        ok((new AuditLog($r))->verify()['ok'], 'audit chain still verifies after restore');
        eq((int)$db->scalar('SELECT COUNT(*) FROM roles'), (int)$r->scalar('SELECT COUNT(*) FROM roles'));
        $db->pdo->exec("DROP DATABASE `$name`");
    }
});
