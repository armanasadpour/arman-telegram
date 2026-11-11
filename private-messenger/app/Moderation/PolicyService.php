<?php
declare(strict_types=1);

namespace PM\Moderation;

use PM\Infra\Database;
use PM\Support\Clock;
use PM\Support\UserError;

/**
 * Service switches. A policy row DISABLES one service for everyone, for one user, or inside one
 * group/channel — forever or until a moment. Any matching row wins (most restrictive).
 *
 * What the server can check by itself and what it has to take from the (official) client:
 *  - creating groups/channels, posting in groups/channels, starting calls, attaching files: server-enforced;
 *  - the kind of a message (text / image / video / audio / file) and voice-vs-video for calls are only
 *    known to the sender's device (content is end-to-end encrypted), so the server checks the kind the
 *    client declares and the app enforces it in its UI. A modified client could lie about the kind.
 */
final class PolicyService
{
    public const SERVICES = [
        'text' => 'ارسال پیام متنی',
        'image' => 'ارسال عکس',
        'video' => 'ارسال ویدیو',
        'audio' => 'ارسال پیام صوتی و فایل صوتی',
        'file' => 'ارسال فایل',
        'voice_call' => 'تماس صوتی',
        'video_call' => 'تماس تصویری',
        'group_message' => 'ارسال پیام در گروه/کانال',
        'create_group' => 'ساخت گروه',
        'create_channel' => 'ساخت کانال',
    ];
    /** Services that make sense inside one group/channel. */
    public const CONVERSATION_SERVICES = ['text', 'image', 'video', 'audio', 'file', 'group_message'];

    public function __construct(private readonly Database $db)
    {
    }

    /** The blocking policy row, or null when the service is allowed. */
    public function blocked(string $service, int $userId, ?int $conversationId = null): ?array
    {
        return $this->db->one("SELECT scope, until_at, reason FROM service_policies
                                WHERE service = ? AND (until_at IS NULL OR until_at > ?)
                                  AND (scope = 'global' OR (scope = 'user' AND scope_id = ?) OR (scope = 'conversation' AND scope_id = ?))
                                ORDER BY FIELD(scope, 'conversation', 'user', 'global') LIMIT 1", [$service, Clock::db(), $userId, $conversationId ?? 0]);
    }

    public function assertAllowed(string $service, int $userId, ?int $conversationId = null): void
    {
        if ($this->blocked($service, $userId, $conversationId) !== null) {
            throw new UserError('service_disabled:' . $service);
        }
    }

    /** @return array<string, array{scope:string, until:?string, reason:?string}> disabled services for a user (global + own) */
    public function forUser(int $userId): array
    {
        $out = [];
        foreach ($this->db->all("SELECT service, scope, until_at, reason FROM service_policies WHERE (until_at IS NULL OR until_at > ?)
                                  AND (scope = 'global' OR (scope = 'user' AND scope_id = ?)) ORDER BY FIELD(scope, 'user', 'global')", [Clock::db(), $userId]) as $r) {
            $out[$r['service']] ??= ['scope' => $r['scope'], 'until' => $r['until_at'], 'reason' => $r['reason']];
        }
        return $out;
    }

    /** @return array<int, list<string>> conversation id => disabled services (conversation scope only) */
    public function forConversations(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->db->all("SELECT scope_id, service FROM service_policies WHERE scope = 'conversation' AND scope_id IN ($in) AND (until_at IS NULL OR until_at > ?)",
            [...$ids, Clock::db()]) as $r) {
            $out[(int)$r['scope_id']][] = $r['service'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function list(?string $scope = null, ?int $scopeId = null): array
    {
        $w = '(until_at IS NULL OR until_at > ?)';
        $p = [Clock::db()];
        if ($scope !== null) {
            $w .= ' AND scope = ?';
            $p[] = $scope;
        }
        if ($scopeId !== null) {
            $w .= ' AND scope_id = ?';
            $p[] = $scopeId;
        }
        return $this->db->all("SELECT p.*, u.username, a.username AS admin FROM service_policies p
                                 LEFT JOIN users u ON p.scope = 'user' AND u.id = p.scope_id LEFT JOIN admin_users a ON a.id = p.created_by
                                WHERE $w ORDER BY p.scope, p.service", $p);
    }

    /**
     * Replaces the set of disabled services for one scope.
     * @param list<string> $services services to disable (all others become allowed for this scope)
     */
    public function setScope(string $scope, int $scopeId, array $services, ?string $until, ?string $reason, int $adminId): array
    {
        if (!in_array($scope, ['global', 'user', 'conversation'], true)) {
            throw new UserError('invalid_scope');
        }
        $allowedKeys = $scope === 'conversation' ? self::CONVERSATION_SERVICES : array_keys(self::SERVICES);
        $services = array_values(array_intersect(array_unique($services), $allowedKeys));
        $scopeId = $scope === 'global' ? 0 : $scopeId;
        $now = Clock::db();
        $this->db->transaction(function () use ($scope, $scopeId, $services, $until, $reason, $adminId, $now, $allowedKeys): void {
            $in = implode(',', array_fill(0, count($allowedKeys), '?'));
            $this->db->run("DELETE FROM service_policies WHERE scope = ? AND scope_id = ? AND service IN ($in)", [$scope, $scopeId, ...$allowedKeys]);
            foreach ($services as $svc) {
                $this->db->run('INSERT INTO service_policies (service, scope, scope_id, until_at, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$svc, $scope, $scopeId, $until, $reason !== null ? mb_substr($reason, 0, 300) : null, $adminId, $now]);
            }
        });
        return $services;
    }

    public function remove(int $id): void
    {
        $this->db->run('DELETE FROM service_policies WHERE id = ?', [$id]);
    }

    /** Duration choices used across the admin panel: '' = forever. */
    public const DURATIONS = ['' => 'دائمی', '1h' => '۱ ساعت', '1d' => '۱ روز', '3d' => '۳ روز', '7d' => '۷ روز', '30d' => '۳۰ روز', '90d' => '۹۰ روز', '365d' => '۱ سال'];

    public static function untilFrom(string $code): ?string
    {
        if (!preg_match('/^(\d+)([hd])$/', $code, $m)) {
            return null;
        }
        return Clock::now()->modify('+' . $m[1] . ($m[2] === 'h' ? ' hours' : ' days'))->format('Y-m-d H:i:s');
    }
}
