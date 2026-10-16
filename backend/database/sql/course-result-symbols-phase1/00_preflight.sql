-- READ ONLY. Run in the selected database, not a hardcoded production database.
-- Do not import a production dump. Maintenance must stop affected writers/scheduler.
SET @crs_db=DATABASE();
SELECT 'DATABASE' AS check_name, @crs_db AS observed, VERSION() AS database_version;
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@crs_db AND (
 (TABLE_NAME='student_course_results' AND COLUMN_NAME IN ('student_course_result_id','student_course_registration_id','theoretical_total','practical_total','final_mark','result_announced_at','calculated_by_user_id')) OR
 (TABLE_NAME IN ('students','users','courses','academic_years','semesters','student_course_registrations') AND COLUMN_KEY='PRI')) ORDER BY TABLE_NAME,ORDINAL_POSITION;
SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=@crs_db AND EVENT_OBJECT_TABLE IN ('student_course_results','student_course_registrations','grade_approvals','grade_audit_logs');
SELECT TABLE_NAME,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=@crs_db AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events');
SET @crs_core=(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@crs_db AND ENGINE='InnoDB' AND TABLE_NAME IN
 ('students','users','courses','academic_years','semesters','student_course_registrations','student_course_results','result_statuses','grade_components','student_grade_components','grade_audit_logs','grade_approvals','grade_part_approvals'));
SET @crs_keys=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@crs_db AND DATA_TYPE='int' AND COLUMN_TYPE NOT LIKE '%unsigned%' AND
 ((TABLE_NAME='students' AND COLUMN_NAME='student_id') OR (TABLE_NAME='users' AND COLUMN_NAME='user_id') OR (TABLE_NAME='courses' AND COLUMN_NAME='course_id') OR
 (TABLE_NAME='academic_years' AND COLUMN_NAME='academic_year_id') OR (TABLE_NAME='semesters' AND COLUMN_NAME='semester_id') OR
 (TABLE_NAME='student_course_registrations' AND COLUMN_NAME='student_course_registration_id') OR (TABLE_NAME='student_course_results' AND COLUMN_NAME='student_course_result_id')));
SET @crs_foreign=(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@crs_db AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events') AND TABLE_COMMENT<>'course-result-symbols-phase1-v1');
-- Dynamic read keeps an absent prerequisite table a controlled BLOCKED result.
SET @crs_status_sql=IF(@crs_core=13,'SELECT COUNT(*) INTO @crs_statuses FROM result_statuses WHERE status_code IN (''passed'',''failed'',''deprived'',''incomplete'',''pending_approval'') AND is_active=1','SET @crs_statuses=0');
PREPARE crs_status_check FROM @crs_status_sql;
EXECUTE crs_status_check;
DEALLOCATE PREPARE crs_status_check;
SET @crs_unique=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@crs_db AND TABLE_NAME='student_course_results' AND NON_UNIQUE=0 AND COLUMN_NAME='student_course_registration_id');
SELECT 'OVERALL' AS check_name, IF(@crs_db IS NOT NULL AND @crs_core=13 AND @crs_keys=7 AND @crs_foreign=0 AND @crs_statuses=5 AND @crs_unique=1,'READY','BLOCKED') AS result;
