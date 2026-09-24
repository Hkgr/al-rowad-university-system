-- Administrative Vice-Presidency governance — APPLY. Manual, idempotent, fail-closed.
-- Creates (only when ABSENT) four permissions in module `vice_presidency` and maps them to
-- the `vice_president_administrative` role. Rows are tagged [vp-admin-governance].
-- Does NOT: change schema, create accounts or deans, grant scopes, or touch other roles.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @apply_ready := 0;
SET @apply_complete := 0;
SET @tag := '[vp-admin-governance]';

SET @db_ready := IF(EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'), 1, 0);

SET @unique_keys_ok := IF(@db_ready = 1, (
    SELECT IF(
        EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'permissions' AND column_name = 'permission_code' AND non_unique = 0)
        AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'role_permissions' AND index_name = 'uq_role_permission' AND non_unique = 0),
    1, 0)
), 0);

SET @module_ok := IF(@db_ready = 1, (
    SELECT IF(COUNT(*) = 1 AND SUM(is_active = 1) = 1, 1, 0) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'vice_presidency'
), 0);

SET @role_ok := IF(@db_ready = 1, (
    SELECT IF(COUNT(*) = 1 AND SUM(is_active = 1) = 1, 1, 0) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative'
), 0);

SET @perm_conflicts := IF(@db_ready = 1, (
    SELECT COUNT(*) FROM (
        SELECT p.permission_code
        FROM `alrowad_uni_rust`.`permissions` p
        LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                                    'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')
        GROUP BY p.permission_code
        HAVING COUNT(*) <> 1 OR SUM(p.is_active = 1 AND sm.module_code = 'vice_presidency') <> 1
    ) conflicting
), 99);

SET @perms_absent_at_start := IF(@db_ready = 1, (
    SELECT COUNT(*) = 0 FROM `alrowad_uni_rust`.`permissions`
    WHERE permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                              'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')
), 0);

SET @apply_ready := IF(@db_ready = 1 AND @unique_keys_ok = 1 AND @module_ok = 1 AND @role_ok = 1 AND @perm_conflicts = 0, 1, 0);

START TRANSACTION;

INSERT INTO `alrowad_uni_rust`.`permissions` (module_id, permission_code, permission_name, description, is_active, created_at, updated_at)
SELECT sm.module_id, defs.code, defs.name, CONCAT(defs.description, ' ', @tag), 1, NOW(), NOW()
FROM (
    SELECT 'vice_presidency.administrative.faculty.view' AS code, 'Administrative VP faculty view' AS name, 'View teacher profiles and college affiliation.' AS description
    UNION ALL SELECT 'vice_presidency.administrative.faculty.manage', 'Administrative VP faculty manage', 'Create or link teacher profiles and change college affiliation (no teaching assignment).'
    UNION ALL SELECT 'vice_presidency.administrative.deans.view', 'Administrative VP deans view', 'View colleges and their deans.'
    UNION ALL SELECT 'vice_presidency.administrative.deans.manage', 'Administrative VP deans manage', 'Appoint, transfer or end college deans (dean role + one college scope only).'
) defs
JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_code = 'vice_presidency' AND sm.is_active = 1
WHERE @apply_ready = 1
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` p WHERE p.permission_code = defs.code);

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, NOW()
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p
  ON p.permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                           'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')
WHERE @apply_ready = 1
  AND r.role_code = 'vice_president_administrative'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id);

SET @apply_complete := IF(@apply_ready = 1, (
    SELECT IF(
        (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions`
         WHERE permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                                   'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage') AND is_active = 1) = 4
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
             JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'vice_president_administrative'
             JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
             WHERE p.permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                                         'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')) = 4,
    1, 0)
), 0);

-- Incomplete run: remove only what this run could have created (tagged and absent at start).
DELETE FROM `alrowad_uni_rust`.`role_permissions`
WHERE @apply_ready = 1 AND @apply_complete = 0 AND @perms_absent_at_start = 1
  AND permission_id IN (SELECT p.permission_id FROM `alrowad_uni_rust`.`permissions` p
                        WHERE p.permission_code LIKE 'vice\_presidency.administrative.%' AND COALESCE(p.description, '') LIKE '%[vp-admin-governance]%');

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @apply_ready = 1 AND @apply_complete = 0 AND @perms_absent_at_start = 1
  AND permission_code LIKE 'vice\_presidency.administrative.%'
  AND COALESCE(description, '') LIKE '%[vp-admin-governance]%';

COMMIT;

SELECT IF(@apply_ready = 0, 'BLOCKED', IF(@apply_complete = 1, 'APPLIED', 'BLOCKED_INCOMPLETE')) AS apply_status,
       @apply_ready AS apply_ready, @unique_keys_ok AS unique_keys_ok, @module_ok AS vice_presidency_module_ok,
       @role_ok AS administrative_vp_role_ok, @perm_conflicts AS permission_conflicts;
