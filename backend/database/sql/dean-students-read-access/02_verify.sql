-- READ ONLY. Every permission check and OVERALL must return PASS.
USE `alrowad_uni_rust`;

SELECT
    'dean_has_students_view' AS check_name,
    IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM role_permissions rp
JOIN roles r ON r.role_id = rp.role_id
JOIN permissions p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'dean'
  AND r.is_active = 1
  AND p.permission_code = 'students.view'
  AND p.is_active = 1;

SELECT
    'dean_has_academic_structure_view' AS check_name,
    IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM role_permissions rp
JOIN roles r ON r.role_id = rp.role_id
JOIN permissions p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'dean'
  AND r.is_active = 1
  AND p.permission_code = 'academic_structure.view'
  AND p.is_active = 1;

SELECT
    'dean_does_not_have_students_manage' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM role_permissions rp
JOIN roles r ON r.role_id = rp.role_id
JOIN permissions p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'dean'
  AND p.permission_code = 'students.manage';

SELECT
    'dean_does_not_have_academic_structure_manage' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM role_permissions rp
JOIN roles r ON r.role_id = rp.role_id
JOIN permissions p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'dean'
  AND p.permission_code = 'academic_structure.manage';

SELECT
    'active_dean_users_and_college_scopes' AS report_section,
    u.user_id,
    u.username,
    u.email,
    s.scope_id AS college_id,
    c.college_code,
    c.college_name
FROM roles r
JOIN user_roles ur ON ur.role_id = r.role_id AND ur.is_active = 1
JOIN users u ON u.user_id = ur.user_id
LEFT JOIN user_access_scopes s
    ON s.user_id = u.user_id
   AND s.is_active = 1
   AND s.scope_type = 'college'
LEFT JOIN colleges c ON c.college_id = s.scope_id
WHERE r.role_code = 'dean'
  AND r.is_active = 1
ORDER BY u.user_id, s.scope_id;

SELECT
    'OVERALL' AS check_name,
    IF(
        (SELECT COUNT(*)
         FROM role_permissions rp
         JOIN roles r ON r.role_id = rp.role_id
         JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE r.role_code = 'dean'
           AND r.is_active = 1
           AND p.is_active = 1
           AND p.permission_code IN ('students.view', 'academic_structure.view')) = 2
        AND NOT EXISTS (
            SELECT 1
            FROM role_permissions rp
            JOIN roles r ON r.role_id = rp.role_id
            JOIN permissions p ON p.permission_id = rp.permission_id
            WHERE r.role_code = 'dean'
              AND p.permission_code IN ('students.manage', 'academic_structure.manage')
        ),
        'PASS',
        'FAIL'
    ) AS result;
