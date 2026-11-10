<?php
declare(strict_types=1);

use PM\E2ee\KeyService;
use PM\Groups\GroupService;
use PM\Messaging\MessageService;
use PM\Support\B64;
use PM\Support\UserError;

/** A second device of the same user: same master key, own device keys. */
function sameUserDevice(FakeDevice $first): FakeDevice { $d = new FakeDevice(); $d->mskSk = $first->mskSk; $d->mskPk = $first->mskPk; return $d; }
function devPayload(FakeDevice $d, string $user): array {
    $cert = KeyService::certificateString($user, B64::enc($d->ik), B64::enc($d->pk));
    return ['identity_key' => B64::enc($d->ik), 'signing_key' => B64::enc($d->pk), 'certificate_sig' => $d->sign($cert, $d->mskSk),
        'one_time_keys' => $d->prekeys('pm-otk-v1', 2), 'fallback_key' => $d->prekeys('pm-fbk-v1', 1, 100)[0]];
}

test('P6: a device restored from the recovery key joins the SAME identity (no key-change), max 5 devices', function () {
    [$db, $users, , $sessions] = svc();
    [$a, $asid] = e2eUser($db, $users, $sessions, 'alice');
    $keys = new KeyService($db);
    $d1 = new FakeDevice();
    $keys->registerIdentity($a, $asid, $d1->identityPayload($a['public_id']), false, 'x');
    $s2 = $sessions->validate($sessions->create((int)$a['id'], '1.1.1.2', 'y', 30));
    throws(fn () => $keys->addDevice($s2, (int)$s2['session_id'], devPayload(new FakeDevice(), $a['public_id']), 'y'), UserError::class); // other master key
    $id2 = $keys->addDevice($s2, (int)$s2['session_id'], devPayload(sameUserDevice($d1), $a['public_id']), 'y');
    eq(2, count($keys->userKeys($a['public_id'])['devices']));
    eq(1, (int)$db->scalar('SELECT COUNT(*) FROM user_master_keys WHERE user_id = ?', [$a['id']]), 'identity unchanged');
    for ($i = 0; $i < 3; $i++) { $sx = $sessions->validate($sessions->create((int)$a['id'], '1.1.1.3', 'z', 30)); $keys->addDevice($sx, (int)$sx['session_id'], devPayload(sameUserDevice($d1), $a['public_id']), 'z'); }
    $sx = $sessions->validate($sessions->create((int)$a['id'], '1.1.1.4', 'w', 30));
    try { $keys->addDevice($sx, (int)$sx['session_id'], devPayload(sameUserDevice($d1), $a['public_id']), 'w'); ok(false); } catch (UserError $e) { eq('too_many_devices', $e->getMessage()); }
});

test('P6: device link — only the creating session sees the result; approval requires a master-key certificate', function () {
    [$db, $users, , $sessions] = svc();
    [$a, $asid] = e2eUser($db, $users, $sessions, 'alice');
    $keys = new KeyService($db);
    $d1 = new FakeDevice();
    $keys->registerIdentity($a, $asid, $d1->identityPayload($a['public_id']), false, 'x');
    $old = (int)$db->scalar('SELECT device_id FROM user_sessions WHERE id = ?', [$asid]);
    $new = new FakeDevice();
    $s2 = $sessions->validate($sessions->create((int)$a['id'], '2.2.2.2', 'new', 30));
    $link = $keys->linkCreate($s2, (int)$s2['session_id'], ['identity_key' => B64::enc($new->ik), 'signing_key' => B64::enc($new->pk), 'one_time_keys' => $new->prekeys('pm-otk-v1', 1)], 'new');
    $list = $keys->linkList((int)$a['id']);
    eq(1, count($list)); ok($list[0]['otk'] !== null);
    eq('waiting', $keys->linkStatus((int)$a['id'], (int)$s2['session_id'], $link)['status']);
    throws(fn () => $keys->linkStatus((int)$a['id'], $asid, $link), UserError::class); // another session cannot read it
    $cert = KeyService::certificateString($a['public_id'], B64::enc($new->ik), B64::enc($new->pk));
    throws(fn () => $keys->linkApprove($a, $old, $link, (new FakeDevice())->sign($cert, (new FakeDevice())->mskSk), 0, base64_encode('x')), UserError::class);
    $keys->linkApprove($a, $old, $link, $d1->sign($cert, $d1->mskSk), 0, base64_encode('olm-payload'));
    $st = $keys->linkStatus((int)$a['id'], (int)$s2['session_id'], $link);
    eq('approved', $st['status']); eq('olm-payload', base64_decode($st['payload']));
    eq('active', $db->scalar('SELECT status FROM devices WHERE public_id = ?', [$st['device_id']]));
    eq((int)$db->scalar('SELECT id FROM devices WHERE public_id = ?', [$st['device_id']]), (int)$db->scalar('SELECT device_id FROM user_sessions WHERE id = ?', [$s2['session_id']]), 'new session bound');
    throws(fn () => $keys->linkApprove($a, $old, $link, $d1->sign($cert, $d1->mskSk), 0, base64_encode('x')), UserError::class); // single use
    $keys->revokeOwnDevice((int)$a['id'], $old, $st['device_id']);
    eq('revoked', $db->scalar('SELECT status FROM devices WHERE public_id = ?', [$st['device_id']]));
    throws(fn () => $keys->revokeOwnDevice((int)$a['id'], $old, $db->scalar('SELECT public_id FROM devices WHERE id = ?', [$old])), UserError::class); // not the current one
});

