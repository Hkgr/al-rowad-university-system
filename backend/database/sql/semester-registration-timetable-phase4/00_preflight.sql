-- Semester Course Registration Phase 4: read-only deployment preflight.
-- Run in phpMyAdmin and continue only when the final row is OVERALL | READY.
USE `alrowad_uni_rust`;

SET @srt4_database := (SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='alrowad_uni_rust');
SET @srt4_tables := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN (
  'course_offerings','courses','users','student_course_registrations','registration_statuses',
  'student_registration_requests','student_registration_request_items',
  'semester_offering_requests','semester_offering_reviews','semester_offering_events',
  'academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions',
  'permissions','roles','role_permissions','user_roles','user_access_scopes'
));
SET @srt4_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND (
  (table_name='course_offerings' AND column_name IN ('course_offering_id','course_id','academic_year_id','semester_id','academic_program_id','status')) OR
  (table_name='courses' AND column_name IN ('course_id','theoretical_hours','practical_hours')) OR
  (table_name='users' AND column_name='user_id') OR
  (table_name='student_course_registrations' AND column_name IN ('student_course_registration_id','student_id','course_offering_id','registration_status_id')) OR
  (table_name='registration_statuses' AND column_name IN ('registration_status_id','status_code')) OR
  (table_name='student_registration_requests' AND column_name IN ('student_registration_request_id','student_id','academic_year_id','semester_id','status','first_submitted_at','expired_at')) OR
  (table_name='student_registration_request_items' AND column_name IN ('student_registration_request_item_id','student_registration_request_id','course_offering_id')) OR
  (table_name='semester_offering_requests' AND column_name IN ('semester_offering_request_id','course_offering_id','status','materialized_at')) OR
  (table_name='semester_offering_reviews' AND column_name IN ('semester_offering_review_id','semester_offering_request_id','status')) OR
  (table_name='semester_offering_events' AND column_name IN ('semester_offering_event_id','semester_offering_request_id','event_type')) OR
  (table_name='academic_calendar_event_types' AND column_name IN ('academic_calendar_event_type_id','event_type_code','is_active')) OR
  (table_name='academic_calendar_events' AND column_name IN ('academic_calendar_event_id','academic_year_id','semester_id','academic_calendar_event_type_id','cancelled_at')) OR
  (table_name='academic_calendar_event_versions' AND column_name IN ('academic_calendar_event_version_id','academic_calendar_event_id','starts_at','ends_at','student_registration_ends_at','advisor_approval_ends_at','is_enforcement','publication_status','published_at','superseded_at')) OR
  (table_name='permissions' AND column_name IN ('permission_id','permission_code','is_active')) OR
  (table_name='roles' AND column_name IN ('role_id','role_code','is_active')) OR
  (table_name='role_permissions' AND column_name IN ('role_id','permission_id')) OR
  (table_name='user_roles' AND column_name IN ('user_id','role_id','is_active')) OR
  (table_name='user_access_scopes' AND column_name IN ('user_id','scope_type','scope_id','is_active'))
));
SET @srt4_signed_parents := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='int' AND column_type NOT LIKE '%unsigned%' AND (
  (table_name='course_offerings' AND column_name='course_offering_id') OR
  (table_name='users' AND column_name='user_id') OR
  (table_name='student_course_registrations' AND column_name='student_course_registration_id') OR
  (table_name='student_registration_requests' AND column_name='student_registration_request_id')
));
SET @srt4_phase1_permission := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code='course_offerings.semester_governance.manage' AND is_active=1);
SET @srt4_dean_role := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code='dean' AND is_active=1);
SET @srt4_dean_mapping := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id=r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id WHERE r.role_code='dean' AND r.is_active=1 AND p.permission_code='course_offerings.semester_governance.manage' AND p.is_active=1);
SET @srt4_registration_status := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`registration_statuses` WHERE status_code='registered');

