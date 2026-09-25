-- President portal: read-only preflight, phpMyAdmin, explicit schema. No provisioning.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Existing deployed RBAC tables are prerequisites. IDs are resolved by code, never hardcoded.
SET @role_ok := (SELECT COUNT(*) = 1 AND COALESCE(MIN(is_active),0) = 1 FROM alrowad_uni_rust.roles WHERE role_code='university_president');
SET @module_conflict := (SELECT COUNT(*) FROM alrowad_uni_rust.system_modules WHERE module_code='president_portal' AND (is_active<>1 OR COALESCE(description,'') NOT LIKE '%[president-portal]%'));
SET @permission_conflict := (SELECT COUNT(*) FROM alrowad_uni_rust.permissions p LEFT JOIN alrowad_uni_rust.system_modules m ON m.module_id=p.module_id WHERE p.permission_code IN ('president_portal.access','president_portal.dashboard.view','president_portal.colleges.view','president_portal.students.view','president_portal.exams.view','president_portal.staff.view','president_portal.leadership.view','president_portal.followup.view','president_portal.reports.view') AND (p.is_active<>1 OR COALESCE(p.description,'') NOT LIKE '%[president-portal]%' OR COALESCE(m.module_code,'')<>'president_portal'));
SET @foreign_mapping := (SELECT COUNT(*) FROM alrowad_uni_rust.role_permissions rp JOIN alrowad_uni_rust.permissions p ON p.permission_id=rp.permission_id JOIN alrowad_uni_rust.roles r ON r.role_id=rp.role_id WHERE p.permission_code IN ('president_portal.access','president_portal.dashboard.view','president_portal.colleges.view','president_portal.students.view','president_portal.exams.view','president_portal.staff.view','president_portal.leadership.view','president_portal.followup.view','president_portal.reports.view') AND r.role_code<>'university_president');
SET @extra_permissions := (SELECT COUNT(*) FROM alrowad_uni_rust.permissions p JOIN alrowad_uni_rust.system_modules m ON m.module_id=p.module_id WHERE m.module_code='president_portal' AND p.permission_code NOT IN ('president_portal.access','president_portal.dashboard.view','president_portal.colleges.view','president_portal.students.view','president_portal.exams.view','president_portal.staff.view','president_portal.leadership.view','president_portal.followup.view','president_portal.reports.view'));
-- Require exact unique business keys; a composite containing the code is not sufficient.
SET @key_ok := (
 SELECT COUNT(DISTINCT table_name)=4 FROM (
  SELECT table_name, index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS cols
  FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND non_unique=0
    AND table_name IN ('roles','system_modules','permissions','role_permissions')
  GROUP BY table_name,index_name
 ) keys_found WHERE (table_name='roles' AND cols='role_code') OR (table_name='system_modules' AND cols='module_code')
  OR (table_name='permissions' AND cols='permission_code') OR (table_name='role_permissions' AND cols='role_id,permission_id')
);
SET @ready := @role_ok AND @key_ok AND @module_conflict=0 AND @permission_conflict=0 AND @foreign_mapping=0 AND @extra_permissions=0;

SELECT 'ROLE' AS section, role_code, is_active FROM alrowad_uni_rust.roles WHERE role_code='university_president';
SELECT 'EXISTING_ROLE_PERMISSIONS' AS section, p.permission_code FROM alrowad_uni_rust.role_permissions rp JOIN alrowad_uni_rust.roles r ON r.role_id=rp.role_id JOIN alrowad_uni_rust.permissions p ON p.permission_id=rp.permission_id WHERE r.role_code='university_president';
SELECT 'SCOPE_REFERENCE' AS section, organizational_unit_id, unit_code, is_active FROM alrowad_uni_rust.organizational_units WHERE unit_code='PRES';
SELECT 'KEY_INVENTORY' AS section, table_name, column_name, column_type FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name IN ('roles','permissions','role_permissions','system_modules') AND column_name IN ('role_id','permission_id','module_id');
SELECT 'OVERALL' AS section, IF(@ready,'READY','BLOCKED') AS result, @module_conflict AS module_conflicts, @permission_conflict AS permission_conflicts, @foreign_mapping AS foreign_mappings;
