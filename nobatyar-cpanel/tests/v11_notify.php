<?php
// 0.11.0 — Web Push crypto and delivery, CLI only, on the disposable test install.
// The RFC 8291 test vector checks encryption byte for byte; a simulated browser decrypts real messages.
if (PHP_SAPI !== 'cli' || getenv('NOBATYAR_TEST') !== '1') {
    exit("Test opt-in required\n");
}
$c = require __DIR__ . '/../config.php';
if ($c['database'] !== 'nobatyar_test_http') {
    exit("Disposable database required\n");
}
ob_start();
require __DIR__ . '/../core.php';
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
    } catch (RuntimeException $e) {
        t($contains === '' || str_contains($e->getMessage(), $contains), $label . ' [' . $e->getMessage() . ']');
        return;
    }
    t(false, $label);
}

/** What a browser does with an aes128gcm push body (RFC 8291 §3.4 in reverse). */
function ua_decrypt(string $body, string $uaPrivatePem, string $uaPublic, string $authSecret): string
{
    $salt = substr($body, 0, 16);
    $rs = unpack('N', substr($body, 16, 4))[1];
    $idlen = ord($body[20]);
    $asPublic = substr($body, 21, $idlen);
    $cipher = substr($body, 21 + $idlen);
    if ($rs !== 4096 || $idlen !== 65) {
        throw new RuntimeException('bad header');
    }
    $shared = openssl_pkey_derive(openssl_pkey_get_public(p256_public_pem($asPublic)), openssl_pkey_get_private($uaPrivatePem), 32);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
    if ($plain === false || substr($plain, -1) !== "\x02") {
        throw new RuntimeException('decrypt failed');
    }
    return substr($plain, 0, -1);
}

