-- Sole installation owner for this academic feature. No migration/seeder/backfill.
-- Run after 00_preflight during maintenance. MariaDB DDL is not transactional.
SET @crs_apply_result=NULL;
DELIMITER $$
CREATE OR REPLACE PROCEDURE crs_phase1_apply()
BEGIN
 DECLARE core_count INT DEFAULT 0;
 DECLARE key_count INT DEFAULT 0;
 DECLARE prior_count INT DEFAULT 0;
 DECLARE foreign_count INT DEFAULT 0;
 DECLARE was_ready INT DEFAULT 0;
 SELECT COUNT(*) INTO core_count FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE='InnoDB' AND TABLE_NAME IN
 ('students','users','courses','academic_years','semesters','student_course_registrations','student_course_results','result_statuses','grade_components','student_grade_components','grade_audit_logs','grade_approvals','grade_part_approvals');
 SELECT COUNT(*) INTO key_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND DATA_TYPE='int' AND COLUMN_TYPE NOT LIKE '%unsigned%' AND
 ((TABLE_NAME='students' AND COLUMN_NAME='student_id') OR (TABLE_NAME='users' AND COLUMN_NAME='user_id') OR (TABLE_NAME='courses' AND COLUMN_NAME='course_id') OR
 (TABLE_NAME='academic_years' AND COLUMN_NAME='academic_year_id') OR (TABLE_NAME='semesters' AND COLUMN_NAME='semester_id') OR
 (TABLE_NAME='student_course_registrations' AND COLUMN_NAME='student_course_registration_id') OR (TABLE_NAME='student_course_results' AND COLUMN_NAME='student_course_result_id'));
 SELECT COUNT(*) INTO prior_count FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events');
 SELECT COUNT(*) INTO was_ready FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_results' AND COLUMN_NAME IN ('theoretical_total','practical_total','final_mark') AND IS_NULLABLE='YES';
 SELECT COUNT(*) INTO foreign_count FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events') AND TABLE_COMMENT<>'course-result-symbols-phase1-v1';
 IF core_count<>13 OR key_count<>7 OR foreign_count<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='course_result_symbols_preflight_blocked'; END IF;
 IF (SELECT COUNT(*) FROM result_statuses WHERE status_code IN ('passed','failed','deprived','incomplete','pending_approval') AND is_active=1)<>5 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='course_result_symbols_status_configuration'; END IF;
 IF (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_results' AND NON_UNIQUE=0 AND COLUMN_NAME='student_course_registration_id')<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='course_result_symbols_result_unique_required'; END IF;
 CREATE TABLE IF NOT EXISTS student_course_incomplete_cases (
  incomplete_case_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_course_registration_id INT NOT NULL, student_course_result_id INT NOT NULL,
  student_id INT NOT NULL, course_id INT NOT NULL, academic_year_id INT NOT NULL, semester_id INT NOT NULL,
  reason TEXT NOT NULL, requirements TEXT NOT NULL, granted_at DATETIME NOT NULL, granted_by_user_id INT NOT NULL,
  deadline_at DATETIME NOT NULL, deadline_source VARCHAR(16) NOT NULL, calendar_snapshot LONGTEXT NULL,
  expiry_outcome CHAR(1) NOT NULL, state VARCHAR(16) NOT NULL, revision INT UNSIGNED NOT NULL,
  outcome_snapshot LONGTEXT NOT NULL, settled_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_crs_case_registration(student_course_registration_id), UNIQUE KEY uq_crs_case_result(student_course_result_id),
  KEY idx_crs_expiration(state,deadline_at,incomplete_case_id), KEY idx_crs_history(student_id,academic_year_id,semester_id),
  CONSTRAINT fk_crs_registration FOREIGN KEY(student_course_registration_id) REFERENCES student_course_registrations(student_course_registration_id) ON DELETE RESTRICT,
  CONSTRAINT fk_crs_result FOREIGN KEY(student_course_result_id) REFERENCES student_course_results(student_course_result_id) ON DELETE RESTRICT,
  CONSTRAINT fk_crs_student FOREIGN KEY(student_id) REFERENCES students(student_id) ON DELETE RESTRICT,
  CONSTRAINT fk_crs_course FOREIGN KEY(course_id) REFERENCES courses(course_id) ON DELETE RESTRICT,
  CONSTRAINT fk_crs_year FOREIGN KEY(academic_year_id) REFERENCES academic_years(academic_year_id) ON DELETE RESTRICT,
  CONSTRAINT fk_crs_semester FOREIGN KEY(semester_id) REFERENCES semesters(semester_id) ON DELETE RESTRICT,
  CONSTRAINT fk_crs_actor FOREIGN KEY(granted_by_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_crs_state CHECK(state IN ('active','resolved','expired')),
  CONSTRAINT ck_crs_outcome CHECK(expiry_outcome IN ('F','Z')),
  CONSTRAINT ck_crs_deadline_source CHECK((deadline_source='manual' AND calendar_snapshot IS NULL) OR (deadline_source='calendar' AND JSON_VALID(calendar_snapshot))),
  CONSTRAINT ck_crs_snapshot CHECK(JSON_VALID(outcome_snapshot)),
  CONSTRAINT ck_crs_revision CHECK(revision>=1),
  CONSTRAINT ck_crs_terminal CHECK((state='active' AND settled_at IS NULL) OR (state IN ('resolved','expired') AND settled_at IS NOT NULL))
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='course-result-symbols-phase1-v1';
 CREATE TABLE IF NOT EXISTS student_course_incomplete_events (
  incomplete_event_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, incomplete_case_id INT NOT NULL, revision INT UNSIGNED NOT NULL,
  action VARCHAR(24) NOT NULL, request_id VARCHAR(100) NOT NULL, request_hash CHAR(64) NOT NULL,
  actor_kind VARCHAR(8) NOT NULL, actor_user_id INT NULL, before_values LONGTEXT NULL, after_values LONGTEXT NOT NULL,
  response_snapshot LONGTEXT NOT NULL, performed_at DATETIME NOT NULL,
  UNIQUE KEY uq_crs_event_revision(incomplete_case_id,revision), UNIQUE KEY uq_crs_request(request_id), KEY idx_crs_event_time(performed_at,incomplete_event_id),
  CONSTRAINT fk_crs_event_case FOREIGN KEY(incomplete_case_id) REFERENCES student_course_incomplete_cases(incomplete_case_id) ON DELETE RESTRICT,
  CONSTRAINT fk_crs_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_crs_event_action CHECK(action IN ('grant','deadline_changed','resolved','expired')),
  CONSTRAINT ck_crs_event_actor CHECK((actor_kind='system' AND actor_user_id IS NULL AND action='expired') OR (actor_kind='user' AND actor_user_id IS NOT NULL)),
  CONSTRAINT ck_crs_event_json CHECK(JSON_VALID(after_values) AND JSON_VALID(response_snapshot) AND (before_values IS NULL OR JSON_VALID(before_values)))
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='course-result-symbols-phase1-v1';
 -- Existing tables with an ownership marker must still match this version, never silently repair foreign layouts.
 IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_incomplete_cases')<>21 OR
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_incomplete_events')<>12 OR
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_incomplete_cases' AND COLUMN_NAME IN
      ('incomplete_case_id','student_course_registration_id','student_course_result_id','student_id','course_id','academic_year_id','semester_id','reason','requirements','granted_at','granted_by_user_id','deadline_at','deadline_source','calendar_snapshot','expiry_outcome','state','revision','outcome_snapshot','settled_at','created_at','updated_at'))<>21 OR
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_incomplete_events' AND COLUMN_NAME IN
      ('incomplete_event_id','incomplete_case_id','revision','action','request_id','request_hash','actor_kind','actor_user_id','before_values','after_values','response_snapshot','performed_at'))<>12 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='course_result_symbols_partial_schema_incompatible';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events') AND DELETE_RULE IN ('RESTRICT','NO ACTION'))<>9 OR
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_incomplete_cases' AND DATA_TYPE='int' AND COLUMN_TYPE NOT LIKE '%unsigned%' AND COLUMN_NAME IN ('incomplete_case_id','student_course_registration_id','student_course_result_id','student_id','course_id','academic_year_id','semester_id','granted_by_user_id'))<>8 OR
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events') AND COLUMN_NAME='revision' AND DATA_TYPE='int' AND COLUMN_TYPE LIKE '%unsigned%')<>2 OR
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events') AND IS_NULLABLE='YES')<>4 OR
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events') AND NON_UNIQUE=0 AND INDEX_NAME IN ('uq_crs_case_registration','uq_crs_case_result','uq_crs_event_revision','uq_crs_request'))<>5 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='course_result_symbols_partial_constraints_incompatible';
 END IF;
 ALTER TABLE student_course_results MODIFY theoretical_total DECIMAL(5,2) NULL DEFAULT NULL,
  MODIFY practical_total DECIMAL(5,2) NULL DEFAULT NULL, MODIFY final_mark DECIMAL(5,2) NULL DEFAULT NULL;
 SET @crs_apply_result=CASE WHEN prior_count=0 THEN 'APPLIED' WHEN prior_count<2 OR was_ready<>3 THEN 'RESUMED' ELSE 'ALREADY_APPLIED' END;
END$$
CALL crs_phase1_apply()$$
DROP PROCEDURE crs_phase1_apply$$
CREATE OR REPLACE TRIGGER crs_events_immutable_u BEFORE UPDATE ON student_course_incomplete_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incomplete_events_append_only'; END$$
CREATE OR REPLACE TRIGGER crs_events_immutable_d BEFORE DELETE ON student_course_incomplete_events FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incomplete_events_append_only'; END$$
CREATE OR REPLACE TRIGGER crs_case_history_u BEFORE UPDATE ON student_course_incomplete_cases FOR EACH ROW
BEGIN
 IF OLD.state<>'active' OR NEW.revision<>OLD.revision+1 OR
  NOT(NEW.student_course_registration_id<=>OLD.student_course_registration_id) OR NOT(NEW.student_course_result_id<=>OLD.student_course_result_id) OR
  NOT(NEW.student_id<=>OLD.student_id) OR NOT(NEW.course_id<=>OLD.course_id) OR NOT(NEW.academic_year_id<=>OLD.academic_year_id) OR NOT(NEW.semester_id<=>OLD.semester_id) OR
  NOT(NEW.granted_at<=>OLD.granted_at) OR NOT(NEW.granted_by_user_id<=>OLD.granted_by_user_id) OR NOT(NEW.reason<=>OLD.reason) OR NOT(NEW.requirements<=>OLD.requirements) OR
  NOT(NEW.expiry_outcome<=>OLD.expiry_outcome) OR NOT(NEW.created_at<=>OLD.created_at) OR
  (NEW.state='active' AND NOT(NEW.outcome_snapshot<=>OLD.outcome_snapshot)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incomplete_case_history_immutable';
 END IF;
END$$
CREATE OR REPLACE TRIGGER crs_case_history_d BEFORE DELETE ON student_course_incomplete_cases FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incomplete_case_history_immutable'; END$$
DELIMITER ;
-- Report success only after all owned history triggers have been installed.
SELECT @crs_apply_result AS result;
