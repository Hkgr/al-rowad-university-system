-- Technical Office portal (المكتب التقني) — PREFLIGHT. Read-only: no writes.
-- Manual SQL only (project policy: no Laravel migrations).
-- Fully qualified objects; does not depend on the selected phpMyAdmin database.
-- No hard-coded ids: modules, roles and permissions are resolved by code.
-- Organizational placement is reported for information only; it grants nothing.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @db_ready := IF(EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'), 1, 0);

-- 1) Required columns
SELECT required_columns.table_name, required_columns.column_name,
       IF(existing.column_name IS NULL, 'MISSING', 'OK') AS column_state
FROM (
    SELECT 'roles' AS table_name, 'role_code' AS column_name
    UNION ALL SELECT 'roles', 'role_name' UNION ALL SELECT 'roles', 'description'
    UNION ALL SELECT 'roles', 'is_system_role' UNION ALL SELECT 'roles', 'is_active'
    UNION ALL SELECT 'permissions', 'module_id' UNION ALL SELECT 'permissions', 'permission_code'
    UNION ALL SELECT 'permissions', 'permission_name' UNION ALL SELECT 'permissions', 'description'
    UNION ALL SELECT 'permissions', 'is_active'
    UNION ALL SELECT 'role_permissions', 'role_id' UNION ALL SELECT 'role_permissions', 'permission_id'
    UNION ALL SELECT 'role_permissions', 'granted_at'
    UNION ALL SELECT 'user_roles', 'user_id' UNION ALL SELECT 'user_roles', 'role_id'
    UNION ALL SELECT 'user_roles', 'assigned_by_user_id' UNION ALL SELECT 'user_roles', 'assigned_at'
    UNION ALL SELECT 'user_roles', 'is_active'
    UNION ALL SELECT 'users', 'password_hash' UNION ALL SELECT 'users', 'account_status_id'
    UNION ALL SELECT 'users', 'created_by_user_id'
    UNION ALL SELECT 'account_statuses', 'status_code'
    UNION ALL SELECT 'system_modules', 'module_code'
    UNION ALL SELECT 'user_activity_logs', 'action_code'
) required_columns
LEFT JOIN information_schema.columns existing
       ON existing.table_schema = 'alrowad_uni_rust'
      AND existing.table_name = required_columns.table_name
      AND existing.column_name = required_columns.column_name
ORDER BY column_state DESC, required_columns.table_name, required_columns.column_name;

-- 2) Unique keys the application relies on (duplicates are prevented by the database)
SELECT expected.table_name, expected.column_name,
       IF(COUNT(s.index_name) > 0, 'OK', 'MISSING') AS unique_state
FROM (
    SELECT 'roles' AS table_name, 'role_code' AS column_name
    UNION ALL SELECT 'permissions', 'permission_code'
    UNION ALL SELECT 'users', 'username'
    UNION ALL SELECT 'users', 'email'
) expected
LEFT JOIN information_schema.statistics s
       ON s.table_schema = 'alrowad_uni_rust' AND s.table_name = expected.table_name
      AND s.column_name = expected.column_name AND s.non_unique = 0
GROUP BY expected.table_name, expected.column_name;

SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_in_key, 'user_roles' AS table_name
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'user_roles' AND non_unique = 0
GROUP BY index_name
UNION ALL
SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index), 'role_permissions'
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'role_permissions' AND non_unique = 0
GROUP BY index_name;

-- 3) Module, account statuses and super_admin role
SELECT 'module users_permissions' AS item, COUNT(*) AS row_count, MAX(module_id) AS resolved_id, MAX(is_active) AS is_active
FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'users_permissions'
UNION ALL
SELECT 'role super_admin', COUNT(*), MAX(role_id), MAX(is_active)
FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'super_admin'
UNION ALL
SELECT CONCAT('account status ', status_code), COUNT(*), MAX(account_status_id), MAX(is_active)
FROM `alrowad_uni_rust`.`account_statuses` WHERE status_code IN ('active', 'disabled') GROUP BY status_code;

-- 4) Target objects: ABSENT / COMPATIBLE / CONFLICT
SELECT 'role technical_team' AS object_name,
       COUNT(*) AS row_count,
       CASE WHEN COUNT(*) = 0 THEN 'ABSENT'
            WHEN COUNT(*) = 1 AND SUM(is_active = 1 AND is_system_role = 1) = 1 THEN 'COMPATIBLE'
            ELSE 'CONFLICT' END AS object_state,
       MAX(IF(COALESCE(description, '') LIKE '%[technical-team-portal]%', 1, 0)) AS owned_by_this_package
FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'technical_team'
UNION ALL
SELECT CONCAT('permission ', codes.code),
       COUNT(p.permission_id),
       CASE WHEN COUNT(p.permission_id) = 0 THEN 'ABSENT'
            WHEN COUNT(p.permission_id) = 1 AND SUM(p.is_active = 1 AND sm.module_code = 'users_permissions') = 1 THEN 'COMPATIBLE'
            ELSE 'CONFLICT' END,
       MAX(IF(COALESCE(p.description, '') LIKE '%[technical-team-portal]%', 1, 0))
FROM (SELECT 'technical_portal.access' AS code UNION ALL SELECT 'user_accounts.view' UNION ALL SELECT 'user_accounts.manage') codes
LEFT JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = codes.code
LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
GROUP BY codes.code;

-- 5) technical_team must not already carry administration permissions (CONFLICT if > 0)
SELECT COUNT(*) AS technical_team_restricted_permissions
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'technical_team'
  AND (p.permission_code LIKE 'users\_permissions.%' OR p.permission_code = 'system_settings.manage');

-- 6) Existing holders of the "manage permissions" power outside super_admin (review manually)
SELECT r.role_code, p.permission_code
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE r.role_code <> 'super_admin'
  AND (p.permission_code LIKE 'users\_permissions.%' OR p.permission_code LIKE 'user\_accounts.%');

-- 7) Active super_admin accounts (must be >= 1 before and after)
SELECT COUNT(DISTINCT u.user_id) AS active_super_admin_accounts
FROM `alrowad_uni_rust`.`users` u
JOIN `alrowad_uni_rust`.`account_statuses` s ON s.account_status_id = u.account_status_id AND s.status_code = 'active'
JOIN `alrowad_uni_rust`.`user_roles` ur ON ur.user_id = u.user_id AND ur.is_active = 1
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id AND r.role_code = 'super_admin' AND r.is_active = 1;

-- 8) Information only: organizational placement of المكتب التقني (grants nothing)
SELECT u.organizational_unit_id, u.unit_code, u.unit_name, parent.unit_code AS parent_code, parent.unit_name AS parent_name
FROM `alrowad_uni_rust`.`organizational_units` u
LEFT JOIN `alrowad_uni_rust`.`organizational_units` parent ON parent.organizational_unit_id = u.parent_unit_id
WHERE u.unit_code = '715' OR u.unit_name = 'المكتب التقني';

SELECT @db_ready AS db_ready;
