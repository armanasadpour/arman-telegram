-- Default roles. The super role implicitly holds every permission (checked in code).
-- No role, and no permission, grants access to message or file content: none exists.
INSERT INTO roles (name, is_super) VALUES ('مدیرکل', 1), ('مدیر کاربران', 0), ('ناظر', 0);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.name = 'مدیر کاربران'
   AND p.code IN ('dashboard.view','users.view','users.create','users.edit','users.activate','users.suspend',
                  'users.block','users.sessions.revoke','users.devices.revoke','storage.view','system.capabilities.view');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.name = 'ناظر'
   AND p.code IN ('dashboard.view','users.view','audit.view','storage.view','settings.view','system.capabilities.view');