test('P6: encrypted key backup is versioned and bound to the current master key', function () {
    [$db, $users, , $sessions] = svc();
    [$a, $asid] = e2eUser($db, $users, $sessions, 'alice');
    $keys = new KeyService($db);
    eq(null, $keys->backupGet((int)$a['id']));
    $keys->registerIdentity($a, $asid, (new FakeDevice())->identityPayload($a['public_id']), false, 'x');
    eq(1, $keys->backupPut((int)$a['id'], 'pm-backup-v1/hkdf-aes256gcm', base64_encode('c1')));
    eq(2, $keys->backupPut((int)$a['id'], 'pm-backup-v1/hkdf-aes256gcm', base64_encode('c2')));
    eq('c2', base64_decode($keys->backupGet((int)$a['id'])['ciphertext']));
    eq(1, (int)$db->scalar('SELECT COUNT(*) FROM key_backups'), 'old versions removed');
    $s2 = $sessions->validate($sessions->create((int)$a['id'], '1.1.1.9', 'q', 30));
    $keys->registerIdentity($s2, (int)$s2['session_id'], (new FakeDevice())->identityPayload($a['public_id'], true), true, 'q');
    eq(null, $keys->backupGet((int)$a['id']), 'backup of a superseded identity is not offered');
});

test('P6: groups — roles, add/remove, owner hand-over, group sends may skip members but not target outsiders', function () {
    [$db, $users, , $sessions] = svc();
    $u = [];
    foreach (['alice', 'bob', 'carol', 'dave'] as $n) { [$s, $sid] = e2eUser($db, $users, $sessions, $n); $u[$n] = [$s, $sid]; }
    $keys = new KeyService($db); $g = new GroupService($db); $m = new MessageService($db);
    $dev = [];
    foreach ($u as $n => [$s, $sid]) { $dev[$n] = $keys->registerIdentity($s, $sid, (new FakeDevice())->identityPayload($s['public_id']), false, 'x'); }
    $aId = (int)$u['alice'][0]['id'];
    $gid = $g->create($aId, [$u['bob'][0]['public_id'], $u['carol'][0]['public_id']]);
    eq(3, count($g->members($aId, $gid)));
    throws(fn () => $g->add((int)$u['bob'][0]['id'], $gid, $u['dave'][0]['public_id']), UserError::class); // members cannot add
    $g->setRole($aId, $gid, $u['bob'][0]['public_id'], 'admin');
    $g->add((int)$u['bob'][0]['id'], $gid, $u['dave'][0]['public_id']); // admins can
    throws(fn () => $g->remove((int)$u['bob'][0]['id'], $gid, $u['alice'][0]['public_id']), UserError::class); // nobody removes the owner
    $g->remove($aId, $gid, $u['carol'][0]['public_id']);
    $names = array_column($g->members($aId, $gid), 'username');
    ok(!in_array('carol', $names, true) && in_array('dave', $names, true));
    throws(fn () => $g->members((int)$u['carol'][0]['id'], $gid), UserError::class); // removed member has no access
    $aDev = (int)$db->scalar('SELECT device_id FROM user_sessions WHERE id = ?', [$u['alice'][1]]);
    $env = fn (array $who) => array_map(fn ($n) => ['device_id' => $dev[$n], 'type' => 0, 'ciphertext' => 'AAAA'], $who);
    $r = $m->send($aId, $aDev, ['conversation_id' => $gid, 'protocol_version' => 1, 'client_msg_id' => str_repeat('C', 21) . 'A', 'envelopes' => $env(['bob'])]);
    ok(!$r['duplicate'], 'sending to a subset of members is allowed in groups');
    try { $m->send($aId, $aDev, ['conversation_id' => $gid, 'protocol_version' => 1, 'client_msg_id' => str_repeat('D', 21) . 'A', 'envelopes' => $env(['bob', 'carol'])]); ok(false); }
    catch (\PM\Messaging\DeviceMismatch $e) { eq([$dev['carol']], $e->stale, 'removed member device is rejected'); }
    $g->remove($aId, $gid, $u['alice'][0]['public_id']); // owner leaves
    eq('owner', $db->scalar("SELECT cm.role FROM conversation_members cm JOIN users x ON x.id = cm.user_id WHERE x.username = 'bob' AND cm.left_at IS NULL"), 'admin takes over');
    throws(fn () => (new \PM\Calls\CallService($db, new \PM\Settings\Settings($db, str_repeat('k', 32))))->start((int)$u['bob'][0]['id'], $gid), UserError::class); // no group calls
});
