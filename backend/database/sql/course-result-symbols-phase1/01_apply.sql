-- Rendered from app/Support/IncompleteSchemaContract.php by tools/render-incomplete-schema.php.
-- Selected database only; maintenance must stop affected writers and workers. No production execution by this tool.
-- Sole installation owner. No migration/seeder/backfill. DDL is not transactional.
SET @crs_apply_result=NULL;
SET @crs_db=DATABASE();
SET @crs_core=(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@crs_db AND ENGINE='InnoDB' AND TABLE_NAME IN
 ('students','users','courses','academic_years','semesters','student_course_registrations','student_course_results','result_statuses','grade_components','student_grade_components','grade_audit_logs','grade_approvals','grade_part_approvals'));
SET @crs_keys=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@crs_db AND DATA_TYPE='int' AND COLUMN_TYPE NOT LIKE '%unsigned%' AND IS_NULLABLE='NO' AND COLUMN_KEY='PRI' AND
 ((TABLE_NAME='students' AND COLUMN_NAME='student_id') OR (TABLE_NAME='users' AND COLUMN_NAME='user_id') OR (TABLE_NAME='courses' AND COLUMN_NAME='course_id') OR
 (TABLE_NAME='academic_years' AND COLUMN_NAME='academic_year_id') OR (TABLE_NAME='semesters' AND COLUMN_NAME='semester_id') OR
 (TABLE_NAME='student_course_registrations' AND COLUMN_NAME='student_course_registration_id') OR (TABLE_NAME='student_course_results' AND COLUMN_NAME='student_course_result_id')));
