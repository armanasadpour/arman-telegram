-- Phase 7: non-content daily counters, new permissions.
CREATE TABLE daily_stats (
  day                 DATE            NOT NULL,
  metric              VARCHAR(40)     NOT NULL,          -- e.g. login.failed (never content)
  value               BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, metric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (code) VALUES ('reports.view'), ('backup.download'), ('system.paths.manage');
-- 'backup.download' and 'system.paths.manage' are super-only (enforced in code as well).

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.name IN ('ناظر', 'مدیر کاربران') AND p.code = 'reports.view';
