SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SELECT p.permission_code,
  IF(p.is_active = 1 AND m.module_code = 'vice_presidency'
     AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp
       JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
       WHERE rp.permission_id = p.permission_id AND r.role_code = 'vice_president_administrative'),
    'PASS', 'FAIL') AS result
FROM `alrowad_uni_rust`.`permissions` p
JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id
WHERE p.permission_code IN ('administrative_staff.view', 'administrative_staff.manage',
                            'administrative_deans.view', 'administrative_deans.manage');
SELECT IF(COUNT(*) = 4, 'PASS', 'FAIL') AS expected_permissions
FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN
 ('administrative_staff.view', 'administrative_staff.manage', 'administrative_deans.view', 'administrative_deans.manage');
SELECT IF(COUNT(*) = 0, 'PASS', 'FAIL') AS no_unintended_grants
FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code IN ('administrative_staff.view', 'administrative_staff.manage',
                            'administrative_deans.view', 'administrative_deans.manage')
  AND r.role_code <> 'vice_president_administrative';
