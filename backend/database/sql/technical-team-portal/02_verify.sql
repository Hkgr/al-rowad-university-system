-- Technical Office portal — VERIFY. Read-only. Every row should report PASS.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT 'technical_team role active and system' AS check_name,
       IF(COUNT(*) = 1 AND SUM(is_active = 1 AND is_system_role = 1) = 1, 'PASS', 'FAIL') AS result
FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'technical_team'
UNION ALL
SELECT 'three portal permissions active in users_permissions module',
       IF(COUNT(*) = 3 AND SUM(p.is_active = 1 AND sm.module_code = 'users_permissions') = 3, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`permissions` p
LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
WHERE p.permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage')
UNION ALL
SELECT 'technical_team exact permission matrix',
       IF(GROUP_CONCAT(p.permission_code ORDER BY p.permission_code SEPARATOR ',')
          = 'technical_portal.access,user_accounts.manage,user_accounts.view', 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'technical_team'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
UNION ALL
SELECT 'technical_team has no users_permissions.* / system_settings.manage',
       IF(COUNT(*) = 0, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'technical_team'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code LIKE 'users\_permissions.%' OR p.permission_code = 'system_settings.manage'
UNION ALL
SELECT 'super_admin holds user_accounts.view and user_accounts.manage',
       IF(COUNT(*) = 2, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'super_admin'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code IN ('user_accounts.view', 'user_accounts.manage')
UNION ALL
SELECT 'no role other than super_admin holds users_permissions.manage',
       IF(COUNT(*) = 0, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code <> 'super_admin'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id AND p.permission_code = 'users_permissions.manage'
UNION ALL
SELECT 'no duplicate user_roles pairs',
       IF(COUNT(*) = 0, 'PASS', 'FAIL')
FROM (SELECT user_id, role_id FROM `alrowad_uni_rust`.`user_roles` GROUP BY user_id, role_id HAVING COUNT(*) > 1) d
UNION ALL
SELECT 'no duplicate role_permissions pairs',
       IF(COUNT(*) = 0, 'PASS', 'FAIL')
FROM (SELECT role_id, permission_id FROM `alrowad_uni_rust`.`role_permissions` GROUP BY role_id, permission_id HAVING COUNT(*) > 1) d
UNION ALL
SELECT 'at least one active super_admin account',
       IF(COUNT(DISTINCT u.user_id) >= 1, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`users` u
JOIN `alrowad_uni_rust`.`account_statuses` s ON s.account_status_id = u.account_status_id AND s.status_code = 'active'
JOIN `alrowad_uni_rust`.`user_roles` ur ON ur.user_id = u.user_id AND ur.is_active = 1
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id AND r.role_code = 'super_admin' AND r.is_active = 1
UNION ALL
SELECT 'account statuses active/disabled exist',
       IF(COUNT(DISTINCT status_code) = 2, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`account_statuses` WHERE status_code IN ('active', 'disabled');

-- Informational: who currently holds technical_team (assigned through the portal by super_admin).
SELECT u.user_id, u.username, ur.is_active, ur.assigned_at, assigner.username AS assigned_by
FROM `alrowad_uni_rust`.`user_roles` ur
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id AND r.role_code = 'technical_team'
JOIN `alrowad_uni_rust`.`users` u ON u.user_id = ur.user_id
LEFT JOIN `alrowad_uni_rust`.`users` assigner ON assigner.user_id = ur.assigned_by_user_id;
