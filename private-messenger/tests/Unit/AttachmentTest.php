<?php
declare(strict_types=1);

use PM\Files\AttachmentService;
use PM\Messaging\MessageService;
use PM\E2ee\KeyService;
use PM\Support\UserError;

function attEnv(): array {
    [$db, $users, , $sessions, $settings] = svc();
    $root = sys_get_temp_dir() . '/pmatt-' . bin2hex(random_bytes(4));
    mkdir("$root/attachments", 0777, true); mkdir("$root/tmp");
    [$a, $asid] = e2eUser($db, $users, $sessions, 'alice');
    [$b, $bsid] = e2eUser($db, $users, $sessions, 'bob');
    [$c] = e2eUser($db, $users, $sessions, 'carol');
    $keys = new KeyService($db);
    $keys->registerIdentity($a, $asid, (new FakeDevice())->identityPayload($a['public_id']), false, 'x');
    $bDev = $keys->registerIdentity($b, $bsid, (new FakeDevice())->identityPayload($b['public_id']), false, 'x');
    $aDevId = (int)$db->scalar('SELECT device_id FROM user_sessions WHERE id = ?', [$asid]);
    $cid = (new MessageService($db))->openDirect((int)$a['id'], $b['public_id']);
    return [$db, new AttachmentService($db, $settings, "$root/attachments"), $a, $b, $c, $aDevId, $bDev, $cid, $settings, $users];
}
function stream(string $s) { $h = fopen('php://memory', 'w+b'); fwrite($h, $s); rewind($h); return $h; }

test('Files: chunk size is a whole number of encrypted segments within the PHP limit', function () {
    $c = AttachmentService::chunkSize();
    eq(0, $c % AttachmentService::SEGMENT_CIPHER);
    ok($c >= AttachmentService::SEGMENT_CIPHER && $c <= 8 * 1048576);
});

test('Files: chunked upload with integrity checks, idempotent retry, resume info, completion', function () {
    [$db, $f, $a, , , $aDev, , $cid] = attEnv();
    $chunk = AttachmentService::chunkSize();
    $data = random_bytes($chunk * 2 + 1234);
    $u = $f->init((int)$a['id'], $aDev, $cid, strlen($data));
    eq(3, $u['chunk_count']);
    $parts = str_split($data, $chunk);
    throws(fn () => $f->putChunk((int)$a['id'], $u['id'], 0, hash('sha256', 'x'), stream($parts[0])), UserError::class); // hash mismatch
    throws(fn () => $f->putChunk((int)$a['id'], $u['id'], 2, hash('sha256', $parts[1]), stream($parts[1])), UserError::class); // wrong size for last chunk
    throws(fn () => $f->putChunk((int)$a['id'], $u['id'], 3, hash('sha256', $parts[0]), stream($parts[0])), UserError::class); // out of range
    $f->putChunk((int)$a['id'], $u['id'], 2, hash('sha256', $parts[2]), stream($parts[2]));
    $f->putChunk((int)$a['id'], $u['id'], 0, hash('sha256', $parts[0]), stream($parts[0]));
    $f->putChunk((int)$a['id'], $u['id'], 0, hash('sha256', $parts[0]), stream($parts[0])); // retry is fine
    eq([0, 2], $f->received((int)$a['id'], $u['id']));
    throws(fn () => $f->complete((int)$a['id'], $u['id']), UserError::class); // chunk 1 missing
    $f->putChunk((int)$a['id'], $u['id'], 1, hash('sha256', $parts[1]), stream($parts[1]));
    $r = $f->complete((int)$a['id'], $u['id']);
    ok($r['expires_at'] !== null && abs(strtotime($r['expires_at'] . ' UTC') - (time() + 7 * 86400)) < 120, '7-day expiry');
    $row = $f->downloadable((int)$a['id'], $u['id']);
    eq($data, file_get_contents($f->filePath($row)), 'file reassembled byte-exact');
    throws(fn () => $f->putChunk((int)$a['id'], $u['id'], 0, hash('sha256', $parts[0]), stream($parts[0])), UserError::class); // closed
});

test('Files: only members of a conversation the file was sent to can download; forwarding needs access', function () {
    [$db, $f, $a, $b, $c, $aDev, $bDev, $cid] = attEnv();
    $u = $f->init((int)$a['id'], $aDev, $cid, 100);
    $f->putChunk((int)$a['id'], $u['id'], 0, hash('sha256', $d = random_bytes(100)), stream($d));
    $f->complete((int)$a['id'], $u['id']);
    eq(null, $f->downloadable((int)$b['id'], $u['id']), 'not yet sent');
    (new MessageService($db))->send((int)$a['id'], $aDev, ['conversation_id' => $cid, 'protocol_version' => 1, 'client_msg_id' => str_repeat('A', 21) . 'A',
        'attachment_ids' => [$u['id']], 'envelopes' => [['device_id' => $bDev, 'type' => 0, 'ciphertext' => 'AAAA']]], $f);
    ok($f->downloadable((int)$b['id'], $u['id']) !== null, 'recipient can download');
    eq(null, $f->downloadable((int)$c['id'], $u['id']), 'outsider cannot');
    throws(fn () => $f->grant((int)$c['id'], 1, 1, [$u['id']]), UserError::class); // outsider cannot forward it
});

