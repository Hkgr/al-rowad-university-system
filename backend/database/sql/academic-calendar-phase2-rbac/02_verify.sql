-- READ ONLY. Deployment is accepted only when the final row is OVERALL | PASS.

SET @ac2_permission_ok := (SELECT COUNT(*) = 1 FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id WHERE p.permission_code = 'academic_calendar.manage' AND p.is_active = 1 AND m.module_code = 'vice_presidency');
SET @ac2_scientific_mapping_ok := (SELECT COUNT(*) = 1 FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id WHERE p.permission_code = 'academic_calendar.manage' AND r.role_code = 'vice_president_scientific' AND r.is_active = 1);
SET @ac2_no_other_mapping := (SELECT COUNT(*) = 0 FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id WHERE p.permission_code = 'academic_calendar.manage' AND r.role_code <> 'vice_president_scientific');

SELECT 'PERMISSION' AS report_section, IF(@ac2_permission_ok, 'PASS', 'FAIL') AS result
UNION ALL SELECT 'SCIENTIFIC_VP_MAPPING', IF(@ac2_scientific_mapping_ok, 'PASS', 'FAIL')
UNION ALL SELECT 'NO_OTHER_ROLE_MAPPING', IF(@ac2_no_other_mapping, 'PASS', 'FAIL')
UNION ALL SELECT 'OVERALL', IF(@ac2_permission_ok AND @ac2_scientific_mapping_ok AND @ac2_no_other_mapping, 'PASS', 'FAIL');
