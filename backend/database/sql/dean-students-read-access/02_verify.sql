-- READ ONLY. Every detail check and OVERALL must return PASS.

SET @dean_role_id := (
  SELECT role_id FROM roles WHERE role_code = 'dean' AND is_active = 1 LIMIT 1
);
SET @fmf_college_id := (
  SELECT college_id
  FROM colleges
  WHERE college_code = 'FMF' AND college_name = 'كلية العلوم الإدارية والمالية' AND is_active = 1
  LIMIT 1
);
SET @fmf_test_dean_user_id := (
  SELECT u.user_id
  FROM users u
  LEFT JOIN employees e ON e.employee_id = u.employee_id
  WHERE u.username = 'dean.fmf.test'
     OR u.email = 'dean.fmf.test@rowad.edu'
     OR e.employee_number = 'TEMP-DEAN-FMF-2026'
  LIMIT 1
);

SELECT 'dean_permissions' AS report_section, r.role_code, p.permission_code, p.is_active,
       rp.role_permission_id, rp.granted_at
FROM role_permissions rp
JOIN roles r ON r.role_id = rp.role_id
JOIN permissions p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'dean'
ORDER BY p.permission_code;

SELECT 'fmf_test_dean_active_scopes' AS report_section, u.user_id, u.username,
       s.scope_type, s.scope_id, c.college_code, s.is_active
FROM users u
JOIN user_access_scopes s ON s.user_id = u.user_id AND s.is_active = 1
LEFT JOIN colleges c ON s.scope_type = 'college' AND c.college_id = s.scope_id
WHERE u.user_id = @fmf_test_dean_user_id
ORDER BY s.scope_type, s.scope_id;

WITH checks AS (
  SELECT 'active_dean_role_exactly_once' AS check_name,
         IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
         CONCAT(COUNT(*), ' active dean roles') AS detail
  FROM roles WHERE role_code = 'dean' AND is_active = 1

  UNION ALL
  SELECT 'intended_read_permissions_exactly_once_each',
         IF(COUNT(*) = 2 AND COUNT(DISTINCT p.permission_code) = 2, 'PASS', 'FAIL'),
         CONCAT(COUNT(*), ' role_permission rows for 2 intended permissions')
  FROM role_permissions rp
  JOIN permissions p ON p.permission_id = rp.permission_id AND p.is_active = 1
  WHERE rp.role_id = @dean_role_id
    AND p.permission_code IN ('students.view', 'academic_structure.view')

  UNION ALL
  SELECT 'dean_has_students_view',
         IF(COUNT(*) = 1, 'PASS', 'FAIL'),
         CONCAT(COUNT(*), ' grants')
  FROM role_permissions rp
  JOIN permissions p ON p.permission_id = rp.permission_id
  WHERE rp.role_id = @dean_role_id
    AND p.permission_code = 'students.view' AND p.is_active = 1

  UNION ALL
  SELECT 'dean_has_academic_structure_view',
         IF(COUNT(*) = 1, 'PASS', 'FAIL'),
         CONCAT(COUNT(*), ' grants')
  FROM role_permissions rp
  JOIN permissions p ON p.permission_id = rp.permission_id
  WHERE rp.role_id = @dean_role_id
    AND p.permission_code = 'academic_structure.view' AND p.is_active = 1

  UNION ALL
  SELECT 'dean_does_not_have_students_manage',
         IF(COUNT(*) = 0, 'PASS', 'FAIL'),
         CONCAT(COUNT(*), ' students.manage grants')
  FROM role_permissions rp
  JOIN permissions p ON p.permission_id = rp.permission_id
  WHERE rp.role_id = @dean_role_id AND p.permission_code = 'students.manage'

  UNION ALL
  SELECT 'all_active_deans_have_one_active_college_scope',
         IF(COUNT(*) = 0, 'PASS', 'FAIL'),
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
  SELECT 'fmf_test_dean_identity_unambiguous',
         IF(COUNT(DISTINCT u.user_id) <= 1, 'PASS', 'FAIL'),
         CONCAT(COUNT(DISTINCT u.user_id), ' matching users')
  FROM users u
  LEFT JOIN employees e ON e.employee_id = u.employee_id
  WHERE u.username = 'dean.fmf.test'
     OR u.email = 'dean.fmf.test@rowad.edu'
     OR e.employee_number = 'TEMP-DEAN-FMF-2026'

  UNION ALL
  SELECT 'fmf_test_dean_has_only_fmf_college_scope_if_present',
         IF(@fmf_test_dean_user_id IS NULL OR (
              COUNT(*) = 1
              AND SUM(scope_type = 'college' AND scope_id = @fmf_college_id) = 1
            ), 'PASS', 'FAIL'),
         CONCAT(COUNT(*), ' active scopes')
  FROM user_access_scopes
  WHERE user_id = @fmf_test_dean_user_id AND is_active = 1

  UNION ALL
  SELECT 'fmf_test_dean_has_no_university_scope',
         IF(COUNT(*) = 0, 'PASS', 'FAIL'),
         CONCAT(COUNT(*), ' active university scopes')
  FROM user_access_scopes
  WHERE user_id = @fmf_test_dean_user_id AND is_active = 1 AND scope_type = 'university'

  UNION ALL
  SELECT 'fmf_test_dean_role_if_present',
         IF(@fmf_test_dean_user_id IS NULL OR (
              COUNT(*) = 1
              AND SUM(r.role_code = 'dean' AND r.is_active = 1) = 1
            ), 'PASS', 'FAIL'),
         CONCAT(COUNT(*), ' active role assignments')
  FROM user_roles ur
  JOIN roles r ON r.role_id = ur.role_id
  WHERE ur.user_id = @fmf_test_dean_user_id AND ur.is_active = 1
)
SELECT check_name, result, detail
FROM checks
UNION ALL
SELECT 'OVERALL', IF(SUM(result = 'FAIL') = 0, 'PASS', 'FAIL'),
       CONCAT(SUM(result = 'FAIL'), ' failed check(s)')
FROM checks;
