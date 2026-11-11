<?php
declare(strict_types=1);

namespace PM\Push;

use PM\App;
use PM\Infra\Database;
use PM\Settings\Settings;
use PM\Support\Clock;
use PM\Support\UserError;

/**
 * Background notifications through the browsers' push services.
 * What a push service (Google, Mozilla, Apple, Microsoft) can learn: that some device got a notification at
 * a given time. The payload is encrypted to the device and contains only {type, conversation id}; never names,
 * never message content (which the server does not have anyway).
 */
final class PushService
{
    /** @var list<array{0:string,1:int,2:int,3:int}> queued until the HTTP response has been sent */
    private static array $queue = [];

    public function __construct(private readonly Database $db, private readonly Settings $settings, private readonly string $appKey,
                                private readonly string $host, private readonly array $env = [])
    {
    }

    public static function for(App $app, string $host = ''): self
    {
        return new self($app->db(), $app->settings(), $app->key(), $host, [
            'extra' => array_filter(array_map('trim', explode(',', strtolower((string)$app->env('PUSH_EXTRA_HOSTS', ''))))),
            'ca' => $app->env('PUSH_CAINFO'),
        ]);
    }

    public function enabled(): bool
    {
        return (bool)$this->settings->get('push.enabled') && WebPush::available();
    }

    /** Server key pair, created on first use. The private key is sealed with APP_KEY in the database. */
    public function publicKey(): string
    {
        $pub = (string)$this->settings->get('push.vapid_public');
        if ($pub === '' || !$this->settings->hasSecret('push.vapid_private')) {
            [$pem, $raw] = WebPush::generateVapidKeys();
            $this->settings->setSecret('push.vapid_private', $pem);
            $this->settings->set('push.vapid_public', $pub = WebPush::b64u($raw));
        }
        return $pub;
    }

    private function sender(): WebPush
    {
        $pub = WebPush::b64uDecode($this->publicKey());
        return new WebPush($this->settings->secret('push.vapid_private'), (string)$pub, 'https://' . ($this->host !== '' ? $this->host : 'localhost'),
            $this->env['extra'] ?? [], $this->env['ca'] ?? null, $this->settings->secret('push.proxy'));
    }

    public function reachability(): array
    {
        return $this->sender()->reachability();
    }

    /**
     * Sends a test push to one device right now and reports what the push service answered.
     * @return array{status:int, error:string, host:string, subscribed:bool}
     */
    public function testDevice(int $deviceId): array
    {
        $r = $this->db->one('SELECT id, endpoint, p256dh, auth_secret FROM push_subscriptions WHERE device_id = ?', [$deviceId]);
        if ($r === null) {
            return ['status' => 0, 'error' => 'not_subscribed', 'host' => '', 'subscribed' => false];
        }
        $res = $this->sendAndRecord([(int)$r['id'] => ['id' => (int)$r['id'], 'endpoint' => $r['endpoint'], 'p256dh' => $r['p256dh'], 'auth' => $r['auth_secret'],
            'payload' => json_encode(['t' => 't']), 'ttl' => 300, 'urgency' => 'high']]);
        [$status, $err] = $res[(int)$r['id']] ?? [0, 'not sent'];
        return ['status' => $status, 'error' => $err, 'host' => (string)parse_url($r['endpoint'], PHP_URL_HOST), 'subscribed' => true];
    }

