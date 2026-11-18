<?php
declare(strict_types=1);

use PM\Calls\CallService;
use PM\Calls\CloudflareTurn;

// Uses the mock Cloudflare API on 127.0.0.1:2098 (tests/Integration/mock-cloudflare.php, started by test-env-up.sh).
const CF_MOCK = 'http://127.0.0.1:2098';

test('Cloudflare TURN: credentials fetched with the key, port 53 dropped, cached, token never handed out', function () {
    [$db, , , , $s] = svc();
    $s->setSecret('calls.cf_cache', ''); $s->set('calls.cf_key_id', ''); $s->setSecret('calls.cf_token', '');
    $cf = new CloudflareTurn($s, CF_MOCK);
    ok(!$cf->configured());
    $s->set('calls.cf_key_id', 'cfkey12345');
    $s->setSecret('calls.cf_token', 'cf-test-token');
    $r = $cf->iceServers();
    eq('', $r['error']);
    $all = array_merge(...array_map(fn ($x) => $x['urls'], $r['servers']));
    ok(in_array('turn:127.0.0.1:3478?transport=udp', $all, true) && in_array('stun:127.0.0.1:3478', $all, true));
    ok(array_filter($all, fn ($u) => str_contains($u, ':53')) === [], 'port 53 removed');
    $turn = array_values(array_filter($r['servers'], fn ($x) => isset($x['username'])))[0];
    ok((int)explode(':', $turn['username'])[0] > time() + 40000, 'credentials valid for ~12 h');
    $at = json_decode($s->secret('calls.cf_cache'), true)['at'];
    $s->set('calls.cf_key_id', 'cfkey12345');
    eq($r['servers'], (new CloudflareTurn($s, 'http://127.0.0.1:1'))->iceServers()['servers'], 'served from cache (no network)');
    eq($at, json_decode($s->secret('calls.cf_cache'), true)['at']);
    // the app's ICE config: Cloudflare servers + has_turn, and no API token anywhere in it
    $s->set('calls.turn_mode', 'cloudflare');
    $cfg = (new CallService($db, $s, $cf))->iceConfig('u1');
    ok($cfg['has_turn'] === true && !str_contains(json_encode($cfg), 'cf-test-token'));
});

test('Cloudflare TURN: wrong token → clear error; Cloudflare down → still-valid cached credentials keep working', function () {
    [$db, , , , $s] = svc();
    $s->set('calls.cf_key_id', 'cfkey12345');
    $s->setSecret('calls.cf_token', 'wrong-token');
    $s->setSecret('calls.cf_cache', ''); // as the admin panel does when a new token is saved
    $r = (new CloudflareTurn($s, CF_MOCK))->iceServers();
    ok($r['servers'] === [] && str_contains($r['error'], 'rejected the API token'), $r['error']);
    $s->setSecret('calls.cf_token', 'cf-test-token');
    $good = (new CloudflareTurn($s, CF_MOCK))->iceServers(true)['servers'];
    $down = (new CloudflareTurn($s, 'http://127.0.0.1:1'))->iceServers(true);
    ok($down['error'] !== '' && $down['servers'] === $good, 'falls back to the cache: ' . json_encode([$down, $good]));
    $s->set('calls.turn_mode', 'cloudflare');
    $cfg = (new CallService($db, $s, new CloudflareTurn($s, 'http://127.0.0.1:1')))->iceConfig('u', true);
    ok($cfg['has_turn'] && !isset($cfg['turn_error']), 'fresh cache is used without asking Cloudflare: ' . json_encode($cfg));
    $s->set('calls.cf_key_id', 'bad key!');
    ok((new CloudflareTurn($s, CF_MOCK))->iceServers(true)['error'] !== '', 'bad key');
});

test('Cloudflare TURN: answer clean-up keeps only well-formed STUN/TURN entries', function () {
    $out = CloudflareTurn::clean([['urls' => ['javascript:alert(1)', 'turns:turn.cloudflare.com:443?transport=tcp', 'turn:turn.cloudflare.com:53?transport=udp'], 'username' => 'u', 'credential' => 'c'],
        ['urls' => 'stun:stun.cloudflare.com:3478'], 'junk', ['urls' => []]]);
    eq([['urls' => ['turns:turn.cloudflare.com:443?transport=tcp'], 'username' => 'u', 'credential' => 'c'], ['urls' => ['stun:stun.cloudflare.com:3478']]], $out);
});
