<?php
// CLI-only tests against a dedicated, disposable installation.
if (PHP_SAPI !== 'cli' || getenv('NOBATYAR_TEST') !== '1') {
    exit("Test opt-in required\n");
}
$c = require __DIR__ . '/../config.php';
if ($c['database'] !== 'nobatyar_test_http') {
    exit("Disposable database required\n");
}
ob_start();
require __DIR__ . '/../core.php';
require __DIR__ . '/../lib/admin_actions.php';
$checks = 0;
$messages = [];
function t(bool $ok, string $label): void
{
    global $checks, $messages;
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    $messages[] = 'PASS ' . $label;
}
function rejects(callable $fn, string $label, ?int $status = null): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        t($status === null || ($e instanceof AuthError && $e->status === $status), $label);
        return;
    }
    t(false, $label);
}
function reset_rates(): void
{
    query('DELETE FROM auth_rate_limits');
}
function challenge(string $phone, ?string &$plain): array
{
    return otp_request($phone, function ($mobile, $code) use (&$plain) {
        $plain = $code;
        return ['messageid' => '1001'];
    });
}
function identity(array $u): void
{
    $_SESSION['user_id'] = (int) $u['id'];
    $_SESSION['user_version'] = (int) $u['session_version'];
    $_SESSION['login_at'] = time();
}
// Formatting tests use known Nowruz boundaries and a round-trip.
t(jdate('2026-10-13') === '۱۴۰۵/۰۷/۲۱', 'Jalali display');
t(parse_jdate('۱۴۰۳/۱۲/۳۰') === '2025-03-20', 'leap Esfand accepted');
t(parse_jdate('۱۴۰۴/۰۱/۰۱') === '2025-03-21', 'Nowruz conversion');
rejects(fn() => parse_jdate('۱۴۰۴/۱۲/۳۰'), 'non-leap Esfand rejected');
rejects(fn() => parse_jdate('۱۴۰۵/۰۷/۳۱'), 'invalid day rejected');
rejects(fn() => parse_jdate('۱۴۰۵/۰۷/۲۱ ۲۵:۰۰', true), 'invalid hour rejected');
t(parse_jdate(jdate('2026-10-13 23:05:00', true), true) === '2026-10-13 23:05:00', 'datetime roundtrip');
t(toman(1234567) === '۱٬۲۳۴٬۵۶۷ تومان', 'Toman grouping');
rejects(fn() => otp_request('09120000900'), 'disabled SMS fails closed', 503);
rejects(fn() => otp_request('02112345678', fn() => []), 'invalid mobile rejected', 422);
$r = challenge('09120000901', $code);
$stored = query('SELECT * FROM otp_challenges WHERE id=?', [$r['challenge_id']])->fetch();
t($stored['code_hash'] !== $code && password_verify($code, $stored['code_hash']), 'OTP only stored as hash');
t($r['expires_in'] === 180 && $r['resend_after'] === 60, 'OTP TTL and cooldown');
rejects(fn() => challenge('09120000901', $new), 'phone cooldown enforced', 429);
$before = session_id();
$pending = otp_verify($r['challenge_id'], $code);
t($pending['status'] === 'profile_required' && $pending['mobile'] === '09120000901', 'valid OTP for a new number asks for the name');
t(session_id() !== $before, 'session id regenerated');
t((int) query("SELECT COUNT(*) FROM users WHERE mobile='09120000901'")->fetchColumn() === 0 && user_now() === null, 'no nameless account before the profile step');
rejects(fn() => auth_complete_signup(['first_name' => 'ع', 'last_name' => 'رضایی', 'accept_terms' => '1']), 'one-letter first name rejected');
rejects(fn() => auth_complete_signup(['first_name' => 'علی', 'last_name' => 'رضایی']), 'terms acceptance required');
rejects(fn() => auth_complete_signup(['first_name' => 'علی<b>', 'last_name' => 'رضایی', 'accept_terms' => '1']), 'markup in name rejected');
$before = session_id();
$u = auth_complete_signup(['first_name' => ' علی ', 'last_name' => 'رضایی‌نژاد', 'accept_terms' => '1']);
t($u['mobile'] === '09120000901' && $u['name'] === 'علی رضایی‌نژاد' && $u['first_name'] === 'علی' && $u['terms_accepted_at'] !== null, 'account created with real name and terms time');
t(session_id() !== $before && (int) user_now()['id'] === (int) $u['id'], 'signed in after sign-up with a new session id');
t(pending_signup() === null, 'pending sign-up cleared');
t(normalize_mobile('+98 912 000 0901') === '09120000901' && normalize_mobile('۹۱۲۰۰۰۰۹۰۱') === '09120000901' && normalize_mobile('00989120000901') === '09120000901' && normalize_mobile('0912-000-0901') === '09120000901', 'mobile formats normalized');
rejects(fn() => normalize_mobile('+44 7700 900123'), 'foreign number rejected');
t(roles((int) $u['id']) === ['patient'], 'no self promotion');
rejects(fn() => otp_verify($r['challenge_id'], $code), 'OTP replay rejected', 422);
reset_rates();
$r = challenge('09120000902', $code);
for ($i = 0; $i < 5; $i++) {
    rejects(
        fn() => otp_verify($r['challenge_id'], $code === '111111' ? '222222' : '111111'),
        'wrong code attempt ' . ($i + 1),
        422,
    );
}
rejects(fn() => otp_verify($r['challenge_id'], $code), 'correct code rejected after lockout', 422);
t(
    (int) query('SELECT attempts FROM otp_challenges WHERE id=?', [$r['challenge_id']])->fetchColumn() === 5,
    'failed attempts persisted',
);
reset_rates();
$r = challenge('09120000903', $code);
query('UPDATE otp_challenges SET expires_at=? WHERE id=?', [time() - 1, $r['challenge_id']]);
rejects(fn() => otp_verify($r['challenge_id'], $code), 'expired code rejected', 422);
reset_rates();
$r = challenge('09120000904', $code);
query('UPDATE otp_challenges SET session_hash=? WHERE id=?', [str_repeat('a', 64), $r['challenge_id']]);
rejects(fn() => otp_verify($r['challenge_id'], $code), 'different session cannot use OTP', 422);
reset_rates();
rejects(
    fn() => otp_request('09120000905', function () {
        throw new RuntimeException('Transport unavailable');
    }),
    'provider failure returned',
    503,
);
t(
    query("SELECT delivery_status FROM otp_challenges WHERE mobile='09120000905'")->fetchColumn() ===
        'failed',
    'failed send is unusable',
);
reset_rates();
$first = challenge('09120000906', $code1);
reset_rates();
$second = challenge('09120000906', $code2);
rejects(fn() => otp_verify($first['challenge_id'], $code1), 'new challenge invalidates old one', 422);
t(otp_verify($second['challenge_id'], $code2)['mobile'] === '09120000906', 'latest challenge accepted');
reset_rates();
logout_user();
$r = challenge('09120000901', $code);
$again = otp_verify($r['challenge_id'], $code);
t($again['status'] === 'logged_in' && (int) $again['id'] === (int) $u['id'] && (int) user_now()['id'] === (int) $u['id'], 'existing account signs in directly after the code');
$good = ['return' => ['status' => 200], 'entries' => [['messageid' => 987, 'status' => 1]]];
t(kavenegar_parse(200, json_encode($good))['messageid'] === '987', 'provider accepted payload');
rejects(fn() => kavenegar_parse(500, json_encode($good)), 'provider HTTP failure rejected');
rejects(fn() => kavenegar_parse(200, 'bad json'), 'provider invalid JSON rejected');
rejects(
    fn() => kavenegar_parse(200, json_encode(['return' => ['status' => 418]])),
    'provider credit error rejected',
);
$good['entries'][0]['status'] = 6;
rejects(fn() => kavenegar_parse(200, json_encode($good)), 'provider failed delivery state rejected');
reset_rates();
$r = challenge('09120000907', $code);
otp_verify($r['challenge_id'], $code);
$blocked = auth_complete_signup(['first_name' => 'مریم', 'last_name' => 'کاظمی', 'accept_terms' => '1']);
query('UPDATE users SET active=0,session_version=session_version+1 WHERE id=?', [$blocked['id']]);
t(user_now() === null, 'suspension revokes active session');
reset_rates();
$r = challenge('09120000907', $code);
rejects(fn() => otp_verify($r['challenge_id'], $code), 'suspended account cannot login', 403);
identity($u);
query('UPDATE users SET session_version=session_version+1 WHERE id=?', [$u['id']]);
t(user_now() === null, 'version change invalidates session');
identity(query('SELECT * FROM users WHERE id=?', [$u['id']])->fetch());
$_SESSION['login_at'] = time() - 43201;
t(user_now() === null, 'session absolute expiry');
reset_rates();
for ($i = 0; $i < 3; $i++) {
    rate_limit('test-key', 3, 60);
}
rejects(fn() => rate_limit('test-key', 3, 60), 'rate limit rejects excess', 429);
// Legacy phone numbers are never automatically linked to user accounts.
t((int) query('SELECT COUNT(*) FROM doctor_accounts')->fetchColumn() === 0, 'no automatic doctor ownership');
$messages[] = "SUCCESS $checks auth/calendar checks";
ob_end_clean();
echo implode("\n", $messages) . "\n";
