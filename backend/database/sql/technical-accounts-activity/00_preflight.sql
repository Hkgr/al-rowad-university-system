-- Technical Office: account editing + activity feed — PREFLIGHT. Read-only.
-- Prerequisite: package technical-team-portal (role technical_team, module users_permissions).
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1) Required columns (users has NO name column: holder names live on employees/students).
SELECT req.t AS table_name, req.c AS column_name, IF(ex.column_name IS NULL, 'MISSING', 'OK') AS column_state
FROM (
    SELECT 'users' AS t, 'username' AS c UNION ALL SELECT 'users', 'email' UNION ALL SELECT 'users', 'password_hash'
    UNION ALL SELECT 'users', 'employee_id' UNION ALL SELECT 'users', 'student_id'
    UNION ALL SELECT 'employees', 'first_name' UNION ALL SELECT 'employees', 'last_name' UNION ALL SELECT 'employees', 'father_name' UNION ALL SELECT 'employees', 'mother_name'
    UNION ALL SELECT 'students', 'first_name' UNION ALL SELECT 'students', 'last_name' UNION ALL SELECT 'students', 'father_name' UNION ALL SELECT 'students', 'mother_name'
    UNION ALL SELECT 'user_activity_logs', 'module_code' UNION ALL SELECT 'user_activity_logs', 'action_code' UNION ALL SELECT 'user_activity_logs', 'created_at'
    UNION ALL SELECT 'login_audit_logs', 'login_status' UNION ALL SELECT 'login_audit_logs', 'attempted_at'
    UNION ALL SELECT 'personal_access_tokens', 'tokenable_id'
) req
LEFT JOIN information_schema.columns ex ON ex.table_schema = 'alrowad_uni_rust' AND ex.table_name = req.t AND ex.column_name = req.c
ORDER BY column_state DESC, req.t;

-- 2) Prerequisite objects.
SELECT 'module users_permissions' AS item, COUNT(*) AS row_count, MAX(is_active) AS is_active FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'users_permissions'
UNION ALL SELECT 'role technical_team', COUNT(*), MAX(is_active) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'technical_team'
UNION ALL SELECT 'role super_admin', COUNT(*), MAX(is_active) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'super_admin';

-- 3) Target permissions: ABSENT / COMPATIBLE / CONFLICT.
SELECT codes.code AS permission_code, COUNT(p.permission_id) AS row_count,
       CASE WHEN COUNT(p.permission_id) = 0 THEN 'ABSENT'
            WHEN COUNT(p.permission_id) = 1 AND SUM(p.is_active = 1 AND sm.module_code = 'users_permissions') = 1 THEN 'COMPATIBLE'
            ELSE 'CONFLICT' END AS object_state,
       MAX(IF(COALESCE(p.description, '') LIKE '%[technical-accounts-activity]%', 1, 0)) AS owned_by_this_package
FROM (SELECT 'user_accounts.holder_name.manage' AS code UNION ALL SELECT 'system_activity.view') codes
LEFT JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = codes.code
LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
GROUP BY codes.code;

-- 4) technical_team must not hold users_permissions.* or system_settings.manage.
SELECT p.permission_code AS restricted_permission_on_technical_team
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'technical_team'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code LIKE 'users\_permissions.%' OR p.permission_code = 'system_settings.manage';

-- 5) Indexes this package adds (NOT_PRESENT before the first apply) and log volumes.
SELECT expected.table_name, expected.index_name, IF(COUNT(s.index_name) > 0, 'PRESENT', 'NOT_PRESENT') AS index_state
FROM (SELECT 'user_activity_logs' AS table_name, 'idx_ual_created_at' AS index_name
      UNION ALL SELECT 'user_activity_logs', 'idx_ual_module_created'
      UNION ALL SELECT 'login_audit_logs', 'idx_lal_attempted_at') expected
LEFT JOIN information_schema.statistics s ON s.table_schema = 'alrowad_uni_rust' AND s.table_name = expected.table_name AND s.index_name = expected.index_name
GROUP BY expected.table_name, expected.index_name;
SELECT 'user_activity_logs' AS table_name, COUNT(*) AS row_count FROM `alrowad_uni_rust`.`user_activity_logs`
UNION ALL SELECT 'login_audit_logs', COUNT(*) FROM `alrowad_uni_rust`.`login_audit_logs`;
