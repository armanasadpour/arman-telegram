<?php
declare(strict_types=1);

namespace PM\Calls;

use PM\Settings\Settings;

/**
 * TURN from Cloudflare Realtime (free monthly allowance), without a server of your own.
 * Cloudflare hands out short-lived TURN credentials through its API: the server asks for them with the
 * «TURN key ID» and «API token» (kept sealed with APP_KEY), caches them for a few hours and gives them to
 * the apps. The API token never leaves this server.
 * What Cloudflare can see: which IP addresses relay how much data and when. Call content stays encrypted
 * end to end (DTLS-SRTP); a TURN relay cannot decrypt it.
 */
final class CloudflareTurn
{
    public const DEFAULT_API = 'https://rtc.live.cloudflare.com';
    private const TTL = 43200;      // credentials valid 12 h
    private const REFRESH = 21600;  // ask for new ones after 6 h (every handed-out set is then valid ≥ 6 h)

    public function __construct(private readonly Settings $settings, private readonly string $api = self::DEFAULT_API,
                                private readonly ?string $caInfo = null)
    {
    }

    public function configured(): bool
    {
        return trim((string)$this->settings->get('calls.cf_key_id')) !== '' && $this->settings->secret('calls.cf_token') !== '';
    }

    /**
     * ICE servers from the cache, refreshed from Cloudflare when old.
     * @return array{servers: list<array<string,mixed>>, error: string}
     */
    public function iceServers(bool $forceRefresh = false): array
    {
        if (!$this->configured()) {
            return ['servers' => [], 'error' => 'not_configured'];
        }
        $keyId = trim((string)$this->settings->get('calls.cf_key_id'));
        $cache = json_decode($this->settings->secret('calls.cf_cache') ?: 'null', true);
        $valid = is_array($cache) && ($cache['key'] ?? '') === $keyId && (int)($cache['exp'] ?? 0) > time() + 600;
        if ($valid && !$forceRefresh && (int)$cache['at'] > time() - self::REFRESH) {
            return ['servers' => $cache['servers'], 'error' => ''];
        }
        try {
            $servers = $this->fetch($keyId, $this->settings->secret('calls.cf_token'));
            $this->settings->setSecret('calls.cf_cache', (string)json_encode(['key' => $keyId, 'at' => time(), 'exp' => time() + self::TTL, 'servers' => $servers]));
            return ['servers' => $servers, 'error' => ''];
        } catch (\Throwable $e) {
            error_log('[pm] cloudflare turn: ' . $e->getMessage());
            // Cloudflare unreachable for a moment: keep using credentials that are still valid.
            return ['servers' => $valid ? $cache['servers'] : [], 'error' => $e->getMessage()];
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetch(string $keyId, string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $keyId)) {
            throw new \RuntimeException('invalid TURN key ID');
        }
        $url = rtrim($this->api, '/') . '/v1/turn/keys/' . $keyId . '/credentials/generate-ice-servers';
        [$status, $body] = $this->post($url, $token, (string)json_encode(['ttl' => self::TTL]));
        if ($status === 404) { // older API form
            [$status, $body] = $this->post(rtrim($this->api, '/') . '/v1/turn/keys/' . $keyId . '/credentials/generate', $token, (string)json_encode(['ttl' => self::TTL]));
        }
        if ($status === 401 || $status === 403) {
            throw new \RuntimeException('Cloudflare rejected the API token (HTTP ' . $status . ')');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Cloudflare answered HTTP ' . $status);
        }
        $j = json_decode($body, true);
        $list = $j['iceServers'] ?? null;
        if (is_array($list) && isset($list['urls'])) {
            $list = [$list]; // the older endpoint returns one object
        }
        if (!is_array($list) || $list === []) {
            throw new \RuntimeException('unexpected answer from Cloudflare');
        }
        return self::clean($list);
    }

    /**
     * Keeps well-formed entries and drops port-53 URLs: browsers block that port, and trying it only delays
     * the connection (Cloudflare's own advice).
     * @return list<array<string,mixed>>
     */
    public static function clean(array $list): array
    {
        $out = [];
        foreach ($list as $s) {
            if (!is_array($s)) {
                continue;
            }
            $urls = array_values(array_filter((array)($s['urls'] ?? []), fn ($u) => is_string($u)
                && preg_match('/^(stun|turns?):[A-Za-z0-9.\-]+(:\d+)?(\?transport=(udp|tcp))?$/', $u) && !preg_match('/:53(\?|$)/', $u)));
            if ($urls === []) {
                continue;
            }
            $e = ['urls' => $urls];
            if (isset($s['username'], $s['credential']) && is_string($s['username']) && is_string($s['credential'])) {
                $e['username'] = $s['username'];
                $e['credential'] = $s['credential'];
            }
            $out[] = $e;
        }
        return $out;
    }

    /** @return array{0:int, 1:string} */
    private function post(string $url, string $token, string $json): array
    {
        $ch = curl_init($url);
        $o = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_FOLLOWLOCATION => false];
        if ($this->caInfo) {
            $o[CURLOPT_CAINFO] = $this->caInfo;
        }
        $proxy = $this->settings->secret('push.proxy'); // the same optional proxy as push notifications (host cannot reach abroad)
        if ($proxy !== '') {
            $o[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $o);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('cannot reach Cloudflare: ' . $err);
        }
        return [$status, (string)$body];
    }
}
