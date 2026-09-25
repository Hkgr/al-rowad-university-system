-- Ministry of Education portal (بوابة وزارة التربية والتعليم) — PREFLIGHT. Read-only.
-- Run in phpMyAdmin (SQL tab) with or without a selected database. Changes nothing.
-- Expected before the first apply: module/role ABSENT, permissions ABSENT (8 rows), no conflicts.
-- On a re-run after apply: COMPATIBLE everywhere.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT 'required_column' AS check_name, CONCAT(req.t, '.', req.c) AS object_name,
       IF(ex.column_name IS NULL, 'MISSING', 'OK') AS state
FROM (
    SELECT 'roles' AS t, 'role_code' AS c UNION ALL SELECT 'roles', 'role_name' UNION ALL SELECT 'roles', 'description'
    UNION ALL SELECT 'roles', 'is_system_role' UNION ALL SELECT 'roles', 'is_active'
    UNION ALL SELECT 'permissions', 'module_id' UNION ALL SELECT 'permissions', 'permission_code' UNION ALL SELECT 'permissions', 'permission_name'
    UNION ALL SELECT 'permissions', 'description' UNION ALL SELECT 'permissions', 'is_active'
    UNION ALL SELECT 'role_permissions', 'role_id' UNION ALL SELECT 'role_permissions', 'permission_id' UNION ALL SELECT 'role_permissions', 'granted_at'
    UNION ALL SELECT 'system_modules', 'module_code' UNION ALL SELECT 'system_modules', 'module_name' UNION ALL SELECT 'system_modules', 'description'
    UNION ALL SELECT 'user_roles', 'role_id' UNION ALL SELECT 'user_roles', 'is_active'
    -- read by the portal
    UNION ALL SELECT 'students', 'deleted_at' UNION ALL SELECT 'academic_programs', 'plan_state' UNION ALL SELECT 'academic_programs', 'default_academic_plan_version_id'
    UNION ALL SELECT 'academic_programs', 'archived_at' UNION ALL SELECT 'program_courses', 'academic_plan_version_id'
    UNION ALL SELECT 'student_graduation_decisions', 'materialized_at' UNION ALL SELECT 'student_graduation_decisions', 'superseded_at'
    UNION ALL SELECT 'employee_positions', 'end_date' UNION ALL SELECT 'employee_unit_assignments', 'end_date'
    UNION ALL SELECT 'user_access_scopes', 'scope_type' UNION ALL SELECT 'course_offering_instructors', 'is_active'
    UNION ALL SELECT 'student_academic_terms', 'is_finalized' UNION ALL SELECT 'grade_approvals', 'approval_status_id'
) req
LEFT JOIN information_schema.columns ex ON ex.table_schema = 'alrowad_uni_rust' AND ex.table_name = req.t AND ex.column_name = req.c
ORDER BY state DESC, object_name;

SELECT 'module ministry_portal' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'ABSENT'
            WHEN COUNT(*) = 1 AND SUM(is_active = 1) = 1 AND SUM(COALESCE(description, '') LIKE '%[ministry-portal]%') = 1 THEN 'COMPATIBLE'
            ELSE 'CONFLICT' END AS state
FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'ministry_portal';

SELECT 'role ministry_observer' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'ABSENT'
            WHEN COUNT(*) = 1 AND SUM(is_active = 1 AND is_system_role = 1) = 1 AND SUM(COALESCE(description, '') LIKE '%[ministry-portal]%') = 1 THEN 'COMPATIBLE'
            ELSE 'CONFLICT' END AS state
FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'ministry_observer';

SELECT codes.code AS permission_code,
       CASE WHEN p.permission_id IS NULL THEN 'ABSENT'
            WHEN p.is_active = 1 AND sm.module_code = 'ministry_portal' AND COALESCE(p.description, '') LIKE '%[ministry-portal]%' THEN 'COMPATIBLE'
            ELSE 'CONFLICT' END AS state
FROM (SELECT 'ministry_portal.access' AS code UNION ALL SELECT 'ministry_portal.dashboard.view' UNION ALL SELECT 'ministry_portal.deans.view'
      UNION ALL SELECT 'ministry_portal.students.view' UNION ALL SELECT 'ministry_portal.colleges.view' UNION ALL SELECT 'ministry_portal.courses.view'
      UNION ALL SELECT 'ministry_portal.faculty.view' UNION ALL SELECT 'ministry_portal.leadership.view') codes
LEFT JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = codes.code
LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
ORDER BY codes.code;

-- Must be 0: a ministry permission mapped to another role, or another permission mapped to the ministry role.
SELECT 'foreign_mappings' AS check_name, COUNT(*) AS actual, IF(COUNT(*) = 0, 'OK', 'CONFLICT') AS state
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE (p.permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view') AND r.role_code <> 'ministry_observer')
   OR (r.role_code = 'ministry_observer' AND p.permission_code NOT IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view'));

-- Informational: accounts already holding the role (0 before the first deployment).
SELECT 'ministry_role_assignments' AS check_name, COUNT(*) AS rows_total, COALESCE(SUM(ur.is_active = 1), 0) AS active_rows
FROM `alrowad_uni_rust`.`user_roles` ur JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id
WHERE r.role_code = 'ministry_observer';

-- Informational: the office mentioned in the org chart without a system role (no data is created for it).
SELECT 'community_vp_role' AS check_name,
       IF(EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` WHERE role_code LIKE 'vice_president%community%'), 'PRESENT', 'NOT_IN_SYSTEM') AS state,
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`organizational_units` WHERE unit_name = 'نائب رئيس الجامعة للشؤون المجتمعية') AS unit_rows;