    public function subscribe(int $deviceId, mixed $endpoint, mixed $p256dh, mixed $auth): void
    {
        $key = is_string($p256dh) ? WebPush::b64uDecode($p256dh) : false;
        $sec = is_string($auth) ? WebPush::b64uDecode($auth) : false;
        if (!is_string($endpoint) || strlen($endpoint) > 1000 || $key === false || strlen($key) !== 65 || $sec === false || strlen($sec) !== 16) {
            throw new UserError('invalid_subscription');
        }
        if (!$this->sender()->endpointAllowed($endpoint)) {
            throw new UserError('push_service_not_allowed');
        }
        $hash = hash('sha256', $endpoint, true);
        $this->db->run('DELETE FROM push_subscriptions WHERE endpoint_hash = ? OR device_id = ?', [$hash, $deviceId]);
        $this->db->insert('INSERT INTO push_subscriptions (device_id, endpoint, endpoint_hash, p256dh, auth_secret, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$deviceId, $endpoint, $hash, $key, $sec, Clock::db()]);
    }

    public function unsubscribe(int $deviceId): void
    {
        $this->db->run('DELETE FROM push_subscriptions WHERE device_id = ?', [$deviceId]);
    }

    public function hasSubscription(int $deviceId): bool
    {
        return $this->db->scalar('SELECT id FROM push_subscriptions WHERE device_id = ?', [$deviceId]) !== null;
    }

    /** Queues a notification for all members' devices (except the sender's device). Sent after the response. */
    public static function queue(string $type, int $conversationId, int $senderUserId, int $senderDeviceId, array $extra = []): void
    {
        self::$queue[] = [$type, $conversationId, $senderUserId, $senderDeviceId, $extra];
    }

    /** Called by the kernel after the HTTP response is complete. */
    public static function flush(App $app, string $host): void
    {
        if (self::$queue === []) {
            return;
        }
        $q = self::$queue;
        self::$queue = [];
        try {
            $svc = self::for($app, $host);
            if ($svc->enabled()) {
                $svc->deliver($q);
            }
        } catch (\Throwable $e) {
            error_log('[pm] push: ' . $e->getMessage());
        }
    }

    /** @param list<array{0:string,1:int,2:int,3:int}> $events */
    public function deliver(array $events): array
    {
        $jobs = [];
        $now = Clock::db();
        $online = Clock::now()->modify('-20 seconds')->format('Y-m-d H:i:s');
        foreach ($events as $ev) {
            [$type, $convId, $senderUser, $senderDevice] = $ev;
            $extra = $ev[4] ?? [];
            $conv = $this->db->one('SELECT public_id, type FROM conversations WHERE id = ?', [$convId]);
            if ($conv === null) {
                continue;
            }
            $isCall = in_array($type, ['call', 'call_video', 'call_missed'], true);
            if ($type === 'call_answered' || $type === 'call_closed') {
                // the callee's OTHER devices: stop their ringing notification
                $rows = $this->db->all("SELECT ps.id, ps.endpoint, ps.p256dh, ps.auth_secret, ps.device_id FROM devices d JOIN push_subscriptions ps ON ps.device_id = d.id
                                         WHERE d.user_id = ? AND d.status = 'active' AND d.id <> ?", [$senderUser, $senderDevice]);
            } else {
                // Members who did not mute the conversation; their devices that are NOT currently open (an open app notifies by itself).
                // Calls ring on every device, muted or not, open or not: a backgrounded phone browser freezes the page.
                $rows = $this->db->all("SELECT ps.id, ps.endpoint, ps.p256dh, ps.auth_secret, ps.device_id
                                          FROM conversation_members cm
                                          JOIN devices d ON d.user_id = cm.user_id AND d.status = 'active'
                                          JOIN push_subscriptions ps ON ps.device_id = d.id
                                         WHERE cm.conversation_id = ? AND cm.left_at IS NULL AND d.id <> ? AND cm.user_id <> ?
                                           AND (? OR cm.muted_until IS NULL OR cm.muted_until < ?)
                                           AND (? OR d.last_seen_at IS NULL OR d.last_seen_at < ?)",
                    [$convId, $senderDevice, $senderUser, $isCall ? 1 : 0, $now, $isCall ? 1 : 0, $online]);
            }
            $t = ['call' => 'c', 'call_video' => 'c', 'call_missed' => 'x', 'call_answered' => 'a', 'call_closed' => 'z'][$type] ?? 'm';
            $payload = json_encode(['t' => $t, 'c' => $conv['public_id'], 'g' => $conv['type'] === 'direct' ? 0 : 1] + ($type === 'call_video' ? ['v' => 1] : []) + $extra);
            foreach ($rows as $r) {
                $jobs[(int)$r['id']] = ['id' => (int)$r['id'], 'endpoint' => $r['endpoint'], 'p256dh' => $r['p256dh'], 'auth' => $r['auth_secret'],
                    'payload' => $payload, 'ttl' => in_array($type, ['call', 'call_video'], true) ? 30 : ($type === 'call_answered' ? 120 : 86400), 'urgency' => $type === 'message' ? 'normal' : 'high'];
            }
        }
        if ($jobs === []) {
            return [];
        }
        return array_map(fn ($r) => $r[0], $this->sendAndRecord($jobs));
    }

    /** @return array<int, array{0:int,1:string}> */
    private function sendAndRecord(array $jobs): array
    {
        $res = $this->sender()->sendMany(array_values($jobs));
        $now = Clock::now()->format('Y-m-d H:i:s');
        foreach ($res as $id => [$status, $err]) {
            if ($status >= 200 && $status < 300) {
                $this->db->run('UPDATE push_subscriptions SET last_success_at = ?, failure_count = 0, last_status = ?, last_error = NULL, last_attempt_at = ? WHERE id = ?', [$now, $status, $now, $id]);
            } elseif ($status === 404 || $status === 410 || $status === -1) {
                $this->db->run('DELETE FROM push_subscriptions WHERE id = ?', [$id]); // expired / unsubscribed: the app subscribes again on its next start
            } else {
                $this->db->run('UPDATE push_subscriptions SET failure_count = failure_count + 1, last_status = ?, last_error = ?, last_attempt_at = ? WHERE id = ?',
                    [$status, mb_substr($err, 0, 300), $now, $id]);
                $this->db->run('DELETE FROM push_subscriptions WHERE id = ? AND failure_count >= 200', [$id]);
            }
        }
        \PM\Admin\Stats::bump($this->db, 'push.sent', count(array_filter($res, fn ($r) => $r[0] >= 200 && $r[0] < 300)));
        \PM\Admin\Stats::bump($this->db, 'push.failed', count(array_filter($res, fn ($r) => $r[0] < 200 || $r[0] >= 300)));
        return $res;
    }

    /** For the admin panel: subscriptions per push service with the last answer. */
    public function overview(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT endpoint, last_status, last_error, last_attempt_at, last_success_at FROM push_subscriptions') as $r) {
            $h = (string)parse_url($r['endpoint'], PHP_URL_HOST);
            $o = &$out[$h];
            $o ??= ['host' => $h, 'devices' => 0, 'ok' => 0, 'failing' => 0, 'last_error' => null, 'last_success' => null];
            $o['devices']++;
            if ($r['last_status'] !== null && (int)$r['last_status'] >= 200 && (int)$r['last_status'] < 300) { $o['ok']++; }
            if ($r['last_status'] !== null && ((int)$r['last_status'] < 200 || (int)$r['last_status'] >= 300)) { $o['failing']++; $o['last_error'] = 'HTTP ' . $r['last_status'] . ' ' . $r['last_error']; }
            if ($r['last_success_at'] !== null && $r['last_success_at'] > ($o['last_success'] ?? '')) { $o['last_success'] = $r['last_success_at']; }
            unset($o);
        }
        return array_values($out);
    }
}
