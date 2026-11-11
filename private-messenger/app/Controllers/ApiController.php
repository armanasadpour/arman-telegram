<?php
declare(strict_types=1);

namespace PM\Controllers;

use PM\Auth\UserSessions;
use PM\E2ee\KeyService;
use PM\Http\Request;
use PM\Http\Response;
use PM\Http\Router;
use PM\Messaging\DeviceMismatch;
use PM\Messaging\MessageService;
use PM\Support\Ip;
use PM\Support\UserError;

/**
 * JSON API under <APP_PATH>/api/v1. Carries public keys and ciphertext only — never message plaintext.
 * State-changing requests require the custom header "X-PM-Request: 1" (cross-site forms cannot send it)
 * and a same-origin Origin/Referer.
 */
final class ApiController extends Controller
{
    private ?array $s = null;

    public function handle(Request $req): Response
    {
        $r = new Router();
        $r->get('/api/v1/me', fn (Request $q) => $this->auth($q, fn () => $this->me()));
        $r->get('/api/v1/sessions', fn (Request $q) => $this->auth($q, fn () => $this->sessionsList()));
        $r->get('/api/v1/bootstrap', fn (Request $q) => $this->auth($q, fn () => $this->bootstrap()));
        $r->post('/api/v1/identity', fn (Request $q) => $this->auth($q, fn () => $this->identity($q)));
        $r->get('/api/v1/device/challenge', fn (Request $q) => $this->auth($q, fn () => Response::json(['nonce' => $this->keys()->bindChallenge((int)$this->s['session_id']), 'session' => $this->s['session_public_id']])));
        $r->post('/api/v1/device/bind', fn (Request $q) => $this->auth($q, fn () => $this->bind($q)));
        $r->post('/api/v1/keys/upload', fn (Request $q) => $this->auth($q, fn () => $this->upload($q), true));
        $r->get('/api/v1/users/{id}/keys', fn (Request $q) => $this->auth($q, fn () => $this->userKeys($q), true));
        $r->post('/api/v1/keys/claim', fn (Request $q) => $this->auth($q, fn () => $this->claim($q), true));
        $r->get('/api/v1/users/search', fn (Request $q) => $this->auth($q, fn () => $this->limiter()->hit('usersearch:' . $this->s['id'], $this->app->settings()->int('limits.search_per_hour'), 3600)
            ? Response::json(['users' => self::users($this->msgs()->searchUsers((int)$this->s['id'], $q->queryString('q')))]) : self::err('rate_limited', 429)));
        $r->get('/api/v1/contacts', fn (Request $q) => $this->auth($q, fn () => Response::json(['users' => self::users($this->msgs()->contacts((int)$this->s['id']))])));
        $r->post('/api/v1/contacts', fn (Request $q) => $this->auth($q, fn () => $this->follow($q, true)));
        $r->post('/api/v1/contacts/remove', fn (Request $q) => $this->auth($q, fn () => $this->follow($q, false)));
        $r->get('/api/v1/calls', fn (Request $q) => $this->auth($q, fn () => Response::json(['calls' => $this->calls()->history((int)$this->s['id'])])));
        $r->get('/api/v1/calls/ice', fn (Request $q) => $this->auth($q, fn () => Response::json($this->calls()->iceConfig((string)$this->s['public_id']))));
        $r->post('/api/v1/calls', fn (Request $q) => $this->auth($q, fn () => Response::json(['id' => $this->calls()->start((int)$this->s['id'], (string)($this->json($q)['conversation_id'] ?? ''), ($this->json($q)['video'] ?? false) === true)], 201), true));
        $r->post('/api/v1/calls/{id}/ring', fn (Request $q) => $this->auth($q, fn () => Response::json(['status' => $this->calls()->ring((int)$this->s['id'], $q->param('id'))]), true));
        $r->post('/api/v1/calls/{id}/status', fn (Request $q) => $this->auth($q, fn () => Response::json(['status' => $this->calls()->update((int)$this->s['id'], $q->param('id'),
            (string)($this->json($q)['status'] ?? ''), isset($this->json($q)['reason']) ? (string)$this->json($q)['reason'] : null)]), true));
        $r->get('/api/v1/conversations', fn (Request $q) => $this->auth($q, fn () => $this->conversations()));
        $r->post('/api/v1/conversations', fn (Request $q) => $this->auth($q, fn () => Response::json(['id' => $this->msgs()->openDirect((int)$this->s['id'], (string)($this->json($q)['user_id'] ?? ''))])));
        $r->post('/api/v1/messages', fn (Request $q) => $this->auth($q, fn () => $this->send($q), true));
        $r->post('/api/v1/messages/status', fn (Request $q) => $this->auth($q, fn () => Response::json(['status' => $this->msgs()->status((int)$this->s['session_device_id'], (array)($this->json($q)['ids'] ?? []))]), true));
        $r->get('/api/v1/sync', fn (Request $q) => $this->auth($q, fn () => $this->sync($q), true));
        $r->post('/api/v1/sync/ack', fn (Request $q) => $this->auth($q, fn () => Response::json(['deleted' => $this->msgs()->ack((int)$this->s['id'], (int)$this->s['session_device_id'], (array)($this->json($q)['ids'] ?? []))]), true));
        // ---- phase 6: more devices, linking, recovery backup
        $r->get('/api/v1/me/devices', fn (Request $q) => $this->auth($q, fn () => $this->myDevices()));
        $r->post('/api/v1/devices/add', fn (Request $q) => $this->auth($q, fn () => Response::json(['device_id' => $this->keys()->addDevice($this->s, (int)$this->s['session_id'], (array)($this->json($q)['device'] ?? []), $q->userAgent())], 201)));
        $r->post('/api/v1/devices/{id}/revoke', fn (Request $q) => $this->auth($q, fn () => $this->ok($this->keys()->revokeOwnDevice((int)$this->s['id'], (int)$this->s['session_device_id'], $q->param('id'))), true));
        $r->post('/api/v1/device-links', fn (Request $q) => $this->auth($q, fn () => Response::json(['id' => $this->keys()->linkCreate($this->s, (int)$this->s['session_id'], (array)($this->json($q)['device'] ?? []), $q->userAgent())], 201)));
        $r->get('/api/v1/device-links', fn (Request $q) => $this->auth($q, fn () => Response::json(['requests' => $this->keys()->linkList((int)$this->s['id'])]), true));
        $r->get('/api/v1/device-links/{id}', fn (Request $q) => $this->auth($q, fn () => Response::json($this->keys()->linkStatus((int)$this->s['id'], (int)$this->s['session_id'], $q->param('id')))));
        $r->post('/api/v1/device-links/{id}/approve', fn (Request $q) => $this->auth($q, fn () => $this->ok($this->keys()->linkApprove($this->s, (int)$this->s['session_device_id'], $q->param('id'),
            $this->json($q)['certificate_sig'] ?? null, $this->json($q)['payload_type'] ?? null, $this->json($q)['payload'] ?? null)), true));
        $r->post('/api/v1/device-links/{id}/reject', fn (Request $q) => $this->auth($q, fn () => $this->ok($this->keys()->linkReject((int)$this->s['id'], $q->param('id'))), true));
        $r->get('/api/v1/me/key-backup', fn (Request $q) => $this->auth($q, fn () => ($b = $this->keys()->backupGet((int)$this->s['id'])) ? Response::json($b) : self::err('no_backup', 404)));
        $r->post('/api/v1/me/key-backup', fn (Request $q) => $this->auth($q, fn () => Response::json(['version' => $this->keys()->backupPut((int)$this->s['id'], $this->json($q)['algorithm'] ?? null, $this->json($q)['ciphertext'] ?? null)]), true));
        // ---- groups
        $r->post('/api/v1/groups', fn (Request $q) => $this->auth($q, fn () => Response::json(['id' => $this->groups()->create((int)$this->s['id'], (array)($this->json($q)['members'] ?? []), ($this->json($q)['channel'] ?? false) === true)], 201), true));
        $r->get('/api/v1/groups/{id}/members', fn (Request $q) => $this->auth($q, fn () => Response::json(['members' => $this->groups()->members((int)$this->s['id'], $q->param('id'))]), true));
        $r->post('/api/v1/groups/{id}/members', fn (Request $q) => $this->auth($q, fn () => $this->ok($this->groups()->add((int)$this->s['id'], $q->param('id'), (string)($this->json($q)['user_id'] ?? ''))), true));
        $r->post('/api/v1/groups/{id}/members/remove', fn (Request $q) => $this->auth($q, fn () => $this->ok($this->groups()->remove((int)$this->s['id'], $q->param('id'), (string)($this->json($q)['user_id'] ?? ''))), true));
        $r->post('/api/v1/groups/{id}/leave', fn (Request $q) => $this->auth($q, fn () => $this->ok($this->groups()->remove((int)$this->s['id'], $q->param('id'), (string)$this->s['public_id'])), true));
        $r->post('/api/v1/groups/{id}/role', fn (Request $q) => $this->auth($q, fn () => $this->ok($this->groups()->setRole((int)$this->s['id'], $q->param('id'),
            (string)($this->json($q)['user_id'] ?? ''), (string)($this->json($q)['role'] ?? ''))), true));
        $r->post('/api/v1/attachments', fn (Request $q) => $this->auth($q, fn () => Response::json($this->files()->init((int)$this->s['id'],
            (int)$this->s['session_device_id'], (string)($this->json($q)['conversation_id'] ?? ''), $this->json($q)['size'] ?? null), 201), true));
        $r->post('/api/v1/attachments/{id}/chunks/{index}', fn (Request $q) => $this->auth($q, fn () => $this->chunk($q), true));
        $r->get('/api/v1/attachments/{id}/status', fn (Request $q) => $this->auth($q, fn () => Response::json(['received' => $this->files()->received((int)$this->s['id'], $q->param('id'))]), true));
        $r->post('/api/v1/attachments/{id}/complete', fn (Request $q) => $this->auth($q, fn () => Response::json($this->files()->complete((int)$this->s['id'], $q->param('id'))), true));
        $r->post('/api/v1/attachments/{id}/abort', fn (Request $q) => $this->auth($q, fn () => Response::json(['ok' => $this->files()->abort((int)$this->s['id'], $q->param('id'))]), true));
        $r->get('/api/v1/attachments/{id}/blob', fn (Request $q) => $this->auth($q, fn () => $this->download($q), true));
        $r->get('/api/v1/conversations/{id}/presence', fn (Request $q) => $this->auth($q, fn () => Response::json(['devices' => $this->msgs()->presentDevices((int)$this->s['id'], $q->param('id')),
            'peer_seen' => $this->msgs()->peerSeen((int)$this->s['id'], $q->param('id'))]), true));
        $r->post('/api/v1/conversations/{id}/prefs', fn (Request $q) => $this->auth($q, fn () => Response::json($this->msgs()->setPrefs((int)$this->s['id'], $q->param('id'), $this->json($q))), true));
        $r->get('/api/v1/push', fn (Request $q) => $this->auth($q, fn () => $this->pushConfig($q), true));
        $r->post('/api/v1/push', fn (Request $q) => $this->auth($q, fn () => $this->pushSubscribe($q), true));
        $r->get('/api/v1/me/storage', fn (Request $q) => $this->auth($q, fn () => Response::json($this->files()->userStorage((int)$this->s['id']))));
        $r->post('/api/v1/reports', fn (Request $q) => $this->auth($q, fn () => $this->ok((new \PM\Moderation\ReportService($this->app->db()))->create((int)$this->s['id'],
            (string)($this->json($q)['target_type'] ?? ''), (string)($this->json($q)['target_id'] ?? ''), (string)($this->json($q)['reason'] ?? ''), (string)($this->json($q)['details'] ?? ''))), true));
        $r->post('/api/v1/push/test', fn (Request $q) => $this->auth($q, fn () => $this->pushTest($q), true));
        $r->post('/api/v1/me/avatar', fn (Request $q) => $this->auth($q, fn () => Response::json(['version' => (new \PM\Users\AvatarService($this->app->db()))->put((int)$this->s['id'], $this->json($q)['ciphertext'] ?? null)], 201), true));
        $r->post('/api/v1/me/avatar/remove', fn (Request $q) => $this->auth($q, fn () => $this->ok((new \PM\Users\AvatarService($this->app->db()))->remove((int)$this->s['id'])), true));
        $r->get('/api/v1/users/{id}/avatar', fn (Request $q) => $this->auth($q, function () use ($q) {
            $a = (new \PM\Users\AvatarService($this->app->db()))->get((int)$this->s['id'], $q->param('id'));
            return $a === null ? self::err('avatar_not_found', 404) : Response::json($a)->header('Cache-Control', 'private, no-store');
        }, true));
        $r->post('/api/v1/push/remove', fn (Request $q) => $this->auth($q, fn () => $this->pushRemove(), true));
        $r->post('/api/v1/ephemeral', fn (Request $q) => $this->auth($q, fn () => Response::json(['sent' => $this->msgs()->sendEphemeral((int)$this->s['id'], (int)$this->s['session_device_id'], $this->json($q))]), true));
        return $r->dispatch($req);
    }

