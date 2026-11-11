<?php
declare(strict_types=1);

namespace PM\Moderation;

use PM\Infra\Database;
use PM\Support\Clock;
use PM\Support\UserError;

/** Abuse reports about a person, group or channel. They carry only what the reporter types — never messages. */
final class ReportService
{
    public const REASONS = [
        'spam' => 'هرزنامه یا تبلیغ',
        'abuse' => 'توهین، آزار یا تهدید',
        'illegal' => 'محتوای غیرقانونی',
        'fraud' => 'کلاهبرداری',
        'fake' => 'جعل هویت',
        'other' => 'سایر',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $reporterId, string $type, string $publicId, string $reason, string $details): void
    {
        if (!isset(self::REASONS[$reason]) || !in_array($type, ['user', 'conversation'], true) || mb_strlen($details) > 1000) {
            throw new UserError('invalid_report');
        }
        if ($type === 'user') {
            $id = $this->db->scalar("SELECT id FROM users WHERE public_id = ? AND status <> 'deleted'", [$publicId]);
        } else { // only members can report a group/channel
            $id = $this->db->scalar('SELECT c.id FROM conversations c JOIN conversation_members m ON m.conversation_id = c.id
                                      WHERE c.public_id = ? AND m.user_id = ? AND m.left_at IS NULL', [$publicId, $reporterId]);
        }
        if ($id === null || ($type === 'user' && (int)$id === $reporterId)) {
            throw new UserError('invalid_report');
        }
        if ((int)$this->db->scalar('SELECT COUNT(*) FROM abuse_reports WHERE reporter_user_id = ? AND created_at > ?',
                [$reporterId, Clock::now()->modify('-1 day')->format('Y-m-d H:i:s')]) >= 20) {
            throw new UserError('rate_limited');
        }
        $this->db->insert('INSERT INTO abuse_reports (reporter_user_id, target_type, target_id, reason, details, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$reporterId, $type, (int)$id, $reason, trim($details) ?: null, Clock::db()]);
    }

    public function list(string $status = 'open', ?string $type = null, ?int $targetId = null): array
    {
        $w = ['(? = \'all\' OR r.status = ?)'];
        $p = [$status, $status];
        if ($type !== null) {
            $w[] = 'r.target_type = ? AND r.target_id = ?';
            array_push($p, $type, $targetId);
        }
        return $this->db->all("SELECT r.*, ru.username AS reporter, tu.username AS target_user, tc.public_id AS target_conv, tc.is_channel, ad.username AS admin
                                 FROM abuse_reports r JOIN users ru ON ru.id = r.reporter_user_id
                                 LEFT JOIN users tu ON r.target_type = 'user' AND tu.id = r.target_id
                                 LEFT JOIN conversations tc ON r.target_type = 'conversation' AND tc.id = r.target_id
                                 LEFT JOIN admin_users ad ON ad.id = r.handled_by
                                WHERE " . implode(' AND ', $w) . ' ORDER BY r.status = \'open\' DESC, r.id DESC LIMIT 300', $p);
    }

    public function setStatus(int $adminId, int $id, string $status): void
    {
        if (!in_array($status, ['reviewed', 'dismissed', 'open'], true)) {
            throw new UserError('invalid_status');
        }
        $this->db->run('UPDATE abuse_reports SET status = ?, handled_by = ?, handled_at = ? WHERE id = ?', [$status, $adminId, Clock::db(), $id]);
    }
}
