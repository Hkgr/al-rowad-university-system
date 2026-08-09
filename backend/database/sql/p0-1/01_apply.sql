-- P0-1 idempotent production apply. Requires a verified backup and successful preflight.
-- Abort rather than guessing if identity uniqueness or the official parent unit is ambiguous.
DELIMITER //
DROP PROCEDURE IF EXISTS p01_identity_indexes//
CREATE PROCEDURE p01_identity_indexes()
BEGIN
 IF EXISTS(SELECT student_id FROM users WHERE student_id IS NOT NULL GROUP BY student_id HAVING COUNT(*)>1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='P0-1 stopped: duplicate student identity links'; END IF;
 IF EXISTS(SELECT employee_id FROM users WHERE employee_id IS NOT NULL GROUP BY employee_id HAVING COUNT(*)>1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='P0-1 stopped: duplicate employee identity links'; END IF;
 IF (SELECT COUNT(*) FROM organizational_units WHERE unit_code='7') <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='P0-1 stopped: official parent unit code 7 is missing or ambiguous'; END IF;
 IF NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='users' AND index_name='uq_users_student_identity') THEN ALTER TABLE users ADD UNIQUE KEY uq_users_student_identity(student_id); END IF;
 IF NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='users' AND index_name='uq_users_employee_identity') THEN ALTER TABLE users ADD UNIQUE KEY uq_users_employee_identity(employee_id); END IF;
END//
CALL p01_identity_indexes()//
DROP PROCEDURE p01_identity_indexes//
DELIMITER ;

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

DELIMITER //
DROP PROCEDURE IF EXISTS p01_validate_scope_table//
CREATE PROCEDURE p01_validate_scope_table()
BEGIN
 IF NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='user_access_scopes' AND column_name='user_id' AND data_type='int' AND column_type NOT LIKE '%unsigned%') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='P0-1 stopped: user_access_scopes.user_id must match signed users.user_id'; END IF;
 IF NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='user_access_scopes' AND column_name='user_id' AND referenced_table_name='users' AND referenced_column_name='user_id') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='P0-1 stopped: user_access_scopes user FK missing'; END IF;
 IF NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='user_access_scopes' AND index_name='user_scope_unique' AND non_unique=0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='P0-1 stopped: user scope unique index missing'; END IF;
END//
CALL p01_validate_scope_table()//
DROP PROCEDURE p01_validate_scope_table//
DELIMITER ;

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



UPDATE organizational_units SET parent_unit_id=(SELECT organizational_unit_id FROM (SELECT organizational_unit_id FROM organizational_units WHERE unit_code='7') p),unit_name='مديرية شؤون الطلاب',unit_type_id=(SELECT unit_type_id FROM organizational_unit_types WHERE type_code='directorate' LIMIT 1),is_active=1 WHERE unit_code='73';

-- Migrate all known FK references from semantic legacy duplicates to official units.
UPDATE employees e JOIN organizational_units old ON old.organizational_unit_id=e.organizational_unit_id JOIN organizational_units official ON official.unit_code=CASE old.unit_code WHEN 'REG_OFFICE' THEN '732' WHEN 'EXAM_OFFICE' THEN '735' END SET e.organizational_unit_id=official.organizational_unit_id WHERE old.unit_code IN ('REG_OFFICE','EXAM_OFFICE');
UPDATE employee_positions e JOIN organizational_units old ON old.organizational_unit_id=e.organizational_unit_id JOIN organizational_units official ON official.unit_code=CASE old.unit_code WHEN 'REG_OFFICE' THEN '732' WHEN 'EXAM_OFFICE' THEN '735' END SET e.organizational_unit_id=official.organizational_unit_id WHERE old.unit_code IN ('REG_OFFICE','EXAM_OFFICE');
UPDATE employee_unit_assignments e JOIN organizational_units old ON old.organizational_unit_id=e.organizational_unit_id JOIN organizational_units official ON official.unit_code=CASE old.unit_code WHEN 'REG_OFFICE' THEN '732' WHEN 'EXAM_OFFICE' THEN '735' END SET e.organizational_unit_id=official.organizational_unit_id WHERE old.unit_code IN ('REG_OFFICE','EXAM_OFFICE');
UPDATE boards e JOIN organizational_units old ON old.organizational_unit_id=e.organizational_unit_id JOIN organizational_units official ON official.unit_code=CASE old.unit_code WHEN 'REG_OFFICE' THEN '732' WHEN 'EXAM_OFFICE' THEN '735' END SET e.organizational_unit_id=official.organizational_unit_id WHERE old.unit_code IN ('REG_OFFICE','EXAM_OFFICE');
UPDATE colleges e JOIN organizational_units old ON old.organizational_unit_id=e.organizational_unit_id JOIN organizational_units official ON official.unit_code=CASE old.unit_code WHEN 'REG_OFFICE' THEN '732' WHEN 'EXAM_OFFICE' THEN '735' END SET e.organizational_unit_id=official.organizational_unit_id WHERE old.unit_code IN ('REG_OFFICE','EXAM_OFFICE');
UPDATE departments e JOIN organizational_units old ON old.organizational_unit_id=e.organizational_unit_id JOIN organizational_units official ON official.unit_code=CASE old.unit_code WHEN 'REG_OFFICE' THEN '732' WHEN 'EXAM_OFFICE' THEN '735' END SET e.organizational_unit_id=official.organizational_unit_id WHERE old.unit_code IN ('REG_OFFICE','EXAM_OFFICE');
UPDATE organizational_units child JOIN organizational_units old ON old.organizational_unit_id=child.parent_unit_id JOIN organizational_units official ON official.unit_code=CASE old.unit_code WHEN 'REG_OFFICE' THEN '732' WHEN 'EXAM_OFFICE' THEN '735' END SET child.parent_unit_id=official.organizational_unit_id WHERE old.unit_code IN ('REG_OFFICE','EXAM_OFFICE');
UPDATE organizational_units SET is_active=0,description=CONCAT_WS(' ',description,'Superseded by official P0-1 unit 732/735') WHERE unit_code IN ('REG_OFFICE','EXAM_OFFICE');
COMMIT;
