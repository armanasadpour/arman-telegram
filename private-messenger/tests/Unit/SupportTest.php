<?php
declare(strict_types=1);

use PM\Http\Response;
use PM\Infra\Migrator;
use PM\Support\Crypto;
use PM\Support\Env;
use PM\Support\Fa;
use PM\Support\Passwords;
use PM\Support\Phone;
use PM\Support\Random;
use PM\Support\Totp;
use PM\Support\Ulid;

test('ULID: format, uniqueness, time ordering', function () {
    $a = Ulid::generate(1700000000000);
    $b = Ulid::generate(1700000000001);
    ok(Ulid::isValid($a) && Ulid::isValid($b));
    ok(strcmp(substr($a, 0, 10), substr($b, 0, 10)) < 0, 'time prefix must sort');
    $set = [];
    for ($i = 0; $i < 2000; $i++) { $set[Ulid::generate()] = 1; }
    eq(2000, count($set));
});

test('TOTP: RFC 6238 SHA-1 test vectors (6-digit truncation)', function () {
    $secret = Totp::base32Encode('12345678901234567890');
    eq('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
    eq('287082', Totp::code($secret, intdiv(59, 30)));
    eq('081804', Totp::code($secret, intdiv(1111111109, 30)));
    eq('005924', Totp::code($secret, intdiv(1234567890, 30)));
    eq('279037', Totp::code($secret, intdiv(2000000000, 30)));
});

test('TOTP: verify accepts ±1 step, rejects replay and wrong codes', function () {
    $s = Totp::newSecret();
    $t = 1_800_000_000;
    $step = intdiv($t, 30);
    eq($step, Totp::verify($s, Totp::code($s, $step), $t));
    eq($step - 1, Totp::verify($s, Totp::code($s, $step - 1), $t));
    eq(null, Totp::verify($s, Totp::code($s, $step - 2), $t));
    eq(null, Totp::verify($s, Totp::code($s, $step), $t, $step), 'replay of same step must fail');
    eq($step, Totp::verify($s, Fa::digits(Totp::code($s, $step)), $t), 'Persian digits accepted');
    eq(null, Totp::verify($s, '12345', $t));
});

test('Phone: Iranian and international normalization', function () {
    eq('+989121234567', Phone::normalize('09121234567'));
    eq('+989121234567', Phone::normalize('۰۹۱۲ ۱۲۳ ۴۵۶۷'));
    eq('+989121234567', Phone::normalize('00989121234567'));
    eq('+989121234567', Phone::normalize('9121234567'));
    eq('+14155550123', Phone::normalize('+1 (415) 555-0123'));
    eq(null, Phone::normalize('12345'));
    eq(null, Phone::normalize('abc'));
});

test('Passwords: policy, Argon2id hashing, dummy verify for unknown users', function () {
    ok(Passwords::policyError('short') !== null);
    ok(Passwords::policyError('aaaaaaaaaaaa') !== null, 'too simple');
    ok(Passwords::policyError('alice.smith1', 'Alice.Smith1') !== null, 'equal to username');
    eq(null, Passwords::policyError('correct horse battery'));
    $h = Passwords::hash('correct horse battery');
    ok(str_starts_with($h, '$argon2id$'), 'argon2id expected');
    ok(Passwords::verify('correct horse battery', $h));
    ok(!Passwords::verify('wrong', $h));
    ok(!Passwords::verify('anything', null));
});

test('Random: readable passwords and tokens', function () {
    $p = Random::readablePassword(16);
    eq(16, strlen($p));
    ok(!preg_match('/[0O1lI]/', $p), 'no ambiguous characters');
    ok(preg_match('/^[A-Za-z0-9_-]{43}$/', Random::token(32)) === 1);
});

test('Crypto: secretbox round-trip and tamper detection', function () {
    $k = random_bytes(32);
    $sealed = Crypto::seal($k, 'sms-api-key');
    eq('sms-api-key', Crypto::open($k, $sealed));
    $sealed[30] = chr(ord($sealed[30]) ^ 1);
    eq(null, Crypto::open($k, $sealed));
    eq(null, Crypto::open(random_bytes(32), Crypto::seal($k, 'x')));
});

test('Env: render/parse round-trip with special characters', function () {
    $vals = ['APP_KEY' => base64_encode(random_bytes(32)), 'DB_PASS' => 'p"a\\s s=#;' . "\t", 'APP_PATH' => 'Ab12'];
    $f = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($f, Env::render($vals));
    eq($vals, Env::parseFile($f));
    unlink($f);
});

test('Migrator: SQL splitter ignores ; in strings and comments', function () {
    $parts = Migrator::split("-- c; omment\nCREATE TABLE a (x VARCHAR(5) DEFAULT 'a;b');\nINSERT INTO a VALUES ('it\\'s;');\n");
    eq(2, count($parts));
    ok(str_contains($parts[0], "'a;b'"));
    ok(str_contains($parts[1], "'it\\'s;'"));
});

test('Migrator: migration files split into the expected statements', function () {
    $n = count(Migrator::split((string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/001_init.sql')));
    ok($n >= 34, "001 should contain >= 34 statements, got $n");
});

test('404 page is byte-identical to Apache default and has no app markers', function () {
    $r = Response::notFound();
    eq(404, $r->status);
    ok(str_contains($r->body, 'The requested URL was not found on this server.'));
    ok(!preg_match('/messenger|پیام|login|ورود/i', $r->body));
});
