-- Idempotent manual DML. No users, employees, dean roles or scopes are created here.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @tag := '[administrative-vp-personnel]';
SET @ready := IF(
  (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'vice_presidency' AND is_active = 1) = 1
  AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative' AND is_active = 1) = 1
  AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id
       WHERE p.permission_code IN ('administrative_staff.view', 'administrative_staff.manage',
                                   'administrative_deans.view', 'administrative_deans.manage')
       AND (p.is_active <> 1 OR m.module_code <> 'vice_presidency')) = 0
  AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
       JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
       JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
       WHERE p.permission_code IN ('administrative_staff.view', 'administrative_staff.manage',
                                   'administrative_deans.view', 'administrative_deans.manage')
         AND r.role_code <> 'vice_president_administrative') = 0, 1, 0);
START TRANSACTION;
INSERT INTO `alrowad_uni_rust`.`permissions`
 (module_id, permission_code, permission_name, description, is_active, created_at, updated_at)
SELECT m.module_id, definitions.code, definitions.name, CONCAT(definitions.description, ' ', @tag), 1, NOW(), NOW()
FROM (SELECT 'administrative_staff.view' AS code, 'عرض المدرسين الإداري' AS name, 'View university teaching staff' AS description
      UNION ALL SELECT 'administrative_staff.manage', 'إدارة المدرسين الإداري', 'Maintain faculty profiles and college membership'
      UNION ALL SELECT 'administrative_deans.view', 'عرض عمداء الكليات', 'View dean accounts and assigned colleges'
      UNION ALL SELECT 'administrative_deans.manage', 'إدارة عمداء الكليات', 'Provision narrowly scoped dean accounts') definitions
JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_code = 'vice_presidency' AND m.is_active = 1
WHERE @ready = 1 AND NOT EXISTS (
  SELECT 1 FROM `alrowad_uni_rust`.`permissions` p WHERE p.permission_code = definitions.code);
INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, NOW()
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code IN
 ('administrative_staff.view', 'administrative_staff.manage', 'administrative_deans.view', 'administrative_deans.manage')
WHERE @ready = 1 AND r.role_code = 'vice_president_administrative'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp
                  WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id);
COMMIT;
SELECT IF(@ready = 1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
    JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
    JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
    WHERE r.role_code = 'vice_president_administrative' AND p.permission_code IN
     ('administrative_staff.view', 'administrative_staff.manage', 'administrative_deans.view', 'administrative_deans.manage')) = 4,
    'APPLIED', 'BLOCKED') AS apply_status;
