-- Restore role_permissions and organizational units from the mandatory backup.
-- This intentionally does not guess the site's former authorization customization.
DROP TABLE IF EXISTS user_access_scopes;
