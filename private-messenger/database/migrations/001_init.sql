-- =====================================================================
-- Private Messenger — migration 001 (initial schema)
-- Target: MySQL 8.0+ / MariaDB 10.6+, InnoDB, utf8mb4
-- Phase-3+ tables (devices keys, envelopes, attachments, calls, groups) are
-- created now so later phases only add columns; their shape may still change.
--
-- Invariants (must never be violated):
--   * No column stores message plaintext, file plaintext, or private keys
--     in plaintext. Ciphertext columns are opaque to the server.
--   * All timestamps are UTC.
--   * External identifiers (public_id) are random; internal ids never
--     appear in URLs.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id                 CHAR(26)        NOT NULL,              -- ULID
  username                  VARCHAR(64)     NOT NULL,              -- lowercase, used for password login
  phone_e164                VARCHAR(20)     NULL,                  -- required only for OTP login modes
  password_hash             VARCHAR(255)    NULL,                  -- Argon2id; authentication ONLY, never key material
  must_change_password      TINYINT(1)      NOT NULL DEFAULT 1,
  password_changed_at       DATETIME(3)     NULL,
  failed_login_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until              DATETIME(3)     NULL,
  display_name              VARCHAR(100)    NOT NULL,
  bio                       VARCHAR(500)    NULL,
  avatar_attachment_id      BIGINT UNSIGNED NULL,                  -- NOT E2EE in v1 (documented)
  status                    ENUM('pending','active','suspended','blocked','deleted') NOT NULL DEFAULT 'pending',
  permanent_file_retention  TINYINT(1)      NOT NULL DEFAULT 0,    -- super admin only
  retention_changed_by      BIGINT UNSIGNED NULL,                  -- admin_users.id
  retention_changed_at      DATETIME(3)     NULL,
  registration_lock_until   DATETIME(3)     NULL,                  -- see threat model T-06
  last_seen_at              DATETIME        NULL,                  -- minute precision
  last_seen_visibility      ENUM('contacts','nobody') NOT NULL DEFAULT 'contacts',
  identity_reset_count      INT UNSIGNED    NOT NULL DEFAULT 0,
  created_by_admin_id       BIGINT UNSIGNED NULL,
  created_at                DATETIME(3)     NOT NULL,
  activated_at              DATETIME(3)     NULL,
  updated_at                DATETIME(3)     NOT NULL,
  deleted_at                DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_public_id (public_id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_phone (phone_e164),
  KEY ix_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_requests (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone_e164      VARCHAR(20)     NOT NULL,
  user_id         BIGINT UNSIGNED NULL,                 -- NULL => unknown phone, no SMS is sent
  challenge_hash  BINARY(32)      NOT NULL,             -- SHA-256 of the browser challenge cookie
  purpose         ENUM('login','second_factor') NOT NULL DEFAULT 'login',
  code_hash       BINARY(32)      NOT NULL,             -- HMAC-SHA256(app key, challenge|phone|code)
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  status          ENUM('pending','consumed','expired','locked') NOT NULL DEFAULT 'pending',
  ip              VARBINARY(16)   NOT NULL,
  provider_ref    VARCHAR(100)    NULL,
  created_at      DATETIME(3)     NOT NULL,
  expires_at      DATETIME(3)     NOT NULL,
  consumed_at     DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_otp_challenge (challenge_hash),
  KEY ix_otp_phone_created (phone_e164, created_at),
  KEY ix_otp_ip_created (ip, created_at),
  KEY ix_otp_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic fixed-window rate limiter (keys are hashed: e.g. "otp:ip:<ip>")
CREATE TABLE rate_limits (
  bucket_hash     BINARY(32)      NOT NULL,
  window_start    DATETIME        NOT NULL,
  hits            INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_hash, window_start),
  KEY ix_rl_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Devices & public keys
-- ---------------------------------------------------------------------
CREATE TABLE devices (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id           CHAR(26)        NOT NULL,
  user_id             BIGINT UNSIGNED NOT NULL,
  label               VARCHAR(100)    NOT NULL,          -- e.g. "Chrome / Android" (user-visible)
  status              ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
  identity_key        VARBINARY(32)   NULL,              -- Curve25519 (Olm)
  signing_key         VARBINARY(32)   NULL,              -- Ed25519 fingerprint key
  certificate         VARBINARY(1024) NULL,              -- canonical device cert, signed by user MSK
  certificate_sig     VARBINARY(64)   NULL,
  protocols           VARCHAR(100)    NOT NULL DEFAULT 'olm-v1',
  last_event_id       BIGINT UNSIGNED NOT NULL DEFAULT 0, -- fast "anything new?" check for /sync
  created_at          DATETIME(3)     NOT NULL,
  activated_at        DATETIME(3)     NULL,
  last_seen_at        DATETIME        NULL,
  revoked_at          DATETIME(3)     NULL,
  revoked_by          ENUM('user','admin','system') NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_devices_public_id (public_id),
  KEY ix_devices_user_status (user_id, status),
  CONSTRAINT fk_devices_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time and fallback prekeys. A one-time key is handed out exactly once.
CREATE TABLE device_public_keys (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id           BIGINT UNSIGNED NOT NULL,
  key_type            ENUM('one_time','fallback') NOT NULL,
  key_id              VARCHAR(64)     NOT NULL,
  public_key          VARBINARY(32)   NOT NULL,
  signature           VARBINARY(64)   NOT NULL,          -- signed by device signing_key
  created_at          DATETIME(3)     NOT NULL,
  claimed_at          DATETIME(3)     NULL,
  claimed_by_device   BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dpk_device_key (device_id, key_id),
  KEY ix_dpk_unclaimed (device_id, key_type, claimed_at),
  CONSTRAINT fk_dpk_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User Master Signing Key (public part). History kept to explain key-change warnings.
CREATE TABLE user_master_keys (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             BIGINT UNSIGNED NOT NULL,
  public_key          VARBINARY(32)   NOT NULL,          -- Ed25519
  device_list_version INT UNSIGNED    NOT NULL DEFAULT 0,
  device_list_sig     VARBINARY(64)   NULL,              -- MSK signature over active device list + version
  created_at          DATETIME(3)     NOT NULL,
  superseded_at       DATETIME(3)     NULL,
  superseded_reason   ENUM('user_reset','admin_reset','recovery_lost') NULL,
  PRIMARY KEY (id),
  KEY ix_umk_user_current (user_id, superseded_at),
  CONSTRAINT fk_umk_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Encrypted key backup. Encrypted client-side with a key derived from the
-- user's Recovery Key. The server can never decrypt it.
CREATE TABLE key_backups (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             BIGINT UNSIGNED NOT NULL,
  master_key_id       BIGINT UNSIGNED NOT NULL,
  version             INT UNSIGNED    NOT NULL,
  algorithm           VARCHAR(50)     NOT NULL,          -- e.g. "pm-backup-v1/hkdf-aes256gcm"
  kdf_params          VARBINARY(255)  NULL,              -- only when passphrase mode (Argon2id)
  ciphertext          BLOB            NOT NULL,          -- small (< 64KB)
  created_at          DATETIME(3)     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_kb_user_version (user_id, version),
  CONSTRAINT fk_kb_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_kb_msk FOREIGN KEY (master_key_id) REFERENCES user_master_keys(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New-device linking handshake (opaque, E2EE payloads between the two devices)
CREATE TABLE device_link_requests (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id           CHAR(26)        NOT NULL,
  user_id             BIGINT UNSIGNED NOT NULL,
  new_device_id       BIGINT UNSIGNED NOT NULL,
  approver_device_id  BIGINT UNSIGNED NULL,
  status              ENUM('waiting','approved','rejected','expired') NOT NULL DEFAULT 'waiting',
  created_at          DATETIME(3)     NOT NULL,
  expires_at          DATETIME(3)     NOT NULL,
  completed_at        DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dlr_public (public_id),
  KEY ix_dlr_user (user_id, status),
  CONSTRAINT fk_dlr_new FOREIGN KEY (new_device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id           CHAR(26)        NOT NULL,
  user_id             BIGINT UNSIGNED NOT NULL,
  device_id           BIGINT UNSIGNED NULL,              -- NULL until device registered
  token_hash          BINARY(32)      NOT NULL,          -- SHA-256(token)
  state               ENUM('device_pending','active') NOT NULL DEFAULT 'device_pending',
  ip_prefix           VARBINARY(16)   NULL,              -- truncated (/24 or /48) for display
  user_agent_summary  VARCHAR(150)    NULL,
  created_at          DATETIME(3)     NOT NULL,
  last_used_at        DATETIME        NOT NULL,
  expires_at          DATETIME(3)     NOT NULL,
  revoked_at          DATETIME(3)     NULL,
  revoked_reason      ENUM('logout','user_revoke','admin_revoke','account_status','expired') NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_us_token (token_hash),
  UNIQUE KEY uq_us_public (public_id),
  KEY ix_us_user (user_id, revoked_at),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Conversations
-- ---------------------------------------------------------------------
CREATE TABLE conversations (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id           CHAR(26)        NOT NULL,
  type                ENUM('direct','group') NOT NULL,   -- 'group' reserved for Phase 6
  direct_pair_hash    BINARY(32)      NULL,              -- SHA-256(min(uid)|max(uid)); one DM per pair
  created_by          BIGINT UNSIGNED NOT NULL,
  created_at          DATETIME(3)     NOT NULL,
  last_activity_at    DATETIME        NULL,              -- minute precision
  PRIMARY KEY (id),
  UNIQUE KEY uq_conv_public (public_id),
  UNIQUE KEY uq_conv_pair (direct_pair_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversation_members (
  conversation_id     BIGINT UNSIGNED NOT NULL,
  user_id             BIGINT UNSIGNED NOT NULL,
  role                ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
  joined_at           DATETIME(3)     NOT NULL,
  left_at             DATETIME(3)     NULL,
  archived            TINYINT(1)      NOT NULL DEFAULT 0,
  muted_until         DATETIME        NULL,              -- server needs this to suppress push
  PRIMARY KEY (conversation_id, user_id),
  KEY ix_cm_user (user_id, left_at),
  CONSTRAINT fk_cm_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id),
  CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Messages: one metadata row + one opaque envelope per recipient device.
-- Edits, deletes, reactions, pins, read receipts are ALSO messages here
-- (encrypted event types); the server cannot tell them apart.
-- ---------------------------------------------------------------------
CREATE TABLE messages (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id     BIGINT UNSIGNED NOT NULL,
  sender_user_id      BIGINT UNSIGNED NOT NULL,
  sender_device_id    BIGINT UNSIGNED NOT NULL,
  client_msg_id       BINARY(16)      NOT NULL,          -- idempotency key from client
  envelope_count      SMALLINT UNSIGNED NOT NULL,
  notify              TINYINT(1)      NOT NULL DEFAULT 1, -- client hint: trigger push or not
  created_at          DATETIME(3)     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_msg_idem (sender_device_id, client_msg_id),
  KEY ix_msg_conv (conversation_id, id),
  KEY ix_msg_created (created_at),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_envelopes (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,  -- also the sync cursor
  message_id          BIGINT UNSIGNED NOT NULL,
  recipient_device_id BIGINT UNSIGNED NOT NULL,
  protocol_version    SMALLINT UNSIGNED NOT NULL,
  olm_type            TINYINT UNSIGNED NOT NULL,                 -- 0 = pre-key, 1 = normal
  ciphertext          MEDIUMBLOB      NOT NULL,
  created_at          DATETIME(3)     NOT NULL,
  expires_at          DATETIME(3)     NOT NULL,                  -- undelivered TTL (default 30d)
  PRIMARY KEY (id),
  KEY ix_env_device (recipient_device_id, id),
  KEY ix_env_expires (expires_at),
  CONSTRAINT fk_env_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_env_dev FOREIGN KEY (recipient_device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Envelopes are DELETED once the recipient device acknowledges them.

-- Server-observable delivery state only (a device fetched+acked).
-- "Read" is an encrypted event and is NOT stored here.
CREATE TABLE message_receipts (
  message_id          BIGINT UNSIGNED NOT NULL,
  user_id             BIGINT UNSIGNED NOT NULL,
  delivered_at        DATETIME(3)     NOT NULL,
  PRIMARY KEY (message_id, user_id),
  CONSTRAINT fk_rcpt_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived encrypted signals: call signaling (SDP/ICE) and typing.
CREATE TABLE ephemeral_envelopes (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id     BIGINT UNSIGNED NOT NULL,
  sender_device_id    BIGINT UNSIGNED NOT NULL,
  recipient_device_id BIGINT UNSIGNED NOT NULL,
  channel             ENUM('call','typing') NOT NULL,
  call_id             BIGINT UNSIGNED NULL,
  olm_type            TINYINT UNSIGNED NOT NULL,
  ciphertext          BLOB            NOT NULL,
  created_at          DATETIME(3)     NOT NULL,
  expires_at          DATETIME(3)     NOT NULL,                  -- 15s typing, 90s call
  PRIMARY KEY (id),
  KEY ix_eph_device (recipient_device_id, id),
  KEY ix_eph_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security/system events delivered via /sync (no content).
CREATE TABLE notifications (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             BIGINT UNSIGNED NOT NULL,
  type                ENUM('new_login','device_added','device_revoked','contact_identity_changed',
                           'contact_device_added','admin_reset','account_status','retention_changed') NOT NULL,
  subject_user_id     BIGINT UNSIGNED NULL,
  subject_device_id   BIGINT UNSIGNED NULL,
  created_at          DATETIME(3)     NOT NULL,
  seen_at             DATETIME(3)     NULL,
  PRIMARY KEY (id),
  KEY ix_notif_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_subscriptions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id           BIGINT UNSIGNED NOT NULL,
  endpoint            VARCHAR(1000)   NOT NULL,
  p256dh              VARBINARY(65)   NOT NULL,
  auth_secret         VARBINARY(16)   NOT NULL,
  created_at          DATETIME(3)     NOT NULL,
  last_success_at     DATETIME        NULL,
  failure_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_push_device (device_id),
  CONSTRAINT fk_push_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Attachments (encrypted blobs) — retention policy fields per spec §12
-- ---------------------------------------------------------------------
CREATE TABLE attachments (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id           CHAR(32)        NOT NULL,          -- 128-bit random hex
  storage_key         CHAR(64)        NOT NULL,          -- 256-bit random hex; path on disk/S3
  storage_backend     ENUM('local','s3') NOT NULL DEFAULT 'local',
  purpose             ENUM('message','avatar') NOT NULL DEFAULT 'message',
  conversation_id     BIGINT UNSIGNED NULL,
  uploader_user_id    BIGINT UNSIGNED NOT NULL,
  uploader_device_id  BIGINT UNSIGNED NOT NULL,
  size_bytes          BIGINT UNSIGNED NOT NULL,          -- ciphertext size (declared, then verified)
  chunk_size          INT UNSIGNED    NOT NULL,
  chunk_count         INT UNSIGNED    NOT NULL,
  ciphertext_sha256   BINARY(32)      NULL,              -- set on completion
  upload_status       ENUM('initiated','uploading','complete','aborted','expired','deleted') NOT NULL DEFAULT 'initiated',
  created_at          DATETIME(3)     NOT NULL,          -- upload started
  last_chunk_at       DATETIME(3)     NULL,
  uploaded_at         DATETIME(3)     NULL,              -- upload completed (retention base)
  expires_at          DATETIME(3)     NULL,              -- NULL only while permanent retention applies
  retention_permanent TINYINT(1)      NOT NULL DEFAULT 0,
  retention_set_by    BIGINT UNSIGNED NULL,              -- admin_users.id
  retention_set_at    DATETIME(3)     NULL,
  deleted_at          DATETIME(3)     NULL,              -- physical deletion done
  delete_reason       ENUM('expired','user','admin','aborted','account_deleted') NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_att_public (public_id),
  UNIQUE KEY uq_att_storage (storage_key),
  KEY ix_att_expiry (upload_status, expires_at),
  KEY ix_att_uploader (uploader_user_id, upload_status),
  KEY ix_att_conv (conversation_id),
  KEY ix_att_stale (upload_status, last_chunk_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Received chunk bookkeeping for resumable uploads (data itself is on disk).
CREATE TABLE upload_chunks (
  attachment_id       BIGINT UNSIGNED NOT NULL,
  chunk_index         INT UNSIGNED    NOT NULL,
  size_bytes          INT UNSIGNED    NOT NULL,
  sha256              BINARY(32)      NOT NULL,
  received_at         DATETIME(3)     NOT NULL,
  PRIMARY KEY (attachment_id, chunk_index),
  CONSTRAINT fk_uc_att FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which conversations may download a blob (original + forwards).
CREATE TABLE attachment_grants (
  attachment_id       BIGINT UNSIGNED NOT NULL,
  conversation_id     BIGINT UNSIGNED NOT NULL,
  message_id          BIGINT UNSIGNED NULL,
  granted_at          DATETIME(3)     NOT NULL,
  PRIMARY KEY (attachment_id, conversation_id),
  KEY ix_ag_conv (conversation_id),
  CONSTRAINT fk_ag_att FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived download tokens (hashed)
CREATE TABLE download_tokens (
  token_hash          BINARY(32)      NOT NULL,
  attachment_id       BIGINT UNSIGNED NOT NULL,
  user_id             BIGINT UNSIGNED NOT NULL,
  expires_at          DATETIME(3)     NOT NULL,
  PRIMARY KEY (token_hash),
  KEY ix_dt_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE storage_usage (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  measured_at             DATETIME        NOT NULL,
  quota_bytes             BIGINT UNSIGNED NULL,          -- from cPanel UAPI or admin input
  quota_source            ENUM('uapi','admin','unknown') NOT NULL,
  partition_free_bytes    BIGINT UNSIGNED NULL,          -- disk_free_space() (NOT the quota)
  used_attachments_bytes  BIGINT UNSIGNED NOT NULL,
  used_partial_bytes      BIGINT UNSIGNED NOT NULL,
  expired_pending_bytes   BIGINT UNSIGNED NOT NULL,      -- expired but not yet physically deleted
  permanent_bytes         BIGINT UNSIGNED NOT NULL,
  file_count              INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_su_time (measured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Calls (minimal metadata; media type is NOT stored)
-- ---------------------------------------------------------------------
CREATE TABLE calls (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id           CHAR(26)        NOT NULL,
  conversation_id     BIGINT UNSIGNED NOT NULL,
  caller_user_id      BIGINT UNSIGNED NOT NULL,
  status              ENUM('ringing','active','ended','missed','rejected','cancelled','failed') NOT NULL,
  end_reason          ENUM('hangup','timeout','ice_failed','busy','error') NULL,
  created_at          DATETIME(3)     NOT NULL,
  answered_at         DATETIME(3)     NULL,
  ended_at            DATETIME(3)     NULL,
  duration_seconds    INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_calls_public (public_id),
  KEY ix_calls_conv (conversation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE call_participants (
  call_id             BIGINT UNSIGNED NOT NULL,
  user_id             BIGINT UNSIGNED NOT NULL,
  device_id           BIGINT UNSIGNED NULL,              -- device that answered
  joined_at           DATETIME(3)     NULL,
  left_at             DATETIME(3)     NULL,
  PRIMARY KEY (call_id, user_id),
  CONSTRAINT fk_cp_call FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Groups (Phase 6 — reserved; final shape depends on MLS vs Megolm)
-- ---------------------------------------------------------------------
CREATE TABLE group_key_epochs (
  conversation_id     BIGINT UNSIGNED NOT NULL,
  epoch               BIGINT UNSIGNED NOT NULL,
  commit_ciphertext   MEDIUMBLOB      NULL,              -- MLS commit / handshake, opaque
  created_by_device   BIGINT UNSIGNED NOT NULL,
  created_at          DATETIME(3)     NOT NULL,
  PRIMARY KEY (conversation_id, epoch)                   -- enforces strict commit ordering
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Administration
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  name                VARCHAR(50)     NOT NULL,
  is_super            TINYINT(1)      NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permission catalogue. There is deliberately NO permission for reading content.
CREATE TABLE permissions (
  id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  code                VARCHAR(64)     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_perm_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id             INT UNSIGNED    NOT NULL,
  permission_id       INT UNSIGNED    NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_users (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username            VARCHAR(64)     NOT NULL,
  password_hash       VARCHAR(255)    NOT NULL,          -- password_hash(PASSWORD_ARGON2ID)
  display_name        VARCHAR(100)    NOT NULL DEFAULT '',
  totp_secret_enc     VARBINARY(128)  NULL,              -- sodium secretbox with app key
  totp_enabled        TINYINT(1)      NOT NULL DEFAULT 0,
  totp_last_step      BIGINT UNSIGNED NULL,              -- replay protection
  role_id             INT UNSIGNED    NOT NULL,
  status              ENUM('active','disabled') NOT NULL DEFAULT 'active',
  failed_attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until        DATETIME        NULL,
  last_login_at       DATETIME(3)     NULL,
  created_at          DATETIME(3)     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_username (username),
  CONSTRAINT fk_admin_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_sessions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id            BIGINT UNSIGNED NOT NULL,
  token_hash          BINARY(32)      NOT NULL,
  ip                  VARBINARY(16)   NOT NULL,
  user_agent_summary  VARCHAR(150)    NULL,
  created_at          DATETIME(3)     NOT NULL,
  last_used_at        DATETIME        NOT NULL,
  expires_at          DATETIME(3)     NOT NULL,          -- absolute (8h); idle timeout checked on last_used_at
  revoked_at          DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_as_token (token_hash),
  CONSTRAINT fk_as_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only, hash-chained audit trail. No content, ever.
CREATE TABLE audit_logs (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type          ENUM('admin','system','user','installer') NOT NULL,
  actor_id            BIGINT UNSIGNED NULL,
  action              VARCHAR(64)     NOT NULL,          -- e.g. user.create, user.retention.enable
  target_type         VARCHAR(32)     NULL,
  target_id           BIGINT UNSIGNED NULL,
  details             JSON            NULL,              -- before/after of non-content fields
  ip                  VARBINARY(16)   NULL,
  created_at          DATETIME(3)     NOT NULL,
  prev_hash           BINARY(32)      NOT NULL,
  entry_hash          BINARY(32)      NOT NULL,          -- SHA-256(prev_hash || canonical(entry))
  PRIMARY KEY (id),
  KEY ix_audit_target (target_type, target_id),
  KEY ix_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
  setting_key         VARCHAR(100)    NOT NULL,
  setting_value       JSON            NOT NULL,
  updated_at          DATETIME(3)     NOT NULL,
  updated_by          BIGINT UNSIGNED NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cron_runs (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job                 VARCHAR(50)     NOT NULL,          -- retention.cleanup, envelopes.expire, ...
  trigger_source      ENUM('cron','manual','pseudo') NOT NULL,
  started_at          DATETIME(3)     NOT NULL,
  finished_at         DATETIME(3)     NULL,
  status              ENUM('running','ok','partial','failed') NOT NULL,
  stats               JSON            NULL,              -- files deleted, bytes freed, errors
  error_message       VARCHAR(500)    NULL,
  PRIMARY KEY (id),
  KEY ix_cron_job (job, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-row mutex for cron/pseudo-cron (SELECT ... FOR UPDATE or GET_LOCK())
CREATE TABLE job_locks (
  job                 VARCHAR(50)     NOT NULL,
  locked_until        DATETIME(3)     NOT NULL,
  owner               CHAR(32)        NOT NULL,
  PRIMARY KEY (job)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: permission catalogue (no content-read permission exists)
INSERT INTO permissions (code) VALUES
 ('dashboard.view'),('users.view'),('users.create'),('users.edit'),('users.activate'),
 ('users.suspend'),('users.block'),('users.delete'),('users.sessions.revoke'),
 ('users.devices.revoke'),('users.identity.reset'),('users.retention.manage'),
 ('settings.view'),('settings.edit'),('storage.view'),('storage.cleanup.run'),
 ('audit.view'),('admins.manage'),('system.capabilities.view');
-- 'users.retention.manage' and 'admins.manage' are granted ONLY to the super role.