SET @crs_status_sql=IF(@crs_core=13,'SELECT COUNT(*) INTO @crs_statuses FROM result_statuses WHERE status_code IN (''passed'',''failed'',''deprived'',''incomplete'',''pending_approval'') AND is_active=1','SET @crs_statuses=0');
PREPARE crs_status_check FROM @crs_status_sql;
EXECUTE crs_status_check;
DEALLOCATE PREPARE crs_status_check;
SET @crs_schema_issues=(SELECT COUNT(*) FROM (
WITH expected_columns AS (SELECT 'student_course_incomplete_cases' AS tbl, 'incomplete_case_id' AS col, 'int' AS typ, 'NO' AS nullable, 'auto_increment' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'student_course_registration_id' AS col, 'int' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'student_course_result_id' AS col, 'int' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'student_id' AS col, 'int' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'course_id' AS col, 'int' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'academic_year_id' AS col, 'int' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'semester_id' AS col, 'int' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'reason' AS col, 'text' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'requirements' AS col, 'text' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'granted_at' AS col, 'datetime' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'granted_by_user_id' AS col, 'int' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'deadline_at' AS col, 'datetime' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'deadline_source' AS col, 'varchar(16)' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'calendar_snapshot' AS col, 'longtext' AS typ, 'YES' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'expiry_outcome' AS col, 'char(1)' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'state' AS col, 'varchar(16)' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'revision' AS col, 'int unsigned' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'outcome_snapshot' AS col, 'longtext' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'settled_at' AS col, 'datetime' AS typ, 'YES' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'created_at' AS col, 'datetime' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'updated_at' AS col, 'datetime' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'incomplete_event_id' AS col, 'bigint' AS typ, 'NO' AS nullable, 'auto_increment' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'incomplete_case_id' AS col, 'int' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'revision' AS col, 'int unsigned' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'action' AS col, 'varchar(24)' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'request_id' AS col, 'varchar(100)' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'request_hash' AS col, 'char(64)' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'actor_kind' AS col, 'varchar(8)' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'actor_user_id' AS col, 'int' AS typ, 'YES' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'before_values' AS col, 'longtext' AS typ, 'YES' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'after_values' AS col, 'longtext' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'response_snapshot' AS col, 'longtext' AS typ, 'NO' AS nullable, '' AS extra, 'utf8mb4' AS charset_name, 'utf8mb4_unicode_ci' AS collation_name
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'performed_at' AS col, 'datetime' AS typ, 'NO' AS nullable, '' AS extra, '' AS charset_name, '' AS collation_name), expected_indexes AS (SELECT 'student_course_incomplete_cases' AS tbl, 'PRIMARY' AS idx, '0' AS non_unique, 'incomplete_case_id' AS cols
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'uq_crs_case_registration' AS idx, '0' AS non_unique, 'student_course_registration_id' AS cols
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'uq_crs_case_result' AS idx, '0' AS non_unique, 'student_course_result_id' AS cols
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'idx_crs_expiration' AS idx, '1' AS non_unique, 'state,deadline_at,incomplete_case_id' AS cols
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'idx_crs_history' AS idx, '1' AS non_unique, 'student_id,academic_year_id,semester_id' AS cols
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'PRIMARY' AS idx, '0' AS non_unique, 'incomplete_event_id' AS cols
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'uq_crs_event_revision' AS idx, '0' AS non_unique, 'incomplete_case_id,revision' AS cols
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'uq_crs_request' AS idx, '0' AS non_unique, 'request_id' AS cols
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'idx_crs_event_time' AS idx, '1' AS non_unique, 'performed_at,incomplete_event_id' AS cols), expected_fks AS (SELECT 'student_course_incomplete_cases' AS tbl, 'fk_crs_registration' AS fk, 'student_course_registration_id' AS cols, 'student_course_registrations' AS target, 'student_course_registration_id' AS target_cols
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'fk_crs_result' AS fk, 'student_course_result_id' AS cols, 'student_course_results' AS target, 'student_course_result_id' AS target_cols
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'fk_crs_student' AS fk, 'student_id' AS cols, 'students' AS target, 'student_id' AS target_cols
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'fk_crs_course' AS fk, 'course_id' AS cols, 'courses' AS target, 'course_id' AS target_cols
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'fk_crs_year' AS fk, 'academic_year_id' AS cols, 'academic_years' AS target, 'academic_year_id' AS target_cols
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'fk_crs_semester' AS fk, 'semester_id' AS cols, 'semesters' AS target, 'semester_id' AS target_cols
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'fk_crs_actor' AS fk, 'granted_by_user_id' AS cols, 'users' AS target, 'user_id' AS target_cols
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'fk_crs_event_case' AS fk, 'incomplete_case_id' AS cols, 'student_course_incomplete_cases' AS target, 'incomplete_case_id' AS target_cols
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'fk_crs_event_actor' AS fk, 'actor_user_id' AS cols, 'users' AS target, 'user_id' AS target_cols), expected_checks AS (SELECT 'student_course_incomplete_cases' AS tbl, 'ck_crs_state' AS chk, 'state in (''active'',''resolved'',''expired'')' AS body
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'ck_crs_outcome' AS chk, 'expiry_outcome in (''F'',''Z'')' AS body
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'ck_crs_deadline_source' AS chk, 'deadline_source = ''manual'' and calendar_snapshot is null or deadline_source = ''calendar'' and json_valid(calendar_snapshot)' AS body
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'ck_crs_snapshot' AS chk, 'json_valid(outcome_snapshot)' AS body
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'ck_crs_revision' AS chk, 'revision >= 1' AS body
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'ck_crs_terminal' AS chk, 'state = ''active'' and settled_at is null or state in (''resolved'',''expired'') and settled_at is not null' AS body
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'ck_crs_event_action' AS chk, 'action in (''grant'',''deadline_changed'',''resolved'',''expired'')' AS body
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'ck_crs_event_actor' AS chk, 'actor_kind = ''system'' and actor_user_id is null and action = ''expired'' or actor_kind = ''user'' and actor_user_id is not null' AS body
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'ck_crs_event_json' AS chk, 'json_valid(after_values) and json_valid(response_snapshot) and (before_values is null or json_valid(before_values))' AS body), expected_triggers AS (SELECT 'student_course_incomplete_events' AS tbl, 'crs_events_immutable_u' AS trg, 'UPDATE' AS op, 'BEGIN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''incomplete_events_append_only''; END' AS body
 UNION ALL SELECT 'student_course_incomplete_events' AS tbl, 'crs_events_immutable_d' AS trg, 'DELETE' AS op, 'BEGIN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''incomplete_events_append_only''; END' AS body
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'crs_case_history_u' AS trg, 'UPDATE' AS op, 'BEGIN IF OLD.state<>''active'' OR NEW.revision<>OLD.revision+1 OR NOT(NEW.student_course_registration_id<=>OLD.student_course_registration_id) OR NOT(NEW.student_course_result_id<=>OLD.student_course_result_id) OR NOT(NEW.student_id<=>OLD.student_id) OR NOT(NEW.course_id<=>OLD.course_id) OR NOT(NEW.academic_year_id<=>OLD.academic_year_id) OR NOT(NEW.semester_id<=>OLD.semester_id) OR NOT(NEW.granted_at<=>OLD.granted_at) OR NOT(NEW.granted_by_user_id<=>OLD.granted_by_user_id) OR NOT(NEW.reason<=>OLD.reason) OR NOT(NEW.requirements<=>OLD.requirements) OR NOT(NEW.expiry_outcome<=>OLD.expiry_outcome) OR NOT(NEW.created_at<=>OLD.created_at) OR (NEW.state=''active'' AND NOT(NEW.outcome_snapshot<=>OLD.outcome_snapshot)) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''incomplete_case_history_immutable''; END IF; END' AS body
 UNION ALL SELECT 'student_course_incomplete_cases' AS tbl, 'crs_case_history_d' AS trg, 'DELETE' AS op, 'BEGIN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''incomplete_case_history_immutable''; END' AS body),
owned_tables AS (SELECT * FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events')),
actual_columns AS (SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events')),
actual_indexes AS (SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,INDEX_TYPE,
 GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols,MAX(COALESCE(SUB_PART,0)) AS prefix_length
 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events') GROUP BY TABLE_NAME,INDEX_NAME,NON_UNIQUE,INDEX_TYPE),
actual_fks AS (SELECT r.TABLE_NAME,r.CONSTRAINT_NAME,r.DELETE_RULE,r.UPDATE_RULE,r.MATCH_OPTION,
 GROUP_CONCAT(u.COLUMN_NAME ORDER BY u.ORDINAL_POSITION SEPARATOR ',') AS cols,
 MIN(u.REFERENCED_TABLE_SCHEMA) AS target_schema, MIN(u.REFERENCED_TABLE_NAME) AS target,
 GROUP_CONCAT(u.REFERENCED_COLUMN_NAME ORDER BY u.ORDINAL_POSITION SEPARATOR ',') AS target_cols
 FROM information_schema.REFERENTIAL_CONSTRAINTS r JOIN information_schema.KEY_COLUMN_USAGE u ON u.CONSTRAINT_SCHEMA=r.CONSTRAINT_SCHEMA AND u.TABLE_NAME=r.TABLE_NAME AND u.CONSTRAINT_NAME=r.CONSTRAINT_NAME
 WHERE r.CONSTRAINT_SCHEMA=DATABASE() AND r.TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events') GROUP BY r.TABLE_NAME,r.CONSTRAINT_NAME,r.DELETE_RULE,r.UPDATE_RULE,r.MATCH_OPTION),
actual_checks AS (SELECT * FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events')),
actual_triggers AS (SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()),
issues AS (
 SELECT e.tbl AS object_name,'table_definition' AS problem FROM (SELECT DISTINCT tbl FROM expected_columns) e LEFT JOIN owned_tables a ON a.TABLE_NAME=e.tbl
 WHERE (a.TABLE_NAME IS NOT NULL) AND (a.TABLE_NAME IS NULL OR a.ENGINE<>'InnoDB' OR a.TABLE_TYPE<>'BASE TABLE' OR BINARY a.TABLE_COMMENT<>BINARY 'course-result-symbols-phase1-v1' OR a.TABLE_COLLATION<>'utf8mb4_unicode_ci')
 UNION ALL SELECT CONCAT(e.tbl,'.',e.col),'column_definition' FROM expected_columns e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_columns a ON a.TABLE_NAME=e.tbl AND a.COLUMN_NAME=e.col
 WHERE a.COLUMN_NAME IS NULL OR BINARY (CASE WHEN a.DATA_TYPE IN ('int','bigint') THEN REGEXP_REPLACE(a.COLUMN_TYPE,'[(][0-9]+[)]','') ELSE a.COLUMN_TYPE END)<>BINARY e.typ
 OR a.IS_NULLABLE<>e.nullable OR BINARY a.EXTRA<>BINARY e.extra OR COALESCE(a.CHARACTER_SET_NAME,'')<>e.charset_name OR COALESCE(a.COLLATION_NAME,'')<>e.collation_name
 OR (a.COLUMN_DEFAULT IS NOT NULL AND a.COLUMN_DEFAULT<>'NULL') OR COALESCE(a.GENERATION_EXPRESSION,'')<>''
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.COLUMN_NAME),'unexpected_column' FROM actual_columns a LEFT JOIN expected_columns e ON e.tbl=a.TABLE_NAME AND e.col=a.COLUMN_NAME WHERE e.col IS NULL
 UNION ALL SELECT CONCAT(e.tbl,'.',e.idx),'index_definition' FROM expected_indexes e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_indexes a ON a.TABLE_NAME=e.tbl AND a.INDEX_NAME=e.idx
 WHERE a.INDEX_NAME IS NULL OR a.cols IS NULL OR a.NON_UNIQUE<>e.non_unique OR BINARY a.cols<>BINARY e.cols OR a.INDEX_TYPE<>'BTREE' OR a.prefix_length<>0
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.INDEX_NAME),'unexpected_unique_index' FROM actual_indexes a LEFT JOIN expected_indexes e ON e.tbl=a.TABLE_NAME AND e.idx=a.INDEX_NAME WHERE a.NON_UNIQUE=0 AND e.idx IS NULL
 UNION ALL SELECT CONCAT(e.tbl,'.',e.fk),'foreign_key_definition' FROM expected_fks e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_fks a ON a.TABLE_NAME=e.tbl AND a.CONSTRAINT_NAME=e.fk
 WHERE a.CONSTRAINT_NAME IS NULL OR a.target_schema IS NULL OR a.target_cols IS NULL OR BINARY a.cols<>BINARY e.cols OR BINARY a.target_schema<>BINARY DATABASE() OR BINARY a.target<>BINARY e.target OR BINARY a.target_cols<>BINARY e.target_cols
 OR a.DELETE_RULE NOT IN ('RESTRICT','NO ACTION') OR a.UPDATE_RULE NOT IN ('RESTRICT','NO ACTION') OR a.MATCH_OPTION<>'NONE'
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.CONSTRAINT_NAME),'unexpected_foreign_key' FROM actual_fks a LEFT JOIN expected_fks e ON e.tbl=a.TABLE_NAME AND e.fk=a.CONSTRAINT_NAME WHERE e.fk IS NULL
 UNION ALL SELECT CONCAT(e.tbl,'.',e.chk),'check_definition' FROM expected_checks e JOIN owned_tables p ON p.TABLE_NAME=e.tbl LEFT JOIN actual_checks a ON a.TABLE_NAME=e.tbl AND a.CONSTRAINT_NAME=e.chk
 WHERE a.CONSTRAINT_NAME IS NULL OR a.CHECK_CLAUSE IS NULL OR BINARY REGEXP_REPLACE(TRIM(REPLACE(a.CHECK_CLAUSE,CHAR(96),'')),'[[:space:]]+',' ')<>BINARY e.body
 UNION ALL SELECT CONCAT(a.TABLE_NAME,'.',a.CONSTRAINT_NAME),'unexpected_check' FROM actual_checks a LEFT JOIN expected_checks e ON e.tbl=a.TABLE_NAME AND e.chk=a.CONSTRAINT_NAME WHERE e.chk IS NULL
 UNION ALL SELECT e.trg,'trigger_definition' FROM expected_triggers e LEFT JOIN actual_triggers a ON a.TRIGGER_NAME=e.trg
 WHERE (a.TRIGGER_NAME IS NOT NULL) AND (a.TRIGGER_NAME IS NULL OR BINARY a.EVENT_OBJECT_TABLE<>BINARY e.tbl OR a.EVENT_MANIPULATION<>e.op OR a.ACTION_TIMING<>'BEFORE' OR a.ACTION_ORIENTATION<>'ROW'
 OR a.ACTION_STATEMENT IS NULL OR BINARY REGEXP_REPLACE(TRIM(a.ACTION_STATEMENT),'[[:space:]]+',' ')<>BINARY e.body)
 UNION ALL SELECT a.TRIGGER_NAME,'unexpected_trigger' FROM actual_triggers a LEFT JOIN expected_triggers e ON e.trg=a.TRIGGER_NAME WHERE a.EVENT_OBJECT_TABLE IN ('student_course_incomplete_cases','student_course_incomplete_events') AND e.trg IS NULL
 UNION ALL SELECT e.col,'result_mark_definition' FROM (SELECT 'theoretical_total' AS col UNION ALL SELECT 'practical_total' UNION ALL SELECT 'final_mark') e
 LEFT JOIN information_schema.COLUMNS a ON a.TABLE_SCHEMA=DATABASE() AND a.TABLE_NAME='student_course_results' AND a.COLUMN_NAME=e.col
 WHERE a.COLUMN_NAME IS NULL OR a.COLUMN_TYPE<>'decimal(5,2)' OR NOT (((a.IS_NULLABLE='YES' AND (a.COLUMN_DEFAULT IS NULL OR a.COLUMN_DEFAULT='NULL')) OR (a.IS_NULLABLE='NO' AND a.COLUMN_DEFAULT IN ('0','0.00')))) OR a.EXTRA<>'' OR COALESCE(a.GENERATION_EXPRESSION,'')<>''
 UNION ALL SELECT 'student_course_results','result_registration_unique' WHERE NOT EXISTS (
 SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_results' AND NON_UNIQUE=0 AND INDEX_TYPE='BTREE'
 GROUP BY INDEX_NAME HAVING COUNT(*)=1 AND MIN(COLUMN_NAME)='student_course_registration_id' AND MAX(COALESCE(SUB_PART,0))=0)
)
SELECT object_name,problem FROM issues
) crs_issues);
DELIMITER $$
BEGIN NOT ATOMIC
 DECLARE prior_count INT DEFAULT 0;
 DECLARE prior_triggers INT DEFAULT 0;
 DECLARE was_ready INT DEFAULT 0;
 IF @crs_db IS NULL OR @crs_core<>13 OR @crs_keys<>7 OR @crs_statuses<>5 OR @crs_schema_issues<>0 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='course_result_symbols_schema_incompatible';
 END IF;
 SELECT COUNT(*) INTO prior_count FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events');
 SELECT COUNT(*) INTO prior_triggers FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('crs_events_immutable_u','crs_events_immutable_d','crs_case_history_u','crs_case_history_d');
 SELECT COUNT(*) INTO was_ready FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_results' AND COLUMN_NAME IN ('theoretical_total','practical_total','final_mark') AND IS_NULLABLE='YES';
 CREATE TABLE IF NOT EXISTS student_course_incomplete_cases (
 incomplete_case_id INT NOT NULL AUTO_INCREMENT,
 student_course_registration_id INT NOT NULL,
 student_course_result_id INT NOT NULL,
 student_id INT NOT NULL,
 course_id INT NOT NULL,
 academic_year_id INT NOT NULL,
 semester_id INT NOT NULL,
 reason TEXT NOT NULL,
 requirements TEXT NOT NULL,
 granted_at DATETIME NOT NULL,
 granted_by_user_id INT NOT NULL,
 deadline_at DATETIME NOT NULL,
 deadline_source VARCHAR(16) NOT NULL,
 calendar_snapshot LONGTEXT NULL DEFAULT NULL,
 expiry_outcome CHAR(1) NOT NULL,
 state VARCHAR(16) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 outcome_snapshot LONGTEXT NOT NULL,
 settled_at DATETIME NULL DEFAULT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(incomplete_case_id),
 UNIQUE KEY uq_crs_case_registration(student_course_registration_id),
 UNIQUE KEY uq_crs_case_result(student_course_result_id),
 KEY idx_crs_expiration(state,deadline_at,incomplete_case_id),
 KEY idx_crs_history(student_id,academic_year_id,semester_id),
 CONSTRAINT fk_crs_registration FOREIGN KEY(student_course_registration_id) REFERENCES student_course_registrations(student_course_registration_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_crs_result FOREIGN KEY(student_course_result_id) REFERENCES student_course_results(student_course_result_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_crs_student FOREIGN KEY(student_id) REFERENCES students(student_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_crs_course FOREIGN KEY(course_id) REFERENCES courses(course_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_crs_year FOREIGN KEY(academic_year_id) REFERENCES academic_years(academic_year_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_crs_semester FOREIGN KEY(semester_id) REFERENCES semesters(semester_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_crs_actor FOREIGN KEY(granted_by_user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ck_crs_state CHECK(state in ('active','resolved','expired')),
 CONSTRAINT ck_crs_outcome CHECK(expiry_outcome in ('F','Z')),
 CONSTRAINT ck_crs_deadline_source CHECK(deadline_source = 'manual' and calendar_snapshot is null or deadline_source = 'calendar' and json_valid(calendar_snapshot)),
 CONSTRAINT ck_crs_snapshot CHECK(json_valid(outcome_snapshot)),
 CONSTRAINT ck_crs_revision CHECK(revision >= 1),
 CONSTRAINT ck_crs_terminal CHECK(state = 'active' and settled_at is null or state in ('resolved','expired') and settled_at is not null)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='course-result-symbols-phase1-v1';
 CREATE TABLE IF NOT EXISTS student_course_incomplete_events (
 incomplete_event_id BIGINT NOT NULL AUTO_INCREMENT,
 incomplete_case_id INT NOT NULL,
 revision INT UNSIGNED NOT NULL,
 action VARCHAR(24) NOT NULL,
 request_id VARCHAR(100) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 actor_kind VARCHAR(8) NOT NULL,
 actor_user_id INT NULL DEFAULT NULL,
 before_values LONGTEXT NULL DEFAULT NULL,
 after_values LONGTEXT NOT NULL,
 response_snapshot LONGTEXT NOT NULL,
 performed_at DATETIME NOT NULL,
 PRIMARY KEY(incomplete_event_id),
 UNIQUE KEY uq_crs_event_revision(incomplete_case_id,revision),
 UNIQUE KEY uq_crs_request(request_id),
 KEY idx_crs_event_time(performed_at,incomplete_event_id),
 CONSTRAINT fk_crs_event_case FOREIGN KEY(incomplete_case_id) REFERENCES student_course_incomplete_cases(incomplete_case_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_crs_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ck_crs_event_action CHECK(action in ('grant','deadline_changed','resolved','expired')),
 CONSTRAINT ck_crs_event_actor CHECK(actor_kind = 'system' and actor_user_id is null and action = 'expired' or actor_kind = 'user' and actor_user_id is not null),
 CONSTRAINT ck_crs_event_json CHECK(json_valid(after_values) and json_valid(response_snapshot) and (before_values is null or json_valid(before_values)))
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='course-result-symbols-phase1-v1';
 IF was_ready<>3 THEN
  ALTER TABLE student_course_results MODIFY theoretical_total DECIMAL(5,2) NULL DEFAULT NULL, MODIFY practical_total DECIMAL(5,2) NULL DEFAULT NULL, MODIFY final_mark DECIMAL(5,2) NULL DEFAULT NULL;
 END IF;
 -- Never replace any existing trigger: it was recognized above or the whole block refused.
 IF NOT EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='crs_events_immutable_u') THEN
  EXECUTE IMMEDIATE 'CREATE TRIGGER crs_events_immutable_u BEFORE UPDATE ON student_course_incomplete_events FOR EACH ROW BEGIN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''incomplete_events_append_only''; END';
 END IF;
 IF NOT EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='crs_events_immutable_d') THEN
  EXECUTE IMMEDIATE 'CREATE TRIGGER crs_events_immutable_d BEFORE DELETE ON student_course_incomplete_events FOR EACH ROW BEGIN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''incomplete_events_append_only''; END';
 END IF;
 IF NOT EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='crs_case_history_u') THEN
  EXECUTE IMMEDIATE 'CREATE TRIGGER crs_case_history_u BEFORE UPDATE ON student_course_incomplete_cases FOR EACH ROW BEGIN
 IF OLD.state<>''active'' OR NEW.revision<>OLD.revision+1 OR
  NOT(NEW.student_course_registration_id<=>OLD.student_course_registration_id) OR NOT(NEW.student_course_result_id<=>OLD.student_course_result_id) OR
  NOT(NEW.student_id<=>OLD.student_id) OR NOT(NEW.course_id<=>OLD.course_id) OR NOT(NEW.academic_year_id<=>OLD.academic_year_id) OR NOT(NEW.semester_id<=>OLD.semester_id) OR
  NOT(NEW.granted_at<=>OLD.granted_at) OR NOT(NEW.granted_by_user_id<=>OLD.granted_by_user_id) OR NOT(NEW.reason<=>OLD.reason) OR NOT(NEW.requirements<=>OLD.requirements) OR
  NOT(NEW.expiry_outcome<=>OLD.expiry_outcome) OR NOT(NEW.created_at<=>OLD.created_at) OR
  (NEW.state=''active'' AND NOT(NEW.outcome_snapshot<=>OLD.outcome_snapshot)) THEN
  SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''incomplete_case_history_immutable'';
 END IF;
END';
 END IF;
 IF NOT EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='crs_case_history_d') THEN
  EXECUTE IMMEDIATE 'CREATE TRIGGER crs_case_history_d BEFORE DELETE ON student_course_incomplete_cases FOR EACH ROW BEGIN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''incomplete_case_history_immutable''; END';
 END IF;
 SET @crs_apply_result=CASE WHEN prior_count=0 THEN 'APPLIED' WHEN prior_count<2 OR was_ready<>3 OR prior_triggers<>4 THEN 'RESUMED' ELSE 'ALREADY_APPLIED' END;
END$$
DELIMITER ;
SELECT @crs_apply_result AS result;
