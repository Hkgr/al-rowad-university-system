-- READ ONLY. Continue only when every prerequisite and OVERALL return READY.
USE `alrowad_uni_rust`;

SELECT
    'active_dean_role_exactly_once' AS check_name,
    IF(COUNT(*) = 1, 'READY', 'BLOCKED') AS result,
    COUNT(*) AS actual
FROM roles
WHERE role_code = 'dean'
  AND is_active = 1;

SELECT
    'students_view_active_exactly_once' AS check_name,
    IF(COUNT(*) = 1, 'READY', 'BLOCKED') AS result,
    COUNT(*) AS actual
FROM permissions
WHERE permission_code = 'students.view'
  AND is_active = 1;

SELECT
    'academic_structure_view_active_exactly_once' AS check_name,
    IF(COUNT(*) = 1, 'READY', 'BLOCKED') AS result,
    COUNT(*) AS actual
FROM permissions
WHERE permission_code = 'academic_structure.view'
  AND is_active = 1;

SELECT
    'dean_does_not_have_students_manage' AS check_name,
    IF(COUNT(*) = 0, 'READY', 'BLOCKED') AS result,
    COUNT(*) AS actual
FROM role_permissions rp
JOIN roles r ON r.role_id = rp.role_id
JOIN permissions p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'dean'
  AND p.permission_code = 'students.manage';

SELECT
    'dean_does_not_have_academic_structure_manage' AS check_name,
    IF(COUNT(*) = 0, 'READY', 'BLOCKED') AS result,
    COUNT(*) AS actual
FROM role_permissions rp
JOIN roles r ON r.role_id = rp.role_id
JOIN permissions p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'dean'
  AND p.permission_code = 'academic_structure.manage';

SELECT
    'current_dean_permissions' AS report_section,
    p.permission_code,
    p.is_active,
    rp.granted_at
FROM roles r
JOIN role_permissions rp ON rp.role_id = r.role_id
JOIN permissions p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'dean'
ORDER BY p.permission_code;

SELECT
    'active_dean_users_and_scopes' AS report_section,
    u.user_id,
    u.username,
    u.email,
    s.scope_type,
    s.scope_id,
    c.college_code,
    c.college_name
FROM roles r
JOIN user_roles ur ON ur.role_id = r.role_id AND ur.is_active = 1
JOIN users u ON u.user_id = ur.user_id
LEFT JOIN user_access_scopes s ON s.user_id = u.user_id AND s.is_active = 1
LEFT JOIN colleges c ON s.scope_type = 'college' AND c.college_id = s.scope_id
WHERE r.role_code = 'dean'
  AND r.is_active = 1
ORDER BY u.user_id, s.scope_type, s.scope_id;

SELECT
    'unsafe_dean_scope' AS report_section,
    u.user_id,
    u.username,
    u.email,
    COUNT(s.user_access_scope_id) AS active_scope_count,
    GROUP_CONCAT(
        CONCAT(COALESCE(s.scope_type, 'none'), ':', COALESCE(s.scope_id, 0))
        ORDER BY s.scope_type, s.scope_id
    ) AS active_scopes,
    'BLOCKED' AS result
FROM roles r
JOIN user_roles ur ON ur.role_id = r.role_id AND ur.is_active = 1
JOIN users u ON u.user_id = ur.user_id
LEFT JOIN user_access_scopes s ON s.user_id = u.user_id AND s.is_active = 1
LEFT JOIN colleges c ON s.scope_type = 'college' AND c.college_id = s.scope_id AND c.is_active = 1
WHERE r.role_code = 'dean'
  AND r.is_active = 1
GROUP BY u.user_id, u.username, u.email
HAVING COUNT(s.user_access_scope_id) <> 1
    OR SUM(s.scope_type = 'college' AND c.college_id IS NOT NULL) <> 1;

SELECT
    'OVERALL' AS check_name,
    IF(
        (SELECT COUNT(*) FROM roles WHERE role_code = 'dean' AND is_active = 1) = 1
        AND (SELECT COUNT(*) FROM permissions WHERE permission_code = 'students.view' AND is_active = 1) = 1
        AND (SELECT COUNT(*) FROM permissions WHERE permission_code = 'academic_structure.view' AND is_active = 1) = 1
        AND NOT EXISTS (
            SELECT 1
            FROM role_permissions rp
            JOIN roles r ON r.role_id = rp.role_id
            JOIN permissions p ON p.permission_id = rp.permission_id
            WHERE r.role_code = 'dean'
              AND p.permission_code IN ('students.manage', 'academic_structure.manage')
        )
        AND NOT EXISTS (
            SELECT 1
            FROM (
                SELECT ur.user_id
                FROM roles r
                JOIN user_roles ur ON ur.role_id = r.role_id AND ur.is_active = 1
                LEFT JOIN user_access_scopes s ON s.user_id = ur.user_id AND s.is_active = 1
                LEFT JOIN colleges c ON s.scope_type = 'college' AND c.college_id = s.scope_id AND c.is_active = 1
                WHERE r.role_code = 'dean'
                  AND r.is_active = 1
                GROUP BY ur.user_id
                HAVING COUNT(s.user_access_scope_id) <> 1
                    OR SUM(s.scope_type = 'college' AND c.college_id IS NOT NULL) <> 1
            ) unsafe_deans
        ),
        'READY',
        'BLOCKED'
    ) AS result;
