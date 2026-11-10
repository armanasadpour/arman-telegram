<?php
declare(strict_types=1);

use PM\Push\PushService;
use PM\Push\WebPush;
use PM\Settings\Settings;
use PM\Support\UserError;

/** Runs the independent Node implementation (tests/tools/webpush-check.mjs). */
function wpTool(string ...$args): string
{
    $cmd = 'node ' . escapeshellarg(dirname(__DIR__) . '/tools/webpush-check.mjs') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    return trim((string)shell_exec($cmd));
}

test('P8: Web Push payload encryption (RFC 8291) is decryptable by an independent implementation', function () {
    if (trim((string)shell_exec('command -v node')) === '') { throw new SkipTest('node not installed'); }
    $sub = json_decode(wpTool('subscriber'), true);
    foreach (['{"t":"m","c":"01HXYZ","g":0}', str_repeat('x', 1000)] as $payload) {
        $body = WebPush::encrypt($payload, WebPush::b64uDecode($sub['p256dh']), WebPush::b64uDecode($sub['auth']));
        eq($payload, wpTool('decrypt', json_encode($sub['jwk']), $sub['auth'], base64_encode($body)));
    }
    // Tampering is detected (AES-GCM tag).
    $body = WebPush::encrypt('secret', WebPush::b64uDecode($sub['p256dh']), WebPush::b64uDecode($sub['auth']));
    $body[strlen($body) - 20] = chr(ord($body[strlen($body) - 20]) ^ 1);
    ok(str_starts_with(wpTool('decrypt', json_encode($sub['jwk']), $sub['auth'], base64_encode($body)), 'error'));
    throws(fn () => WebPush::encrypt('x', str_repeat("\x04", 10), str_repeat('a', 16)), \InvalidArgumentException::class);
});

test('P8: VAPID JWT (ES256) verifies with the published server key; audience = push service origin', function () {
    if (trim((string)shell_exec('command -v node')) === '') { throw new SkipTest('node not installed'); }
    [$pem, $raw] = WebPush::generateVapidKeys();
    $wp = new WebPush($pem, $raw, 'https://example.org');
    for ($i = 0; $i < 20; $i++) { // DER→raw conversion must hold for every r/s length (leading zero bytes)
        $ep = 'https://fcm.googleapis.com/fcm/send/abc' . $i;
        eq('ok https://fcm.googleapis.com https://example.org', wpTool('verify', $wp->vapidHeader($ep, time()), WebPush::b64u($raw), $ep));
    }
    [, $otherRaw] = WebPush::generateVapidKeys();
    eq('bad', wpTool('verify', $wp->vapidHeader('https://fcm.googleapis.com/x', time()), WebPush::b64u($otherRaw), 'https://fcm.googleapis.com/x'));
});

