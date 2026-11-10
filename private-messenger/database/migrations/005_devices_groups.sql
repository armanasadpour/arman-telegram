-- Phase 6: device linking payloads, more notification types.
ALTER TABLE device_link_requests
  ADD COLUMN creator_session_id BIGINT UNSIGNED NULL AFTER approver_device_id,
  ADD COLUMN payload_type TINYINT UNSIGNED NULL AFTER status,
  ADD COLUMN payload MEDIUMBLOB NULL AFTER payload_type;

ALTER TABLE notifications MODIFY type ENUM('new_login','device_added','device_revoked','contact_identity_changed',
  'contact_device_added','admin_reset','account_status','retention_changed','device_link_request','group_changed') NOT NULL;