test('Files: expiry blocks download immediately, cleanup deletes the ciphertext; permanent retention is kept', function () {
    [$db, $f, $a, $b, , $aDev, , $cid, , $users] = attEnv();
    $up = function () use ($f, $a, $aDev, $cid) { $u = $f->init((int)$a['id'], $aDev, $cid, 50); $f->putChunk((int)$a['id'], $u['id'], 0, hash('sha256', $d = random_bytes(50)), stream($d)); $f->complete((int)$a['id'], $u['id']); return $u['id']; };
    $normal = $up();
    $path = $f->filePath($f->downloadable((int)$a['id'], $normal));
    $db->run("UPDATE attachments SET expires_at = NOW(3) - INTERVAL 1 SECOND WHERE public_id = ?", [$normal]);
    $users->setPermanentRetention(1, (int)$a['id'], true, ''); // must not revive the already-expired file
    $perm = $up();
    eq(null, $db->scalar('SELECT expires_at FROM attachments WHERE public_id = ?', [$perm]), 'permanent: no expiry');
    eq(null, $f->downloadable((int)$a['id'], $normal), 'expired → not downloadable even before cleanup');
    ok($f->isExpired($normal));
    ok(is_file($path), 'still on disk until cleanup runs');
    $st = $f->cleanup();
    eq(1, $st['files_deleted']);
    ok(!is_file($path), 'ciphertext deleted from disk');
    eq('deleted', $db->scalar('SELECT upload_status FROM attachments WHERE public_id = ?', [$normal]));
    ok($f->downloadable((int)$a['id'], $perm) !== null, 'permanent file kept');
});

test('Files: stale unfinished uploads are removed; optional admin size cap', function () {
    [$db, $f, $a, , , $aDev, , $cid, $settings] = attEnv();
    $u = $f->init((int)$a['id'], $aDev, $cid, AttachmentService::chunkSize() + 10);
    $f->putChunk((int)$a['id'], $u['id'], 1, hash('sha256', $d = random_bytes(10)), stream($d));
    $db->run('UPDATE attachments SET last_chunk_at = NOW(3) - INTERVAL 3 DAY WHERE public_id = ?', [$u['id']]);
    eq(1, $f->cleanup()['stale_uploads_deleted']);
    eq(0, (int)$db->scalar('SELECT COUNT(*) FROM upload_chunks'));
    $settings->set('files.max_bytes', 1000);
    throws(fn () => $f->init((int)$a['id'], $aDev, $cid, 1001), UserError::class);
    $settings->set('files.max_bytes', 0);
    ok(is_array($f->init((int)$a['id'], $aDev, $cid, 50 * 1048576)), 'no software cap by default');
});

test('Quota: per-user file quota — default, personal override, unlimited; expired files do not count', function () {
    [$db, $files, $a, , , $aDev, , $cid, $settings] = attEnv();
    $uid = (int)$a['id'];
    eq(null, $files->userQuota($uid), 'no limit by default');
    $settings->set('storage.user_quota_bytes', 1000);
    eq(1000, $files->userQuota($uid), 'system default applies');
    $up = $files->init($uid, $aDev, $cid, 600);
    eq(600, $files->userUsage($uid), 'unfinished uploads count');
    throws(fn () => $files->init($uid, $aDev, $cid, 500), \PM\Support\UserError::class);
    try { $files->init($uid, $aDev, $cid, 500); } catch (\PM\Support\UserError $e) { eq('user_quota_exceeded', $e->getMessage()); }
    $db->run('UPDATE users SET storage_quota_bytes = 5000 WHERE id = ?', [$uid]);
    eq(5000, $files->userQuota($uid), 'personal quota overrides the default');
    $files->init($uid, $aDev, $cid, 500);
    $db->run('UPDATE users SET storage_quota_bytes = 0 WHERE id = ?', [$uid]);
    eq(null, $files->userQuota($uid), 'personal 0 = unlimited even with a default');
    $db->run("UPDATE attachments SET upload_status = 'complete', expires_at = ? WHERE public_id = ?", [gmdate('Y-m-d H:i:s', time() - 60), $up['id']]);
    eq(500, $files->userUsage($uid), 'an expired file stops counting at once');
    $st = $files->userStorage($uid);
    ok($st['personal'] && $st['quota'] === null && $st['used'] === 500);
});
