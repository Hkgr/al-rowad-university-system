-- READ ONLY. Continue to 01_apply.sql only when OVERALL is READY.
-- This script creates no tables, temporary tables, routines, or data.

SELECT 'required_columns' AS report_section, table_name, column_name, column_type,
       is_nullable, column_key, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ((table_name = 'roles' AND column_name IN ('role_id', 'role_code', 'is_active'))
    OR (table_name = 'permissions' AND column_name IN ('permission_id', 'permission_code', 'is_active'))
    OR (table_name = 'role_permissions' AND column_name IN ('role_permission_id', 'role_id', 'permission_id', 'granted_at'))
    OR (table_name = 'user_access_scopes' AND column_name IN ('user_access_scope_id', 'user_id', 'scope_type', 'scope_id', 'is_active', 'created_at', 'updated_at'))
    OR (table_name = 'colleges' AND column_name IN ('college_id', 'college_code', 'college_name', 'is_active'))
    OR (table_name = 'users' AND column_name IN ('user_id', 'username', 'email', 'employee_id'))
    OR (table_name = 'employees' AND column_name IN ('employee_id', 'employee_number', 'email'))
    OR (table_name = 'user_roles' AND column_name IN ('user_id', 'role_id', 'is_active')))
ORDER BY table_name, ordinal_position;

SELECT 'required_indexes' AS report_section, table_name, index_name, non_unique,
       GROUP_CONCAT(column_name ORDER BY seq_in_index) AS indexed_columns
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('role_permissions', 'user_access_scopes')
GROUP BY table_name, index_name, non_unique
ORDER BY table_name, index_name;

SELECT 'dean_role' AS report_section, role_id, role_code, is_active
FROM roles
WHERE role_code = 'dean';

SELECT 'required_permissions' AS report_section, permission_id, permission_code, is_active
FROM permissions
WHERE permission_code IN ('students.view', 'academic_structure.view', 'students.manage')
ORDER BY permission_code;

SELECT 'fmf_college' AS report_section, college_id, college_code, college_name, is_active
FROM colleges
WHERE college_code = 'FMF' OR college_name = 'كلية العلوم الإدارية والمالية';

SELECT 'existing_fmf_test_dean_scopes' AS report_section, u.user_id, u.username, u.email,
       r.role_code, ur.is_active AS role_is_active, s.scope_type, s.scope_id,
       s.is_active AS scope_is_active, c.college_code
FROM users u
LEFT JOIN employees e ON e.employee_id = u.employee_id
LEFT JOIN user_roles ur ON ur.user_id = u.user_id
LEFT JOIN roles r ON r.role_id = ur.role_id
LEFT JOIN user_access_scopes s ON s.user_id = u.user_id
LEFT JOIN colleges c ON s.scope_type = 'college' AND c.college_id = s.scope_id
WHERE u.username = 'dean.fmf.test'
   OR u.email = 'dean.fmf.test@rowad.edu'
   OR e.employee_number = 'TEMP-DEAN-FMF-2026'
ORDER BY u.user_id, r.role_code, s.scope_type, s.scope_id;