    private function groups(): \PM\Groups\GroupService
    {
        return new \PM\Groups\GroupService($this->app->db());
    }

    private function ok(mixed $_ = null): Response
    {
        return Response::json(['ok' => true]);
    }

    private function myDevices(): Response
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT public_id, label, created_at, last_seen_at FROM devices WHERE user_id = ? AND status = 'active' ORDER BY id", [$this->s['id']]) as $d) {
            $out[] = ['id' => $d['public_id'], 'label' => $d['label'], 'created_at' => $d['created_at'], 'last_seen_at' => $d['last_seen_at'],
                'current' => (string)$this->app->db()->scalar('SELECT public_id FROM devices WHERE id = ?', [(int)$this->s['session_device_id']]) === $d['public_id']];
        }
        return Response::json(['devices' => $out]);
    }

    private function calls(): \PM\Calls\CallService
    {
        return new \PM\Calls\CallService($this->app->db(), $this->app->settings());
    }

    private static function users(array $rows): array
    {
        return array_map(fn ($u) => ['id' => $u['id'], 'display_name' => $u['display_name'], 'username' => $u['username'],
            'has_identity' => (bool)$u['has_identity'], 'is_contact' => (bool)$u['is_contact']], $rows);
    }

    private function follow(Request $req, bool $on): Response
    {
        $this->msgs()->follow((int)$this->s['id'], (string)($this->json($req)['user_id'] ?? ''), $on);
        return Response::json(['ok' => true]);
    }

    private function files(): \PM\Files\AttachmentService
    {
        return new \PM\Files\AttachmentService($this->app->db(), $this->app->settings(), $this->app->root . '/storage/attachments');
    }

    private function chunk(Request $req): Response
    {
        if (!preg_match('/^\d{1,7}$/', $req->param('index'))) {
            return self::err('invalid_chunk_index', 400);
        }
        $in = fopen('php://input', 'rb');
        try {
            $this->files()->putChunk((int)$this->s['id'], $req->param('id'), (int)$req->param('index'), strtolower((string)$req->header('X-Chunk-SHA256')), $in);
        } finally {
            fclose($in);
        }
        return Response::json(['ok' => true]);
    }

    private function download(Request $req): Response
    {
        $files = $this->files();
        $a = $files->downloadable((int)$this->s['id'], $req->param('id'));
        if ($a === null) {
            return $files->isExpired($req->param('id')) ? self::err('file_expired', 410) : self::err('not_found', 404);
        }
        $path = $files->filePath($a);
        return is_file($path) ? Response::file($path, $req->header('Range')) : self::err('file_expired', 410);
    }

    private function keys(): KeyService
    {
        return new KeyService($this->app->db(), $this->audit());
    }

    private function msgs(): MessageService
    {
        return new MessageService($this->app->db());
    }

    private static function err(string $code, int $status, array $extra = []): Response
    {
        return Response::json(['error' => ['code' => $code] + $extra], $status);
    }

    private function json(Request $req): array
    {
        $d = json_decode($req->body(), true);
        return is_array($d) ? $d : [];
    }

    /** Authentication, CSRF for POST, optional "device bound" requirement, and error mapping. */
    private function auth(Request $req, callable $fn, bool $needDevice = false): Response
    {
        $this->s = (new UserSessions($this->app->db()))->validate($req->cookie(UserSessions::COOKIE));
        if ($this->s === null) {
            return self::err('unauthenticated', 401);
        }
        if ($req->method === 'POST' && (($req->header('X-PM-Request') ?? '') !== '1' || !$req->isSameOrigin())) {
            return self::err('csrf', 403);
        }
        if ((int)$this->s['must_change_password'] === 1) {
            return self::err('password_change_required', 403);
        }
        if ($needDevice && empty($this->s['session_device_id'])) {
            return self::err('device_not_bound', 409);
        }
        try {
            return $fn();
        } catch (DeviceMismatch $e) {
            return self::err('devices_mismatch', 409, ['missing' => $e->missing, 'stale' => $e->stale]);
        } catch (UserError $e) {
            $code = $e->getMessage();
            if (preg_match('/^service_disabled:([a-z_]+)$/', $code, $m)) {
                return self::err('service_disabled', 403, ['service' => $m[1]]);
            }
            $status = match ($code) {
                'identity_exists' => 409, 'insufficient_storage' => 507, 'file_too_large' => 413, 'user_quota_exceeded' => 413,
                'upload_not_found', 'attachment_not_found', 'call_not_found', 'user_not_found', 'link_not_found', 'group_not_found', 'device_not_found', 'avatar_not_found' => 404,
                'too_many_devices', 'group_full', 'push_disabled' => 409, 'channel_read_only', 'conversation_banned', 'support_closed' => 403, 'not_group_admin', 'not_group_owner' => 403, default => 400,
            };
            return self::err(preg_match('/^[a-z_]+$/', $code) ? $code : 'invalid_request', $status);
        }
    }

    private function me(): Response
    {
        $u = $this->s;
        return Response::json(['id' => $u['public_id'], 'username' => $u['username'], 'display_name' => $u['display_name'],
            'must_change_password' => (bool)$u['must_change_password']]);
    }

    private function sessionsList(): Response
    {
        $out = [];
        foreach ((new UserSessions($this->app->db()))->listForUser((int)$this->s['id']) as $x) {
            $out[] = ['id' => $x['public_id'], 'device' => $x['user_agent_summary'], 'ip' => Ip::displayPrefix($x['ip_prefix']),
                'created_at' => $x['created_at'], 'last_used_at' => $x['last_used_at'], 'current' => $x['public_id'] === $this->s['session_public_id']];
        }
        return Response::json(['sessions' => $out]);
    }

    private function bootstrap(): Response
    {
        $db = $this->app->db();
        $msk = $this->keys()->currentMasterKey((int)$this->s['id']);
        $dev = $this->s['session_device_id'] ? $db->one('SELECT public_id, status FROM devices WHERE id = ?', [$this->s['session_device_id']]) : null;
        return Response::json([
            'me' => ['id' => $this->s['public_id'], 'username' => $this->s['username'], 'display_name' => $this->s['display_name']],
            'session' => $this->s['session_public_id'],
            'identity' => ['msk' => $msk ? rtrim(base64_encode($msk['public_key']), '=') : null],
            'device' => $dev ? ['id' => $dev['public_id'], 'status' => $dev['status'], 'otk_count' => $this->keys()->otkCount((int)$this->s['session_device_id'])] : null,
            'name' => (string)$this->app->settings()->get('app.internal_name'),
            'notify' => ['enabled' => (bool)$this->app->settings()->get('notify.enabled'), 'preview' => (string)$this->app->settings()->get('notify.preview')],
            'retention_days' => $this->app->settings()->int('files.retention_days'),
            'services_off' => (new \PM\Moderation\PolicyService($this->app->db()))->forUser((int)$this->s['id']),
            'support' => ['enabled' => $this->support()->enabled(), 'name' => $this->support()->name(), 'agent' => $this->support()->isAgent((int)$this->s['id'])],
            'version' => is_file($this->app->root . '/VERSION') ? trim((string)file_get_contents($this->app->root . '/VERSION')) : 'dev',
        ]);
    }

    private function identity(Request $req): Response
    {
        $in = $this->json($req);
        $id = $this->keys()->registerIdentity($this->s, (int)$this->s['session_id'], $in, ($in['reset'] ?? false) === true, $req->userAgent());
        return Response::json(['device_id' => $id], 201);
    }

    private function bind(Request $req): Response
    {
        $in = $this->json($req);
        $ok = $this->keys()->bind($this->s, (string)($in['device_id'] ?? ''), $in['signature'] ?? null);
        return $ok ? Response::json(['ok' => true]) : self::err('bind_failed', 403);
    }

    private function upload(Request $req): Response
    {
        $in = $this->json($req);
        return Response::json(['otk_count' => $this->keys()->uploadPrekeys((int)$this->s['session_device_id'], $in['one_time_keys'] ?? [], $in['fallback_key'] ?? null)]);
    }

    private function userKeys(Request $req): Response
    {
        $k = $this->keys()->userKeys($req->param('id'));
        return $k === null ? self::err('user_not_found', 404) : Response::json($k);
    }

    private function claim(Request $req): Response
    {
        if (!$this->limiter()->hit('claim:' . $this->s['id'], $this->app->settings()->int('limits.claim_per_hour'), 3600)) {
            return self::err('rate_limited', 429);
        }
        return Response::json(['keys' => (object)$this->keys()->claim((array)($this->json($req)['device_ids'] ?? []), (int)$this->s['session_device_id'])]);
    }

    private function push(Request $req): \PM\Push\PushService
    {
        return \PM\Push\PushService::for($this->app, $req->host());
    }

    private function pushConfig(Request $req): Response
    {
        $p = $this->push($req);
        $on = $p->enabled();
        return Response::json(['enabled' => $on, 'public_key' => $on ? $p->publicKey() : null,
            'subscribed' => $on && $p->hasSubscription((int)$this->s['session_device_id'])]);
    }

    private function pushSubscribe(Request $req): Response
    {
        $p = $this->push($req);
        if (!$p->enabled()) {
            return self::err('push_disabled', 409);
        }
        $in = $this->json($req);
        $p->subscribe((int)$this->s['session_device_id'], $in['endpoint'] ?? null, $in['keys']['p256dh'] ?? null, $in['keys']['auth'] ?? null);
        return Response::json(['ok' => true], 201);
    }

    private function pushTest(Request $req): Response
    {
        $p = $this->push($req);
        if (!$p->enabled()) {
            return self::err('push_disabled', 409);
        }
        return Response::json($p->testDevice((int)$this->s['session_device_id']));
    }

    private function pushRemove(): Response
    {
        \PM\Push\PushService::for($this->app)->unsubscribe((int)$this->s['session_device_id']);
        return Response::json(['ok' => true]);
    }

    private function support(): \PM\Moderation\SupportService
    {
        return new \PM\Moderation\SupportService($this->app->db(), $this->app->settings());
    }

    private function conversations(): Response
    {
        $out = [];
        $sup = $this->support();
        $sup->ensureFor((int)$this->s['id']);
        $supOn = $sup->enabled();
        $list = $this->msgs()->conversations((int)$this->s['id']);
        $blocked = (new \PM\Moderation\PolicyService($this->app->db()))->forConversations(array_map(fn ($c) => (int)$c['internal_id'], $list));
        $now = time();
        foreach ($list as $c) {
            if ($c['type'] === 'support' && !$supOn) {
                continue;
            }
            $banned = $c['status'] === 'banned' && ($c['banned_until'] === null || strtotime($c['banned_until'] . ' UTC') > $now);
            $extra = ['banned' => $banned ? ['until' => $c['banned_until'], 'reason' => $c['ban_reason']] : null, 'blocked_services' => $blocked[(int)$c['internal_id']] ?? []];
            if ($c['type'] === 'support') {
                $mine = $c['owner_id'] === $this->s['public_id'];
                $extra += ['support' => true, 'verified' => $mine, 'name' => $mine ? $sup->name() : 'پشتیبانی: ' . $c['owner_name'],
                    'support_user' => $mine ? null : ['id' => $c['owner_id'], 'display_name' => $c['owner_name'], 'username' => $c['owner_username']]];
            }
            $out[] = ['id' => $c['id'], 'type' => $c['type'], 'last_activity_at' => $c['last_activity_at'], 'member_count' => (int)$c['member_count'], 'role' => $c['role'],
                'channel' => (int)$c['is_channel'] === 1, 'archived' => (int)$c['archived'] === 1,
                'muted_until' => $c['muted_until'] !== null && strtotime($c['muted_until'] . ' UTC') > time() ? $c['muted_until'] : null,
                'peer' => $c['peer_id'] ? ['id' => $c['peer_id'], 'display_name' => $c['peer_name'], 'username' => $c['peer_username'], 'active' => $c['peer_status'] === 'active'] : null] + $extra;
        }
        return Response::json(['conversations' => $out]);
    }

    private function send(Request $req): Response
    {
        if (!$this->limiter()->hit('send:' . $this->s['id'], $this->app->settings()->int('limits.send_per_hour'), 3600)) {
            return self::err('rate_limited', 429);
        }
        return Response::json($this->msgs()->send((int)$this->s['id'], (int)$this->s['session_device_id'], $this->json($req), $this->files()), 201);
    }

    private function sync(Request $req): Response
    {
        $dev = $this->app->db()->one('SELECT status, last_event_id FROM devices WHERE id = ?', [$this->s['session_device_id']]);
        if ($dev === null || $dev['status'] !== 'active') {
            return self::err('device_revoked', 410);
        }
        $out = $this->msgs()->sync((int)$this->s['id'], (int)$this->s['session_device_id'],
            max(0, (int)$req->queryString('cursor', '0')), max(0, (int)$req->queryString('ncursor', '0')));
        $out['otk_count'] = $this->keys()->otkCount((int)$this->s['session_device_id']);
        return Response::json($out);
    }
}
