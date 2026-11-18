-- Per-user exemption from the login attempt limits (set by an admin): no per-IP limit and no account lock for this user.
ALTER TABLE users ADD COLUMN login_unlimited TINYINT(1) NOT NULL DEFAULT 0 AFTER locked_until;
