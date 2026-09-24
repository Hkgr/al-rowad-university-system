-- Administrative Vice-Presidency governance — VERIFY. Read-only. Every row should be PASS.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT 'four permissions active in module vice_presidency' AS check_name,
       IF(COUNT(*) = 4 AND SUM(p.is_active = 1 AND sm.module_code = 'vice_presidency') = 4, 'PASS', 'FAIL') AS result
FROM `alrowad_uni_rust`.`permissions` p
LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
WHERE p.permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                            'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')
UNION ALL
SELECT 'vice_president_administrative holds the four permissions',
       IF(COUNT(*) = 4, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'vice_president_administrative'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                            'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')
UNION ALL
SELECT 'no other role holds the new permissions',
       IF(COUNT(*) = 0, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code <> 'vice_president_administrative'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage', 'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')
UNION ALL
SELECT 'dean role carries no administrative powers',
       IF(COUNT(*) = 0, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'dean'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code LIKE 'users\_permissions.%' OR p.permission_code LIKE 'user\_accounts.%'
   OR p.permission_code LIKE 'vice\_presidency.%' OR p.permission_code = 'system_settings.manage'
UNION ALL
SELECT 'position DEAN exists (needed for dean position history)',
       IF(COUNT(*) = 1, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`positions` WHERE position_code = 'DEAN'
UNION ALL
SELECT 'no duplicate user_access_scopes',
       IF(COUNT(*) = 0, 'PASS', 'FAIL')
FROM (SELECT user_id, scope_type, scope_id FROM `alrowad_uni_rust`.`user_access_scopes` GROUP BY user_id, scope_type, scope_id HAVING COUNT(*) > 1) d
UNION ALL
SELECT 'no active dean holds a university scope',
       IF(COUNT(*) = 0, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`user_roles` ur
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id AND r.role_code = 'dean'
JOIN `alrowad_uni_rust`.`user_access_scopes` s ON s.user_id = ur.user_id AND s.scope_type = 'university' AND s.is_active = 1
WHERE ur.is_active = 1;

-- Informational: active deans per college and dean-flow audit trail.
SELECT c.college_id, c.college_name, GROUP_CONCAT(u.username ORDER BY u.username) AS active_deans
FROM `alrowad_uni_rust`.`colleges` c
LEFT JOIN `alrowad_uni_rust`.`user_access_scopes` s ON s.scope_type = 'college' AND s.scope_id = c.college_id AND s.is_active = 1
LEFT JOIN `alrowad_uni_rust`.`user_roles` ur ON ur.user_id = s.user_id AND ur.is_active = 1
LEFT JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id AND r.role_code = 'dean'
LEFT JOIN `alrowad_uni_rust`.`users` u ON u.user_id = s.user_id AND r.role_id IS NOT NULL
GROUP BY c.college_id, c.college_name;
