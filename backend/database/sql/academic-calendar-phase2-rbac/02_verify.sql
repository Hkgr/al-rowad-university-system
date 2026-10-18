-- READ ONLY. Deployment is accepted only when the final visible row is OVERALL | PASS.
-- One ordinary SELECT result set; no dynamic operator reporting.

WITH facts AS (
    SELECT
      (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id=p.module_id WHERE p.permission_code='academic_calendar.manage' AND p.is_active=1 AND m.module_code='vice_presidency') AS permission_ok,
      (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id=rp.role_id WHERE p.permission_code='academic_calendar.manage' AND r.role_code='vice_president_scientific' AND r.is_active=1) AS scientific_mapping_ok,
      (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id=rp.role_id WHERE p.permission_code='academic_calendar.manage' AND r.role_code<>'vice_president_scientific') AS other_mappings,
      (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code='academic_calendar.manage' AND description LIKE '%[academic-calendar-phase2-rbac]%') AS owned_permission
)
SELECT report_section, result, details
FROM (
  SELECT 1 AS report_order, 'PERMISSION' AS report_section, IF(permission_ok=1,'PASS','FAIL') AS result, IF(owned_permission=1,'PACKAGE_OWNED','EXTERNAL_PRESERVED') AS details FROM facts
  UNION ALL SELECT 2, 'SCIENTIFIC_VP_MAPPING', IF(scientific_mapping_ok=1,'PASS','FAIL'), CONCAT('mapping_count=',scientific_mapping_ok) FROM facts
  UNION ALL SELECT 3, 'NO_OTHER_ROLE_MAPPING', IF(other_mappings=0,'PASS','FAIL'), CONCAT('unauthorized_mapping_count=',other_mappings) FROM facts
  UNION ALL SELECT 4, 'OVERALL', IF(permission_ok=1 AND scientific_mapping_ok=1 AND other_mappings=0,'PASS','FAIL'), 'Accept only when this row is PASS.' FROM facts
) report
ORDER BY report_order;
