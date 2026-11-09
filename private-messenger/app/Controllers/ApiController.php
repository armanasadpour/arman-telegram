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
        $r->get('/api/v1/directory', fn (Request $q) => $this->auth($q, fn () => Response::json(['users' => array_map(
            fn ($u) => ['id' => $u['id'], 'display_name' => $u['display_name'], 'username' => $u['username'], 'has_identity' => (bool)$u['has_identity']],
            $this->msgs()->directory((int)$this->s['id']))])));
        $r->get('/api/v1/conversations', fn (Request $q) => $this->auth($q, fn () => $this->conversations()));
        $r->post('/api/v1/conversations', fn (Request $q) => $this->auth($q, fn () => Response::json(['id' => $this->msgs()->openDirect((int)$this->s['id'], (string)($this->json($q)['user_id'] ?? ''))])));
        $r->post('/api/v1/messages', fn (Request $q) => $this->auth($q, fn () => $this->send($q), true));
        $r->post('/api/v1/messages/status', fn (Request $q) => $this->auth($q, fn () => Response::json(['status' => $this->msgs()->status((int)$this->s['session_device_id'], (array)($this->json($q)['ids'] ?? []))]), true));
        $r->get('/api/v1/sync', fn (Request $q) => $this->auth($q, fn () => $this->sync($q), true));
        $r->post('/api/v1/sync/ack', fn (Request $q) => $this->auth($q, fn () => Response::json(['deleted' => $this->msgs()->ack((int)$this->s['id'], (int)$this->s['session_device_id'], (array)($this->json($q)['ids'] ?? []))]), true));
        return $r->dispatch($req);
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
            return self::err(preg_match('/^[a-z_]+$/', $code) ? $code : 'invalid_request', in_array($code, ['identity_exists'], true) ? 409 : 400);
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
        if (!$this->limiter()->hit('claim:' . $this->s['id'], 600, 3600)) {
            return self::err('rate_limited', 429);
        }
        return Response::json(['keys' => (object)$this->keys()->claim((array)($this->json($req)['device_ids'] ?? []), (int)$this->s['session_device_id'])]);
    }

    private function conversations(): Response
    {
        $out = [];
        foreach ($this->msgs()->conversations((int)$this->s['id']) as $c) {
            $out[] = ['id' => $c['id'], 'type' => $c['type'], 'last_activity_at' => $c['last_activity_at'],
                'peer' => $c['peer_id'] ? ['id' => $c['peer_id'], 'display_name' => $c['peer_name'], 'username' => $c['peer_username'], 'active' => $c['peer_status'] === 'active'] : null];
        }
        return Response::json(['conversations' => $out]);
    }

    private function send(Request $req): Response
    {
        if (!$this->limiter()->hit('send:' . $this->s['id'], 3000, 3600)) {
            return self::err('rate_limited', 429);
        }
        return Response::json($this->msgs()->send((int)$this->s['id'], (int)$this->s['session_device_id'], $this->json($req)), 201);
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
