-- P0-1 idempotent production apply. Requires a verified backup and successful preflight.
START TRANSACTION;
CREATE TABLE IF NOT EXISTS user_access_scopes (
 user_access_scope_id BIGINT NOT NULL AUTO_INCREMENT,
 user_id INT NOT NULL,
 scope_type ENUM('university','college','department','program','section') NOT NULL,
 scope_id INT NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NULL DEFAULT NULL,
 updated_at TIMESTAMP NULL DEFAULT NULL,
 PRIMARY KEY(user_access_scope_id),
 UNIQUE KEY user_scope_unique(user_id,scope_type,scope_id),
 KEY active_scope_lookup(scope_type,scope_id,is_active),
 CONSTRAINT fk_user_access_scopes_user FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TEMPORARY TABLE IF EXISTS p01_expected;
CREATE TEMPORARY TABLE p01_expected(role_code VARCHAR(80), permission_code VARCHAR(120), PRIMARY KEY(role_code,permission_code));
INSERT INTO p01_expected VALUES
('exam_officer','students.view'),('exam_officer','academic_structure.view'),('exam_officer','courses.view'),('exam_officer','registration.view'),('exam_officer','exams.view'),('exam_officer','exams.manage'),('exam_officer','grades.view'),('exam_officer','grades.manage'),('exam_officer','system_settings.view'),
('registration_officer','students.view'),('registration_officer','students.manage'),('registration_officer','admissions.view'),('registration_officer','admissions.manage'),('registration_officer','academic_structure.view'),('registration_officer','courses.view'),('registration_officer','registration.view'),('registration_officer','registration.manage'),('registration_officer','system_settings.view'),
('doctor_instructor','courses.view'),('doctor_instructor','students.view'),('doctor_instructor','attendance.view'),('doctor_instructor','attendance.manage'),('doctor_instructor','grades.view'),('doctor_instructor','grades.manage'),
('student','students.view'),('student','registration.view'),('student','grades.view'),('student','attendance.view');
DELETE rp FROM role_permissions rp JOIN roles r ON r.role_id=rp.role_id LEFT JOIN permissions p ON p.permission_id=rp.permission_id LEFT JOIN p01_expected x ON x.role_code=r.role_code AND x.permission_code=p.permission_code WHERE r.role_code IN ('exam_officer','registration_officer','doctor_instructor','student') AND x.role_code IS NULL;
INSERT INTO role_permissions(role_id,permission_id,granted_at)
SELECT r.role_id,p.permission_id,CURRENT_TIMESTAMP FROM p01_expected x JOIN roles r USING(role_code) JOIN permissions p USING(permission_code) LEFT JOIN role_permissions rp ON rp.role_id=r.role_id AND rp.permission_id=p.permission_id WHERE rp.role_permission_id IS NULL;

INSERT INTO organizational_unit_types(type_code,type_name,description,is_active,created_at,updated_at)
SELECT 'administration','إدارة','Administrative unit required by P0-1',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM organizational_unit_types WHERE type_code='administration');
INSERT INTO organizational_units(unit_code,unit_name,unit_type_id,parent_unit_id,description,is_active,created_at,updated_at)
VALUES ('73','مديرية شؤون الطلاب',(SELECT unit_type_id FROM organizational_unit_types WHERE type_code='directorate' LIMIT 1),NULL,'P0-1 organizational reference',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE unit_name=VALUES(unit_name),unit_type_id=VALUES(unit_type_id),is_active=1,updated_at=CURRENT_TIMESTAMP;
INSERT INTO organizational_units(unit_code,unit_name,unit_type_id,parent_unit_id,description,is_active,created_at,updated_at)
SELECT x.code,x.name,t.unit_type_id,p.organizational_unit_id,'P0-1 organizational reference',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP FROM (
 SELECT '731' code,'مكتب الإرشاد والتوجيه' name,'office' type_code UNION ALL SELECT '732','مكتب القبول والتسجيل','office' UNION ALL SELECT '733','مكتب الخدمات الطلابية','office' UNION ALL SELECT '734','مكتب المنح والإيفاد والتبادل الطلابي','office' UNION ALL SELECT '735','إدارة الامتحانات','administration' UNION ALL SELECT '736','مكتب التوثيق والتدقيق','office'
) x JOIN organizational_unit_types t ON t.type_code=x.type_code JOIN organizational_units p ON p.unit_code='73'
ON DUPLICATE KEY UPDATE unit_name=VALUES(unit_name),unit_type_id=VALUES(unit_type_id),parent_unit_id=VALUES(parent_unit_id),is_active=1,updated_at=CURRENT_TIMESTAMP;
COMMIT;
