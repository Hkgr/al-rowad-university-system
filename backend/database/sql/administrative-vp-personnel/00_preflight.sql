-- Read only. All five results must be READY before running 01_apply.sql.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SELECT IF(COUNT(*) = 1, 'READY', 'BLOCKED') AS database_check
FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust';
SELECT IF(COUNT(*) = 4, 'READY', 'BLOCKED') AS required_tables
FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN ('permissions', 'roles', 'role_permissions', 'system_modules');
SELECT IF(COUNT(*) = 2, 'READY', 'BLOCKED') AS module_and_role
FROM (SELECT 1 AS ok FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'vice_presidency' AND is_active = 1
      UNION ALL SELECT 1 FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative' AND is_active = 1) prerequisites;
SELECT IF(COUNT(*) = 0, 'READY', 'BLOCKED') AS existing_permission_conflicts
FROM `alrowad_uni_rust`.`permissions` p
LEFT JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id
WHERE p.permission_code IN ('administrative_staff.view', 'administrative_staff.manage',
                            'administrative_deans.view', 'administrative_deans.manage')
  AND (p.is_active <> 1 OR m.module_code <> 'vice_presidency');
SELECT IF(COUNT(*) = 0, 'READY', 'BLOCKED') AS unintended_role_grants
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
WHERE p.permission_code IN ('administrative_staff.view', 'administrative_staff.manage',
                            'administrative_deans.view', 'administrative_deans.manage')
  AND r.role_code <> 'vice_president_administrative';