test('P8: push endpoints are limited to real push services (no SSRF to internal hosts)', function () {
    [$pem, $raw] = WebPush::generateVapidKeys();
    $wp = new WebPush($pem, $raw, 'https://x');
    foreach (['https://fcm.googleapis.com/fcm/send/x', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/Q', 'https://db5p.notify.windows.com/w/?token=x'] as $ok) {
        ok($wp->endpointAllowed($ok), $ok);
    }
    foreach (['http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'https://localhost/x', 'https://evilfcm.googleapis.com.attacker.net/x', 'https://fcm.googleapis.com:8443/x',
              'https://user@fcm.googleapis.com/x', 'file:///etc/passwd', 'https://googleapis.com/x', 'https://push.test:9443/x'] as $bad) {
        ok(!$wp->endpointAllowed($bad), $bad);
    }
    ok((new WebPush($pem, $raw, 'https://x', ['push.test']))->endpointAllowed('https://push.test:9443/x'), 'explicitly allowed test host');
});

test('P8: push targets: other members only, not muted (except calls), not currently open, never the sender', function () {
    [$db, $users, , $sessions] = svc();
    $s = new Settings($db, str_repeat('k', 32));
    $mk = function (string $u) use ($db, $users) {
        [$id] = $users->create(1, $u, $u, '', true, '');
        $dev = $db->insert("INSERT INTO devices (public_id, user_id, label, status, identity_key, signing_key, created_at, last_seen_at) VALUES (?, ?, 'x', 'active', ?, ?, NOW(3), NULL)",
            [substr(str_pad($u, 26, 'X'), 0, 26), $id, random_bytes(32), random_bytes(32)]);
        return [$id, $dev];
    };
    [$a, $ad] = $mk('alice'); [$b, $bd] = $mk('bob'); [$c, $cd] = $mk('carol');
    $conv = $db->insert("INSERT INTO conversations (public_id, type, created_by, created_at) VALUES ('01GROUPCONV000000000000000', 'group', ?, NOW(3))", [$a]);
    foreach ([$a, $b, $c] as $u) { $db->run('INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW(3))', [$conv, $u, 'member']); }
    $p = new PushService($db, $s, str_repeat('k', 32), 'pm.test', ['extra' => ['push.test'], 'ca' => null]);
    foreach ([$ad, $bd, $cd] as $i => $d) {
        $sub = json_decode(wpTool('subscriber'), true) ?: ['p256dh' => WebPush::b64u("\x04" . random_bytes(64)), 'auth' => WebPush::b64u(random_bytes(16))];
        $p->subscribe($d, 'https://push.test:1/ep' . $i, $sub['p256dh'], $sub['auth']);
    }
    throws(fn () => $p->subscribe($ad, 'https://169.254.169.254/latest', WebPush::b64u("\x04" . random_bytes(64)), WebPush::b64u(random_bytes(16))), UserError::class);
    eq(3, (int)$db->scalar('SELECT COUNT(*) FROM push_subscriptions'));
    // No network in this test: port 1 refuses → status 0 counts as failure, but the TARGET set is what we check.
    $targets = fn (string $type) => array_keys($p->deliver([[$type, $conv, $a, $ad]]));
    $ids = array_column($db->all('SELECT id, device_id FROM push_subscriptions'), 'id', 'device_id');
    $sorted = fn (array $x) => (function () use ($x) { sort($x); return $x; })();
    eq($sorted([$ids[$bd], $ids[$cd]]), $sorted($targets('message')), 'sender excluded');
    $db->run('UPDATE conversation_members SET muted_until = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', time() + 3600), $b]);
    eq([$ids[$cd]], $targets('message'), 'muted member skipped');
    eq($sorted([$ids[$bd], $ids[$cd]]), $sorted($targets('call')), 'calls ring even when muted');
    $db->run('UPDATE devices SET last_seen_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $cd]);
    eq([], $targets('message'), 'an open app (synced in the last 20 s) gets no push');
    $p->unsubscribe($bd);
    eq(2, (int)$db->scalar('SELECT COUNT(*) FROM push_subscriptions'));
    // Subscriptions of a device: one at a time (a new endpoint replaces the old one).
    $sub = json_decode(wpTool('subscriber'), true) ?: ['p256dh' => WebPush::b64u("\x04" . random_bytes(64)), 'auth' => WebPush::b64u(random_bytes(16))];
    $p->subscribe($ad, 'https://push.test:1/other', $sub['p256dh'], $sub['auth']);
    eq(1, (int)$db->scalar('SELECT COUNT(*) FROM push_subscriptions WHERE device_id = ?', [$ad]));
    ok($s->hasSecret('push.vapid_private') && strlen((string)WebPush::b64uDecode((string)$s->get('push.vapid_public'))) === 65, 'VAPID key created once, private part sealed');
    ok(!str_contains((string)$db->scalar("SELECT setting_value FROM system_settings WHERE setting_key = 'secret.push.vapid_private'"), 'PRIVATE KEY'), 'not stored in clear');
});

test('P8.2: profile photos are stored only as ciphertext and only visible to people who share a chat or follow', function () {
    [$db, $users] = svc();
    [$a] = $users->create(1, 'alice', 'A', '', true, '');
    [$b] = $users->create(1, 'bob', 'B', '', true, '');
    [$c] = $users->create(1, 'carol', 'C', '', true, '');
    $pub = fn ($id) => (string)$db->scalar('SELECT public_id FROM users WHERE id = ?', [$id]);
    $av = new \PM\Users\AvatarService($db);
    throws(fn () => $av->put($a, 'not base64!'), UserError::class);
    throws(fn () => $av->put($a, base64_encode(str_repeat('x', 10))), UserError::class);
    throws(fn () => $av->put($a, base64_encode(random_bytes(\PM\Users\AvatarService::MAX_BYTES + 1))), UserError::class);
    $ct = random_bytes(5000);
    eq(1, $av->put($a, base64_encode($ct)));
    eq(2, $av->put($a, base64_encode($ct)));
    eq(null, $av->get($b, $pub($a)), 'a stranger cannot fetch it');
    $conv = $db->insert("INSERT INTO conversations (public_id, type, created_by, created_at) VALUES ('01AVATARCONV00000000000000', 'direct', ?, NOW(3))", [$a]);
    foreach ([$a, $b] as $u) { $db->run('INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW(3))', [$conv, $u, 'member']); }
    eq(['version' => 2, 'ciphertext' => base64_encode($ct)], $av->get($b, $pub($a)), 'a chat partner can');
    $db->run('INSERT INTO user_contacts (user_id, contact_user_id, created_at) VALUES (?, ?, NOW(3))', [$c, $a]);
    ok($av->get($c, $pub($a)) !== null, 'a follower can');
    $av->remove($a);
    eq(null, $av->get($b, $pub($a)));
    eq(3, $av->put($a, base64_encode($ct)), 'versions never repeat after removal');
});
