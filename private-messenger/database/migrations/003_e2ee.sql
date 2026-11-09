-- Phase 3: binding a login session to a device requires proof of possession of the device key.
ALTER TABLE user_sessions
  ADD COLUMN bind_nonce BINARY(32) NULL AFTER state,
  ADD COLUMN bind_nonce_expires DATETIME(3) NULL AFTER bind_nonce;

-- Store certificates as text (canonical JSON signed by the user's master key).
ALTER TABLE devices MODIFY certificate TEXT NULL;

-- One identity (master key) is current per user: superseded_at IS NULL.
ALTER TABLE user_master_keys ADD COLUMN public_id CHAR(26) NULL AFTER id;

-- Delivery receipts are looked up by message for the sender.
ALTER TABLE message_receipts ADD KEY ix_rcpt_user (user_id, delivered_at);

-- One-time and fallback key ids come from separate counters on the device: make them unique per type.
ALTER TABLE device_public_keys DROP INDEX uq_dpk_device_key, ADD UNIQUE KEY uq_dpk_device_key (device_id, key_type, key_id);
