-- Administrative Vice-Presidency governance (teachers + college deans) — PREFLIGHT. Read-only.
-- Manual SQL only (no Laravel migrations). No hard-coded ids.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1) Required columns
SELECT req.t AS table_name, req.c AS column_name, IF(ex.column_name IS NULL, 'MISSING', 'OK') AS column_state
FROM (
    SELECT 'permissions' AS t, 'permission_code' AS c UNION ALL SELECT 'permissions', 'module_id'
    UNION ALL SELECT 'permissions', 'description' UNION ALL SELECT 'role_permissions', 'role_id'
    UNION ALL SELECT 'role_permissions', 'permission_id' UNION ALL SELECT 'roles', 'role_code'
    UNION ALL SELECT 'system_modules', 'module_code' UNION ALL SELECT 'user_access_scopes', 'scope_type'
    UNION ALL SELECT 'user_access_scopes', 'is_active' UNION ALL SELECT 'employee_unit_assignments', 'end_date'
    UNION ALL SELECT 'employee_positions', 'end_date' UNION ALL SELECT 'colleges', 'organizational_unit_id'
    UNION ALL SELECT 'users', 'employee_id' UNION ALL SELECT 'user_activity_logs', 'action_code'
) req
LEFT JOIN information_schema.columns ex
       ON ex.table_schema = 'alrowad_uni_rust' AND ex.table_name = req.t AND ex.column_name = req.c
ORDER BY column_state DESC, req.t;

-- 2) Unique keys this feature relies on
SELECT expected.table_name, expected.index_name, IF(COUNT(s.index_name) > 0, 'OK', 'MISSING') AS unique_state
FROM (
    SELECT 'user_access_scopes' AS table_name, 'user_scope_unique' AS index_name
    UNION ALL SELECT 'user_roles', 'uq_user_role'
    UNION ALL SELECT 'role_permissions', 'uq_role_permission'
    UNION ALL SELECT 'users', 'uq_users_employee_identity'
) expected
LEFT JOIN information_schema.statistics s
       ON s.table_schema = 'alrowad_uni_rust' AND s.table_name = expected.table_name
      AND s.index_name = expected.index_name AND s.non_unique = 0
GROUP BY expected.table_name, expected.index_name;

-- 3) Reference rows (resolved by code)
SELECT 'module vice_presidency' AS item, COUNT(*) AS row_count, MAX(is_active) AS is_active FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'vice_presidency'
UNION ALL SELECT 'role vice_president_administrative', COUNT(*), MAX(is_active) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative'
UNION ALL SELECT 'role dean', COUNT(*), MAX(is_active) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'dean'
UNION ALL SELECT 'position DEAN', COUNT(*), MAX(is_active) FROM `alrowad_uni_rust`.`positions` WHERE position_code = 'DEAN'
UNION ALL SELECT 'employee type academic', COUNT(*), MAX(is_active) FROM `alrowad_uni_rust`.`employee_types` WHERE type_code = 'academic'
UNION ALL SELECT 'employee status active', COUNT(*), MAX(is_active) FROM `alrowad_uni_rust`.`employee_statuses` WHERE status_code = 'active';

-- 4) Target permissions: ABSENT / COMPATIBLE / CONFLICT
SELECT codes.code AS permission_code,
       COUNT(p.permission_id) AS row_count,
       CASE WHEN COUNT(p.permission_id) = 0 THEN 'ABSENT'
            WHEN COUNT(p.permission_id) = 1 AND SUM(p.is_active = 1 AND sm.module_code = 'vice_presidency') = 1 THEN 'COMPATIBLE'
            ELSE 'CONFLICT' END AS object_state,
       MAX(IF(COALESCE(p.description, '') LIKE '%[vp-admin-governance]%', 1, 0)) AS owned_by_this_package
FROM (SELECT 'vice_presidency.administrative.faculty.view' AS code
      UNION ALL SELECT 'vice_presidency.administrative.faculty.manage'
      UNION ALL SELECT 'vice_presidency.administrative.deans.view'
      UNION ALL SELECT 'vice_presidency.administrative.deans.manage') codes
LEFT JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = codes.code
LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
GROUP BY codes.code;

-- 5) The dean role must not carry administrative powers (the dean flow refuses such a role).
SELECT p.permission_code AS restricted_permission_on_dean_role
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'dean'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code LIKE 'users\_permissions.%' OR p.permission_code LIKE 'user\_accounts.%'
   OR p.permission_code LIKE 'vice\_presidency.%' OR p.permission_code = 'system_settings.manage';

-- 6) Current data: colleges without organizational unit (cannot receive deans/teachers), current deans per college,
--    dean accounts that also hold a university scope (the flow will refuse to modify them).
SELECT college_id, college_code, college_name FROM `alrowad_uni_rust`.`colleges` WHERE organizational_unit_id IS NULL;
SELECT s.scope_id AS college_id, COUNT(DISTINCT s.user_id) AS active_deans
FROM `alrowad_uni_rust`.`user_access_scopes` s
JOIN `alrowad_uni_rust`.`user_roles` ur ON ur.user_id = s.user_id AND ur.is_active = 1
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id AND r.role_code = 'dean'
WHERE s.scope_type = 'college' AND s.is_active = 1
GROUP BY s.scope_id;
SELECT ur.user_id AS dean_user_with_university_scope
FROM `alrowad_uni_rust`.`user_roles` ur
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id AND r.role_code = 'dean'
JOIN `alrowad_uni_rust`.`user_access_scopes` s ON s.user_id = ur.user_id AND s.scope_type = 'university' AND s.is_active = 1
WHERE ur.is_active = 1;
