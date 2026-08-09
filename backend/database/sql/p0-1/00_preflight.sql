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

-- Identity conflicts must be empty before unique indexes are applied.
SELECT 'duplicate-student-link' finding,student_id,GROUP_CONCAT(user_id ORDER BY user_id) user_ids FROM users WHERE student_id IS NOT NULL GROUP BY student_id HAVING COUNT(*)>1;
SELECT 'duplicate-employee-link' finding,employee_id,GROUP_CONCAT(user_id ORDER BY user_id) user_ids FROM users WHERE employee_id IS NOT NULL GROUP BY employee_id HAVING COUNT(*)>1;

-- Official hierarchy, legacy duplicates, employees, and every FK reference to organizational units.
SELECT u.organizational_unit_id,u.unit_code,u.unit_name,t.type_code,u.parent_unit_id,u.is_active FROM organizational_units u JOIN organizational_unit_types t ON t.unit_type_id=u.unit_type_id WHERE u.unit_code IN ('7','73','731','732','733','734','735','736','REG_OFFICE','EXAM_OFFICE') ORDER BY u.unit_code;
SELECT e.employee_id,e.employee_number,u.unit_code FROM employees e JOIN organizational_units u ON u.organizational_unit_id=e.organizational_unit_id WHERE u.unit_code IN ('REG_OFFICE','EXAM_OFFICE','732','735');
SELECT table_name,column_name,constraint_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND referenced_table_name='organizational_units' AND referenced_column_name='organizational_unit_id' ORDER BY table_name,column_name;
SELECT 'legacy-reference' finding,'boards' source,COUNT(*) total FROM boards b JOIN organizational_units u ON u.organizational_unit_id=b.organizational_unit_id WHERE u.unit_code IN ('REG_OFFICE','EXAM_OFFICE') UNION ALL
SELECT 'legacy-reference','colleges',COUNT(*) FROM colleges x JOIN organizational_units u ON u.organizational_unit_id=x.organizational_unit_id WHERE u.unit_code IN ('REG_OFFICE','EXAM_OFFICE') UNION ALL
SELECT 'legacy-reference','departments',COUNT(*) FROM departments x JOIN organizational_units u ON u.organizational_unit_id=x.organizational_unit_id WHERE u.unit_code IN ('REG_OFFICE','EXAM_OFFICE') UNION ALL
SELECT 'legacy-reference','employee_positions',COUNT(*) FROM employee_positions x JOIN organizational_units u ON u.organizational_unit_id=x.organizational_unit_id WHERE u.unit_code IN ('REG_OFFICE','EXAM_OFFICE') UNION ALL
SELECT 'legacy-reference','employee_unit_assignments',COUNT(*) FROM employee_unit_assignments x JOIN organizational_units u ON u.organizational_unit_id=x.organizational_unit_id WHERE u.unit_code IN ('REG_OFFICE','EXAM_OFFICE') UNION ALL
SELECT 'legacy-reference','children',COUNT(*) FROM organizational_units x JOIN organizational_units u ON u.organizational_unit_id=x.parent_unit_id WHERE u.unit_code IN ('REG_OFFICE','EXAM_OFFICE');

-- Invalid/orphan scopes. The first deployment has no scope table yet.
DELIMITER //
DROP PROCEDURE IF EXISTS p01_preflight_scopes//
CREATE PROCEDURE p01_preflight_scopes()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='user_access_scopes') THEN
  SELECT s.*,CASE s.scope_type WHEN 'university' THEN ou.organizational_unit_id WHEN 'college' THEN c.college_id WHEN 'department' THEN d.department_id WHEN 'program' THEN p.academic_program_id WHEN 'section' THEN co.course_offering_id ELSE NULL END referenced_id
  FROM user_access_scopes s LEFT JOIN organizational_units ou ON s.scope_type='university' AND ou.organizational_unit_id=s.scope_id AND EXISTS(SELECT 1 FROM organizational_unit_types t WHERE t.unit_type_id=ou.unit_type_id AND t.type_code='university') LEFT JOIN colleges c ON s.scope_type='college' AND c.college_id=s.scope_id LEFT JOIN departments d ON s.scope_type='department' AND d.department_id=s.scope_id LEFT JOIN academic_programs p ON s.scope_type='program' AND p.academic_program_id=s.scope_id LEFT JOIN course_offerings co ON s.scope_type='section' AND co.course_offering_id=s.scope_id WHERE CASE s.scope_type WHEN 'university' THEN ou.organizational_unit_id WHEN 'college' THEN c.college_id WHEN 'department' THEN d.department_id WHEN 'program' THEN p.academic_program_id WHEN 'section' THEN co.course_offering_id ELSE NULL END IS NULL;
 ELSE SELECT 'user_access_scopes not created yet' scope_preflight_status; END IF;
END//
CALL p01_preflight_scopes()//
DROP PROCEDURE p01_preflight_scopes//
DELIMITER ;
