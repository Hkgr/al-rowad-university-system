-- President portal: scoped RBAC only; no role, user, password, scope, workflow or business data creation.
-- Stop on any SQL error. Run during maintenance, after 00_preflight. Partial compatible reruns supported.
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

START TRANSACTION;
INSERT INTO alrowad_uni_rust.system_modules (module_code,module_name,description,is_active)
SELECT 'president_portal','بوابة رئيس الجامعة','Read-only president portal [president-portal]',1 FROM DUAL
WHERE @ready AND NOT EXISTS (SELECT 1 FROM alrowad_uni_rust.system_modules WHERE module_code='president_portal');
INSERT INTO alrowad_uni_rust.permissions (module_id,permission_code,permission_name,description,is_active)
SELECT m.module_id,d.code,d.name,'Dedicated read-only president section [president-portal]',1
FROM (SELECT 'president_portal.access' AS code, 'دخول بوابة الرئيس' AS name
UNION ALL SELECT 'president_portal.dashboard.view' AS code, 'لوحة الرئيس' AS name
UNION ALL SELECT 'president_portal.colleges.view' AS code, 'الكليات والبرامج' AS name
UNION ALL SELECT 'president_portal.students.view' AS code, 'الطلاب والشؤون الأكاديمية' AS name
UNION ALL SELECT 'president_portal.exams.view' AS code, 'الامتحانات والنتائج' AS name
UNION ALL SELECT 'president_portal.staff.view' AS code, 'المدرسون والكوادر' AS name
UNION ALL SELECT 'president_portal.leadership.view' AS code, 'النيابات والإدارات' AS name
UNION ALL SELECT 'president_portal.followup.view' AS code, 'القرارات والمتابعة للقراءة' AS name
UNION ALL SELECT 'president_portal.reports.view' AS code, 'التقارير' AS name) d CROSS JOIN alrowad_uni_rust.system_modules m
WHERE @ready AND m.module_code='president_portal' AND NOT EXISTS (SELECT 1 FROM alrowad_uni_rust.permissions p WHERE p.permission_code=d.code);
INSERT INTO alrowad_uni_rust.role_permissions (role_id,permission_id)
SELECT r.role_id,p.permission_id FROM alrowad_uni_rust.roles r CROSS JOIN alrowad_uni_rust.permissions p
WHERE @ready AND r.role_code='university_president' AND p.permission_code IN ('president_portal.access','president_portal.dashboard.view','president_portal.colleges.view','president_portal.students.view','president_portal.exams.view','president_portal.staff.view','president_portal.leadership.view','president_portal.followup.view','president_portal.reports.view')
AND NOT EXISTS (SELECT 1 FROM alrowad_uni_rust.role_permissions rp WHERE rp.role_id=r.role_id AND rp.permission_id=p.permission_id);
COMMIT;
SELECT 'OVERALL' AS section, IF(@ready,'APPLIED_OR_ALREADY_APPLIED','BLOCKED') AS result;
