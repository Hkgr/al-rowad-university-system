-- Scientific VP course management. MANUAL ONLY, MariaDB 10.11.
-- Run 00_preflight first. Maintenance window required; stop on the FIRST error.
-- Resumable: owned functions/triggers are replaced while is_ready=0. No academic data is rewritten.
USE alrowad_uni_rust;
DELIMITER //
CREATE OR REPLACE PROCEDURE sc_catalog_apply_guard()
COMMENT 'scientific-course-management-v1'
BEGIN
  IF EXISTS(SELECT 1 FROM information_schema.routines WHERE routine_schema='alrowad_uni_rust' AND routine_name IN ('sc_catalog_touch','sc_catalog_program_used','sc_catalog_course_used','sc_catalog_membership_program') AND routine_comment<>'scientific-course-management-v1')
OR EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND (LEFT(trigger_name,7)='sc_cat_' OR LEFT(trigger_name,7)='sc_use_' OR LEFT(trigger_name,7)='sc_ref_') AND action_statement NOT LIKE '%CALL sc_catalog_touch()%')
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_routine_namespace_conflict'; END IF;
  IF NOT (EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='courses' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='course_code')
  AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='program_courses' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='academic_program_id,course_id')
  AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_requirement_groups' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='group_code')
  AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_requirement_groups' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='academic_program_id,requirement_scope,requirement_type')
  AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='program_course_requirement_groups' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='program_course_id')
  AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='course_departments' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='course_id,department_id')
  AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='course_prerequisites' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='course_id,prerequisite_course_id') AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='program_courses' AND k.column_name='course_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='courses' AND k.referenced_column_name='course_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
  AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='program_courses' AND k.column_name='academic_program_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_programs' AND k.referenced_column_name='academic_program_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
  AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='program_courses' AND k.column_name='academic_level_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_levels' AND k.referenced_column_name='academic_level_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
  AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='program_courses' AND k.column_name='recommended_semester_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='semesters' AND k.referenced_column_name='semester_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
  AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='program_course_requirement_groups' AND k.column_name='program_course_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='program_courses' AND k.referenced_column_name='program_course_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
  AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='program_course_requirement_groups' AND k.column_name='requirement_group_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_requirement_groups' AND k.referenced_column_name='requirement_group_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
  AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='academic_requirement_groups' AND k.column_name='academic_program_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_programs' AND k.referenced_column_name='academic_program_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
  AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='course_departments' AND k.column_name='course_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='courses' AND k.referenced_column_name='course_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
  AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='course_departments' AND k.column_name='department_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='departments' AND k.referenced_column_name='department_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
  AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='course_prerequisites' AND k.column_name='course_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='courses' AND k.referenced_column_name='course_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
  AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='course_prerequisites' AND k.column_name='prerequisite_course_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='courses' AND k.referenced_column_name='course_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
  AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='course_prerequisites' AND k.column_name='minimum_result_status_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='result_statuses' AND k.referenced_column_name='result_status_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION')))
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_relational_contract_invalid'; END IF;
  IF EXISTS (
    SELECT 1 FROM (
      SELECT 'courses' table_name,'course_id' column_name
            UNION ALL SELECT 'courses' table_name,'course_code' column_name
            UNION ALL SELECT 'courses' table_name,'credit_hours' column_name
            UNION ALL SELECT 'courses' table_name,'theoretical_hours' column_name
            UNION ALL SELECT 'courses' table_name,'practical_hours' column_name
            UNION ALL SELECT 'courses' table_name,'is_active' column_name
            UNION ALL SELECT 'courses' table_name,'created_at' column_name
            UNION ALL SELECT 'courses' table_name,'updated_at' column_name
            UNION ALL SELECT 'courses' table_name,'course_name' column_name
            UNION ALL SELECT 'courses' table_name,'description' column_name
            UNION ALL SELECT 'academic_programs' table_name,'academic_program_id' column_name
            UNION ALL SELECT 'academic_programs' table_name,'department_id' column_name
            UNION ALL SELECT 'academic_programs' table_name,'program_code' column_name
            UNION ALL SELECT 'academic_programs' table_name,'degree_level' column_name
            UNION ALL SELECT 'academic_programs' table_name,'total_credit_hours' column_name
            UNION ALL SELECT 'academic_programs' table_name,'duration_years' column_name
            UNION ALL SELECT 'academic_programs' table_name,'is_active' column_name
            UNION ALL SELECT 'academic_programs' table_name,'created_at' column_name
            UNION ALL SELECT 'academic_programs' table_name,'updated_at' column_name
            UNION ALL SELECT 'academic_programs' table_name,'program_name' column_name
            UNION ALL SELECT 'academic_programs' table_name,'description' column_name
            UNION ALL SELECT 'program_courses' table_name,'program_course_id' column_name
            UNION ALL SELECT 'program_courses' table_name,'academic_program_id' column_name
            UNION ALL SELECT 'program_courses' table_name,'course_id' column_name
            UNION ALL SELECT 'program_courses' table_name,'academic_level_id' column_name
            UNION ALL SELECT 'program_courses' table_name,'recommended_semester_id' column_name
            UNION ALL SELECT 'program_courses' table_name,'course_type' column_name
            UNION ALL SELECT 'program_courses' table_name,'is_active' column_name
            UNION ALL SELECT 'program_courses' table_name,'created_at' column_name
            UNION ALL SELECT 'program_courses' table_name,'updated_at' column_name
            UNION ALL SELECT 'academic_requirement_groups' table_name,'requirement_group_id' column_name
            UNION ALL SELECT 'academic_requirement_groups' table_name,'academic_program_id' column_name
            UNION ALL SELECT 'academic_requirement_groups' table_name,'group_code' column_name
            UNION ALL SELECT 'academic_requirement_groups' table_name,'requirement_scope' column_name
            UNION ALL SELECT 'academic_requirement_groups' table_name,'requirement_type' column_name
            UNION ALL SELECT 'academic_requirement_groups' table_name,'required_credit_hours' column_name
            UNION ALL SELECT 'academic_requirement_groups' table_name,'is_active' column_name
            UNION ALL SELECT 'academic_requirement_groups' table_name,'created_at' column_name
            UNION ALL SELECT 'academic_requirement_groups' table_name,'updated_at' column_name
            UNION ALL SELECT 'academic_requirement_groups' table_name,'group_name' column_name
            UNION ALL SELECT 'program_course_requirement_groups' table_name,'program_course_requirement_group_id' column_name
            UNION ALL SELECT 'program_course_requirement_groups' table_name,'program_course_id' column_name
            UNION ALL SELECT 'program_course_requirement_groups' table_name,'requirement_group_id' column_name
            UNION ALL SELECT 'program_course_requirement_groups' table_name,'created_at' column_name
            UNION ALL SELECT 'program_course_requirement_groups' table_name,'updated_at' column_name
            UNION ALL SELECT 'course_departments' table_name,'course_department_id' column_name
            UNION ALL SELECT 'course_departments' table_name,'course_id' column_name
            UNION ALL SELECT 'course_departments' table_name,'department_id' column_name
            UNION ALL SELECT 'course_departments' table_name,'is_primary' column_name
            UNION ALL SELECT 'course_departments' table_name,'created_at' column_name
            UNION ALL SELECT 'course_prerequisites' table_name,'course_prerequisite_id' column_name
            UNION ALL SELECT 'course_prerequisites' table_name,'course_id' column_name
            UNION ALL SELECT 'course_prerequisites' table_name,'prerequisite_course_id' column_name
            UNION ALL SELECT 'course_prerequisites' table_name,'minimum_result_status_id' column_name
            UNION ALL SELECT 'course_prerequisites' table_name,'created_at' column_name
            UNION ALL SELECT 'students' table_name,'student_id' column_name
            UNION ALL SELECT 'students' table_name,'academic_program_id' column_name
            UNION ALL SELECT 'course_offerings' table_name,'course_offering_id' column_name
            UNION ALL SELECT 'course_offerings' table_name,'academic_program_id' column_name
            UNION ALL SELECT 'supplementary_exam_offerings' table_name,'supplementary_exam_offering_id' column_name
            UNION ALL SELECT 'supplementary_exam_offerings' table_name,'academic_program_id' column_name
            UNION ALL SELECT 'admission_applications' table_name,'admission_application_id' column_name
            UNION ALL SELECT 'admission_applications' table_name,'academic_program_id' column_name
            UNION ALL SELECT 'ministry_placement_records' table_name,'placement_record_id' column_name
            UNION ALL SELECT 'ministry_placement_records' table_name,'matched_academic_program_id' column_name
            UNION ALL SELECT 'student_graduation_decisions' table_name,'student_graduation_decision_id' column_name
            UNION ALL SELECT 'student_graduation_decisions' table_name,'academic_program_id' column_name
            UNION ALL SELECT 'student_progression_decisions' table_name,'student_progression_decision_id' column_name
            UNION ALL SELECT 'student_progression_decisions' table_name,'academic_program_id' column_name
            UNION ALL SELECT 'permissions' table_name,'permission_id' column_name
            UNION ALL SELECT 'permissions' table_name,'module_id' column_name
            UNION ALL SELECT 'permissions' table_name,'permission_code' column_name
            UNION ALL SELECT 'permissions' table_name,'permission_name' column_name
            UNION ALL SELECT 'permissions' table_name,'description' column_name
            UNION ALL SELECT 'permissions' table_name,'is_active' column_name
            UNION ALL SELECT 'permissions' table_name,'created_at' column_name
            UNION ALL SELECT 'permissions' table_name,'updated_at' column_name
            UNION ALL SELECT 'roles' table_name,'role_id' column_name
            UNION ALL SELECT 'roles' table_name,'role_code' column_name
            UNION ALL SELECT 'roles' table_name,'is_active' column_name
            UNION ALL SELECT 'role_permissions' table_name,'role_permission_id' column_name
            UNION ALL SELECT 'role_permissions' table_name,'role_id' column_name
            UNION ALL SELECT 'role_permissions' table_name,'permission_id' column_name
            UNION ALL SELECT 'role_permissions' table_name,'granted_at' column_name
            UNION ALL SELECT 'system_modules' table_name,'module_id' column_name
            UNION ALL SELECT 'system_modules' table_name,'module_code' column_name
            UNION ALL SELECT 'system_modules' table_name,'is_active' column_name
            UNION ALL SELECT 'user_activity_logs' table_name,'activity_log_id' column_name
            UNION ALL SELECT 'user_activity_logs' table_name,'user_id' column_name
            UNION ALL SELECT 'user_activity_logs' table_name,'module_code' column_name
            UNION ALL SELECT 'user_activity_logs' table_name,'action_code' column_name
            UNION ALL SELECT 'user_activity_logs' table_name,'description' column_name
            UNION ALL SELECT 'user_activity_logs' table_name,'created_at' column_name
            UNION ALL SELECT 'colleges' table_name,'college_id' column_name
            UNION ALL SELECT 'colleges' table_name,'college_name' column_name
            UNION ALL SELECT 'colleges' table_name,'is_active' column_name
            UNION ALL SELECT 'departments' table_name,'department_id' column_name
            UNION ALL SELECT 'departments' table_name,'college_id' column_name
            UNION ALL SELECT 'departments' table_name,'is_active' column_name
            UNION ALL SELECT 'academic_levels' table_name,'academic_level_id' column_name
            UNION ALL SELECT 'academic_levels' table_name,'level_name' column_name
            UNION ALL SELECT 'semesters' table_name,'semester_id' column_name
            UNION ALL SELECT 'semesters' table_name,'semester_name' column_name
            UNION ALL SELECT 'course_instructors' table_name,'course_instructor_id' column_name
            UNION ALL SELECT 'course_instructors' table_name,'course_id' column_name
            UNION ALL SELECT 'result_statuses' table_name,'result_status_id' column_name
            UNION ALL SELECT 'result_statuses' table_name,'status_code' column_name
UNION ALL SELECT 'users' table_name,'user_id' column_name
UNION ALL SELECT 'users' table_name,'account_status_id' column_name
UNION ALL SELECT 'account_statuses' table_name,'account_status_id' column_name
UNION ALL SELECT 'account_statuses' table_name,'status_code' column_name
UNION ALL SELECT 'user_roles' table_name,'user_id' column_name
UNION ALL SELECT 'user_roles' table_name,'role_id' column_name
UNION ALL SELECT 'user_roles' table_name,'is_active' column_name
UNION ALL SELECT 'user_access_scopes' table_name,'user_id' column_name
UNION ALL SELECT 'user_access_scopes' table_name,'scope_type' column_name
UNION ALL SELECT 'user_access_scopes' table_name,'scope_id' column_name
UNION ALL SELECT 'user_access_scopes' table_name,'is_active' column_name
UNION ALL SELECT 'organizational_units' table_name,'organizational_unit_id' column_name
UNION ALL SELECT 'organizational_units' table_name,'unit_code' column_name
UNION ALL SELECT 'organizational_units' table_name,'is_active' column_name
UNION ALL SELECT 'departments' table_name,'department_name' column_name
            UNION ALL SELECT 'result_statuses' table_name,'status_name' column_name
    ) required LEFT JOIN information_schema.columns c
      ON c.table_schema='alrowad_uni_rust' AND c.table_name=required.table_name AND c.column_name=required.column_name
    WHERE c.column_name IS NULL OR NOT EXISTS(SELECT 1 FROM information_schema.tables t WHERE t.table_schema='alrowad_uni_rust' AND t.table_name=required.table_name AND t.engine='InnoDB')
      OR ((RIGHT(required.column_name,3)='_id' OR required.column_name IN ('credit_hours','theoretical_hours','practical_hours','total_credit_hours','required_credit_hours')) AND (c.data_type<>'int' OR c.column_type LIKE '%unsigned%'))
  ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_column_contract_invalid'; END IF;
  IF (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust'
      AND table_name IN ('courses','academic_programs','program_courses','academic_requirement_groups','program_course_requirement_groups','course_departments','course_prerequisites','students','course_offerings','supplementary_exam_offerings','admission_applications','ministry_placement_records','student_graduation_decisions','student_progression_decisions','permissions','roles','role_permissions','system_modules','user_activity_logs')) <> 19
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_prerequisites_missing'; END IF;
  IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='academic_catalog_control'
      AND table_comment <> 'scientific-course-management-v1')
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_target_conflict'; END IF;
  IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='academic_catalog_control') AND NOT (EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='academic_catalog_control' AND engine='InnoDB' AND table_comment='scientific-course-management-v1')
AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_catalog_control' AND is_nullable='NO' AND ((column_name IN ('control_id','schema_version') AND data_type='int' AND column_type NOT LIKE '%unsigned%') OR (column_name='revision' AND data_type='bigint' AND column_type LIKE '%unsigned%') OR (column_name='is_ready' AND data_type='tinyint')))=4
AND EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_catalog_control' AND index_name='PRIMARY' GROUP BY index_name HAVING COUNT(*)=1 AND MIN(column_name)='control_id')
AND EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='academic_catalog_control' AND constraint_name='chk_sc_catalog_singleton' AND constraint_type='CHECK'))
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_control_contract_invalid'; END IF;
  IF (SELECT COUNT(*) FROM system_modules WHERE module_code='courses' AND is_active=1) <> 1
     OR (SELECT COUNT(*) FROM roles WHERE role_code='vice_president_scientific' AND is_active=1) <> 1
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_rbac_source_invalid'; END IF;
  IF EXISTS (SELECT 1 FROM permissions p LEFT JOIN system_modules m ON m.module_id=p.module_id
       WHERE p.permission_code IN ('vice_presidency.scientific.courses.view','vice_presidency.scientific.courses.manage')
       AND (COALESCE(m.module_code,'')<>'courses' OR p.is_active<>1))
     OR EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id JOIN roles r ON r.role_id=rp.role_id
       WHERE p.permission_code IN ('vice_presidency.scientific.courses.view','vice_presidency.scientific.courses.manage') AND r.role_code<>'vice_president_scientific')
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_permission_conflict'; END IF;
END//
DELIMITER ;
CALL sc_catalog_apply_guard();
CREATE TABLE IF NOT EXISTS academic_catalog_control (
  control_id INT NOT NULL PRIMARY KEY,
  schema_version INT NOT NULL,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  is_ready TINYINT NOT NULL DEFAULT 0,
  CONSTRAINT chk_sc_catalog_singleton CHECK(control_id=1 AND schema_version=1 AND is_ready IN (0,1))
) ENGINE=InnoDB COMMENT='scientific-course-management-v1';
INSERT INTO academic_catalog_control(control_id,schema_version,revision,is_ready)
SELECT 1,1,1,0 WHERE NOT EXISTS(SELECT 1 FROM academic_catalog_control WHERE control_id=1);
UPDATE academic_catalog_control SET is_ready=0 WHERE control_id=1;
DELIMITER //
CREATE OR REPLACE PROCEDURE sc_catalog_touch()
MODIFIES SQL DATA
COMMENT 'scientific-course-management-v1'
BEGIN
  DECLARE ready_value INT DEFAULT 0;
  SELECT is_ready INTO ready_value FROM academic_catalog_control WHERE control_id=1 AND schema_version=1 FOR UPDATE;
  IF ready_value<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_schema_not_ready'; END IF;
  UPDATE academic_catalog_control SET revision=revision+1 WHERE control_id=1;
