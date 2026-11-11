-- Per-user storage quota for file attachments. NULL = use the default from settings; 0 = unlimited for this user.
ALTER TABLE users ADD COLUMN storage_quota_bytes BIGINT UNSIGNED NULL AFTER permanent_file_retention;
