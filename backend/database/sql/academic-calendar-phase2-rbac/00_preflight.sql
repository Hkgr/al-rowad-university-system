-- READ ONLY. Continue only when the final visible row is OVERALL | READY.
-- One ordinary SELECT result set; no dynamic operator reporting.

WITH facts AS (
    SELECT
      (SELECT COUNT(*) = 1 FROM information_schema.schemata WHERE schema_name='alrowad_uni_rust') AS database_ok,
      (SELECT COUNT(*) = 4 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_type='BASE TABLE' AND table_name IN ('system_modules','permissions','roles','role_permissions')) AS tables_ok,
      (SELECT COUNT(*) = 14 FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND ((table_name='system_modules' AND column_name IN ('module_id','module_code','is_active')) OR (table_name='permissions' AND column_name IN ('permission_id','module_id','permission_code','permission_name','description','is_active')) OR (table_name='roles' AND column_name IN ('role_id','role_code','is_active')) OR (table_name='role_permissions' AND column_name IN ('role_id','permission_id')))) AS columns_ok,
      (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code='vice_presidency' AND is_active=1) AS module_count,
      (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code='vice_president_scientific' AND is_active=1) AS role_count,
      (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code='academic_calendar.manage') AS permission_rows,
      (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id=p.module_id WHERE p.permission_code='academic_calendar.manage' AND p.is_active=1 AND m.module_code='vice_presidency') AS compatible_permission,
      (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id=p.module_id WHERE p.permission_code='academic_calendar.manage' AND p.is_active=1 AND m.module_code='vice_presidency' AND p.description LIKE '%[academic-calendar-phase2-rbac]%') AS owned_permission,
      (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id=rp.role_id WHERE p.permission_code='academic_calendar.manage' AND r.role_code='vice_president_scientific' AND r.is_active=1) AS scientific_mapping,
      (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id=rp.role_id WHERE p.permission_code='academic_calendar.manage' AND r.role_code<>'vice_president_scientific') AS other_mappings
),
readiness AS (
    SELECT facts.*,
      (database_ok AND tables_ok AND columns_ok AND module_count=1 AND role_count=1
       AND (permission_rows=0 OR compatible_permission=1)
       AND other_mappings=0
       AND (permission_rows=0 OR owned_permission=1 OR scientific_mapping=1)) AS ready
    FROM facts
)
SELECT report_section, result, details
FROM (
  SELECT 1 AS report_order, 'DATABASE_AND_STRUCTURE' AS report_section, IF(database_ok AND tables_ok AND columns_ok,'PASS','FAIL') AS result, CONCAT('database=',database_ok,'; tables=',tables_ok,'; columns=',columns_ok) AS details FROM readiness
  UNION ALL SELECT 2, 'MODULE_AND_ROLE', IF(module_count=1 AND role_count=1,'PASS','FAIL'), CONCAT('module=',module_count,'; role=',role_count) FROM readiness
  UNION ALL SELECT 3, 'PERMISSION_OWNERSHIP', IF((permission_rows=0 OR compatible_permission=1) AND other_mappings=0 AND (permission_rows=0 OR owned_permission=1 OR scientific_mapping=1),'PASS','FAIL'), CASE WHEN permission_rows=0 THEN 'ABSENT_PACKAGE_MAY_CREATE' WHEN owned_permission=1 THEN 'PACKAGE_OWNED' WHEN compatible_permission=1 AND scientific_mapping=1 THEN 'EXTERNAL_PRESERVED' WHEN compatible_permission=1 THEN 'EXTERNAL_MAPPING_MISSING_BLOCKED' ELSE 'CONFLICTING' END FROM readiness
  UNION ALL SELECT 4, 'OVERALL', IF(ready,'READY','BLOCKED'), 'Run 01_apply.sql only when this row is READY.' FROM readiness
) report
ORDER BY report_order;
