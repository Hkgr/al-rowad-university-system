-- Guarded, rerunnable MariaDB 10.11 apply. No historical data is changed.
USE `alrowad_uni_rust`;

SET @srt4_apply_tables := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN ('course_offerings','courses','users','student_course_registrations','registration_statuses','student_registration_requests','student_registration_request_items','semester_offering_requests','semester_offering_reviews','semester_offering_events','academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions','permissions','roles','role_permissions','user_roles','user_access_scopes'));
SET @srt4_apply_deadlines := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND ((table_name='academic_calendar_event_versions' AND column_name IN ('student_registration_ends_at','advisor_approval_ends_at')) OR (table_name='student_registration_requests' AND column_name='expired_at')));
SET @srt4_apply_signed := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='int' AND column_type NOT LIKE '%unsigned%' AND ((table_name='course_offerings' AND column_name='course_offering_id') OR (table_name='users' AND column_name='user_id')));
SET @srt4_apply_permission := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code='course_offerings.semester_governance.manage' AND is_active=1);
SET @srt4_apply_existing := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots');
SET @srt4_apply_existing_total_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots');
SET @srt4_apply_existing_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND column_name IN ('course_offering_schedule_slot_id','course_offering_id','component_type','day_of_week','start_time','end_time','location_label','created_by_user_id','created_at','updated_at'));
SET @srt4_apply_existing_shape := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND (
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
SET @srt4_apply_existing_pk := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND index_name='PRIMARY' AND column_name='course_offering_schedule_slot_id' AND seq_in_index=1);
SET @srt4_apply_existing_indexes := (SELECT COUNT(*) FROM (SELECT index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) cols,MIN(non_unique) non_unique FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND index_name IN ('uq_coss_exact_slot','idx_coss_offering_window') GROUP BY index_name) x WHERE (index_name='uq_coss_exact_slot' AND cols='course_offering_id,component_type,day_of_week,start_time,end_time' AND non_unique=0) OR (index_name='idx_coss_offering_window' AND cols='course_offering_id,day_of_week,start_time,end_time'));
SET @srt4_apply_existing_fks := (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.table_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND k.table_name='course_offering_schedule_slots' AND r.update_rule='RESTRICT' AND r.delete_rule='RESTRICT' AND ((k.constraint_name='fk_coss_offering' AND k.column_name='course_offering_id' AND k.referenced_table_name='course_offerings' AND k.referenced_column_name='course_offering_id') OR (k.constraint_name='fk_coss_created_by' AND k.column_name='created_by_user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='user_id')));
SET @srt4_apply_existing_checks := (SELECT COUNT(*) FROM information_schema.table_constraints tc JOIN information_schema.check_constraints cc ON cc.constraint_schema=tc.constraint_schema AND cc.constraint_name=tc.constraint_name WHERE tc.constraint_schema='alrowad_uni_rust' AND tc.table_name='course_offering_schedule_slots' AND tc.constraint_type='CHECK' AND (
  (tc.constraint_name='chk_coss_component' AND LOWER(cc.check_clause) LIKE '%component_type%' AND LOWER(cc.check_clause) LIKE '%theoretical%' AND LOWER(cc.check_clause) LIKE '%practical%') OR
  (tc.constraint_name='chk_coss_day' AND LOWER(cc.check_clause) LIKE '%day_of_week%' AND LOWER(cc.check_clause) LIKE '%between%') OR
  (tc.constraint_name='chk_coss_interval' AND LOWER(cc.check_clause) LIKE '%start_time%' AND LOWER(cc.check_clause) LIKE '%end_time%' AND LOCATE('<',cc.check_clause)>0)
));
SET @srt4_apply_existing_comment := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='course_offering_schedule_slots' AND table_comment LIKE 'Owned by semester-registration-timetable-phase4%');
SET @srt4_apply_existing_compatible := (@srt4_apply_existing=1 AND @srt4_apply_existing_total_columns=10 AND @srt4_apply_existing_columns=10 AND @srt4_apply_existing_shape=10 AND @srt4_apply_existing_pk=1 AND @srt4_apply_existing_indexes=2 AND @srt4_apply_existing_fks=2 AND @srt4_apply_existing_checks=3 AND @srt4_apply_existing_comment=1);
SET @srt4_apply_ready := (@srt4_apply_tables=18 AND @srt4_apply_deadlines=3 AND @srt4_apply_signed=2 AND @srt4_apply_permission=1 AND (@srt4_apply_existing=0 OR @srt4_apply_existing_compatible));

SET @srt4_sql := IF(@srt4_apply_ready AND @srt4_apply_existing=0,
'CREATE TABLE `alrowad_uni_rust`.`course_offering_schedule_slots` (
 `course_offering_schedule_slot_id` INT NOT NULL AUTO_INCREMENT,
 `course_offering_id` INT NOT NULL,
 `component_type` VARCHAR(16) NOT NULL,
 `day_of_week` TINYINT NOT NULL,
 `start_time` TIME NOT NULL,
 `end_time` TIME NOT NULL,
 `location_label` VARCHAR(150) NULL,
 `created_by_user_id` INT NOT NULL,
 `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (`course_offering_schedule_slot_id`),
 UNIQUE KEY `uq_coss_exact_slot` (`course_offering_id`,`component_type`,`day_of_week`,`start_time`,`end_time`),
 KEY `idx_coss_offering_window` (`course_offering_id`,`day_of_week`,`start_time`,`end_time`),
 CONSTRAINT `chk_coss_component` CHECK (`component_type` IN (''theoretical'',''practical'')),
 CONSTRAINT `chk_coss_day` CHECK (`day_of_week` BETWEEN 1 AND 7),
 CONSTRAINT `chk_coss_interval` CHECK (`start_time` < `end_time`),
 CONSTRAINT `fk_coss_offering` FOREIGN KEY (`course_offering_id`) REFERENCES `alrowad_uni_rust`.`course_offerings` (`course_offering_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
 CONSTRAINT `fk_coss_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Owned by semester-registration-timetable-phase4; official recurring weekly CourseOffering timetable''',
'SELECT ''PHASE4_DDL'' report_section,''SKIPPED'' result');
PREPARE srt4_apply_stmt FROM @srt4_sql;
EXECUTE srt4_apply_stmt;
DEALLOCATE PREPARE srt4_apply_stmt;

SELECT 'APPLY_GUARD' report_section,IF(@srt4_apply_ready,'PASS','FAIL') result,CONCAT('prerequisite_tables=',@srt4_apply_tables,'/18; phase2_columns=',@srt4_apply_deadlines,'/3; target_existed=',@srt4_apply_existing,'; existing_compatible=',@srt4_apply_existing_compatible) detail;
SELECT 'OVERALL' report_section,IF(@srt4_apply_ready,'APPLIED','BLOCKED') result;
