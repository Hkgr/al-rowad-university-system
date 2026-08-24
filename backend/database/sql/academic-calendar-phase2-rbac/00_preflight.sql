-- READ ONLY. Run in phpMyAdmin and continue only when the final row is OVERALL | READY.
-- Uses the explicit target database and performs no application-data mutations.

SET @ac2_database_ok := (SELECT COUNT(*) = 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust');
SET @ac2_tables_ok := (SELECT COUNT(*) = 4 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_type = 'BASE TABLE' AND table_name IN ('system_modules', 'permissions', 'roles', 'role_permissions'));
SET @ac2_columns_ok := (SELECT COUNT(*) = 14 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND ((table_name = 'system_modules' AND column_name IN ('module_id', 'module_code', 'is_active')) OR (table_name = 'permissions' AND column_name IN ('permission_id', 'module_id', 'permission_code', 'permission_name', 'description', 'is_active')) OR (table_name = 'roles' AND column_name IN ('role_id', 'role_code', 'is_active')) OR (table_name = 'role_permissions' AND column_name IN ('role_id', 'permission_id'))));
SET @ac2_module_ok := (SELECT COUNT(*) = 1 FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'vice_presidency' AND is_active = 1);
SET @ac2_role_ok := (SELECT COUNT(*) = 1 FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_scientific' AND is_active = 1);
SET @ac2_permission_rows := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'academic_calendar.manage');
SET @ac2_permission_compatible := (SELECT COUNT(*) = 1 FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id WHERE p.permission_code = 'academic_calendar.manage' AND m.module_code = 'vice_presidency' AND p.is_active = 1);
SET @ac2_permission_ok := @ac2_permission_rows = 0 OR @ac2_permission_compatible;
SET @ac2_other_role_mappings := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id WHERE p.permission_code = 'academic_calendar.manage' AND r.role_code <> 'vice_president_scientific');
SET @ac2_ready := @ac2_database_ok AND @ac2_tables_ok AND @ac2_columns_ok AND @ac2_module_ok AND @ac2_role_ok AND @ac2_permission_ok AND @ac2_other_role_mappings = 0;

SELECT 'DATABASE_AND_STRUCTURE' AS report_section, IF(@ac2_database_ok AND @ac2_tables_ok AND @ac2_columns_ok, 'PASS', 'FAIL') AS result
UNION ALL SELECT 'MODULE_AND_ROLE', IF(@ac2_module_ok AND @ac2_role_ok, 'PASS', 'FAIL')
UNION ALL SELECT 'PERMISSION_COMPATIBILITY', IF(@ac2_permission_ok AND @ac2_other_role_mappings = 0, 'PASS', 'FAIL')
UNION ALL SELECT 'OVERALL', IF(@ac2_ready, 'READY', 'BLOCKED');
