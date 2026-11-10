-- Phase 8: channels, Web Push subscriptions (one per device), archive/mute synced across devices.
ALTER TABLE conversations ADD COLUMN is_channel TINYINT(1) NOT NULL DEFAULT 0 AFTER type;
ALTER TABLE push_subscriptions ADD COLUMN endpoint_hash BINARY(32) NULL AFTER endpoint;
ALTER TABLE push_subscriptions ADD UNIQUE KEY uq_push_endpoint (endpoint_hash);