/* ---------- RFC 8291 Appendix A ---------- */
$asPub = b64url_decode('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$asPem = p256_private_pem(b64url_decode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), $asPub);
$uaPub = b64url_decode('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4');
$auth = b64url_decode('BTBZMqHH6r4Tts7J_aSIgg');
$out = webpush_encrypt('When I grow up, I want to be a watermelon', $uaPub, $auth, $asPem, $asPub, b64url_decode('DGv6ra1nlYgDCS1FRnbzlw'));
t(
    b64url_encode($out) === 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
    'RFC 8291 test vector reproduced byte for byte',
);
$uaPem = p256_private_pem(b64url_decode('q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94'), $uaPub);
t(ua_decrypt($out, $uaPem, $uaPub, $auth) === 'When I grow up, I want to be a watermelon', 'RFC vector decrypts as a browser would');
$fresh = webpush_encrypt('سلام', $uaPub, $auth);
t($fresh !== webpush_encrypt('سلام', $uaPub, $auth) && ua_decrypt($fresh, $uaPem, $uaPub, $auth) === 'سلام', 'fresh key and salt per message, Persian text intact');
rejects(fn() => webpush_encrypt('x', $uaPub, 'short'), 'invalid auth secret rejected');
rejects(fn() => p256_public_pem('bad'), 'invalid public key rejected');

/* ---------- VAPID ---------- */
$keys = vapid_keys();
t($keys !== null && strlen(b64url_decode($keys['public'])) === 65, 'installation VAPID keys available');
$header = vapid_authorization('https://fcm.googleapis.com/fcm/send/abc', $keys);
t((bool) preg_match('/^vapid t=([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+), k=([A-Za-z0-9_-]+)$/', $header, $m), 'Authorization header shape');
$claims = json_decode(b64url_decode($m[2]), true);
t($claims['aud'] === 'https://fcm.googleapis.com' && $claims['exp'] > time() && $claims['exp'] <= time() + 86400 && str_starts_with($claims['sub'], 'mailto:'), 'JWT audience, expiry within 24h and contact');
$sig = b64url_decode($m[3]);
// Raw r||s → DER. Minimal INTEGERs: strip leading zero bytes, add one back when the top bit is set
// (without this, about 1 run in 250 failed when r or s happened to start with a zero byte).
$int = function (string $v): string {
    $v = ltrim($v, "\0");
    if ($v === '' || ord($v[0]) > 127) {
        $v = "\0" . $v;
    }
    return "\x02" . chr(strlen($v)) . $v;
};
$seq = $int(substr($sig, 0, 32)) . $int(substr($sig, 32));
$der = "\x30" . chr(strlen($seq)) . $seq;
t(strlen($sig) === 64 && openssl_verify($m[1] . '.' . $m[2], $der, p256_public_pem(b64url_decode($m[4])), OPENSSL_ALGO_SHA256) === 1, 'ES256 signature verifies with the public key');
rejects(fn() => vapid_authorization('http://insecure.example/x', $keys), 'non-HTTPS endpoint rejected');

/* ---------- Subscription, consent, delivery ---------- */
$user = (int) query("SELECT id FROM users WHERE mobile='09127770003'")->fetchColumn();
query('DELETE FROM push_messages');
query('DELETE FROM push_subscriptions');
query('DELETE FROM notification_preferences WHERE user_id=?', [$user]);
[$browserPem, $browserPub] = p256_generate();
$browserAuth = random_bytes(16);
$sub = ['endpoint' => 'https://push.example.test/send/abc123', 'keys' => ['p256dh' => b64url_encode($browserPub), 'auth' => b64url_encode($browserAuth)]];
$_SERVER['REMOTE_ADDR'] = '127.0.0.9';
rejects(fn() => push_subscribe($user, $sub), 'subscription needs explicit consent', 'رضایت');
rejects(fn() => push_subscribe($user, ['endpoint' => 'http://x.test/a', 'consent' => 1] + $sub), 'non-HTTPS subscription rejected');
rejects(fn() => push_subscribe($user, ['keys' => ['p256dh' => 'abc', 'auth' => 'x']] + $sub + ['consent' => 1]), 'malformed keys rejected');
push_subscribe($user, $sub + ['consent' => 1]);
push_subscribe($user, $sub + ['consent' => 1]);
t((int) query('SELECT COUNT(*) FROM push_subscriptions WHERE user_id=?', [$user])->fetchColumn() === 1, 'same endpoint stored once');
t(notification_preferences($user)['push_consent_at'] !== null, 'consent time recorded');

notify_user($user, 'یادآوری نوبت دکتر نمونه', 'فردا ساعت ۱۰ • مطب مرکزی', null, 'reminder', 'receipt.php?id=1');
t((int) query("SELECT COUNT(*) FROM push_messages WHERE status='queued'")->fetchColumn() === 1, 'notification queues one push per device');
$captured = [];
$transport = function (string $endpoint, array $headers, string $body) use (&$captured) {
    $captured[] = [$endpoint, $headers, $body];
    return 201;
};
$stats = push_dispatch(10, $transport);
t($stats['sent'] === 1 && count($captured) === 1, 'push sent through transport');
[$endpoint, $headers, $body] = $captured[0];
$payload = json_decode(ua_decrypt($body, $browserPem, $browserPub, $browserAuth), true);
t($endpoint === $sub['endpoint'] && $payload['title'] === 'یادآوری نوبت دکتر نمونه' && $payload['url'] === 'receipt.php?id=1', 'browser decrypts title and link');
t(in_array('Content-Encoding: aes128gcm', $headers, true) && (bool) preg_grep('/^TTL: \d+$/', $headers) && (bool) preg_grep('/^Authorization: vapid t=/', $headers), 'push headers per RFC 8030/8291/8292');
t(strlen($body) < 4096, 'message within one record');

// Category switched off: in-app only.
save_notification_preferences($user, ['push_booking' => 1, 'push_delay' => 1, 'push_waitlist' => 1, 'push_announcement' => 1, 'sms_reminder' => 1]);
notify_user($user, 'یادآوری دوم', 'متن', null, 'reminder');
t((int) query("SELECT COUNT(*) FROM push_messages WHERE status='queued'")->fetchColumn() === 0 && (int) query("SELECT COUNT(*) FROM notifications WHERE user_id=? AND title='یادآوری دوم'", [$user])->fetchColumn() === 1, 'disabled category keeps in-app notice but skips push');

// Temporary failure then gone.
notify_user($user, 'تغییر نوبت', 'متن', null, 'booking');
$stats = push_dispatch(10, fn() => 503);
t($stats['retry'] === 1 && query("SELECT status FROM push_messages ORDER BY id DESC LIMIT 1")->fetchColumn() === 'queued', '5xx retried later');
query('UPDATE push_messages SET next_attempt_at=NOW()');
$stats = push_dispatch(10, fn() => 410);
t($stats['gone'] === 1 && (int) query('SELECT active FROM push_subscriptions WHERE user_id=?', [$user])->fetchColumn() === 0, '410 Gone closes the subscription');
notify_user($user, 'پس از لغو', 'متن', null, 'booking');
t((int) query("SELECT COUNT(*) FROM push_messages WHERE status='queued'")->fetchColumn() === 0, 'no push to closed subscription');
push_subscribe($user, $sub + ['consent' => 1]);
push_unsubscribe($user);
t(notification_preferences($user)['push_consent_at'] === null && (int) query('SELECT COUNT(*) FROM push_subscriptions WHERE user_id=? AND active=1', [$user])->fetchColumn() === 0, 'turning off all devices withdraws consent');
setting_set('push_enabled', '0');
t(!push_available(), 'admin switch disables push');
setting_set('push_enabled', '1');

/* ---------- Reminder thresholds ---------- */
setting_set('reminder_hours', '۲۴,۲,۲,۵۰۰');
t(reminder_hours() === [24, 2], 'reminder hours parsed, deduplicated and bounded');
setting_set('reminder_hours', '24,2');

ob_end_flush();
echo "SUCCESS $checks v0.11 push checks\n";
