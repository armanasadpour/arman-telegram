-- Phase 5: contacts ("following") instead of a full user directory; callee on calls.
CREATE TABLE user_contacts (
  user_id             BIGINT UNSIGNED NOT NULL,
  contact_user_id     BIGINT UNSIGNED NOT NULL,
  created_at          DATETIME(3)     NOT NULL,
  PRIMARY KEY (user_id, contact_user_id),
  KEY ix_uc_contact (contact_user_id),
  CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_uc_contact FOREIGN KEY (contact_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE calls ADD COLUMN callee_user_id BIGINT UNSIGNED NULL AFTER caller_user_id,
                  ADD KEY ix_calls_caller (caller_user_id, created_at),
                  ADD KEY ix_calls_callee (callee_user_id, created_at);
