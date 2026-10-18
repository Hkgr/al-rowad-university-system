-- Technical Office: account editing + activity feed — APPLY. Manual, idempotent, fail-closed.
-- Creates (only when ABSENT), tagged [technical-accounts-activity]:
--   permission user_accounts.holder_name.manage  (correct the linked employee/student NAME only)
--   permission system_activity.view              (filtered, sanitized activity feed)
--   role_permissions technical_team -> both; super_admin -> both (explicit, super_admin also bypasses)
--   indexes idx_ual_created_at, idx_ual_module_created (user_activity_logs), idx_lal_attempted_at (login_audit_logs)
-- Does NOT: create users or roles, change passwords, grant users_permissions.*, touch log rows.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @apply_ready := 0;
SET @apply_complete := 0;
SET @tag := '[technical-accounts-activity]';

SET @db_ready := IF(EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'), 1, 0);
SET @tables_ok := IF(@db_ready = 1, (
    SELECT IF(COUNT(*) = 4, 1, 0) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust' AND table_name IN ('user_activity_logs', 'login_audit_logs', 'permissions', 'role_permissions')
), 0);
SET @module_ok := IF(@tables_ok = 1, (SELECT IF(COUNT(*) = 1 AND SUM(is_active = 1) = 1, 1, 0) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'users_permissions'), 0);
SET @technical_role_ok := IF(@tables_ok = 1, (SELECT IF(COUNT(*) = 1 AND SUM(is_active = 1) = 1, 1, 0) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'technical_team'), 0);
SET @super_admin_ok := IF(@tables_ok = 1, (SELECT IF(COUNT(*) = 1 AND SUM(is_active = 1) = 1, 1, 0) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'super_admin'), 0);
SET @unique_keys_ok := IF(@tables_ok = 1, IF(
    EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'permissions' AND column_name = 'permission_code' AND non_unique = 0)
    AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'role_permissions' AND index_name = 'uq_role_permission' AND non_unique = 0), 1, 0), 0);
SET @perm_conflicts := IF(@tables_ok = 1, (
    SELECT COUNT(*) FROM (
        SELECT p.permission_code FROM `alrowad_uni_rust`.`permissions` p
        LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')
        GROUP BY p.permission_code
        HAVING COUNT(*) <> 1 OR SUM(p.is_active = 1 AND sm.module_code = 'users_permissions') <> 1
    ) conflicting
), 99);
SET @restricted_on_role := IF(@tables_ok = 1, (
    SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
    JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'technical_team'
    JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
    WHERE p.permission_code LIKE 'users\_permissions.%' OR p.permission_code = 'system_settings.manage'
), 99);
SET @perms_absent_at_start := IF(@tables_ok = 1, (SELECT COUNT(*) = 0 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')), 0);

SET @apply_ready := IF(@module_ok = 1 AND @technical_role_ok = 1 AND @super_admin_ok = 1 AND @unique_keys_ok = 1 AND @perm_conflicts = 0 AND @restricted_on_role = 0, 1, 0);

-- Indexes: DDL cannot sit in the transaction; each runs only when ready and is idempotent.
SET @sql := IF(@apply_ready = 1, 'CREATE INDEX IF NOT EXISTS `idx_ual_created_at` ON `alrowad_uni_rust`.`user_activity_logs` (`created_at`, `activity_log_id`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@apply_ready = 1, 'CREATE INDEX IF NOT EXISTS `idx_ual_module_created` ON `alrowad_uni_rust`.`user_activity_logs` (`module_code`, `created_at`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@apply_ready = 1, 'CREATE INDEX IF NOT EXISTS `idx_lal_attempted_at` ON `alrowad_uni_rust`.`login_audit_logs` (`attempted_at`)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

START TRANSACTION;

INSERT INTO `alrowad_uni_rust`.`permissions` (module_id, permission_code, permission_name, description, is_active, created_at, updated_at)
SELECT sm.module_id, defs.code, defs.name, CONCAT(defs.description, ' ', @tag), 1, NOW(), NOW()
FROM (
    SELECT 'user_accounts.holder_name.manage' AS code, 'Account holder name correction' AS name,
           'Correct first/last/father/mother name on the employee or student record the account is already linked to. No other field, no new person.' AS description
    UNION ALL SELECT 'system_activity.view', 'System activity view', 'Read the filtered, sanitized activity feed of the Technical Office (read-only).'
) defs
JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_code = 'users_permissions' AND sm.is_active = 1
WHERE @apply_ready = 1 AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` p WHERE p.permission_code = defs.code);

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, NOW()
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')
WHERE @apply_ready = 1 AND r.role_code IN ('technical_team', 'super_admin')
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id);

SET @apply_complete := IF(@apply_ready = 1, IF(
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view') AND is_active = 1) = 2
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
         JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code IN ('technical_team', 'super_admin')
         JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
         WHERE p.permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')) = 4, 1, 0), 0);

-- Incomplete run: remove only what this run could have created (tagged, absent at start).
DELETE FROM `alrowad_uni_rust`.`role_permissions`
WHERE @apply_ready = 1 AND @apply_complete = 0 AND @perms_absent_at_start = 1
  AND permission_id IN (SELECT p.permission_id FROM `alrowad_uni_rust`.`permissions` p
                        WHERE p.permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')
                          AND COALESCE(p.description, '') LIKE '%[technical-accounts-activity]%');
DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @apply_ready = 1 AND @apply_complete = 0 AND @perms_absent_at_start = 1
  AND permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')
  AND COALESCE(description, '') LIKE '%[technical-accounts-activity]%';

COMMIT;

SELECT IF(@apply_ready = 0, 'BLOCKED', IF(@apply_complete = 1, 'APPLIED', 'BLOCKED_INCOMPLETE')) AS apply_status,
       @apply_ready AS apply_ready, @module_ok AS users_permissions_module_ok, @technical_role_ok AS technical_team_role_ok,
       @super_admin_ok AS super_admin_role_ok, @unique_keys_ok AS unique_keys_ok, @perm_conflicts AS permission_conflicts,
       @restricted_on_role AS technical_team_restricted_permissions;
