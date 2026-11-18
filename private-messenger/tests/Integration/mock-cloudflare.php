<?php
// Test-only stand-in for the Cloudflare Realtime TURN API (php -S 127.0.0.1:2098 mock-cloudflare.php).
// Key «cfkey12345», token «cf-test-token»; hands out credentials for the local coturn (secret «pm-turn-test»).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !preg_match('#^/v1/turn/keys/([^/]+)/credentials/generate-ice-servers$#', $path, $m)) {
    http_response_code(404); echo '{"error":"not found"}'; return;
}
if ($m[1] !== 'cfkey12345' || ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer cf-test-token') {
    http_response_code(401); echo '{"error":"unauthorized"}'; return;
}
$ttl = (int)(json_decode((string)file_get_contents('php://input'), true)['ttl'] ?? 0);
if ($ttl < 60) { http_response_code(400); echo '{"error":"ttl"}'; return; }
$user = (time() + $ttl) . ':cf';
echo json_encode(['iceServers' => [
    ['urls' => ['stun:127.0.0.1:3478', 'stun:127.0.0.1:53']],
    ['urls' => ['turn:127.0.0.1:3478?transport=udp', 'turn:127.0.0.1:53?transport=udp', 'turn:127.0.0.1:3478?transport=tcp'],
     'username' => $user, 'credential' => base64_encode(hash_hmac('sha1', $user, 'pm-turn-test', true))],
]]);