SET @srt4_target_tables := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots');
SET @srt4_target_engine := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND engine='InnoDB');
SET @srt4_target_total_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots');
SET @srt4_target_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND column_name IN ('course_offering_schedule_slot_id','course_offering_id','component_type','day_of_week','start_time','end_time','location_label','created_by_user_id','created_at','updated_at'));
SET @srt4_target_shape := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND (
  (column_name='course_offering_schedule_slot_id' AND data_type='int' AND column_type NOT LIKE '%unsigned%' AND is_nullable='NO' AND extra LIKE '%auto_increment%') OR
  (column_name='course_offering_id' AND data_type='int' AND column_type NOT LIKE '%unsigned%' AND is_nullable='NO') OR
  (column_name='component_type' AND data_type='varchar' AND character_maximum_length=16 AND is_nullable='NO') OR
  (column_name='day_of_week' AND data_type='tinyint' AND column_type NOT LIKE '%unsigned%' AND is_nullable='NO') OR
  (column_name IN ('start_time','end_time') AND data_type='time' AND is_nullable='NO') OR
  (column_name='location_label' AND data_type='varchar' AND character_maximum_length=150 AND is_nullable='YES') OR
  (column_name='created_by_user_id' AND data_type='int' AND column_type NOT LIKE '%unsigned%' AND is_nullable='NO') OR
  (column_name='created_at' AND data_type='timestamp' AND is_nullable='NO' AND LOWER(COALESCE(column_default,'')) LIKE 'current_timestamp%') OR
  (column_name='updated_at' AND data_type='timestamp' AND is_nullable='NO' AND LOWER(COALESCE(column_default,'')) LIKE 'current_timestamp%' AND extra LIKE '%on update%')
));
SET @srt4_target_pk := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND index_name='PRIMARY' AND column_name='course_offering_schedule_slot_id' AND seq_in_index=1);
SET @srt4_target_indexes := (SELECT COUNT(*) FROM (SELECT index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) cols,MIN(non_unique) non_unique FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND index_name IN ('uq_coss_exact_slot','idx_coss_offering_window') GROUP BY index_name) x WHERE (index_name='uq_coss_exact_slot' AND cols='course_offering_id,component_type,day_of_week,start_time,end_time' AND non_unique=0) OR (index_name='idx_coss_offering_window' AND cols='course_offering_id,day_of_week,start_time,end_time' AND non_unique=1));
SET @srt4_target_fks := (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.table_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='course_offering_schedule_slots' AND r.update_rule='RESTRICT' AND r.delete_rule='RESTRICT' AND ((k.constraint_name='fk_coss_offering' AND k.column_name='course_offering_id' AND k.referenced_table_name='course_offerings' AND k.referenced_column_name='course_offering_id') OR (k.constraint_name='fk_coss_created_by' AND k.column_name='created_by_user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='user_id')));
SET @srt4_target_fk_total := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND constraint_type='FOREIGN KEY');
SET @srt4_target_checks := (SELECT COUNT(*) FROM information_schema.table_constraints tc JOIN information_schema.check_constraints cc ON cc.constraint_schema=tc.constraint_schema AND cc.constraint_name=tc.constraint_name WHERE tc.constraint_schema='alrowad_uni_rust' AND tc.table_name='course_offering_schedule_slots' AND tc.constraint_type='CHECK' AND (
  (tc.constraint_name='chk_coss_component' AND LOWER(cc.check_clause) LIKE '%component_type%' AND LOWER(cc.check_clause) LIKE '%theoretical%' AND LOWER(cc.check_clause) LIKE '%practical%') OR
  (tc.constraint_name='chk_coss_day' AND LOWER(cc.check_clause) LIKE '%day_of_week%' AND LOWER(cc.check_clause) LIKE '%between%') OR
  (tc.constraint_name='chk_coss_interval' AND LOWER(cc.check_clause) LIKE '%start_time%' AND LOWER(cc.check_clause) LIKE '%end_time%' AND LOCATE('<',cc.check_clause)>0)
));
SET @srt4_target_check_total := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND constraint_type='CHECK');
SET @srt4_target_comment := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND table_comment LIKE 'Owned by semester-registration-timetable-phase4%');
SET @srt4_target_state := IF(@srt4_target_tables=0,'ABSENT',IF(@srt4_target_engine=1 AND @srt4_target_total_columns=10 AND @srt4_target_columns=10 AND @srt4_target_shape=10 AND @srt4_target_pk=1 AND @srt4_target_indexes=2 AND @srt4_target_fks=2 AND @srt4_target_fk_total=2 AND @srt4_target_checks=3 AND @srt4_target_check_total=3 AND @srt4_target_comment=1,'COMPATIBLE','CONFLICTING'));
SET @srt4_ready := (@srt4_database=1 AND @srt4_tables=18 AND @srt4_columns=69 AND @srt4_signed_parents=4 AND @srt4_phase1_permission=1 AND @srt4_dean_role=1 AND @srt4_dean_mapping=1 AND @srt4_registration_status=1 AND @srt4_target_state<>'CONFLICTING');

SELECT 'DATABASE_AND_PREREQUISITES' report_section,IF(@srt4_database=1 AND @srt4_tables=18 AND @srt4_columns=69,'PASS','FAIL') result,CONCAT('tables=',@srt4_tables,'/18; columns=',@srt4_columns,'/69') detail;
SELECT 'SIGNED_KEYS_AND_RBAC' report_section,IF(@srt4_signed_parents=4 AND @srt4_phase1_permission=1 AND @srt4_dean_role=1 AND @srt4_dean_mapping=1 AND @srt4_registration_status=1,'PASS','FAIL') result,CONCAT('signed_keys=',@srt4_signed_parents,'/4; manage_permission=',@srt4_phase1_permission,'; dean_role=',@srt4_dean_role,'; dean_mapping=',@srt4_dean_mapping,'; registered_status=',@srt4_registration_status) detail;
SELECT 'PHASE4_OBJECT' report_section,IF(@srt4_target_state='CONFLICTING','FAIL','PASS') result,CONCAT(@srt4_target_state,'; total_columns=',@srt4_target_total_columns) detail;
SELECT 'INFORMATIONAL_COUNTS' report_section,
  (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings` WHERE status='open') open_offerings,
  (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_course_registrations` r JOIN `alrowad_uni_rust`.`registration_statuses` s ON s.registration_status_id=r.registration_status_id WHERE s.status_code='registered') current_registrations,
  (SELECT COUNT(DISTINCT course_offering_id) FROM `alrowad_uni_rust`.`student_course_registrations`) offerings_with_registration_activity;
SELECT 'ATTENDANCE_INFORMATIONAL_ONLY' report_section,
  (SELECT COUNT(*) FROM `alrowad_uni_rust`.`attendance_sessions`) attendance_sessions,
  (SELECT COUNT(*) FROM `alrowad_uni_rust`.`attendance_sessions` WHERE start_time IS NULL OR end_time IS NULL) attendance_sessions_with_null_start_or_end,
  'Attendance sessions are actual-session records and are never timetable data.' detail;
SELECT 'OVERALL' report_section,IF(@srt4_ready,'READY','BLOCKED') result;
