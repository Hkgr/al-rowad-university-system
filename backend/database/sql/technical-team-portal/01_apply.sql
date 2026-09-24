-- Technical Office portal (المكتب التقني) — APPLY. Manual and idempotent.
-- Fail-closed: writes run only when @apply_ready = 1.
-- Fully qualified objects; no DATABASE(), stored procedures, DELIMITER or SIGNAL.
-- Recomputes every safety condition itself; does not rely on 00_preflight.sql variables.
-- No hard-coded ids: module/role/permission ids are resolved by code.
-- Rows created here carry the description tag [technical-team-portal].
--
-- Creates (only when ABSENT):
--   role        technical_team            (is_system_role = 1)
--   permissions technical_portal.access, user_accounts.view, user_accounts.manage (module users_permissions)
--   role_permissions technical_team -> the three permissions
--                    super_admin    -> user_accounts.view, user_accounts.manage
-- Does NOT: create users, set passwords, assign roles (user_roles),
--           grant users_permissions.* to anyone, touch organizational_units,
--           or rewrite conflicting objects.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @apply_ready := 0;
SET @apply_complete := 0;
SET @tag := '[technical-team-portal]';

SET @db_ready := IF(EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'), 1, 0);

SET @missing_required_columns := IF(@db_ready = 1, (
    SELECT COUNT(*) FROM (
        SELECT 'roles' AS t, 'role_code' AS c UNION ALL SELECT 'roles', 'role_name'
        UNION ALL SELECT 'roles', 'description' UNION ALL SELECT 'roles', 'is_system_role'
        UNION ALL SELECT 'roles', 'is_active' UNION ALL SELECT 'permissions', 'module_id'
        UNION ALL SELECT 'permissions', 'permission_code' UNION ALL SELECT 'permissions', 'permission_name'
        UNION ALL SELECT 'permissions', 'description' UNION ALL SELECT 'permissions', 'is_active'
        UNION ALL SELECT 'role_permissions', 'role_id' UNION ALL SELECT 'role_permissions', 'permission_id'
        UNION ALL SELECT 'role_permissions', 'granted_at' UNION ALL SELECT 'system_modules', 'module_code'
    ) req
    LEFT JOIN information_schema.columns ex
           ON ex.table_schema = 'alrowad_uni_rust' AND ex.table_name = req.t AND ex.column_name = req.c
    WHERE ex.column_name IS NULL
), 1);

SET @structure_ok := IF(@db_ready = 1 AND @missing_required_columns = 0, 1, 0);

SET @unique_keys_ok := IF(@structure_ok = 1, (
    SELECT IF(
        EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'roles' AND column_name = 'role_code' AND non_unique = 0)
        AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'permissions' AND column_name = 'permission_code' AND non_unique = 0)
        AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'role_permissions' AND index_name = 'uq_role_permission' AND non_unique = 0),
    1, 0)
), 0);

SET @module_ok := IF(@structure_ok = 1, (
    SELECT IF(COUNT(*) = 1 AND SUM(is_active = 1) = 1, 1, 0)
    FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'users_permissions'
), 0);

SET @super_admin_role_ok := IF(@structure_ok = 1, (
    SELECT IF(COUNT(*) = 1 AND SUM(is_active = 1) = 1, 1, 0)
    FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'super_admin'
), 0);

SET @role_state := IF(@structure_ok = 1, (
    SELECT CASE WHEN COUNT(*) = 0 THEN 'ABSENT'
                WHEN COUNT(*) = 1 AND SUM(is_active = 1 AND is_system_role = 1) = 1 THEN 'COMPATIBLE'
                ELSE 'CONFLICT' END
    FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'technical_team'
), 'UNKNOWN');

SET @perm_conflicts := IF(@structure_ok = 1, (
    SELECT COUNT(*) FROM (
        SELECT codes.code
        FROM (SELECT 'technical_portal.access' AS code UNION ALL SELECT 'user_accounts.view' UNION ALL SELECT 'user_accounts.manage') codes
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = codes.code
        LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        GROUP BY codes.code
        HAVING COUNT(*) <> 1 OR SUM(p.is_active = 1 AND sm.module_code = 'users_permissions') <> 1
    ) conflicting
), 99);

SET @perms_absent_at_start := IF(@structure_ok = 1, (
    SELECT COUNT(*) = 0 FROM `alrowad_uni_rust`.`permissions`
    WHERE permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage')
), 0);

