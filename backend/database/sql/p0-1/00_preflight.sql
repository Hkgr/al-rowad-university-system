-- P0-1 read-only preflight. Run against the target MySQL/MariaDB database.
SELECT VERSION() AS server_version, DATABASE() AS database_name;
SELECT table_name, engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('users','students','employees','roles','permissions','role_permissions','organizational_units','organizational_unit_types','user_access_scopes');
SELECT table_name,column_name,column_type,is_nullable,column_key FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name IN ('users','students','employees','user_access_scopes') AND column_name IN ('user_id','student_id','employee_id','scope_id','scope_type') ORDER BY table_name,ordinal_position;
SELECT index_name,column_name,non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name IN ('users','user_access_scopes') ORDER BY table_name,index_name,seq_in_index;
SELECT constraint_name,table_name,column_name,referenced_table_name,referenced_column_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name IN ('users','user_access_scopes') AND referenced_table_name IS NOT NULL;

DROP TEMPORARY TABLE IF EXISTS p01_expected;
CREATE TEMPORARY TABLE p01_expected(role_code VARCHAR(80), permission_code VARCHAR(120), PRIMARY KEY(role_code,permission_code));
INSERT INTO p01_expected VALUES
('exam_officer','students.view'),('exam_officer','academic_structure.view'),('exam_officer','courses.view'),('exam_officer','registration.view'),('exam_officer','exams.view'),('exam_officer','exams.manage'),('exam_officer','grades.view'),('exam_officer','grades.manage'),('exam_officer','system_settings.view'),
('registration_officer','students.view'),('registration_officer','students.manage'),('registration_officer','admissions.view'),('registration_officer','admissions.manage'),('registration_officer','academic_structure.view'),('registration_officer','courses.view'),('registration_officer','registration.view'),('registration_officer','registration.manage'),('registration_officer','system_settings.view'),
('doctor_instructor','courses.view'),('doctor_instructor','students.view'),('doctor_instructor','attendance.view'),('doctor_instructor','attendance.manage'),('doctor_instructor','grades.view'),('doctor_instructor','grades.manage'),
('student','students.view'),('student','registration.view'),('student','grades.view'),('student','attendance.view');
SELECT 'MISSING' finding,e.* FROM p01_expected e LEFT JOIN roles r USING(role_code) LEFT JOIN permissions p USING(permission_code) LEFT JOIN role_permissions rp ON rp.role_id=r.role_id AND rp.permission_id=p.permission_id WHERE rp.role_permission_id IS NULL
UNION ALL
SELECT 'EXCESS',r.role_code,p.permission_code FROM role_permissions rp JOIN roles r ON r.role_id=rp.role_id JOIN permissions p ON p.permission_id=rp.permission_id LEFT JOIN p01_expected e ON e.role_code=r.role_code AND e.permission_code=p.permission_code WHERE r.role_code IN ('exam_officer','registration_officer','doctor_instructor','student') AND e.role_code IS NULL;

SELECT 'linked' status,user_id,email,student_id,employee_id FROM users WHERE student_id IS NOT NULL OR employee_id IS NOT NULL;
SELECT 'unlinked' status,user_id,email,NULL student_id,NULL employee_id FROM users WHERE student_id IS NULL AND employee_id IS NULL;
SELECT 'conflicting-exact-email' status,u.user_id,u.email,COUNT(DISTINCT s.student_id) student_matches,COUNT(DISTINCT e.employee_id) employee_matches FROM users u LEFT JOIN students s ON BINARY s.email=BINARY u.email LEFT JOIN employees e ON BINARY e.email=BINARY u.email GROUP BY u.user_id,u.email HAVING student_matches+employee_matches>1;