END//
CREATE OR REPLACE FUNCTION sc_catalog_program_used(program_key INT) RETURNS BOOLEAN
READS SQL DATA
COMMENT 'scientific-course-management-v1'
BEGIN
  DECLARE found_key INT DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET found_key=NULL;
  IF program_key IS NULL THEN RETURN FALSE; END IF;
  SELECT student_id INTO found_key FROM students WHERE academic_program_id=program_key ORDER BY student_id LIMIT 1 FOR UPDATE;
  IF found_key IS NOT NULL THEN RETURN TRUE; END IF;
  SELECT course_offering_id INTO found_key FROM course_offerings WHERE academic_program_id=program_key ORDER BY course_offering_id LIMIT 1 FOR UPDATE;
  IF found_key IS NOT NULL THEN RETURN TRUE; END IF;
  SELECT supplementary_exam_offering_id INTO found_key FROM supplementary_exam_offerings WHERE academic_program_id=program_key ORDER BY supplementary_exam_offering_id LIMIT 1 FOR UPDATE;
  IF found_key IS NOT NULL THEN RETURN TRUE; END IF;
  SELECT admission_application_id INTO found_key FROM admission_applications WHERE academic_program_id=program_key ORDER BY admission_application_id LIMIT 1 FOR UPDATE;
  IF found_key IS NOT NULL THEN RETURN TRUE; END IF;
  SELECT placement_record_id INTO found_key FROM ministry_placement_records WHERE matched_academic_program_id=program_key ORDER BY placement_record_id LIMIT 1 FOR UPDATE;
  IF found_key IS NOT NULL THEN RETURN TRUE; END IF;
  SELECT student_graduation_decision_id INTO found_key FROM student_graduation_decisions WHERE academic_program_id=program_key ORDER BY student_graduation_decision_id LIMIT 1 FOR UPDATE;
  IF found_key IS NOT NULL THEN RETURN TRUE; END IF;
  SELECT student_progression_decision_id INTO found_key FROM student_progression_decisions WHERE academic_program_id=program_key ORDER BY student_progression_decision_id LIMIT 1 FOR UPDATE;
  IF found_key IS NOT NULL THEN RETURN TRUE; END IF;
  RETURN FALSE;