WITH checks AS (
  SELECT 'required_table_columns' AS check_name,
         IF(COUNT(*) = 31, 'READY', 'BLOCKED') AS result,
         CONCAT(COUNT(*), ' of 31 required columns found') AS detail
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND ((table_name = 'roles' AND column_name IN ('role_id', 'role_code', 'is_active'))
      OR (table_name = 'permissions' AND column_name IN ('permission_id', 'permission_code', 'is_active'))
      OR (table_name = 'role_permissions' AND column_name IN ('role_permission_id', 'role_id', 'permission_id', 'granted_at'))
      OR (table_name = 'user_access_scopes' AND column_name IN ('user_access_scope_id', 'user_id', 'scope_type', 'scope_id', 'is_active', 'created_at', 'updated_at'))
      OR (table_name = 'colleges' AND column_name IN ('college_id', 'college_code', 'college_name', 'is_active'))
      OR (table_name = 'users' AND column_name IN ('user_id', 'username', 'email', 'employee_id'))
      OR (table_name = 'employees' AND column_name IN ('employee_id', 'employee_number', 'email'))
      OR (table_name = 'user_roles' AND column_name IN ('user_id', 'role_id', 'is_active')))

  UNION ALL
  SELECT 'role_permissions_auto_increment', IF(COUNT(*) = 1, 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' matching role_permission_id columns')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'role_permissions'
    AND column_name = 'role_permission_id' AND extra LIKE '%auto_increment%'

  UNION ALL
  SELECT 'role_permissions_unique_pair', IF(COUNT(*) = 1, 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' matching unique indexes')
  FROM (
    SELECT index_name
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'role_permissions' AND non_unique = 0
    GROUP BY index_name
    HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'role_id,permission_id'
  ) indexes_found

  UNION ALL
  SELECT 'user_access_scopes_unique_tuple', IF(COUNT(*) = 1, 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' matching unique indexes')
  FROM (
    SELECT index_name
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'user_access_scopes' AND non_unique = 0
    GROUP BY index_name
    HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'user_id,scope_type,scope_id'
  ) indexes_found

  UNION ALL
  SELECT 'college_scope_supported', IF(COUNT(*) = 1, 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' compatible scope_type columns')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'user_access_scopes'
    AND column_name = 'scope_type' AND column_type LIKE '%college%'

  UNION ALL
  SELECT 'active_dean_role_exactly_once', IF(COUNT(*) = 1, 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' active dean roles')
  FROM roles WHERE role_code = 'dean' AND is_active = 1

  UNION ALL
  SELECT 'active_required_permissions_exactly_once', IF(COUNT(*) = 2, 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' of 2 active read permissions')
  FROM permissions
  WHERE permission_code IN ('students.view', 'academic_structure.view') AND is_active = 1

  UNION ALL
  SELECT 'active_students_manage_exactly_once', IF(COUNT(*) = 1, 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' active students.manage permissions')
  FROM permissions WHERE permission_code = 'students.manage' AND is_active = 1

  UNION ALL
  SELECT 'dean_does_not_have_students_manage', IF(COUNT(*) = 0, 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' students.manage grants found')
  FROM role_permissions rp
  JOIN roles r ON r.role_id = rp.role_id
  JOIN permissions p ON p.permission_id = rp.permission_id
  WHERE r.role_code = 'dean' AND p.permission_code = 'students.manage'

  UNION ALL
  SELECT 'active_fmf_college_exactly_once', IF(COUNT(*) = 1, 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' matching active FMF colleges')
  FROM colleges
  WHERE college_code = 'FMF' AND college_name = 'كلية العلوم الإدارية والمالية' AND is_active = 1

  UNION ALL
  SELECT 'all_active_deans_have_one_active_college_scope',
         IF(COUNT(*) = 0, 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' active dean users with unsafe or ambiguous scopes')
  FROM (
    SELECT ur.user_id
    FROM user_roles ur
    JOIN roles r ON r.role_id = ur.role_id
    LEFT JOIN user_access_scopes s ON s.user_id = ur.user_id AND s.is_active = 1
    LEFT JOIN colleges c ON s.scope_type = 'college' AND c.college_id = s.scope_id AND c.is_active = 1
    WHERE ur.is_active = 1 AND r.role_code = 'dean' AND r.is_active = 1
    GROUP BY ur.user_id
    HAVING COUNT(s.user_access_scope_id) <> 1
       OR SUM(s.scope_type = 'college' AND c.college_id IS NOT NULL) <> 1
  ) unsafe_deans

  UNION ALL
  SELECT 'fmf_test_dean_identity_unambiguous', IF(COUNT(*) <= 1, 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' matching users')
  FROM users u
  LEFT JOIN employees e ON e.employee_id = u.employee_id
  WHERE u.username = 'dean.fmf.test'
     OR u.email = 'dean.fmf.test@rowad.edu'
     OR e.employee_number = 'TEMP-DEAN-FMF-2026'

  UNION ALL
  SELECT 'fmf_test_dean_role_if_present',
         IF(COUNT(*) = 0 OR (
              COUNT(*) = 1
              AND SUM(r.role_code = 'dean' AND r.is_active = 1 AND ur.is_active = 1) = 1
            ), 'READY', 'BLOCKED'),
         CONCAT(COUNT(*), ' active role assignments for matching user')
  FROM users u
  LEFT JOIN employees e ON e.employee_id = u.employee_id
  LEFT JOIN user_roles ur ON ur.user_id = u.user_id AND ur.is_active = 1
  LEFT JOIN roles r ON r.role_id = ur.role_id
  WHERE u.username = 'dean.fmf.test'
     OR u.email = 'dean.fmf.test@rowad.edu'
     OR e.employee_number = 'TEMP-DEAN-FMF-2026'

  UNION ALL
  SELECT 'fmf_test_dean_scope_if_present',
         IF(COUNT(DISTINCT u.user_id) = 0 OR (
              COUNT(DISTINCT u.user_id) = 1
              AND COUNT(s.user_access_scope_id) = 1
              AND SUM(s.scope_type = 'college' AND s.scope_id = f.college_id) = 1
            ), 'READY', 'BLOCKED'),
         CONCAT(COUNT(s.user_access_scope_id), ' active scopes for matching user')
  FROM users u
  LEFT JOIN employees e ON e.employee_id = u.employee_id
  LEFT JOIN user_access_scopes s ON s.user_id = u.user_id AND s.is_active = 1
  LEFT JOIN (
    SELECT college_id FROM colleges
    WHERE college_code = 'FMF' AND college_name = 'كلية العلوم الإدارية والمالية' AND is_active = 1
  ) f ON 1 = 1
  WHERE u.username = 'dean.fmf.test'
     OR u.email = 'dean.fmf.test@rowad.edu'
     OR e.employee_number = 'TEMP-DEAN-FMF-2026'
)
SELECT check_name, result, detail
FROM checks
UNION ALL
SELECT 'OVERALL', IF(SUM(result = 'BLOCKED') = 0, 'READY', 'BLOCKED'),
       CONCAT(SUM(result = 'BLOCKED'), ' blocker(s)')
FROM checks;
