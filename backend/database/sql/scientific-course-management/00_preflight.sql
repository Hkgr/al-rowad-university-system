-- Read-only structural preflight. Explicit database; extra compatible columns are allowed.
-- Run on MariaDB 10.11. Do not run the production dump. No plan approval/versioning is introduced.
WITH required_columns AS (
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
), checks AS (
SELECT r.table_name,r.column_name,c.column_type,c.is_nullable,
 CASE WHEN c.column_name IS NULL OR NOT EXISTS(SELECT 1 FROM information_schema.tables t WHERE t.table_schema='alrowad_uni_rust' AND t.table_name=r.table_name AND t.engine='InnoDB') THEN 'BLOCKED'
 WHEN (r.column_name LIKE '%\\_id' OR r.column_name IN ('credit_hours','theoretical_hours','practical_hours','total_credit_hours','required_credit_hours')) AND (c.data_type<>'int' OR c.column_type LIKE '%unsigned%') THEN 'BLOCKED'
 ELSE 'READY' END result
FROM required_columns r LEFT JOIN information_schema.columns c ON c.table_schema='alrowad_uni_rust' AND c.table_name=r.table_name AND c.column_name=r.column_name
)
SELECT 'REQUIRED_COLUMNS' report_section,table_name,column_name,column_type,is_nullable,result FROM checks ORDER BY table_name,column_name;
SELECT 'TARGET' report_section,table_name,table_comment,IF(table_comment='scientific-course-management-v1','EXISTING_OWNED','BLOCKED_CONFLICT') result FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='academic_catalog_control';
SELECT 'EXISTING_RELATIONAL_CONSTRAINTS' report_section,table_name,constraint_name,constraint_type FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name IN ('courses','program_courses','academic_requirement_groups','program_course_requirement_groups','course_departments','course_prerequisites');
SELECT 'COURSE_CODE_UNIQUENESS' report_section,index_name,IF(non_unique=0 AND COUNT(*)=1 AND MIN(column_name)='course_code','READY','INFORMATIONAL') result FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='courses' GROUP BY index_name,non_unique;
-- Static data checks below assume the current deployed RBAC objects listed above exist.
-- If a required object is absent, stop; a schema error is NOT a READY result.
SELECT 'RBAC_SOURCE' report_section,IF((SELECT COUNT(*) FROM alrowad_uni_rust.system_modules WHERE module_code='courses' AND is_active=1)=1
AND (SELECT COUNT(*) FROM alrowad_uni_rust.roles WHERE role_code='vice_president_scientific' AND is_active=1)=1
AND NOT EXISTS(SELECT 1 FROM alrowad_uni_rust.permissions p LEFT JOIN alrowad_uni_rust.system_modules m ON m.module_id=p.module_id WHERE p.permission_code IN ('vice_presidency.scientific.courses.view','vice_presidency.scientific.courses.manage') AND (COALESCE(m.module_code,'')<>'courses' OR p.is_active<>1))
AND NOT EXISTS(SELECT 1 FROM alrowad_uni_rust.role_permissions rp JOIN alrowad_uni_rust.permissions p ON p.permission_id=rp.permission_id JOIN alrowad_uni_rust.roles r ON r.role_id=rp.role_id WHERE p.permission_code IN ('vice_presidency.scientific.courses.view','vice_presidency.scientific.courses.manage') AND r.role_code<>'vice_president_scientific'),'READY','BLOCKED') result;
SELECT 'SCOPE_POLICY' report_section,'Existing actual college/department/program scopes; PRES-backed university scope only. No user or scope assignment is performed.' detail;
SELECT 'ROUTINE_PRIVILEGES' report_section,'Apply needs CREATE ROUTINE, ALTER ROUTINE, TRIGGER and schema DDL/DML privileges; review server binary-log policy. No runtime user grants are added.' detail;
SELECT 'DEPLOYMENT' report_section,'Use maintenance mode for Apply; retain existing academic data and permissions. Verify before resuming traffic.' detail;
WITH required_columns AS (
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
), checks AS (
SELECT r.table_name,r.column_name,c.column_type,c.is_nullable,
 CASE WHEN c.column_name IS NULL OR NOT EXISTS(SELECT 1 FROM information_schema.tables t WHERE t.table_schema='alrowad_uni_rust' AND t.table_name=r.table_name AND t.engine='InnoDB') THEN 'BLOCKED'
 WHEN (r.column_name LIKE '%\\_id' OR r.column_name IN ('credit_hours','theoretical_hours','practical_hours','total_credit_hours','required_credit_hours')) AND (c.data_type<>'int' OR c.column_type LIKE '%unsigned%') THEN 'BLOCKED'
 ELSE 'READY' END result
FROM required_columns r LEFT JOIN information_schema.columns c ON c.table_schema='alrowad_uni_rust' AND c.table_name=r.table_name AND c.column_name=r.column_name
)
SELECT 'OVERALL' report_section,IF(NOT (EXISTS(SELECT 1 FROM information_schema.routines WHERE routine_schema='alrowad_uni_rust' AND routine_name IN ('sc_catalog_touch','sc_catalog_program_used','sc_catalog_course_used','sc_catalog_membership_program') AND routine_comment<>'scientific-course-management-v1')
OR EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND (LEFT(trigger_name,7)='sc_cat_' OR LEFT(trigger_name,7)='sc_use_' OR LEFT(trigger_name,7)='sc_ref_') AND action_statement NOT LIKE '%CALL sc_catalog_touch()%'))
AND (NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='academic_catalog_control') OR (EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='academic_catalog_control' AND engine='InnoDB' AND table_comment='scientific-course-management-v1')
AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_catalog_control' AND is_nullable='NO' AND ((column_name IN ('control_id','schema_version') AND data_type='int' AND column_type NOT LIKE '%unsigned%') OR (column_name='revision' AND data_type='bigint' AND column_type LIKE '%unsigned%') OR (column_name='is_ready' AND data_type='tinyint')))=4
AND EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_catalog_control' AND index_name='PRIMARY' GROUP BY index_name HAVING COUNT(*)=1 AND MIN(column_name)='control_id')
AND EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='academic_catalog_control' AND constraint_name='chk_sc_catalog_singleton' AND constraint_type='CHECK')))
AND (EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='courses' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='course_code')
AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='program_courses' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='academic_program_id,course_id')
AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_requirement_groups' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='group_code')
AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_requirement_groups' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='academic_program_id,requirement_scope,requirement_type')
AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='program_course_requirement_groups' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='program_course_id')
AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='course_departments' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='course_id,department_id')
AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='course_prerequisites' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='course_id,prerequisite_course_id')) AND (EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='program_courses' AND k.column_name='course_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='courses' AND k.referenced_column_name='course_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))
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
AND EXISTS (SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='course_prerequisites' AND k.column_name='minimum_result_status_id' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='result_statuses' AND k.referenced_column_name='result_status_id' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION'))) AND (SELECT COUNT(*) FROM alrowad_uni_rust.system_modules WHERE module_code='courses' AND is_active=1)=1
AND (SELECT COUNT(*) FROM alrowad_uni_rust.roles WHERE role_code='vice_president_scientific' AND is_active=1)=1
AND NOT EXISTS(SELECT 1 FROM alrowad_uni_rust.permissions p LEFT JOIN alrowad_uni_rust.system_modules m ON m.module_id=p.module_id WHERE p.permission_code IN ('vice_presidency.scientific.courses.view','vice_presidency.scientific.courses.manage') AND (COALESCE(m.module_code,'')<>'courses' OR p.is_active<>1))
AND NOT EXISTS(SELECT 1 FROM alrowad_uni_rust.role_permissions rp JOIN alrowad_uni_rust.permissions p ON p.permission_id=rp.permission_id JOIN alrowad_uni_rust.roles r ON r.role_id=rp.role_id WHERE p.permission_code IN ('vice_presidency.scientific.courses.view','vice_presidency.scientific.courses.manage') AND r.role_code<>'vice_president_scientific')
AND SUM(result='BLOCKED')=0 AND EXISTS(SELECT 1 FROM information_schema.schemata WHERE schema_name='alrowad_uni_rust') AND NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='academic_catalog_control' AND table_comment<>'scientific-course-management-v1'),'READY','BLOCKED') result FROM checks;
