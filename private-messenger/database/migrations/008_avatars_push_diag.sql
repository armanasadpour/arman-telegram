-- Profile photos: stored ONLY as ciphertext (AES-256-GCM on the user's device; the key travels end-to-end encrypted).
CREATE TABLE user_avatars (
  user_id             BIGINT UNSIGNED NOT NULL,
  version             INT UNSIGNED    NOT NULL,
  ciphertext          MEDIUMBLOB      NOT NULL,
  updated_at          DATETIME(3)     NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_avatar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Push diagnostics: what the push service answered last time.
ALTER TABLE push_subscriptions ADD COLUMN last_status SMALLINT NULL, ADD COLUMN last_error VARCHAR(300) NULL, ADD COLUMN last_attempt_at DATETIME NULL;
