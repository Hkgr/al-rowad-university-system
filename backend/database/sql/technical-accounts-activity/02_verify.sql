-- Technical Office: account editing + activity feed — VERIFY. Read-only. Every row should be PASS.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT 'two permissions active in users_permissions' AS check_name,
       IF(COUNT(*) = 2 AND SUM(p.is_active = 1 AND sm.module_code = 'users_permissions') = 2, 'PASS', 'FAIL') AS result
FROM `alrowad_uni_rust`.`permissions` p LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
WHERE p.permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')
UNION ALL
SELECT 'technical_team holds both', IF(COUNT(*) = 2, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'technical_team'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')
UNION ALL
SELECT 'no other role holds them (besides super_admin)', IF(COUNT(*) = 0, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code NOT IN ('technical_team', 'super_admin')
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')
UNION ALL
SELECT 'technical_team holds no users_permissions.* / system_settings.manage', IF(COUNT(*) = 0, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'technical_team'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code LIKE 'users\_permissions.%' OR p.permission_code = 'system_settings.manage'
UNION ALL
SELECT 'indexes present (3)', IF(COUNT(DISTINCT CONCAT(table_name, '.', index_name)) = 3, 'PASS', 'FAIL')
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND ((table_name = 'user_activity_logs' AND index_name IN ('idx_ual_created_at', 'idx_ual_module_created'))
    OR (table_name = 'login_audit_logs' AND index_name = 'idx_lal_attempted_at'))
UNION ALL
SELECT 'no password hash or token stored in activity descriptions', IF(COUNT(*) = 0, 'PASS', 'FAIL')
FROM `alrowad_uni_rust`.`user_activity_logs` WHERE description LIKE '%$2y$%' OR description LIKE '%"password%' OR description LIKE '%plainTextToken%';
