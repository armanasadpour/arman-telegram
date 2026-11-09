<?php
declare(strict_types=1);

use PM\E2ee\KeyService;
use PM\Messaging\DeviceMismatch;
use PM\Messaging\MessageService;
use PM\Support\B64;
use PM\Support\UserError;

/** Simulated client device: sodium Ed25519 keys (identical format to vodozemac's). */
final class FakeDevice
{
    public string $mskSk; public string $mskPk; public string $sk; public string $pk; public string $ik;
    public function __construct() {
        $k = sodium_crypto_sign_keypair(); $this->mskSk = sodium_crypto_sign_secretkey($k); $this->mskPk = sodium_crypto_sign_publickey($k);
        $d = sodium_crypto_sign_keypair(); $this->sk = sodium_crypto_sign_secretkey($d); $this->pk = sodium_crypto_sign_publickey($d);
        $this->ik = random_bytes(32);
    }
    public function sign(string $msg, ?string $key = null): string { return B64::enc(sodium_crypto_sign_detached($msg, $key ?? $this->sk)); }
    public function prekeys(string $prefix, int $n, int $start = 1): array {
        $o = []; for ($i = $start; $i < $start + $n; $i++) { $id = "K$i"; $key = B64::enc(random_bytes(32)); $o[] = ['id' => $id, 'key' => $key, 'sig' => $this->sign("$prefix:$id:$key")]; } return $o;
    }
    public function identityPayload(string $userPublicId, bool $reset = false): array {
        $cert = KeyService::certificateString($userPublicId, B64::enc($this->ik), B64::enc($this->pk));
        return ['msk' => B64::enc($this->mskPk), 'reset' => $reset, 'device' => ['identity_key' => B64::enc($this->ik), 'signing_key' => B64::enc($this->pk),
            'certificate_sig' => $this->sign($cert, $this->mskSk), 'one_time_keys' => $this->prekeys('pm-otk-v1', 3), 'fallback_key' => $this->prekeys('pm-fbk-v1', 1, 100)[0]]];
    }
}

/** @return array{0:\PM\Infra\Database,1:array,2:int} user row + a session id */
function e2eUser(\PM\Infra\Database $db, \PM\Users\UserService $users, \PM\Auth\UserSessions $sessions, string $name): array {
    [$id] = $users->create(1, $name, $name, '', true, '');
    $tok = $sessions->create($id, '1.1.1.1', 'Chrome', 30);
    $s = $sessions->validate($tok);
    return [$s, (int)$s['session_id'], $tok];
}

test('E2EE: identity registration verifies certificate and prekey signatures', function () {
    [$db, $users, , $sessions] = svc();
    [$s, $sid] = e2eUser($db, $users, $sessions, 'alice');
    $keys = new KeyService($db);
    $dev = new FakeDevice();
    $bad = $dev->identityPayload($s['public_id']); $bad['device']['certificate_sig'] = $dev->sign('something else', $dev->mskSk);
    throws(fn () => $keys->registerIdentity($s, $sid, $bad, false, 'x'), UserError::class);
    $bad = $dev->identityPayload($s['public_id']); $bad['device']['one_time_keys'][1]['key'] = B64::enc(random_bytes(32));
    throws(fn () => $keys->registerIdentity($s, $sid, $bad, false, 'x'), UserError::class);
    $wrongUser = $dev->identityPayload('01SOMEONEELSE0000000000000');
    throws(fn () => $keys->registerIdentity($s, $sid, $wrongUser, false, 'x'), UserError::class);
    $devId = $keys->registerIdentity($s, $sid, $dev->identityPayload($s['public_id']), false, 'x');
    ok(\PM\Support\Ulid::isValid($devId));
    eq(3, $keys->otkCount((int)$db->scalar('SELECT id FROM devices WHERE public_id = ?', [$devId])));
    eq((int)$db->scalar('SELECT id FROM devices WHERE public_id = ?', [$devId]), (int)$db->scalar('SELECT device_id FROM user_sessions WHERE id = ?', [$sid]), 'session bound');
    try { $keys->registerIdentity($s, $sid, (new FakeDevice())->identityPayload($s['public_id']), false, 'x'); ok(false); }
    catch (UserError $e) { eq('identity_exists', $e->getMessage()); }
    $k = $keys->userKeys($s['public_id']);
    eq(B64::enc($dev->mskPk), $k['msk']);
    eq(1, count($k['devices']));
});