END//
CREATE OR REPLACE FUNCTION sc_catalog_membership_program(membership_key INT) RETURNS INT
READS SQL DATA
COMMENT 'scientific-course-management-v1'
BEGIN
  DECLARE program_key INT DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET program_key=NULL;
  SELECT academic_program_id INTO program_key FROM program_courses WHERE program_course_id=membership_key FOR UPDATE;
  RETURN program_key;
END//
CREATE OR REPLACE FUNCTION sc_catalog_course_used(course_key INT) RETURNS BOOLEAN
READS SQL DATA
COMMENT 'scientific-course-management-v1'
BEGIN
  DECLARE found_key INT DEFAULT NULL;
  DECLARE program_key INT DEFAULT NULL;
  DECLARE finished BOOLEAN DEFAULT FALSE;
  DECLARE programs CURSOR FOR SELECT academic_program_id FROM program_courses WHERE course_id=course_key ORDER BY academic_program_id FOR UPDATE;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished=TRUE;
  SELECT course_offering_id INTO found_key FROM course_offerings WHERE course_id=course_key ORDER BY course_offering_id LIMIT 1 FOR UPDATE;
  IF found_key IS NOT NULL THEN RETURN TRUE; END IF;
  SET finished=FALSE;
  SELECT supplementary_exam_offering_id INTO found_key FROM supplementary_exam_offerings WHERE course_id=course_key ORDER BY supplementary_exam_offering_id LIMIT 1 FOR UPDATE;
  IF found_key IS NOT NULL THEN RETURN TRUE; END IF;
  SET finished=FALSE;
  OPEN programs;
  scan_programs: LOOP
    FETCH programs INTO program_key;
    IF finished THEN LEAVE scan_programs; END IF;
    IF sc_catalog_program_used(program_key) THEN CLOSE programs; RETURN TRUE; END IF;
  END LOOP;
  CLOSE programs;
  RETURN FALSE;
