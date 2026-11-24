<?php

/**
 * Runs FakeIbs as a real XML-RPC HTTP server for browser end-to-end tests:
 *   FAKE_IBS_STATE=/tmp/state.json php -S 127.0.0.1:18235 tests/Fakes/ibsng-server.php
 * Extra test-only endpoint: POST /__connect?username=x simulates a VPN login.
 */
require __DIR__.'/../../vendor/autoload.php';

use App\Ibs\IbsException;
use App\Ibs\XmlRpcCodec;
use Tests\Fakes\FakeIbs;

$stateFile = getenv('FAKE_IBS_STATE') ?: sys_get_temp_dir().'/fake-ibs.json';
$fake = new FakeIbs('system', getenv('FAKE_IBS_PASS') ?: 'secret');
$fp = fopen($stateFile.'.lock', 'c');
flock($fp, LOCK_EX);
if (is_file($stateFile)) {
    $fake->import(json_decode((string) file_get_contents($stateFile), true) ?: []);
}

if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/__connect')) {
    $fake->connect((string) ($_GET['username'] ?? ''));
    file_put_contents($stateFile, json_encode($fake->export()));
    echo 'ok';

    return;
}

header('Content-Type: text/xml');
try {
    [$method, $params] = XmlRpcCodec::decodeRequest((string) file_get_contents('php://input'));
    $result = $fake->call($method, (array) ($params[0] ?? []));
    echo XmlRpcCodec::encodeResponse($result);
} catch (IbsException $e) {
    echo XmlRpcCodec::encodeFault(1, $e->raw);
} catch (Throwable $e) {
    http_response_code(500);
}
file_put_contents($stateFile, json_encode($fake->export()));
