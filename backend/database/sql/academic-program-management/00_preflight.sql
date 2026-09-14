-- Read-only. Run during the documented maintenance window; no data is migrated here.
-- Missing new objects are installable; incompatible existing objects block. Extra compatible columns are allowed.
WITH expected AS (
SELECT 'academic_plan_control' table_name,'control_id' column_name,'int' data_type,0 min_length,'NO' nullable_flag
UNION ALL SELECT 'academic_plan_control','schema_version','int',0,'NO'
UNION ALL SELECT 'academic_plan_control','is_ready','tinyint',0,'NO'
UNION ALL SELECT 'academic_plan_versions','academic_plan_version_id','int',0,'NO'
UNION ALL SELECT 'academic_plan_versions','academic_program_id','int',0,'NO'
UNION ALL SELECT 'academic_plan_versions','version_number','int',0,'NO'
UNION ALL SELECT 'academic_plan_versions','label','varchar',150,'NO'
UNION ALL SELECT 'academic_plan_versions','status','varchar',20,'NO'
UNION ALL SELECT 'academic_plan_versions','calculation_policy','varchar',30,'NO'
UNION ALL SELECT 'academic_plan_versions','source_version_id','int',0,'YES'
UNION ALL SELECT 'academic_plan_versions','total_credit_hours','int',0,'YES'
UNION ALL SELECT 'academic_plan_versions','created_by_user_id','int',0,'NO'
UNION ALL SELECT 'academic_plan_versions','approved_by_user_id','int',0,'YES'
UNION ALL SELECT 'academic_plan_versions','approved_at','datetime',0,'YES'
UNION ALL SELECT 'academic_plan_versions','fixed_at','datetime',0,'YES'
UNION ALL SELECT 'academic_plan_versions','created_at','timestamp',0,'YES'
UNION ALL SELECT 'academic_plan_versions','updated_at','timestamp',0,'YES'
UNION ALL SELECT 'student_academic_plan_assignments','student_academic_plan_assignment_id','int',0,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','student_id','int',0,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','academic_program_id','int',0,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','academic_plan_version_id','int',0,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','current_slot','tinyint',0,'YES'
UNION ALL SELECT 'student_academic_plan_assignments','assigned_by_user_id','int',0,'YES'
UNION ALL SELECT 'student_academic_plan_assignments','reason','varchar',1000,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','assigned_at','datetime',0,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','ended_at','datetime',0,'YES'
UNION ALL SELECT 'academic_plan_events','academic_plan_event_id','bigint',0,'NO'
UNION ALL SELECT 'academic_plan_events','academic_program_id','int',0,'NO'
UNION ALL SELECT 'academic_plan_events','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'academic_plan_events','action','varchar',80,'NO'
UNION ALL SELECT 'academic_plan_events','actor_user_id','int',0,'NO'
UNION ALL SELECT 'academic_plan_events','context','longtext',0,'NO'
UNION ALL SELECT 'academic_plan_events','created_at','datetime',0,'NO'
UNION ALL SELECT 'academic_programs','plan_state','varchar',20,'NO'
UNION ALL SELECT 'academic_programs','default_academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'academic_programs','archived_at','datetime',0,'YES'
UNION ALL SELECT 'program_courses','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'academic_requirement_groups','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_course_registrations','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_course_registrations','plan_program_course_id','int',0,'YES'
UNION ALL SELECT 'student_registration_requests','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_registration_modification_requests','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_registration_replacement_requests','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_progression_decisions','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_graduation_decisions','academic_plan_version_id','int',0,'YES'
), issues AS (
SELECT CONCAT(e.table_name,'.',e.column_name) object_name,'INCOMPATIBLE_COLUMN' issue_code
FROM expected e JOIN information_schema.columns c ON c.table_schema='alrowad_uni_rust' AND c.table_name=e.table_name AND c.column_name=e.column_name
WHERE c.data_type<>e.data_type OR c.column_type LIKE '%unsigned%' OR c.is_nullable<>e.nullable_flag
 OR (e.min_length>0 AND c.character_maximum_length<e.min_length)
UNION ALL SELECT table_name,'FOREIGN_TARGET_OBJECT' FROM information_schema.tables
WHERE table_schema='alrowad_uni_rust' AND table_name IN('academic_plan_control','academic_plan_versions','student_academic_plan_assignments','academic_plan_events') AND (table_type<>'BASE TABLE' OR engine<>'InnoDB' OR table_comment<>'academic-plans-v1')
UNION ALL SELECT 'prerequisites','MISSING_REQUIRED_TABLE' WHERE
(SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN('academic_programs','students','program_courses','academic_requirement_groups','program_course_requirement_groups','course_offerings','courses','user_activity_logs','academic_catalog_control','permissions','system_modules','student_course_registrations','student_registration_requests','student_registration_modification_requests','student_registration_replacement_requests','student_progression_decisions','student_graduation_decisions','student_course_results','grade_approvals','grade_audit_logs','student_registration_request_items','student_registration_modification_items','student_registration_replacement_items','student_registration_withdrawal_requests','grade_appeals','appeal_statuses','supplementary_exam_registrations','supplementary_exam_materializations') AND engine='InnoDB')<>28
UNION ALL SELECT 'user_activity_logs.activity_log_id','INCOMPATIBLE_AUDIT_KEY' WHERE NOT EXISTS(SELECT 1 FROM information_schema.columns
WHERE table_schema='alrowad_uni_rust' AND table_name='user_activity_logs' AND column_name='activity_log_id' AND data_type='bigint' AND column_type NOT LIKE '%unsigned%')
UNION ALL SELECT CONCAT(e.table_name,'.',e.column_name),'INCOMPLETE_EXISTING_TARGET' FROM expected e JOIN information_schema.tables t ON t.table_schema='alrowad_uni_rust' AND t.table_name=e.table_name LEFT JOIN information_schema.columns c ON c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.column_name=e.column_name WHERE e.table_name IN('academic_plan_control','academic_plan_versions','student_academic_plan_assignments','academic_plan_events') AND c.column_name IS NULL
)
SELECT object_name,issue_code FROM issues ORDER BY object_name,issue_code;
SELECT 'TARGET_OBJECTS' section,table_name,table_comment FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN('academic_plan_control','academic_plan_versions','student_academic_plan_assignments','academic_plan_events');
WITH expected AS (
SELECT 'academic_plan_control' table_name,'control_id' column_name,'int' data_type,0 min_length,'NO' nullable_flag
UNION ALL SELECT 'academic_plan_control','schema_version','int',0,'NO'
UNION ALL SELECT 'academic_plan_control','is_ready','tinyint',0,'NO'
UNION ALL SELECT 'academic_plan_versions','academic_plan_version_id','int',0,'NO'
UNION ALL SELECT 'academic_plan_versions','academic_program_id','int',0,'NO'
UNION ALL SELECT 'academic_plan_versions','version_number','int',0,'NO'
UNION ALL SELECT 'academic_plan_versions','label','varchar',150,'NO'
UNION ALL SELECT 'academic_plan_versions','status','varchar',20,'NO'
UNION ALL SELECT 'academic_plan_versions','calculation_policy','varchar',30,'NO'
UNION ALL SELECT 'academic_plan_versions','source_version_id','int',0,'YES'
UNION ALL SELECT 'academic_plan_versions','total_credit_hours','int',0,'YES'
UNION ALL SELECT 'academic_plan_versions','created_by_user_id','int',0,'NO'
UNION ALL SELECT 'academic_plan_versions','approved_by_user_id','int',0,'YES'
UNION ALL SELECT 'academic_plan_versions','approved_at','datetime',0,'YES'
UNION ALL SELECT 'academic_plan_versions','fixed_at','datetime',0,'YES'
UNION ALL SELECT 'academic_plan_versions','created_at','timestamp',0,'YES'
UNION ALL SELECT 'academic_plan_versions','updated_at','timestamp',0,'YES'
UNION ALL SELECT 'student_academic_plan_assignments','student_academic_plan_assignment_id','int',0,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','student_id','int',0,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','academic_program_id','int',0,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','academic_plan_version_id','int',0,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','current_slot','tinyint',0,'YES'
UNION ALL SELECT 'student_academic_plan_assignments','assigned_by_user_id','int',0,'YES'
UNION ALL SELECT 'student_academic_plan_assignments','reason','varchar',1000,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','assigned_at','datetime',0,'NO'
UNION ALL SELECT 'student_academic_plan_assignments','ended_at','datetime',0,'YES'
UNION ALL SELECT 'academic_plan_events','academic_plan_event_id','bigint',0,'NO'
UNION ALL SELECT 'academic_plan_events','academic_program_id','int',0,'NO'
UNION ALL SELECT 'academic_plan_events','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'academic_plan_events','action','varchar',80,'NO'
UNION ALL SELECT 'academic_plan_events','actor_user_id','int',0,'NO'
UNION ALL SELECT 'academic_plan_events','context','longtext',0,'NO'
UNION ALL SELECT 'academic_plan_events','created_at','datetime',0,'NO'
UNION ALL SELECT 'academic_programs','plan_state','varchar',20,'NO'
UNION ALL SELECT 'academic_programs','default_academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'academic_programs','archived_at','datetime',0,'YES'
UNION ALL SELECT 'program_courses','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'academic_requirement_groups','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_course_registrations','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_course_registrations','plan_program_course_id','int',0,'YES'
UNION ALL SELECT 'student_registration_requests','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_registration_modification_requests','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_registration_replacement_requests','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_progression_decisions','academic_plan_version_id','int',0,'YES'
UNION ALL SELECT 'student_graduation_decisions','academic_plan_version_id','int',0,'YES'
), issues AS (
SELECT CONCAT(e.table_name,'.',e.column_name) object_name,'INCOMPATIBLE_COLUMN' issue_code
FROM expected e JOIN information_schema.columns c ON c.table_schema='alrowad_uni_rust' AND c.table_name=e.table_name AND c.column_name=e.column_name
WHERE c.data_type<>e.data_type OR c.column_type LIKE '%unsigned%' OR c.is_nullable<>e.nullable_flag
 OR (e.min_length>0 AND c.character_maximum_length<e.min_length)
UNION ALL SELECT table_name,'FOREIGN_TARGET_OBJECT' FROM information_schema.tables
WHERE table_schema='alrowad_uni_rust' AND table_name IN('academic_plan_control','academic_plan_versions','student_academic_plan_assignments','academic_plan_events') AND (table_type<>'BASE TABLE' OR engine<>'InnoDB' OR table_comment<>'academic-plans-v1')
UNION ALL SELECT 'prerequisites','MISSING_REQUIRED_TABLE' WHERE
(SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN('academic_programs','students','program_courses','academic_requirement_groups','program_course_requirement_groups','course_offerings','courses','user_activity_logs','academic_catalog_control','permissions','system_modules','student_course_registrations','student_registration_requests','student_registration_modification_requests','student_registration_replacement_requests','student_progression_decisions','student_graduation_decisions','student_course_results','grade_approvals','grade_audit_logs','student_registration_request_items','student_registration_modification_items','student_registration_replacement_items','student_registration_withdrawal_requests','grade_appeals','appeal_statuses','supplementary_exam_registrations','supplementary_exam_materializations') AND engine='InnoDB')<>28
UNION ALL SELECT 'user_activity_logs.activity_log_id','INCOMPATIBLE_AUDIT_KEY' WHERE NOT EXISTS(SELECT 1 FROM information_schema.columns
WHERE table_schema='alrowad_uni_rust' AND table_name='user_activity_logs' AND column_name='activity_log_id' AND data_type='bigint' AND column_type NOT LIKE '%unsigned%')
UNION ALL SELECT CONCAT(e.table_name,'.',e.column_name),'INCOMPLETE_EXISTING_TARGET' FROM expected e JOIN information_schema.tables t ON t.table_schema='alrowad_uni_rust' AND t.table_name=e.table_name LEFT JOIN information_schema.columns c ON c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.column_name=e.column_name WHERE e.table_name IN('academic_plan_control','academic_plan_versions','student_academic_plan_assignments','academic_plan_events') AND c.column_name IS NULL
)
SELECT 'OVERALL' section,IF(COUNT(*)=0,'READY','BLOCKED') result FROM issues;