END//
CREATE OR REPLACE TRIGGER sc_cat_courses_i BEFORE INSERT ON courses FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();

END//
CREATE OR REPLACE TRIGGER sc_cat_courses_u BEFORE UPDATE ON courses FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (NOT(NEW.course_code<=>OLD.course_code) OR NOT(NEW.credit_hours<=>OLD.credit_hours) OR NOT(NEW.theoretical_hours<=>OLD.theoretical_hours) OR NOT(NEW.practical_hours<=>OLD.practical_hours) OR NOT(NEW.is_active<=>OLD.is_active)) AND (sc_catalog_course_used(OLD.course_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_courses_d BEFORE DELETE ON courses FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_course_used(OLD.course_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_academic_programs_i BEFORE INSERT ON academic_programs FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();

END//
CREATE OR REPLACE TRIGGER sc_cat_academic_programs_u BEFORE UPDATE ON academic_programs FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (NOT(NEW.department_id<=>OLD.department_id) OR NOT(NEW.program_code<=>OLD.program_code) OR NOT(NEW.degree_level<=>OLD.degree_level) OR NOT(NEW.total_credit_hours<=>OLD.total_credit_hours) OR NOT(NEW.duration_years<=>OLD.duration_years) OR NOT(NEW.is_active<=>OLD.is_active)) AND (sc_catalog_program_used(OLD.academic_program_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_academic_programs_d BEFORE DELETE ON academic_programs FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_program_used(OLD.academic_program_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_program_courses_i BEFORE INSERT ON program_courses FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_program_used(NEW.academic_program_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_program_courses_u BEFORE UPDATE ON program_courses FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (NOT(NEW.academic_program_id<=>OLD.academic_program_id) OR NOT(NEW.course_id<=>OLD.course_id) OR NOT(NEW.academic_level_id<=>OLD.academic_level_id) OR NOT(NEW.recommended_semester_id<=>OLD.recommended_semester_id) OR NOT(NEW.course_type<=>OLD.course_type) OR NOT(NEW.is_active<=>OLD.is_active)) AND (sc_catalog_program_used(NEW.academic_program_id) OR sc_catalog_program_used(OLD.academic_program_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_program_courses_d BEFORE DELETE ON program_courses FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_program_used(OLD.academic_program_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_academic_requirement_groups_i BEFORE INSERT ON academic_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_program_used(NEW.academic_program_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_academic_requirement_groups_u BEFORE UPDATE ON academic_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (NOT(NEW.academic_program_id<=>OLD.academic_program_id) OR NOT(NEW.group_code<=>OLD.group_code) OR NOT(NEW.requirement_scope<=>OLD.requirement_scope) OR NOT(NEW.requirement_type<=>OLD.requirement_type) OR NOT(NEW.required_credit_hours<=>OLD.required_credit_hours) OR NOT(NEW.is_active<=>OLD.is_active)) AND (sc_catalog_program_used(NEW.academic_program_id) OR sc_catalog_program_used(OLD.academic_program_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_academic_requirement_groups_d BEFORE DELETE ON academic_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_program_used(OLD.academic_program_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_program_course_requirement_groups_i BEFORE INSERT ON program_course_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_program_used(sc_catalog_membership_program(NEW.program_course_id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_program_course_requirement_groups_u BEFORE UPDATE ON program_course_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (NOT(NEW.program_course_id<=>OLD.program_course_id) OR NOT(NEW.requirement_group_id<=>OLD.requirement_group_id)) AND (sc_catalog_program_used(sc_catalog_membership_program(NEW.program_course_id)) OR sc_catalog_program_used(sc_catalog_membership_program(OLD.program_course_id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_program_course_requirement_groups_d BEFORE DELETE ON program_course_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_program_used(sc_catalog_membership_program(OLD.program_course_id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_course_departments_i BEFORE INSERT ON course_departments FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_course_used(NEW.course_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_course_departments_u BEFORE UPDATE ON course_departments FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (NOT(NEW.course_id<=>OLD.course_id) OR NOT(NEW.department_id<=>OLD.department_id) OR NOT(NEW.is_primary<=>OLD.is_primary)) AND (sc_catalog_course_used(NEW.course_id) OR sc_catalog_course_used(OLD.course_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_course_departments_d BEFORE DELETE ON course_departments FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_course_used(OLD.course_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_course_prerequisites_i BEFORE INSERT ON course_prerequisites FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_course_used(NEW.course_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_course_prerequisites_u BEFORE UPDATE ON course_prerequisites FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (NOT(NEW.course_id<=>OLD.course_id) OR NOT(NEW.prerequisite_course_id<=>OLD.prerequisite_course_id) OR NOT(NEW.minimum_result_status_id<=>OLD.minimum_result_status_id)) AND (sc_catalog_course_used(NEW.course_id) OR sc_catalog_course_used(OLD.course_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_course_prerequisites_d BEFORE DELETE ON course_prerequisites FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
  IF (sc_catalog_course_used(OLD.course_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_students_i BEFORE INSERT ON students FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_students_u BEFORE UPDATE ON students FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_students_d BEFORE DELETE ON students FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_course_offerings_i BEFORE INSERT ON course_offerings FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_course_offerings_u BEFORE UPDATE ON course_offerings FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_course_offerings_d BEFORE DELETE ON course_offerings FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_supplementary_exam_offerings_i BEFORE INSERT ON supplementary_exam_offerings FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_supplementary_exam_offerings_u BEFORE UPDATE ON supplementary_exam_offerings FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_supplementary_exam_offerings_d BEFORE DELETE ON supplementary_exam_offerings FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_admission_applications_i BEFORE INSERT ON admission_applications FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_admission_applications_u BEFORE UPDATE ON admission_applications FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_admission_applications_d BEFORE DELETE ON admission_applications FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_ministry_placement_records_i BEFORE INSERT ON ministry_placement_records FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_ministry_placement_records_u BEFORE UPDATE ON ministry_placement_records FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_ministry_placement_records_d BEFORE DELETE ON ministry_placement_records FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_student_graduation_decisions_i BEFORE INSERT ON student_graduation_decisions FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_student_graduation_decisions_u BEFORE UPDATE ON student_graduation_decisions FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_student_graduation_decisions_d BEFORE DELETE ON student_graduation_decisions FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_student_progression_decisions_i BEFORE INSERT ON student_progression_decisions FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_student_progression_decisions_u BEFORE UPDATE ON student_progression_decisions FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Usage writers serialize with catalog writers; registration already has a persisted offering.
CREATE OR REPLACE TRIGGER sc_use_student_progression_decisions_d BEFORE DELETE ON student_progression_decisions FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_departments_i BEFORE INSERT ON departments FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_departments_u BEFORE UPDATE ON departments FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_departments_d BEFORE DELETE ON departments FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_colleges_i BEFORE INSERT ON colleges FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_colleges_u BEFORE UPDATE ON colleges FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_colleges_d BEFORE DELETE ON colleges FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_academic_levels_i BEFORE INSERT ON academic_levels FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_academic_levels_u BEFORE UPDATE ON academic_levels FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_academic_levels_d BEFORE DELETE ON academic_levels FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_semesters_i BEFORE INSERT ON semesters FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_semesters_u BEFORE UPDATE ON semesters FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_semesters_d BEFORE DELETE ON semesters FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_result_statuses_i BEFORE INSERT ON result_statuses FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_result_statuses_u BEFORE UPDATE ON result_statuses FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
-- Lookup/hierarchy changes also invalidate editors opened under the earlier context.
CREATE OR REPLACE TRIGGER sc_ref_result_statuses_d BEFORE DELETE ON result_statuses FOR EACH ROW
BEGIN
  CALL sc_catalog_touch();
END//
DELIMITER ;
INSERT INTO permissions(module_id,permission_code,permission_name,description,is_active,created_at,updated_at)
SELECT m.module_id,s.code,s.label,s.label,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP FROM system_modules m
JOIN (SELECT 'vice_presidency.scientific.courses.view' code,'عرض إدارة المواد العلمية' label UNION ALL SELECT 'vice_presidency.scientific.courses.manage','إدارة المواد العلمية') s
WHERE m.module_code='courses' AND m.is_active=1 AND NOT EXISTS(SELECT 1 FROM permissions p WHERE p.permission_code=s.code);
INSERT INTO role_permissions(role_id,permission_id,granted_at)
SELECT r.role_id,p.permission_id,CURRENT_TIMESTAMP FROM roles r JOIN permissions p
ON p.permission_code IN ('vice_presidency.scientific.courses.view','vice_presidency.scientific.courses.manage')
WHERE r.role_code='vice_president_scientific' AND r.is_active=1 AND p.is_active=1
AND NOT EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=r.role_id AND rp.permission_id=p.permission_id);
UPDATE academic_catalog_control SET is_ready=1,revision=revision+1 WHERE control_id=1
AND (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name IN ('sc_cat_courses_i','sc_cat_courses_u','sc_cat_courses_d','sc_cat_academic_programs_i','sc_cat_academic_programs_u','sc_cat_academic_programs_d','sc_cat_program_courses_i','sc_cat_program_courses_u','sc_cat_program_courses_d','sc_cat_academic_requirement_groups_i','sc_cat_academic_requirement_groups_u','sc_cat_academic_requirement_groups_d','sc_cat_program_course_requirement_groups_i','sc_cat_program_course_requirement_groups_u','sc_cat_program_course_requirement_groups_d','sc_cat_course_departments_i','sc_cat_course_departments_u','sc_cat_course_departments_d','sc_cat_course_prerequisites_i','sc_cat_course_prerequisites_u','sc_cat_course_prerequisites_d','sc_use_students_i','sc_use_students_u','sc_use_students_d','sc_use_course_offerings_i','sc_use_course_offerings_u','sc_use_course_offerings_d','sc_use_supplementary_exam_offerings_i','sc_use_supplementary_exam_offerings_u','sc_use_supplementary_exam_offerings_d','sc_use_admission_applications_i','sc_use_admission_applications_u','sc_use_admission_applications_d','sc_use_ministry_placement_records_i','sc_use_ministry_placement_records_u','sc_use_ministry_placement_records_d','sc_use_student_graduation_decisions_i','sc_use_student_graduation_decisions_u','sc_use_student_graduation_decisions_d','sc_use_student_progression_decisions_i','sc_use_student_progression_decisions_u','sc_use_student_progression_decisions_d','sc_ref_departments_i','sc_ref_departments_u','sc_ref_departments_d','sc_ref_colleges_i','sc_ref_colleges_u','sc_ref_colleges_d','sc_ref_academic_levels_i','sc_ref_academic_levels_u','sc_ref_academic_levels_d','sc_ref_semesters_i','sc_ref_semesters_u','sc_ref_semesters_d','sc_ref_result_statuses_i','sc_ref_result_statuses_u','sc_ref_result_statuses_d'))=57;
SELECT 'OVERALL' report_section,IF(is_ready=1,'APPLIED','BLOCKED') result,revision FROM academic_catalog_control WHERE control_id=1;
