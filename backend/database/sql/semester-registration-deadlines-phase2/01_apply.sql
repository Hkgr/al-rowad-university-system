-- Guarded, rerunnable MariaDB 10.11 DDL. No historical rows are rewritten.
USE `alrowad_uni_rust`;

SET @srd_apply_tables := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN ('academic_calendar_event_versions','student_registration_requests'));
SET @srd_apply_existing := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND ((table_name='academic_calendar_event_versions' AND column_name IN ('student_registration_ends_at','advisor_approval_ends_at')) OR (table_name='student_registration_requests' AND column_name='expired_at')));
SET @srd_apply_compatible := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='datetime' AND is_nullable='YES' AND ((table_name='academic_calendar_event_versions' AND column_name IN ('student_registration_ends_at','advisor_approval_ends_at')) OR (table_name='student_registration_requests' AND column_name='expired_at')));
SET @srd_apply_runtime_contract := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND (
  (table_name='student_registration_requests' AND column_name='status' AND data_type='varchar' AND character_maximum_length>=9) OR
  (table_name='student_registration_request_events' AND column_name='event_type' AND data_type='varchar' AND character_maximum_length>=16) OR
  (table_name='student_registration_request_events' AND column_name IN ('from_status','to_status') AND data_type='varchar' AND character_maximum_length>=9) OR
  (table_name='student_registration_request_events' AND column_name='actor_user_id' AND data_type='int' AND column_type NOT LIKE '%unsigned%' AND is_nullable='YES')
));
SET @srd_apply_ready := (@srd_apply_tables=2 AND @srd_apply_existing=@srd_apply_compatible AND @srd_apply_runtime_contract=5);

SET @srd_sql := IF(@srd_apply_ready,
  'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` ADD COLUMN IF NOT EXISTS `student_registration_ends_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; final student mutation time'' AFTER `ends_at`, ADD COLUMN IF NOT EXISTS `advisor_approval_ends_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; final advisor decision time'' AFTER `student_registration_ends_at`',
  'SELECT ''PHASE2_CALENDAR_COLUMNS'' report_section,''BLOCKED'' result');
PREPARE srd_apply_stmt FROM @srd_sql; EXECUTE srd_apply_stmt; DEALLOCATE PREPARE srd_apply_stmt;
SET @srd_sql := IF(@srd_apply_ready,
  'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` MODIFY COLUMN `student_registration_ends_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; final student mutation time'', MODIFY COLUMN `advisor_approval_ends_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; final advisor decision time''',
  'SELECT ''PHASE2_CALENDAR_OWNERSHIP'' report_section,''BLOCKED'' result');
PREPARE srd_apply_stmt FROM @srd_sql; EXECUTE srd_apply_stmt; DEALLOCATE PREPARE srd_apply_stmt;

SET @srd_sql := IF(@srd_apply_ready,
  'ALTER TABLE `alrowad_uni_rust`.`student_registration_requests` ADD COLUMN IF NOT EXISTS `expired_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; unresolved advisor-deadline expiration'' AFTER `approved_at`',
  'SELECT ''PHASE2_REQUEST_COLUMN'' report_section,''BLOCKED'' result');
PREPARE srd_apply_stmt FROM @srd_sql; EXECUTE srd_apply_stmt; DEALLOCATE PREPARE srd_apply_stmt;
SET @srd_sql := IF(@srd_apply_ready,
  'ALTER TABLE `alrowad_uni_rust`.`student_registration_requests` MODIFY COLUMN `expired_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; unresolved advisor-deadline expiration''',
  'SELECT ''PHASE2_REQUEST_OWNERSHIP'' report_section,''BLOCKED'' result');
PREPARE srd_apply_stmt FROM @srd_sql; EXECUTE srd_apply_stmt; DEALLOCATE PREPARE srd_apply_stmt;

SELECT 'APPLY_GUARD' report_section,IF(@srd_apply_ready,'PASS','FAIL') result,CONCAT('required_tables=',@srd_apply_tables,'/2; existing_targets=',@srd_apply_existing,'; compatible_targets=',@srd_apply_compatible,'; runtime_columns=',@srd_apply_runtime_contract,'/5') detail;
SELECT 'OVERALL' report_section,IF(@srd_apply_ready,'APPLIED','BLOCKED') result;