-- technical_team must never hold the "manage permissions" power or system settings.
SET @restricted_on_role := IF(@structure_ok = 1, (
    SELECT COUNT(*)
    FROM `alrowad_uni_rust`.`role_permissions` rp
    JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
    JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
    WHERE r.role_code = 'technical_team'
      AND (p.permission_code LIKE 'users\_permissions.%' OR p.permission_code = 'system_settings.manage')
), 99);

SET @apply_ready := IF(
    @structure_ok = 1 AND @unique_keys_ok = 1 AND @module_ok = 1 AND @super_admin_role_ok = 1
    AND @role_state IN ('ABSENT', 'COMPATIBLE') AND @perm_conflicts = 0 AND @restricted_on_role = 0,
    1, 0);

START TRANSACTION;

INSERT INTO `alrowad_uni_rust`.`roles` (role_code, role_name, description, is_system_role, is_active, created_at, updated_at)
SELECT 'technical_team', 'الفريق التقني',
       CONCAT('Technical Office portal: account creation and allowlisted role assignment. Organizational placement grants nothing. ', @tag),
       1, 1, NOW(), NOW()
FROM DUAL
WHERE @apply_ready = 1
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'technical_team');

INSERT INTO `alrowad_uni_rust`.`permissions` (module_id, permission_code, permission_name, description, is_active, created_at, updated_at)
SELECT sm.module_id, defs.code, defs.name, CONCAT(defs.description, ' ', @tag), 1, NOW(), NOW()
FROM (
    SELECT 'technical_portal.access' AS code, 'Technical portal access' AS name, 'Opens the Technical Office portal. Identity only, grants no action.' AS description
    UNION ALL SELECT 'user_accounts.view', 'User accounts view', 'List accounts, their roles and role-derived permissions.'
    UNION ALL SELECT 'user_accounts.manage', 'User accounts manage', 'Create accounts, assign/revoke allowlisted roles, enable/disable accounts.'
) defs
JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_code = 'users_permissions' AND sm.is_active = 1
WHERE @apply_ready = 1
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` p WHERE p.permission_code = defs.code);

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, NOW()
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p
  ON p.permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage')
WHERE @apply_ready = 1
  AND r.role_code = 'technical_team'
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp
      WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, NOW()
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p
  ON p.permission_code IN ('user_accounts.view', 'user_accounts.manage')
WHERE @apply_ready = 1
  AND r.role_code = 'super_admin'
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp
      WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id
  );

SET @apply_complete := IF(@apply_ready = 1, (
    SELECT IF(
        (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'technical_team' AND is_active = 1) = 1
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions`
             WHERE permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage') AND is_active = 1) = 3
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
             JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'technical_team'
             JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
             WHERE p.permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage')) = 3
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
             JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'super_admin'
             JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
             WHERE p.permission_code IN ('user_accounts.view', 'user_accounts.manage')) = 2,
    1, 0)
), 0);

-- Incomplete run: remove only rows this run could have created (tagged, and absent at start).
-- Single-table DELETEs with fully qualified names: works without a selected database.
DELETE FROM `alrowad_uni_rust`.`role_permissions`
WHERE @apply_ready = 1 AND @apply_complete = 0 AND @perms_absent_at_start = 1
  AND permission_id IN (
      SELECT p.permission_id FROM `alrowad_uni_rust`.`permissions` p
      WHERE p.permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage')
        AND COALESCE(p.description, '') LIKE '%[technical-team-portal]%'
  );

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @apply_ready = 1 AND @apply_complete = 0 AND @perms_absent_at_start = 1
  AND permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage')
  AND COALESCE(description, '') LIKE '%[technical-team-portal]%';

DELETE FROM `alrowad_uni_rust`.`roles`
WHERE @apply_ready = 1 AND @apply_complete = 0 AND @role_state = 'ABSENT'
  AND role_code = 'technical_team'
  AND COALESCE(description, '') LIKE '%[technical-team-portal]%'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`user_roles` ur WHERE ur.role_id = `alrowad_uni_rust`.`roles`.`role_id`);

COMMIT;

SELECT IF(@apply_ready = 0, 'BLOCKED', IF(@apply_complete = 1, 'APPLIED', 'BLOCKED_INCOMPLETE')) AS apply_status,
       @apply_ready AS apply_ready,
       @missing_required_columns AS missing_required_columns,
       @unique_keys_ok AS unique_keys_ok,
       @module_ok AS users_permissions_module_ok,
       @super_admin_role_ok AS super_admin_role_ok,
       @role_state AS technical_team_role_state,
       @perm_conflicts AS permission_conflicts,
       @restricted_on_role AS technical_team_restricted_permissions;
