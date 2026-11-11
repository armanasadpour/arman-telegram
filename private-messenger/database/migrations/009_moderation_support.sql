-- Moderation, service switches, appeals, abuse reports and the support chat. No message content anywhere.
ALTER TABLE users ADD COLUMN status_until DATETIME NULL AFTER status, ADD COLUMN status_reason VARCHAR(500) NULL AFTER status_until;
ALTER TABLE conversations MODIFY type ENUM('direct','group','support') NOT NULL;
ALTER TABLE conversations ADD COLUMN status ENUM('active','banned') NOT NULL DEFAULT 'active' AFTER is_channel,
  ADD COLUMN banned_until DATETIME NULL AFTER status, ADD COLUMN ban_reason VARCHAR(500) NULL AFTER banned_until;
ALTER TABLE calls ADD COLUMN is_video TINYINT(1) NULL AFTER callee_user_id;

-- A row DISABLES a service for everyone (scope global, scope_id 0), one user, or one conversation, until a time or forever.
CREATE TABLE service_policies (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  service             VARCHAR(32)     NOT NULL,
  scope               ENUM('global','user','conversation') NOT NULL,
  scope_id            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  until_at            DATETIME        NULL,
  reason              VARCHAR(300)    NULL,
  created_by          BIGINT UNSIGNED NULL,
  created_at          DATETIME(3)     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_policy (service, scope, scope_id),
  KEY ix_policy_scope (scope, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_appeals (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             BIGINT UNSIGNED NOT NULL,
  message             VARCHAR(2000)   NOT NULL,
  status              ENUM('open','accepted','rejected') NOT NULL DEFAULT 'open',
  admin_reply         VARCHAR(1000)   NULL,
  handled_by          BIGINT UNSIGNED NULL,
  created_at          DATETIME(3)     NOT NULL,
  handled_at          DATETIME(3)     NULL,
  PRIMARY KEY (id),
  KEY ix_appeal_user (user_id, created_at),
  KEY ix_appeal_status (status, created_at),
  CONSTRAINT fk_appeal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abuse_reports (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_user_id    BIGINT UNSIGNED NOT NULL,
  target_type         ENUM('user','conversation') NOT NULL,
  target_id           BIGINT UNSIGNED NOT NULL,
  reason              VARCHAR(32)     NOT NULL,
  details             VARCHAR(1000)   NULL,
  status              ENUM('open','reviewed','dismissed') NOT NULL DEFAULT 'open',
  handled_by          BIGINT UNSIGNED NULL,
  created_at          DATETIME(3)     NOT NULL,
  handled_at          DATETIME(3)     NULL,
  PRIMARY KEY (id),
  KEY ix_report_status (status, created_at),
  KEY ix_report_target (target_type, target_id),
  CONSTRAINT fk_report_user FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (code) VALUES ('conversations.view'), ('conversations.manage'), ('services.manage'), ('abuse.handle'), ('appeals.handle'), ('support.manage');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.name = 'مدیر کاربران' AND p.code IN ('conversations.view', 'abuse.handle', 'appeals.handle');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.name = 'ناظر' AND p.code IN ('conversations.view');