test('E2EE: reset supersedes identity, revokes old device, drops its envelopes, notifies contacts', function () {
    [$db, $users, , $sessions] = svc();
    [$a, $asid] = e2eUser($db, $users, $sessions, 'alice');
    [$b, $bsid] = e2eUser($db, $users, $sessions, 'bob');
    $keys = new KeyService($db); $msgs = new MessageService($db);
    $keys->registerIdentity($a, $asid, (new FakeDevice())->identityPayload($a['public_id']), false, 'x');
    $bDev = $keys->registerIdentity($b, $bsid, (new FakeDevice())->identityPayload($b['public_id']), false, 'x');
    $cid = $msgs->openDirect((int)$a['id'], $b['public_id']);
    eq($cid, $msgs->openDirect((int)$b['id'], $a['public_id']), 'same direct conversation both ways');
    $aDevId = (int)$db->scalar('SELECT device_id FROM user_sessions WHERE id = ?', [$asid]);
    $msgs->send((int)$a['id'], $aDevId, ['conversation_id' => $cid, 'protocol_version' => 1, 'client_msg_id' => str_repeat('A', 22),
        'envelopes' => [['device_id' => $bDev, 'type' => 0, 'ciphertext' => base64_encode('opaque')]]]);
    $b2 = $sessions->validate($sessions->create((int)$b['id'], '2.2.2.2', 'Firefox', 30));
    $keys->registerIdentity($b2, (int)$b2['session_id'], (new FakeDevice())->identityPayload($b['public_id'], true), true, 'y');
    eq('revoked', $db->scalar('SELECT status FROM devices WHERE public_id = ?', [$bDev]));
    eq(0, (int)$db->scalar('SELECT COUNT(*) FROM message_envelopes'));
    eq(1, (int)$db->scalar('SELECT COUNT(*) FROM user_master_keys WHERE user_id = ? AND superseded_at IS NULL', [$b['id']]));
    eq(1, (int)$db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'contact_identity_changed'", [$a['id']]));
    eq(null, $db->scalar('SELECT device_id FROM user_sessions WHERE id = ?', [$bsid]), 'old session unbound');
});

test('E2EE: one-time keys are handed out once, then the fallback key', function () {
    [$db, $users, , $sessions] = svc();
    [$s, $sid] = e2eUser($db, $users, $sessions, 'alice');
    $keys = new KeyService($db);
    $dev = $keys->registerIdentity($s, $sid, (new FakeDevice())->identityPayload($s['public_id']), false, 'x');
    $seen = [];
    for ($i = 0; $i < 3; $i++) { $k = $keys->claim([$dev], 99)[$dev]; eq('one_time', $k['type']); $seen[$k['id']] = 1; }
    eq(3, count($seen), 'all distinct');
    eq('fallback', $keys->claim([$dev], 99)[$dev]['type']);
    eq('fallback', $keys->claim([$dev], 99)[$dev]['type'], 'fallback reusable');
    eq([], $keys->claim(['01NOPE000000000000000000AA'], 99));
});

test('E2EE: session binding requires a signature by the device key over a fresh nonce', function () {
    [$db, $users, , $sessions] = svc();
    [$s, $sid, $tok] = e2eUser($db, $users, $sessions, 'alice');
    $keys = new KeyService($db);
    $dev = new FakeDevice();
    $devId = $keys->registerIdentity($s, $sid, $dev->identityPayload($s['public_id']), false, 'x');
    $s2 = $sessions->validate($sessions->create((int)$s['id'], '3.3.3.3', 'Chrome', 30));
    $n = $keys->bindChallenge((int)$s2['session_id']);
    ok(!$keys->bind($s2, $devId, (new FakeDevice())->sign("pm-bind-v1:$n:{$s2['session_public_id']}")), 'other key rejected');
    $n = $keys->bindChallenge((int)$s2['session_id']);
    ok(!$keys->bind($s2, $devId, $dev->sign("pm-bind-v1:$n:{$s['session_public_id']}")), 'signature for another session rejected');
    $n = $keys->bindChallenge((int)$s2['session_id']);
    $sig = $dev->sign("pm-bind-v1:$n:{$s2['session_public_id']}");
    ok($keys->bind($s2, $devId, $sig));
    ok(!$keys->bind($s2, $devId, $sig), 'nonce is single-use');
});

test('E2EE: send enforces exact device coverage, membership, idempotency; sync/ack/receipts', function () {
    [$db, $users, , $sessions] = svc();
    [$a, $asid] = e2eUser($db, $users, $sessions, 'alice');
    [$b, $bsid] = e2eUser($db, $users, $sessions, 'bob');
    [$c] = e2eUser($db, $users, $sessions, 'carol');
    $keys = new KeyService($db); $msgs = new MessageService($db);
    $keys->registerIdentity($a, $asid, (new FakeDevice())->identityPayload($a['public_id']), false, 'x');
    $bDev = $keys->registerIdentity($b, $bsid, (new FakeDevice())->identityPayload($b['public_id']), false, 'x');
    $aDevId = (int)$db->scalar('SELECT device_id FROM user_sessions WHERE id = ?', [$asid]);
    $bDevId = (int)$db->scalar('SELECT device_id FROM user_sessions WHERE id = ?', [$bsid]);
    $cid = $msgs->openDirect((int)$a['id'], $b['public_id']);
    $msg = fn (array $env, string $mid = 'BBBBBBBBBBBBBBBBBBBBBA') => ['conversation_id' => $cid, 'protocol_version' => 1, 'client_msg_id' => $mid, 'envelopes' => $env];
    try { $msgs->send((int)$a['id'], $aDevId, $msg([['device_id' => '01STALE0000000000000000000', 'type' => 1, 'ciphertext' => 'AAAA']])); ok(false); }
    catch (DeviceMismatch $e) { eq([$bDev], $e->missing); eq(['01STALE0000000000000000000'], $e->stale); }
    throws(fn () => $msgs->send((int)$c['id'], 1, $msg([['device_id' => $bDev, 'type' => 1, 'ciphertext' => 'AAAA']])), UserError::class); // not a member
    throws(fn () => $msgs->send((int)$a['id'], $aDevId, ['protocol_version' => 0] + $msg([['device_id' => $bDev, 'type' => 1, 'ciphertext' => 'AAAA']])), UserError::class);
    throws(fn () => $msgs->send((int)$a['id'], $aDevId, $msg([['device_id' => $bDev, 'type' => 1, 'ciphertext' => base64_encode(random_bytes(70000))]])), UserError::class);
    throws(fn () => $msgs->send((int)$a['id'], $aDevId, $msg([['device_id' => $bDev, 'type' => 0, 'ciphertext' => 'AAAA']], 'BBBBBBBBBBBBBBBBBBBBBB')), UserError::class); // non-canonical id
    $r1 = $msgs->send((int)$a['id'], $aDevId, $msg([['device_id' => $bDev, 'type' => 0, 'ciphertext' => base64_encode("\x01opaque")]]));
    $r2 = $msgs->send((int)$a['id'], $aDevId, $msg([['device_id' => $bDev, 'type' => 0, 'ciphertext' => base64_encode("\x01opaque")]]));
    ok(!$r1['duplicate'] && $r2['duplicate']);
    eq(1, (int)$db->scalar('SELECT COUNT(*) FROM messages'));
    eq('sent', $msgs->status($aDevId, ['BBBBBBBBBBBBBBBBBBBBBA'])['BBBBBBBBBBBBBBBBBBBBBA']);
    $sync = $msgs->sync((int)$b['id'], $bDevId, 0, 0);
    eq(1, count($sync['envelopes']));
    eq("\x01opaque", base64_decode($sync['envelopes'][0]['ciphertext']));
    eq($a['public_id'], $sync['envelopes'][0]['sender_user_id']);
    eq('BBBBBBBBBBBBBBBBBBBBBA', $sync['envelopes'][0]['message_id']);
    eq(0, count($msgs->sync((int)$a['id'], $aDevId, 0, 0)['envelopes']), 'sender gets nothing');
    eq(0, $msgs->ack((int)$a['id'], $aDevId, [$sync['envelopes'][0]['id']]), 'cannot ack someone else\'s envelope');
    eq(1, $msgs->ack((int)$b['id'], $bDevId, [$sync['envelopes'][0]['id']]));
    eq(0, (int)$db->scalar('SELECT COUNT(*) FROM message_envelopes'), 'deleted after ack');
    eq('delivered', $msgs->status($aDevId, ['BBBBBBBBBBBBBBBBBBBBBA'])['BBBBBBBBBBBBBBBBBBBBBA']);
});
