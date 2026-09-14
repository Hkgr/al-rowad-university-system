-- MANUAL MAINTENANCE ONLY. Stop affected HTTP writers and workers before applying.
-- Do not run the old application against this package. No students or plans are backfilled.
USE alrowad_uni_rust;
DELIMITER //
CREATE OR REPLACE PROCEDURE sc_plan_apply_guard()
BEGIN
  DECLARE incompatible_count INT DEFAULT 0;
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
SELECT COUNT(*) INTO incompatible_count FROM issues;
  IF incompatible_count<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_incompatible_partial_schema'; END IF;
  IF NOT EXISTS(SELECT 1 FROM academic_catalog_control WHERE control_id=1 AND schema_version=1 AND is_ready=1)
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_schema_not_ready'; END IF;
  IF NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='user_activity_logs'
    AND column_name='activity_log_id' AND data_type='bigint' AND column_type NOT LIKE '%unsigned%')
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_incompatible_audit_key'; END IF;
END//
DELIMITER ;
CALL sc_plan_apply_guard();

CREATE TABLE IF NOT EXISTS academic_plan_control (
  control_id INT NOT NULL PRIMARY KEY,
  schema_version INT NOT NULL,
  is_ready TINYINT NOT NULL DEFAULT 0,
  CONSTRAINT chk_plan_control CHECK(control_id=1 AND schema_version=1 AND is_ready IN(0,1))
) ENGINE=InnoDB COMMENT='academic-plans-v1';
INSERT INTO academic_plan_control(control_id,schema_version,is_ready)
SELECT 1,1,0 WHERE NOT EXISTS(SELECT 1 FROM academic_plan_control WHERE control_id=1);
UPDATE academic_plan_control SET is_ready=0 WHERE control_id=1;

CREATE TABLE IF NOT EXISTS academic_plan_versions (
  academic_plan_version_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  academic_program_id INT NOT NULL,
  version_number INT NOT NULL,
  label VARCHAR(150) NOT NULL,
  status VARCHAR(20) NOT NULL,
  calculation_policy VARCHAR(30) NOT NULL,
  source_version_id INT NULL,
  total_credit_hours INT NULL,
  created_by_user_id INT NOT NULL,
  approved_by_user_id INT NULL,
  approved_at DATETIME NULL,
  fixed_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_plan_version(academic_program_id,version_number),
  UNIQUE KEY uq_plan_owner(academic_plan_version_id,academic_program_id),
  CONSTRAINT fk_plan_program FOREIGN KEY(academic_program_id) REFERENCES academic_programs(academic_program_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_plan_source FOREIGN KEY(source_version_id,academic_program_id) REFERENCES academic_plan_versions(academic_plan_version_id,academic_program_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_plan_creator FOREIGN KEY(created_by_user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_plan_approver FOREIGN KEY(approved_by_user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_plan_state CHECK(
    (status='draft' AND fixed_at IS NULL AND approved_at IS NULL AND approved_by_user_id IS NULL AND calculation_policy='explicit_zero_v1')
    OR (status='transitional' AND fixed_at IS NOT NULL AND approved_at IS NULL AND approved_by_user_id IS NULL AND calculation_policy='legacy')
    OR (status='approved' AND fixed_at IS NOT NULL AND approved_at IS NOT NULL AND approved_by_user_id IS NOT NULL AND calculation_policy='explicit_zero_v1')
  ),
  CONSTRAINT chk_plan_hours CHECK(version_number>0 AND (total_credit_hours IS NULL OR total_credit_hours>0))
) ENGINE=InnoDB COMMENT='academic-plans-v1';

CREATE TABLE IF NOT EXISTS student_academic_plan_assignments (
  student_academic_plan_assignment_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  academic_program_id INT NOT NULL,
  academic_plan_version_id INT NOT NULL,
  current_slot TINYINT NULL,
  assigned_by_user_id INT NULL,
  reason VARCHAR(1000) NOT NULL,
  assigned_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  UNIQUE KEY uq_student_plan_current(student_id,current_slot),
  KEY idx_plan_students(academic_plan_version_id,current_slot,student_id),
  CONSTRAINT fk_student_plan_student FOREIGN KEY(student_id) REFERENCES students(student_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_student_plan_version FOREIGN KEY(academic_plan_version_id,academic_program_id) REFERENCES academic_plan_versions(academic_plan_version_id,academic_program_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_student_plan_actor FOREIGN KEY(assigned_by_user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_student_plan_slot CHECK((current_slot IS NOT NULL AND current_slot=1 AND ended_at IS NULL) OR (current_slot IS NULL AND ended_at IS NOT NULL)),
  CONSTRAINT chk_student_plan_reason CHECK(CHAR_LENGTH(TRIM(reason))>0)
) ENGINE=InnoDB COMMENT='academic-plans-v1';

CREATE TABLE IF NOT EXISTS academic_plan_events (
  academic_plan_event_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  academic_program_id INT NOT NULL,
  academic_plan_version_id INT NULL,
  action VARCHAR(80) NOT NULL,
  actor_user_id INT NOT NULL,
  context LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_plan_event_history(academic_program_id,academic_plan_event_id),
  CONSTRAINT fk_plan_event_program FOREIGN KEY(academic_program_id) REFERENCES academic_programs(academic_program_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_plan_event_version FOREIGN KEY(academic_plan_version_id,academic_program_id) REFERENCES academic_plan_versions(academic_plan_version_id,academic_program_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_plan_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_plan_event_json CHECK(JSON_VALID(context))
) ENGINE=InnoDB COMMENT='academic-plans-v1';

ALTER TABLE academic_programs
  ADD COLUMN IF NOT EXISTS plan_state VARCHAR(20) NOT NULL DEFAULT 'legacy',
  ADD COLUMN IF NOT EXISTS default_academic_plan_version_id INT NULL,
  ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL;
ALTER TABLE program_courses
  ADD COLUMN IF NOT EXISTS academic_plan_version_id INT NULL,
  ADD COLUMN IF NOT EXISTS plan_scope_key INT GENERATED ALWAYS AS (COALESCE(academic_plan_version_id,0)) STORED;
ALTER TABLE academic_requirement_groups
  ADD COLUMN IF NOT EXISTS academic_plan_version_id INT NULL,
  ADD COLUMN IF NOT EXISTS plan_scope_key INT GENERATED ALWAYS AS (COALESCE(academic_plan_version_id,0)) STORED,
  MODIFY required_credit_hours INT NULL;
-- Existing program/course and program/scope/type uniqueness must be replaced, not simply dropped.
-- Names below match the audited production schema. Guard and verify must reject foreign layouts.
ALTER TABLE program_courses
  ADD UNIQUE INDEX IF NOT EXISTS uq_program_plan_course(academic_program_id,plan_scope_key,course_id);
ALTER TABLE academic_requirement_groups
  ADD UNIQUE INDEX IF NOT EXISTS uq_program_plan_scope(academic_program_id,plan_scope_key,requirement_scope,requirement_type);
ALTER TABLE program_courses DROP INDEX IF EXISTS uq_program_course;
ALTER TABLE academic_requirement_groups DROP INDEX IF EXISTS uq_program_scope_type;

DELIMITER //
CREATE OR REPLACE PROCEDURE sc_plan_add_constraints()
BEGIN
  IF NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='academic_programs' AND constraint_name='fk_program_default_plan') THEN
    ALTER TABLE academic_programs ADD CONSTRAINT fk_program_default_plan FOREIGN KEY(default_academic_plan_version_id,academic_program_id) REFERENCES academic_plan_versions(academic_plan_version_id,academic_program_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='program_courses' AND constraint_name='fk_membership_plan') THEN
    ALTER TABLE program_courses ADD CONSTRAINT fk_membership_plan FOREIGN KEY(academic_plan_version_id,academic_program_id) REFERENCES academic_plan_versions(academic_plan_version_id,academic_program_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='academic_requirement_groups' AND constraint_name='fk_requirement_plan') THEN
    ALTER TABLE academic_requirement_groups ADD CONSTRAINT fk_requirement_plan FOREIGN KEY(academic_plan_version_id,academic_program_id) REFERENCES academic_plan_versions(academic_plan_version_id,academic_program_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='academic_programs' AND constraint_name='chk_program_plan_state') THEN
    ALTER TABLE academic_programs ADD CONSTRAINT chk_program_plan_state CHECK(
      (plan_state IN('legacy','preparing') AND default_academic_plan_version_id IS NULL)
      OR (plan_state='ready' AND default_academic_plan_version_id IS NOT NULL));
  END IF;
END//
DELIMITER ;
CALL sc_plan_add_constraints();

DELIMITER //
CREATE OR REPLACE PROCEDURE sc_plan_touch()
BEGIN
  DECLARE ready_value INT DEFAULT 0;
  CALL sc_catalog_touch();
  SELECT is_ready INTO ready_value FROM academic_plan_control WHERE control_id=1 AND schema_version=1 FOR UPDATE;
  IF ready_value<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_schema_not_ready'; END IF;
END//
CREATE OR REPLACE PROCEDURE sc_plan_editable(program_key INT, version_key INT)
BEGIN
  DECLARE plan_status VARCHAR(20) DEFAULT NULL;
  DECLARE program_state VARCHAR(20) DEFAULT NULL;
  SELECT plan_state INTO program_state FROM academic_programs WHERE academic_program_id=program_key FOR UPDATE;
  IF version_key IS NULL THEN
    IF program_state<>'legacy' OR sc_catalog_program_used(program_key) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked';
    END IF;
  ELSE
    SELECT status INTO plan_status FROM academic_plan_versions WHERE academic_plan_version_id=version_key AND academic_program_id=program_key FOR UPDATE;
    IF plan_status IS NULL OR plan_status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_locked'; END IF;
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_versions_i BEFORE INSERT ON academic_plan_versions FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF NEW.status='approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_approval_required'; END IF;
  IF NEW.status='transitional' AND (NEW.version_number<>1 OR EXISTS(SELECT 1 FROM academic_plan_versions WHERE academic_program_id=NEW.academic_program_id)
    OR NOT EXISTS(SELECT 1 FROM academic_programs WHERE academic_program_id=NEW.academic_program_id AND plan_state='preparing')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_transition_invalid';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_versions_u BEFORE UPDATE ON academic_plan_versions FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF OLD.status<>'draft' OR NOT(OLD.academic_program_id<=>NEW.academic_program_id) OR NOT(OLD.version_number<=>NEW.version_number)
    OR NOT(OLD.source_version_id<=>NEW.source_version_id) OR NEW.status NOT IN('draft','approved') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_locked';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_program_i BEFORE INSERT ON academic_programs FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF NEW.plan_state<>'preparing' OR NEW.default_academic_plan_version_id IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_initialization_required';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_program_u BEFORE UPDATE ON academic_programs FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF (OLD.plan_state='preparing' AND NEW.plan_state NOT IN('preparing','ready'))
    OR (OLD.plan_state='ready' AND NEW.plan_state<>'ready')
    OR (OLD.plan_state='legacy' AND NEW.plan_state NOT IN('legacy','preparing')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_transition_invalid';
  END IF;
  IF NEW.plan_state='ready' AND (
    NOT EXISTS(SELECT 1 FROM academic_plan_versions WHERE academic_plan_version_id=NEW.default_academic_plan_version_id
      AND academic_program_id=NEW.academic_program_id AND status='approved' AND fixed_at IS NOT NULL)
    OR EXISTS(SELECT 1 FROM students s LEFT JOIN student_academic_plan_assignments a ON a.student_id=s.student_id AND a.current_slot=1
      WHERE s.academic_program_id=NEW.academic_program_id AND a.student_academic_plan_assignment_id IS NULL)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_initialization_incomplete';
  END IF;
  IF EXISTS(SELECT 1 FROM academic_plan_versions WHERE academic_program_id=OLD.academic_program_id AND status IN('approved','transitional'))
    AND (NOT(NEW.program_code<=>OLD.program_code) OR NOT(NEW.department_id<=>OLD.department_id)
      OR NOT(NEW.degree_level<=>OLD.degree_level) OR NOT(NEW.duration_years<=>OLD.duration_years)
      OR NOT(NEW.total_credit_hours<=>OLD.total_credit_hours) OR NOT(NEW.is_active<=>OLD.is_active)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_program_identity_locked';
  END IF;
END//
CREATE OR REPLACE FUNCTION sc_plan_fixed_course(course_key INT) RETURNS BOOLEAN READS SQL DATA
BEGIN
  RETURN EXISTS(SELECT 1 FROM program_courses pc JOIN academic_plan_versions v ON v.academic_plan_version_id=pc.academic_plan_version_id
    WHERE pc.course_id=course_key AND v.status IN('approved','transitional'));
END//
CREATE OR REPLACE TRIGGER sc_plan_course_u BEFORE UPDATE ON courses FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF sc_plan_fixed_course(OLD.course_id) AND (NOT(NEW.course_code<=>OLD.course_code) OR NOT(NEW.credit_hours<=>OLD.credit_hours)
    OR NOT(NEW.theoretical_hours<=>OLD.theoretical_hours) OR NOT(NEW.practical_hours<=>OLD.practical_hours) OR NOT(NEW.is_active<=>OLD.is_active)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_prerequisite_i BEFORE INSERT ON course_prerequisites FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF sc_plan_fixed_course(NEW.course_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_prerequisite_u BEFORE UPDATE ON course_prerequisites FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF sc_plan_fixed_course(OLD.course_id) OR sc_plan_fixed_course(NEW.course_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_prerequisite_d BEFORE DELETE ON course_prerequisites FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF sc_plan_fixed_course(OLD.course_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_catalog_history_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_versions_d BEFORE DELETE ON academic_plan_versions FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_locked'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_program_courses_i BEFORE INSERT ON program_courses FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  CALL sc_plan_editable(NEW.academic_program_id,NEW.academic_plan_version_id);
END//
CREATE OR REPLACE TRIGGER sc_cat_program_courses_u BEFORE UPDATE ON program_courses FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL AND (NEW.academic_program_id<=>OLD.academic_program_id) AND (NEW.course_id<=>OLD.course_id) AND (NEW.academic_level_id<=>OLD.academic_level_id) AND (NEW.recommended_semester_id<=>OLD.recommended_semester_id) AND (NEW.course_type<=>OLD.course_type) AND (NEW.is_active<=>OLD.is_active)
    AND EXISTS(SELECT 1 FROM academic_plan_versions v JOIN academic_programs p ON p.academic_program_id=v.academic_program_id
      WHERE v.academic_plan_version_id=NEW.academic_plan_version_id AND v.academic_program_id=OLD.academic_program_id
      AND v.status='transitional' AND p.plan_state='preparing') THEN
    BEGIN END;
  ELSE
    IF NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) OR NOT(NEW.academic_program_id<=>OLD.academic_program_id) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
    END IF;
    CALL sc_plan_editable(OLD.academic_program_id,OLD.academic_plan_version_id);
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_program_courses_d BEFORE DELETE ON program_courses FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  CALL sc_plan_editable(OLD.academic_program_id,OLD.academic_plan_version_id);
END//
CREATE OR REPLACE TRIGGER sc_cat_academic_requirement_groups_i BEFORE INSERT ON academic_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  CALL sc_plan_editable(NEW.academic_program_id,NEW.academic_plan_version_id);
END//
CREATE OR REPLACE TRIGGER sc_cat_academic_requirement_groups_u BEFORE UPDATE ON academic_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL AND (NEW.academic_program_id<=>OLD.academic_program_id) AND (NEW.group_code<=>OLD.group_code) AND (NEW.group_name<=>OLD.group_name) AND (NEW.requirement_scope<=>OLD.requirement_scope) AND (NEW.requirement_type<=>OLD.requirement_type) AND (NEW.required_credit_hours<=>OLD.required_credit_hours) AND (NEW.is_active<=>OLD.is_active)
    AND EXISTS(SELECT 1 FROM academic_plan_versions v JOIN academic_programs p ON p.academic_program_id=v.academic_program_id
      WHERE v.academic_plan_version_id=NEW.academic_plan_version_id AND v.academic_program_id=OLD.academic_program_id
      AND v.status='transitional' AND p.plan_state='preparing') THEN
    BEGIN END;
  ELSE
    IF NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) OR NOT(NEW.academic_program_id<=>OLD.academic_program_id) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
    END IF;
    CALL sc_plan_editable(OLD.academic_program_id,OLD.academic_plan_version_id);
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_academic_requirement_groups_d BEFORE DELETE ON academic_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  CALL sc_plan_editable(OLD.academic_program_id,OLD.academic_plan_version_id);
END//
CREATE OR REPLACE PROCEDURE sc_plan_mapping_editable(membership_key INT, group_key INT)
BEGIN
  DECLARE program_key INT DEFAULT NULL;
  DECLARE version_key INT DEFAULT NULL;
  SELECT academic_program_id,academic_plan_version_id INTO program_key,version_key FROM program_courses WHERE program_course_id=membership_key FOR UPDATE;
  CALL sc_plan_editable(program_key,version_key);
  IF group_key IS NOT NULL AND NOT EXISTS(SELECT 1 FROM academic_requirement_groups g JOIN program_courses pc
    ON pc.program_course_id=membership_key WHERE g.requirement_group_id=group_key AND g.academic_program_id=pc.academic_program_id
    AND (g.academic_plan_version_id<=>pc.academic_plan_version_id) AND g.requirement_type=pc.course_type) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_group_mismatch';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_cat_program_course_requirement_groups_i BEFORE INSERT ON program_course_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_plan_touch(); CALL sc_plan_mapping_editable(NEW.program_course_id,NEW.requirement_group_id);
END//
CREATE OR REPLACE TRIGGER sc_cat_program_course_requirement_groups_u BEFORE UPDATE ON program_course_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_plan_touch(); CALL sc_plan_mapping_editable(OLD.program_course_id,NULL); CALL sc_plan_mapping_editable(NEW.program_course_id,NEW.requirement_group_id);
END//
CREATE OR REPLACE TRIGGER sc_cat_program_course_requirement_groups_d BEFORE DELETE ON program_course_requirement_groups FOR EACH ROW
BEGIN
  CALL sc_plan_touch(); CALL sc_plan_mapping_editable(OLD.program_course_id,NULL);
END//
CREATE OR REPLACE TRIGGER sc_plan_events_i BEFORE INSERT ON academic_plan_events FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
END//
CREATE OR REPLACE TRIGGER sc_plan_events_u BEFORE UPDATE ON academic_plan_events FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_event_immutable';
END//
CREATE OR REPLACE TRIGGER sc_plan_events_d BEFORE DELETE ON academic_plan_events FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_event_immutable';
END//
CREATE OR REPLACE TRIGGER sc_plan_assignment_i BEFORE INSERT ON student_academic_plan_assignments FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF NOT EXISTS(SELECT 1 FROM students s JOIN academic_plan_versions v ON v.academic_program_id=s.academic_program_id
    WHERE s.student_id=NEW.student_id AND s.academic_program_id=NEW.academic_program_id
    AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status IN('approved','transitional') AND v.fixed_at IS NOT NULL)
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid'; END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_assignment_u BEFORE UPDATE ON student_academic_plan_assignments FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF NOT(NEW.student_id<=>OLD.student_id) OR NOT(NEW.academic_program_id<=>OLD.academic_program_id)
    OR NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) OR NOT(NEW.assigned_by_user_id<=>OLD.assigned_by_user_id)
    OR NOT(NEW.assigned_at<=>OLD.assigned_at) OR NOT(NEW.reason<=>OLD.reason)
    OR OLD.current_slot IS NULL OR NEW.current_slot IS NOT NULL OR NEW.ended_at IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_assignment_immutable';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_assignment_d BEFORE DELETE ON student_academic_plan_assignments FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_assignment_immutable';
END//
CREATE OR REPLACE PROCEDURE sc_plan_accepting(program_key INT)
BEGIN
  DECLARE state_value VARCHAR(20) DEFAULT NULL;
  DECLARE archived_value DATETIME DEFAULT NULL;
  DECLARE default_key INT DEFAULT NULL;
  SELECT plan_state,archived_at,default_academic_plan_version_id INTO state_value,archived_value,default_key
    FROM academic_programs WHERE academic_program_id=program_key FOR UPDATE;
  IF archived_value IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_program_archived'; END IF;
  IF state_value IS NULL OR state_value NOT IN('legacy','ready') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_initialization_incomplete'; END IF;
  IF state_value='ready' AND NOT EXISTS(SELECT 1 FROM academic_plan_versions WHERE academic_plan_version_id=default_key
    AND academic_program_id=program_key AND status='approved' AND fixed_at IS NOT NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_initialization_incomplete';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_use_students_i BEFORE INSERT ON students FOR EACH ROW
BEGIN
  CALL sc_plan_touch(); CALL sc_plan_accepting(NEW.academic_program_id);
END//
CREATE OR REPLACE TRIGGER sc_plan_student_assign AFTER INSERT ON students FOR EACH ROW
BEGIN
  INSERT INTO student_academic_plan_assignments(student_id,academic_program_id,academic_plan_version_id,current_slot,assigned_by_user_id,reason,assigned_at)
  SELECT NEW.student_id,p.academic_program_id,p.default_academic_plan_version_id,1,NULL,'default_on_student_creation',CURRENT_TIMESTAMP
    FROM academic_programs p WHERE p.academic_program_id=NEW.academic_program_id AND p.plan_state='ready';
END//
CREATE OR REPLACE TRIGGER sc_use_students_u BEFORE UPDATE ON students FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF NOT(NEW.academic_program_id<=>OLD.academic_program_id) THEN
    IF EXISTS(SELECT 1 FROM student_academic_plan_assignments WHERE student_id=OLD.student_id)
      OR EXISTS(SELECT 1 FROM academic_programs WHERE academic_program_id=NEW.academic_program_id AND plan_state<>'legacy') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_program_transfer_required';
    END IF;
    CALL sc_plan_accepting(NEW.academic_program_id);
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_use_admission_applications_i BEFORE INSERT ON admission_applications FOR EACH ROW
BEGIN
  CALL sc_plan_touch(); CALL sc_plan_accepting(NEW.academic_program_id);
END//
CREATE OR REPLACE TRIGGER sc_use_admission_applications_u BEFORE UPDATE ON admission_applications FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF NOT(NEW.academic_program_id<=>OLD.academic_program_id) THEN CALL sc_plan_accepting(NEW.academic_program_id); END IF;
END//
DELIMITER ;

ALTER TABLE student_course_registrations ADD COLUMN IF NOT EXISTS academic_plan_version_id INT NULL;
ALTER TABLE student_course_registrations ADD COLUMN IF NOT EXISTS plan_program_course_id INT NULL;
ALTER TABLE student_registration_requests ADD COLUMN IF NOT EXISTS academic_plan_version_id INT NULL;
ALTER TABLE student_registration_modification_requests ADD COLUMN IF NOT EXISTS academic_plan_version_id INT NULL;
ALTER TABLE student_registration_replacement_requests ADD COLUMN IF NOT EXISTS academic_plan_version_id INT NULL;
ALTER TABLE student_progression_decisions ADD COLUMN IF NOT EXISTS academic_plan_version_id INT NULL;
ALTER TABLE student_graduation_decisions ADD COLUMN IF NOT EXISTS academic_plan_version_id INT NULL;
DELIMITER //
CREATE OR REPLACE PROCEDURE sc_plan_record_constraints()
BEGIN
  IF NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='student_course_registrations' AND constraint_name='fk_plan_course_registrations') THEN
    ALTER TABLE student_course_registrations ADD CONSTRAINT fk_plan_course_registrations FOREIGN KEY(academic_plan_version_id) REFERENCES academic_plan_versions(academic_plan_version_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='student_registration_requests' AND constraint_name='fk_plan_requests') THEN
    ALTER TABLE student_registration_requests ADD CONSTRAINT fk_plan_requests FOREIGN KEY(academic_plan_version_id) REFERENCES academic_plan_versions(academic_plan_version_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='student_registration_modification_requests' AND constraint_name='fk_plan_modification_requests') THEN
    ALTER TABLE student_registration_modification_requests ADD CONSTRAINT fk_plan_modification_requests FOREIGN KEY(academic_plan_version_id) REFERENCES academic_plan_versions(academic_plan_version_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='student_registration_replacement_requests' AND constraint_name='fk_plan_replacement_requests') THEN
    ALTER TABLE student_registration_replacement_requests ADD CONSTRAINT fk_plan_replacement_requests FOREIGN KEY(academic_plan_version_id) REFERENCES academic_plan_versions(academic_plan_version_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='student_progression_decisions' AND constraint_name='fk_plan_progression_decisions') THEN
    ALTER TABLE student_progression_decisions ADD CONSTRAINT fk_plan_progression_decisions FOREIGN KEY(academic_plan_version_id) REFERENCES academic_plan_versions(academic_plan_version_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='student_graduation_decisions' AND constraint_name='fk_plan_graduation_decisions') THEN
    ALTER TABLE student_graduation_decisions ADD CONSTRAINT fk_plan_graduation_decisions FOREIGN KEY(academic_plan_version_id) REFERENCES academic_plan_versions(academic_plan_version_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='student_course_registrations' AND constraint_name='fk_registration_plan_membership') THEN
    ALTER TABLE student_course_registrations ADD CONSTRAINT fk_registration_plan_membership FOREIGN KEY(plan_program_course_id) REFERENCES program_courses(program_course_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  END IF;
END//
DELIMITER ;
CALL sc_plan_record_constraints();
DELIMITER //
CREATE OR REPLACE FUNCTION sc_plan_student_version(student_key INT) RETURNS INT READS SQL DATA
BEGIN
  DECLARE version_key INT DEFAULT NULL;
  DECLARE owner_key INT DEFAULT NULL;
  DECLARE state_value VARCHAR(20) DEFAULT NULL;
  SELECT academic_program_id INTO owner_key FROM students WHERE student_id=student_key;
  SELECT plan_state INTO state_value FROM academic_programs WHERE academic_program_id=owner_key;
  SELECT academic_plan_version_id INTO version_key FROM student_academic_plan_assignments
    WHERE student_id=student_key AND academic_program_id=owner_key AND current_slot=1;
  IF version_key IS NULL AND (state_value='ready' OR EXISTS(SELECT 1 FROM academic_plan_versions WHERE academic_program_id=owner_key AND status='transitional')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
  END IF;
  RETURN version_key;
END//
CREATE OR REPLACE TRIGGER sc_plan_course_registrations_i BEFORE INSERT ON student_course_registrations FOR EACH ROW
BEGIN
  DECLARE version_key INT DEFAULT NULL;
  CALL sc_plan_touch();
  SET version_key=sc_plan_student_version(NEW.student_id);
  IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
  END IF;
  SET NEW.academic_plan_version_id=version_key;
  IF version_key IS NOT NULL THEN
    SET NEW.plan_program_course_id=(SELECT pc.program_course_id FROM program_courses pc JOIN course_offerings o ON o.course_id=pc.course_id
      WHERE o.course_offering_id=NEW.course_offering_id AND pc.academic_plan_version_id=version_key AND pc.is_active=1);
  ELSE SET NEW.plan_program_course_id=NULL;
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_course_registrations_u BEFORE UPDATE ON student_course_registrations FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
  IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL
    AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id
      WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status='transitional') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
  IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.plan_program_course_id<=>OLD.plan_program_course_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
  IF NEW.plan_program_course_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM program_courses pc JOIN course_offerings o ON o.course_id=pc.course_id
    WHERE pc.program_course_id=NEW.plan_program_course_id AND pc.academic_plan_version_id=NEW.academic_plan_version_id AND o.course_offering_id=NEW.course_offering_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_requests_i BEFORE INSERT ON student_registration_requests FOR EACH ROW
BEGIN
  DECLARE version_key INT DEFAULT NULL;
  CALL sc_plan_touch();
  SET version_key=sc_plan_student_version(NEW.student_id);
  IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
  END IF;
  SET NEW.academic_plan_version_id=version_key;
END//
CREATE OR REPLACE TRIGGER sc_plan_requests_u BEFORE UPDATE ON student_registration_requests FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
  IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL
    AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id
      WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status='transitional') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_modification_requests_i BEFORE INSERT ON student_registration_modification_requests FOR EACH ROW
BEGIN
  DECLARE version_key INT DEFAULT NULL;
  CALL sc_plan_touch();
  SET version_key=sc_plan_student_version(NEW.student_id);
  IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
  END IF;
  SET NEW.academic_plan_version_id=version_key;
END//
CREATE OR REPLACE TRIGGER sc_plan_modification_requests_u BEFORE UPDATE ON student_registration_modification_requests FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
  IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL
    AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id
      WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status='transitional') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_replacement_requests_i BEFORE INSERT ON student_registration_replacement_requests FOR EACH ROW
BEGIN
  DECLARE version_key INT DEFAULT NULL;
  CALL sc_plan_touch();
  SET version_key=sc_plan_student_version(NEW.student_id);
  IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
  END IF;
  SET NEW.academic_plan_version_id=version_key;
END//
CREATE OR REPLACE TRIGGER sc_plan_replacement_requests_u BEFORE UPDATE ON student_registration_replacement_requests FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
  IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL
    AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id
      WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status='transitional') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_progression_decisions_i BEFORE INSERT ON student_progression_decisions FOR EACH ROW
BEGIN
  DECLARE version_key INT DEFAULT NULL;
  CALL sc_plan_touch();
  SET version_key=sc_plan_student_version(NEW.student_id);
  IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
  END IF;
  SET NEW.academic_plan_version_id=version_key;
END//
CREATE OR REPLACE TRIGGER sc_plan_progression_decisions_u BEFORE UPDATE ON student_progression_decisions FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
  IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL
    AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id
      WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status='transitional') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_graduation_decisions_i BEFORE INSERT ON student_graduation_decisions FOR EACH ROW
BEGIN
  DECLARE version_key INT DEFAULT NULL;
  CALL sc_plan_touch();
  SET version_key=sc_plan_student_version(NEW.student_id);
  IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_context_invalid';
  END IF;
  SET NEW.academic_plan_version_id=version_key;
END//
CREATE OR REPLACE TRIGGER sc_plan_graduation_decisions_u BEFORE UPDATE ON student_graduation_decisions FOR EACH ROW
BEGIN
  CALL sc_plan_touch();
  IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
  IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL
    AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id
      WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status='transitional') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_record_immutable';
  END IF;
END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_course_results_i BEFORE INSERT ON student_course_results FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_course_results_u BEFORE UPDATE ON student_course_results FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_course_results_d BEFORE DELETE ON student_course_results FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_grade_approvals_i BEFORE INSERT ON grade_approvals FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_grade_approvals_u BEFORE UPDATE ON grade_approvals FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_grade_approvals_d BEFORE DELETE ON grade_approvals FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_grade_audit_logs_i BEFORE INSERT ON grade_audit_logs FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_grade_audit_logs_u BEFORE UPDATE ON grade_audit_logs FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_grade_audit_logs_d BEFORE DELETE ON grade_audit_logs FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_request_items_i BEFORE INSERT ON student_registration_request_items FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_request_items_u BEFORE UPDATE ON student_registration_request_items FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_request_items_d BEFORE DELETE ON student_registration_request_items FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_modification_items_i BEFORE INSERT ON student_registration_modification_items FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_modification_items_u BEFORE UPDATE ON student_registration_modification_items FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_modification_items_d BEFORE DELETE ON student_registration_modification_items FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_replacement_items_i BEFORE INSERT ON student_registration_replacement_items FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_replacement_items_u BEFORE UPDATE ON student_registration_replacement_items FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_replacement_items_d BEFORE DELETE ON student_registration_replacement_items FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_withdrawal_requests_i BEFORE INSERT ON student_registration_withdrawal_requests FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_withdrawal_requests_u BEFORE UPDATE ON student_registration_withdrawal_requests FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_withdrawal_requests_d BEFORE DELETE ON student_registration_withdrawal_requests FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_grade_appeals_i BEFORE INSERT ON grade_appeals FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_grade_appeals_u BEFORE UPDATE ON grade_appeals FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_grade_appeals_d BEFORE DELETE ON grade_appeals FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_appeal_statuses_i BEFORE INSERT ON appeal_statuses FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_appeal_statuses_u BEFORE UPDATE ON appeal_statuses FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_appeal_statuses_d BEFORE DELETE ON appeal_statuses FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_supplementary_exam_registrations_i BEFORE INSERT ON supplementary_exam_registrations FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_supplementary_exam_registrations_u BEFORE UPDATE ON supplementary_exam_registrations FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_supplementary_exam_registrations_d BEFORE DELETE ON supplementary_exam_registrations FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_supplementary_exam_materializations_i BEFORE INSERT ON supplementary_exam_materializations FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_supplementary_exam_materializations_u BEFORE UPDATE ON supplementary_exam_materializations FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
CREATE OR REPLACE TRIGGER sc_plan_epoch_supplementary_exam_materializations_d BEFORE DELETE ON supplementary_exam_materializations FOR EACH ROW BEGIN CALL sc_plan_touch(); END//
DELIMITER ;

DELIMITER //
CREATE OR REPLACE PROCEDURE sc_plan_permission_guard()
BEGIN
  IF (SELECT COUNT(*) FROM permissions p JOIN system_modules m ON m.module_id=p.module_id WHERE p.permission_code='academic_structure.manage' AND p.is_active=1 AND m.is_active=1)<>1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_permission_source_invalid';
  END IF;
  IF EXISTS(SELECT 1 FROM permissions p WHERE p.permission_code IN('vice_presidency.scientific.programs.plans.manage','vice_presidency.scientific.programs.plans.approve','vice_presidency.scientific.programs.plans.assign','vice_presidency.scientific.programs.archive','vice_presidency.scientific.programs.delete') AND (p.is_active<>1 OR p.module_id<>(SELECT module_id FROM permissions WHERE permission_code='academic_structure.manage'))) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_permission_conflict';
  END IF;
END//
DELIMITER ;
CALL sc_plan_permission_guard();
-- Define only. No role_permissions or user assignments are created.
INSERT INTO permissions(module_id,permission_code,permission_name,description,is_active)
SELECT p.module_id,'vice_presidency.scientific.programs.plans.manage','إدارة مسودات الخطط وتهيئة البرامج','إدارة مسودات الخطط وتهيئة البرامج',1 FROM permissions p WHERE p.permission_code='academic_structure.manage'
AND NOT EXISTS(SELECT 1 FROM permissions existing WHERE existing.permission_code='vice_presidency.scientific.programs.plans.manage');
INSERT INTO permissions(module_id,permission_code,permission_name,description,is_active)
SELECT p.module_id,'vice_presidency.scientific.programs.plans.approve','اعتماد الخطط الأكاديمية','اعتماد الخطط الأكاديمية',1 FROM permissions p WHERE p.permission_code='academic_structure.manage'
AND NOT EXISTS(SELECT 1 FROM permissions existing WHERE existing.permission_code='vice_presidency.scientific.programs.plans.approve');
INSERT INTO permissions(module_id,permission_code,permission_name,description,is_active)
SELECT p.module_id,'vice_presidency.scientific.programs.plans.assign','تعيين الخطط ونقل الطلاب','تعيين الخطط ونقل الطلاب',1 FROM permissions p WHERE p.permission_code='academic_structure.manage'
AND NOT EXISTS(SELECT 1 FROM permissions existing WHERE existing.permission_code='vice_presidency.scientific.programs.plans.assign');
INSERT INTO permissions(module_id,permission_code,permission_name,description,is_active)
SELECT p.module_id,'vice_presidency.scientific.programs.archive','أرشفة البرامج واستعادتها','أرشفة البرامج واستعادتها',1 FROM permissions p WHERE p.permission_code='academic_structure.manage'
AND NOT EXISTS(SELECT 1 FROM permissions existing WHERE existing.permission_code='vice_presidency.scientific.programs.archive');
INSERT INTO permissions(module_id,permission_code,permission_name,description,is_active)
SELECT p.module_id,'vice_presidency.scientific.programs.delete','حذف البرامج غير المستخدمة','حذف البرامج غير المستخدمة',1 FROM permissions p WHERE p.permission_code='academic_structure.manage'
AND NOT EXISTS(SELECT 1 FROM permissions existing WHERE existing.permission_code='vice_presidency.scientific.programs.delete');

DELIMITER //
CREATE OR REPLACE PROCEDURE sc_plan_verify_before_ready()
BEGIN
  DECLARE error_count INT DEFAULT 0;
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
UNION ALL SELECT CONCAT(e.table_name,'.',e.column_name),'MISSING_COLUMN' FROM expected e LEFT JOIN information_schema.columns c ON c.table_schema='alrowad_uni_rust' AND c.table_name=e.table_name AND c.column_name=e.column_name WHERE c.column_name IS NULL
UNION ALL SELECT 'academic_plan_control.PRIMARY' object_name,'INDEX_MISMATCH' issue_code WHERE NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_plan_control' AND index_name='PRIMARY' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='control_id')
UNION ALL SELECT 'academic_plan_versions.PRIMARY' object_name,'INDEX_MISMATCH' issue_code WHERE NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_plan_versions' AND index_name='PRIMARY' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='academic_plan_version_id')
UNION ALL SELECT 'academic_plan_versions.uq_plan_version' object_name,'INDEX_MISMATCH' issue_code WHERE NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_plan_versions' AND index_name='uq_plan_version' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='academic_program_id,version_number')
UNION ALL SELECT 'academic_plan_versions.uq_plan_owner' object_name,'INDEX_MISMATCH' issue_code WHERE NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_plan_versions' AND index_name='uq_plan_owner' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='academic_plan_version_id,academic_program_id')
UNION ALL SELECT 'student_academic_plan_assignments.PRIMARY' object_name,'INDEX_MISMATCH' issue_code WHERE NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='student_academic_plan_assignments' AND index_name='PRIMARY' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='student_academic_plan_assignment_id')
UNION ALL SELECT 'student_academic_plan_assignments.uq_student_plan_current' object_name,'INDEX_MISMATCH' issue_code WHERE NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='student_academic_plan_assignments' AND index_name='uq_student_plan_current' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='student_id,current_slot')
UNION ALL SELECT 'academic_plan_events.PRIMARY' object_name,'INDEX_MISMATCH' issue_code WHERE NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_plan_events' AND index_name='PRIMARY' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='academic_plan_event_id')
UNION ALL SELECT 'program_courses.uq_program_plan_course' object_name,'INDEX_MISMATCH' issue_code WHERE NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='program_courses' AND index_name='uq_program_plan_course' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='academic_program_id,plan_scope_key,course_id')
UNION ALL SELECT 'academic_requirement_groups.uq_program_plan_scope' object_name,'INDEX_MISMATCH' issue_code WHERE NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_requirement_groups' AND index_name='uq_program_plan_scope' AND non_unique=0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')='academic_program_id,plan_scope_key,requirement_scope,requirement_type')
UNION ALL SELECT 'academic_plan_versions.fk_plan_program','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='academic_plan_versions' AND k.constraint_name='fk_plan_program' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_programs' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_program_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_program_id')
UNION ALL SELECT 'academic_plan_versions.fk_plan_source','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='academic_plan_versions' AND k.constraint_name='fk_plan_source' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='source_version_id,academic_program_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id,academic_program_id')
UNION ALL SELECT 'academic_plan_versions.fk_plan_creator','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='academic_plan_versions' AND k.constraint_name='fk_plan_creator' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='users' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='created_by_user_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='user_id')
UNION ALL SELECT 'academic_plan_versions.fk_plan_approver','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='academic_plan_versions' AND k.constraint_name='fk_plan_approver' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='users' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='approved_by_user_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='user_id')
UNION ALL SELECT 'student_academic_plan_assignments.fk_student_plan_student','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='student_academic_plan_assignments' AND k.constraint_name='fk_student_plan_student' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='students' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='student_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='student_id')
UNION ALL SELECT 'student_academic_plan_assignments.fk_student_plan_version','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='student_academic_plan_assignments' AND k.constraint_name='fk_student_plan_version' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id,academic_program_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id,academic_program_id')
UNION ALL SELECT 'student_academic_plan_assignments.fk_student_plan_actor','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='student_academic_plan_assignments' AND k.constraint_name='fk_student_plan_actor' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='users' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='assigned_by_user_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='user_id')
UNION ALL SELECT 'academic_plan_events.fk_plan_event_program','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='academic_plan_events' AND k.constraint_name='fk_plan_event_program' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_programs' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_program_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_program_id')
UNION ALL SELECT 'academic_plan_events.fk_plan_event_version','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='academic_plan_events' AND k.constraint_name='fk_plan_event_version' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id,academic_program_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id,academic_program_id')
UNION ALL SELECT 'academic_plan_events.fk_plan_event_actor','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='academic_plan_events' AND k.constraint_name='fk_plan_event_actor' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='users' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='actor_user_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='user_id')
UNION ALL SELECT 'academic_programs.fk_program_default_plan','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='academic_programs' AND k.constraint_name='fk_program_default_plan' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='default_academic_plan_version_id,academic_program_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id,academic_program_id')
UNION ALL SELECT 'program_courses.fk_membership_plan','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='program_courses' AND k.constraint_name='fk_membership_plan' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id,academic_program_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id,academic_program_id')
UNION ALL SELECT 'academic_requirement_groups.fk_requirement_plan','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='academic_requirement_groups' AND k.constraint_name='fk_requirement_plan' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id,academic_program_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id,academic_program_id')
UNION ALL SELECT 'student_course_registrations.fk_plan_course_registrations','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='student_course_registrations' AND k.constraint_name='fk_plan_course_registrations' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id')
UNION ALL SELECT 'student_registration_requests.fk_plan_requests','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='student_registration_requests' AND k.constraint_name='fk_plan_requests' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id')
UNION ALL SELECT 'student_registration_modification_requests.fk_plan_modification_requests','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='student_registration_modification_requests' AND k.constraint_name='fk_plan_modification_requests' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id')
UNION ALL SELECT 'student_registration_replacement_requests.fk_plan_replacement_requests','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='student_registration_replacement_requests' AND k.constraint_name='fk_plan_replacement_requests' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id')
UNION ALL SELECT 'student_progression_decisions.fk_plan_progression_decisions','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='student_progression_decisions' AND k.constraint_name='fk_plan_progression_decisions' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id')
UNION ALL SELECT 'student_graduation_decisions.fk_plan_graduation_decisions','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='student_graduation_decisions' AND k.constraint_name='fk_plan_graduation_decisions' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='academic_plan_versions' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='academic_plan_version_id')
UNION ALL SELECT 'student_course_registrations.fk_registration_plan_membership','FK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.constraint_schema='alrowad_uni_rust' AND k.table_name='student_course_registrations' AND k.constraint_name='fk_registration_plan_membership' AND k.referenced_table_schema='alrowad_uni_rust' AND k.referenced_table_name='program_courses' AND r.delete_rule='RESTRICT' AND r.update_rule='RESTRICT' GROUP BY k.constraint_name HAVING GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',')='plan_program_course_id' AND GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',')='program_course_id')
UNION ALL SELECT 'sc_plan_touch','ROUTINE_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.routines WHERE routine_schema='alrowad_uni_rust' AND routine_name='sc_plan_touch' AND routine_type='PROCEDURE' AND REGEXP_REPLACE(TRIM(routine_definition),'[[:space:]]+',' ')='BEGIN DECLARE ready_value INT DEFAULT 0; CALL sc_catalog_touch(); SELECT is_ready INTO ready_value FROM academic_plan_control WHERE control_id=1 AND schema_version=1 FOR UPDATE; IF ready_value<>1 THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_schema_not_ready''; END IF; END')
UNION ALL SELECT 'sc_plan_editable','ROUTINE_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.routines WHERE routine_schema='alrowad_uni_rust' AND routine_name='sc_plan_editable' AND routine_type='PROCEDURE' AND REGEXP_REPLACE(TRIM(routine_definition),'[[:space:]]+',' ')='BEGIN DECLARE plan_status VARCHAR(20) DEFAULT NULL; DECLARE program_state VARCHAR(20) DEFAULT NULL; SELECT plan_state INTO program_state FROM academic_programs WHERE academic_program_id=program_key FOR UPDATE; IF version_key IS NULL THEN IF program_state<>''legacy'' OR sc_catalog_program_used(program_key) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_catalog_history_locked''; END IF; ELSE SELECT status INTO plan_status FROM academic_plan_versions WHERE academic_plan_version_id=version_key AND academic_program_id=program_key FOR UPDATE; IF plan_status IS NULL OR plan_status<>''draft'' THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_locked''; END IF; END IF; END')
UNION ALL SELECT 'sc_plan_versions_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_versions_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='academic_plan_versions' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF NEW.status=''approved'' THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_approval_required''; END IF; IF NEW.status=''transitional'' AND (NEW.version_number<>1 OR EXISTS(SELECT 1 FROM academic_plan_versions WHERE academic_program_id=NEW.academic_program_id) OR NOT EXISTS(SELECT 1 FROM academic_programs WHERE academic_program_id=NEW.academic_program_id AND plan_state=''preparing'')) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_transition_invalid''; END IF; END')
UNION ALL SELECT 'sc_plan_versions_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_versions_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='academic_plan_versions' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF OLD.status<>''draft'' OR NOT(OLD.academic_program_id<=>NEW.academic_program_id) OR NOT(OLD.version_number<=>NEW.version_number) OR NOT(OLD.source_version_id<=>NEW.source_version_id) OR NEW.status NOT IN(''draft'',''approved'') THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_locked''; END IF; END')
UNION ALL SELECT 'sc_plan_program_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_program_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='academic_programs' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF NEW.plan_state<>''preparing'' OR NEW.default_academic_plan_version_id IS NOT NULL THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_initialization_required''; END IF; END')
UNION ALL SELECT 'sc_plan_program_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_program_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='academic_programs' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF (OLD.plan_state=''preparing'' AND NEW.plan_state NOT IN(''preparing'',''ready'')) OR (OLD.plan_state=''ready'' AND NEW.plan_state<>''ready'') OR (OLD.plan_state=''legacy'' AND NEW.plan_state NOT IN(''legacy'',''preparing'')) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_transition_invalid''; END IF; IF NEW.plan_state=''ready'' AND ( NOT EXISTS(SELECT 1 FROM academic_plan_versions WHERE academic_plan_version_id=NEW.default_academic_plan_version_id AND academic_program_id=NEW.academic_program_id AND status=''approved'' AND fixed_at IS NOT NULL) OR EXISTS(SELECT 1 FROM students s LEFT JOIN student_academic_plan_assignments a ON a.student_id=s.student_id AND a.current_slot=1 WHERE s.academic_program_id=NEW.academic_program_id AND a.student_academic_plan_assignment_id IS NULL)) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_initialization_incomplete''; END IF; IF EXISTS(SELECT 1 FROM academic_plan_versions WHERE academic_program_id=OLD.academic_program_id AND status IN(''approved'',''transitional'')) AND (NOT(NEW.program_code<=>OLD.program_code) OR NOT(NEW.department_id<=>OLD.department_id) OR NOT(NEW.degree_level<=>OLD.degree_level) OR NOT(NEW.duration_years<=>OLD.duration_years) OR NOT(NEW.total_credit_hours<=>OLD.total_credit_hours) OR NOT(NEW.is_active<=>OLD.is_active)) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_program_identity_locked''; END IF; END')
UNION ALL SELECT 'sc_plan_fixed_course','ROUTINE_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.routines WHERE routine_schema='alrowad_uni_rust' AND routine_name='sc_plan_fixed_course' AND routine_type='FUNCTION' AND REGEXP_REPLACE(TRIM(routine_definition),'[[:space:]]+',' ')='BEGIN RETURN EXISTS(SELECT 1 FROM program_courses pc JOIN academic_plan_versions v ON v.academic_plan_version_id=pc.academic_plan_version_id WHERE pc.course_id=course_key AND v.status IN(''approved'',''transitional'')); END')
UNION ALL SELECT 'sc_plan_course_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_course_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='courses' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF sc_plan_fixed_course(OLD.course_id) AND (NOT(NEW.course_code<=>OLD.course_code) OR NOT(NEW.credit_hours<=>OLD.credit_hours) OR NOT(NEW.theoretical_hours<=>OLD.theoretical_hours) OR NOT(NEW.practical_hours<=>OLD.practical_hours) OR NOT(NEW.is_active<=>OLD.is_active)) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_catalog_history_locked''; END IF; END')
UNION ALL SELECT 'sc_plan_prerequisite_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_prerequisite_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='course_prerequisites' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF sc_plan_fixed_course(NEW.course_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_catalog_history_locked''; END IF; END')
UNION ALL SELECT 'sc_plan_prerequisite_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_prerequisite_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='course_prerequisites' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF sc_plan_fixed_course(OLD.course_id) OR sc_plan_fixed_course(NEW.course_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_catalog_history_locked''; END IF; END')
UNION ALL SELECT 'sc_plan_prerequisite_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_prerequisite_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='course_prerequisites' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF sc_plan_fixed_course(OLD.course_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_catalog_history_locked''; END IF; END')
UNION ALL SELECT 'sc_plan_versions_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_versions_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='academic_plan_versions' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF OLD.status<>''draft'' THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_locked''; END IF; END')
UNION ALL SELECT 'sc_cat_program_courses_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_cat_program_courses_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='program_courses' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); CALL sc_plan_editable(NEW.academic_program_id,NEW.academic_plan_version_id); END')
UNION ALL SELECT 'sc_cat_program_courses_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_cat_program_courses_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='program_courses' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL AND (NEW.academic_program_id<=>OLD.academic_program_id) AND (NEW.course_id<=>OLD.course_id) AND (NEW.academic_level_id<=>OLD.academic_level_id) AND (NEW.recommended_semester_id<=>OLD.recommended_semester_id) AND (NEW.course_type<=>OLD.course_type) AND (NEW.is_active<=>OLD.is_active) AND EXISTS(SELECT 1 FROM academic_plan_versions v JOIN academic_programs p ON p.academic_program_id=v.academic_program_id WHERE v.academic_plan_version_id=NEW.academic_plan_version_id AND v.academic_program_id=OLD.academic_program_id AND v.status=''transitional'' AND p.plan_state=''preparing'') THEN BEGIN END; ELSE IF NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) OR NOT(NEW.academic_program_id<=>OLD.academic_program_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_context_invalid''; END IF; CALL sc_plan_editable(OLD.academic_program_id,OLD.academic_plan_version_id); END IF; END')
UNION ALL SELECT 'sc_cat_program_courses_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_cat_program_courses_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='program_courses' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); CALL sc_plan_editable(OLD.academic_program_id,OLD.academic_plan_version_id); END')
UNION ALL SELECT 'sc_cat_academic_requirement_groups_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_cat_academic_requirement_groups_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='academic_requirement_groups' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); CALL sc_plan_editable(NEW.academic_program_id,NEW.academic_plan_version_id); END')
UNION ALL SELECT 'sc_cat_academic_requirement_groups_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_cat_academic_requirement_groups_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='academic_requirement_groups' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL AND (NEW.academic_program_id<=>OLD.academic_program_id) AND (NEW.group_code<=>OLD.group_code) AND (NEW.group_name<=>OLD.group_name) AND (NEW.requirement_scope<=>OLD.requirement_scope) AND (NEW.requirement_type<=>OLD.requirement_type) AND (NEW.required_credit_hours<=>OLD.required_credit_hours) AND (NEW.is_active<=>OLD.is_active) AND EXISTS(SELECT 1 FROM academic_plan_versions v JOIN academic_programs p ON p.academic_program_id=v.academic_program_id WHERE v.academic_plan_version_id=NEW.academic_plan_version_id AND v.academic_program_id=OLD.academic_program_id AND v.status=''transitional'' AND p.plan_state=''preparing'') THEN BEGIN END; ELSE IF NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) OR NOT(NEW.academic_program_id<=>OLD.academic_program_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_context_invalid''; END IF; CALL sc_plan_editable(OLD.academic_program_id,OLD.academic_plan_version_id); END IF; END')
UNION ALL SELECT 'sc_cat_academic_requirement_groups_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_cat_academic_requirement_groups_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='academic_requirement_groups' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); CALL sc_plan_editable(OLD.academic_program_id,OLD.academic_plan_version_id); END')
UNION ALL SELECT 'sc_plan_mapping_editable','ROUTINE_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.routines WHERE routine_schema='alrowad_uni_rust' AND routine_name='sc_plan_mapping_editable' AND routine_type='PROCEDURE' AND REGEXP_REPLACE(TRIM(routine_definition),'[[:space:]]+',' ')='BEGIN DECLARE program_key INT DEFAULT NULL; DECLARE version_key INT DEFAULT NULL; SELECT academic_program_id,academic_plan_version_id INTO program_key,version_key FROM program_courses WHERE program_course_id=membership_key FOR UPDATE; CALL sc_plan_editable(program_key,version_key); IF group_key IS NOT NULL AND NOT EXISTS(SELECT 1 FROM academic_requirement_groups g JOIN program_courses pc ON pc.program_course_id=membership_key WHERE g.requirement_group_id=group_key AND g.academic_program_id=pc.academic_program_id AND (g.academic_plan_version_id<=>pc.academic_plan_version_id) AND g.requirement_type=pc.course_type) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_group_mismatch''; END IF; END')
UNION ALL SELECT 'sc_cat_program_course_requirement_groups_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_cat_program_course_requirement_groups_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='program_course_requirement_groups' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); CALL sc_plan_mapping_editable(NEW.program_course_id,NEW.requirement_group_id); END')
UNION ALL SELECT 'sc_cat_program_course_requirement_groups_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_cat_program_course_requirement_groups_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='program_course_requirement_groups' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); CALL sc_plan_mapping_editable(OLD.program_course_id,NULL); CALL sc_plan_mapping_editable(NEW.program_course_id,NEW.requirement_group_id); END')
UNION ALL SELECT 'sc_cat_program_course_requirement_groups_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_cat_program_course_requirement_groups_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='program_course_requirement_groups' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); CALL sc_plan_mapping_editable(OLD.program_course_id,NULL); END')
UNION ALL SELECT 'sc_plan_events_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_events_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='academic_plan_events' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_events_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_events_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='academic_plan_events' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_event_immutable''; END')
UNION ALL SELECT 'sc_plan_events_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_events_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='academic_plan_events' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_event_immutable''; END')
UNION ALL SELECT 'sc_plan_assignment_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_assignment_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_academic_plan_assignments' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF NOT EXISTS(SELECT 1 FROM students s JOIN academic_plan_versions v ON v.academic_program_id=s.academic_program_id WHERE s.student_id=NEW.student_id AND s.academic_program_id=NEW.academic_program_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status IN(''approved'',''transitional'') AND v.fixed_at IS NOT NULL) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_context_invalid''; END IF; END')
UNION ALL SELECT 'sc_plan_assignment_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_assignment_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_academic_plan_assignments' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF NOT(NEW.student_id<=>OLD.student_id) OR NOT(NEW.academic_program_id<=>OLD.academic_program_id) OR NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) OR NOT(NEW.assigned_by_user_id<=>OLD.assigned_by_user_id) OR NOT(NEW.assigned_at<=>OLD.assigned_at) OR NOT(NEW.reason<=>OLD.reason) OR OLD.current_slot IS NULL OR NEW.current_slot IS NOT NULL OR NEW.ended_at IS NULL THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_assignment_immutable''; END IF; END')
UNION ALL SELECT 'sc_plan_assignment_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_assignment_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='student_academic_plan_assignments' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_assignment_immutable''; END')
UNION ALL SELECT 'sc_plan_accepting','ROUTINE_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.routines WHERE routine_schema='alrowad_uni_rust' AND routine_name='sc_plan_accepting' AND routine_type='PROCEDURE' AND REGEXP_REPLACE(TRIM(routine_definition),'[[:space:]]+',' ')='BEGIN DECLARE state_value VARCHAR(20) DEFAULT NULL; DECLARE archived_value DATETIME DEFAULT NULL; DECLARE default_key INT DEFAULT NULL; SELECT plan_state,archived_at,default_academic_plan_version_id INTO state_value,archived_value,default_key FROM academic_programs WHERE academic_program_id=program_key FOR UPDATE; IF archived_value IS NOT NULL THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_program_archived''; END IF; IF state_value IS NULL OR state_value NOT IN(''legacy'',''ready'') THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_initialization_incomplete''; END IF; IF state_value=''ready'' AND NOT EXISTS(SELECT 1 FROM academic_plan_versions WHERE academic_plan_version_id=default_key AND academic_program_id=program_key AND status=''approved'' AND fixed_at IS NOT NULL) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_initialization_incomplete''; END IF; END')
UNION ALL SELECT 'sc_use_students_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_use_students_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='students' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); CALL sc_plan_accepting(NEW.academic_program_id); END')
UNION ALL SELECT 'sc_plan_student_assign','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_student_assign' AND action_timing='AFTER' AND event_manipulation='INSERT' AND event_object_table='students' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN INSERT INTO student_academic_plan_assignments(student_id,academic_program_id,academic_plan_version_id,current_slot,assigned_by_user_id,reason,assigned_at) SELECT NEW.student_id,p.academic_program_id,p.default_academic_plan_version_id,1,NULL,''default_on_student_creation'',CURRENT_TIMESTAMP FROM academic_programs p WHERE p.academic_program_id=NEW.academic_program_id AND p.plan_state=''ready''; END')
UNION ALL SELECT 'sc_use_students_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_use_students_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='students' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF NOT(NEW.academic_program_id<=>OLD.academic_program_id) THEN IF EXISTS(SELECT 1 FROM student_academic_plan_assignments WHERE student_id=OLD.student_id) OR EXISTS(SELECT 1 FROM academic_programs WHERE academic_program_id=NEW.academic_program_id AND plan_state<>''legacy'') THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_program_transfer_required''; END IF; CALL sc_plan_accepting(NEW.academic_program_id); END IF; END')
UNION ALL SELECT 'sc_use_admission_applications_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_use_admission_applications_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='admission_applications' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); CALL sc_plan_accepting(NEW.academic_program_id); END')
UNION ALL SELECT 'sc_use_admission_applications_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_use_admission_applications_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='admission_applications' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF NOT(NEW.academic_program_id<=>OLD.academic_program_id) THEN CALL sc_plan_accepting(NEW.academic_program_id); END IF; END')
UNION ALL SELECT 'sc_plan_student_version','ROUTINE_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.routines WHERE routine_schema='alrowad_uni_rust' AND routine_name='sc_plan_student_version' AND routine_type='FUNCTION' AND REGEXP_REPLACE(TRIM(routine_definition),'[[:space:]]+',' ')='BEGIN DECLARE version_key INT DEFAULT NULL; DECLARE owner_key INT DEFAULT NULL; DECLARE state_value VARCHAR(20) DEFAULT NULL; SELECT academic_program_id INTO owner_key FROM students WHERE student_id=student_key; SELECT plan_state INTO state_value FROM academic_programs WHERE academic_program_id=owner_key; SELECT academic_plan_version_id INTO version_key FROM student_academic_plan_assignments WHERE student_id=student_key AND academic_program_id=owner_key AND current_slot=1; IF version_key IS NULL AND (state_value=''ready'' OR EXISTS(SELECT 1 FROM academic_plan_versions WHERE academic_program_id=owner_key AND status=''transitional'')) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_context_invalid''; END IF; RETURN version_key; END')
UNION ALL SELECT 'sc_plan_course_registrations_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_course_registrations_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_course_registrations' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN DECLARE version_key INT DEFAULT NULL; CALL sc_plan_touch(); SET version_key=sc_plan_student_version(NEW.student_id); IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_context_invalid''; END IF; SET NEW.academic_plan_version_id=version_key; IF version_key IS NOT NULL THEN SET NEW.plan_program_course_id=(SELECT pc.program_course_id FROM program_courses pc JOIN course_offerings o ON o.course_id=pc.course_id WHERE o.course_offering_id=NEW.course_offering_id AND pc.academic_plan_version_id=version_key AND pc.is_active=1); ELSE SET NEW.plan_program_course_id=NULL; END IF; END')
UNION ALL SELECT 'sc_plan_course_registrations_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_course_registrations_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_course_registrations' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status=''transitional'') THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.plan_program_course_id<=>OLD.plan_program_course_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; IF NEW.plan_program_course_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM program_courses pc JOIN course_offerings o ON o.course_id=pc.course_id WHERE pc.program_course_id=NEW.plan_program_course_id AND pc.academic_plan_version_id=NEW.academic_plan_version_id AND o.course_offering_id=NEW.course_offering_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_context_invalid''; END IF; END')
UNION ALL SELECT 'sc_plan_requests_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_requests_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_registration_requests' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN DECLARE version_key INT DEFAULT NULL; CALL sc_plan_touch(); SET version_key=sc_plan_student_version(NEW.student_id); IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_context_invalid''; END IF; SET NEW.academic_plan_version_id=version_key; END')
UNION ALL SELECT 'sc_plan_requests_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_requests_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_registration_requests' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status=''transitional'') THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; END')
UNION ALL SELECT 'sc_plan_modification_requests_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_modification_requests_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_registration_modification_requests' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN DECLARE version_key INT DEFAULT NULL; CALL sc_plan_touch(); SET version_key=sc_plan_student_version(NEW.student_id); IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_context_invalid''; END IF; SET NEW.academic_plan_version_id=version_key; END')
UNION ALL SELECT 'sc_plan_modification_requests_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_modification_requests_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_registration_modification_requests' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status=''transitional'') THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; END')
UNION ALL SELECT 'sc_plan_replacement_requests_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_replacement_requests_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_registration_replacement_requests' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN DECLARE version_key INT DEFAULT NULL; CALL sc_plan_touch(); SET version_key=sc_plan_student_version(NEW.student_id); IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_context_invalid''; END IF; SET NEW.academic_plan_version_id=version_key; END')
UNION ALL SELECT 'sc_plan_replacement_requests_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_replacement_requests_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_registration_replacement_requests' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status=''transitional'') THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; END')
UNION ALL SELECT 'sc_plan_progression_decisions_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_progression_decisions_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_progression_decisions' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN DECLARE version_key INT DEFAULT NULL; CALL sc_plan_touch(); SET version_key=sc_plan_student_version(NEW.student_id); IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_context_invalid''; END IF; SET NEW.academic_plan_version_id=version_key; END')
UNION ALL SELECT 'sc_plan_progression_decisions_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_progression_decisions_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_progression_decisions' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status=''transitional'') THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; END')
UNION ALL SELECT 'sc_plan_graduation_decisions_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_graduation_decisions_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_graduation_decisions' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN DECLARE version_key INT DEFAULT NULL; CALL sc_plan_touch(); SET version_key=sc_plan_student_version(NEW.student_id); IF NEW.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>version_key) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_context_invalid''; END IF; SET NEW.academic_plan_version_id=version_key; END')
UNION ALL SELECT 'sc_plan_graduation_decisions_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_graduation_decisions_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_graduation_decisions' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); IF OLD.academic_plan_version_id IS NOT NULL AND NOT(NEW.academic_plan_version_id<=>OLD.academic_plan_version_id) THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; IF OLD.academic_plan_version_id IS NULL AND NEW.academic_plan_version_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM academic_plan_versions v JOIN students s ON s.academic_program_id=v.academic_program_id WHERE s.student_id=OLD.student_id AND v.academic_plan_version_id=NEW.academic_plan_version_id AND v.status=''transitional'') THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''academic_plan_record_immutable''; END IF; END')
UNION ALL SELECT 'sc_plan_epoch_course_results_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_course_results_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_course_results' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_course_results_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_course_results_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_course_results' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_course_results_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_course_results_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='student_course_results' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_grade_approvals_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_grade_approvals_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='grade_approvals' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_grade_approvals_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_grade_approvals_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='grade_approvals' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_grade_approvals_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_grade_approvals_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='grade_approvals' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_grade_audit_logs_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_grade_audit_logs_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='grade_audit_logs' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_grade_audit_logs_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_grade_audit_logs_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='grade_audit_logs' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_grade_audit_logs_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_grade_audit_logs_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='grade_audit_logs' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_request_items_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_request_items_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_registration_request_items' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_request_items_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_request_items_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_registration_request_items' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_request_items_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_request_items_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='student_registration_request_items' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_modification_items_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_modification_items_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_registration_modification_items' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_modification_items_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_modification_items_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_registration_modification_items' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_modification_items_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_modification_items_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='student_registration_modification_items' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_replacement_items_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_replacement_items_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_registration_replacement_items' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_replacement_items_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_replacement_items_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_registration_replacement_items' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_replacement_items_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_replacement_items_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='student_registration_replacement_items' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_withdrawal_requests_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_withdrawal_requests_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='student_registration_withdrawal_requests' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_withdrawal_requests_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_withdrawal_requests_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='student_registration_withdrawal_requests' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_withdrawal_requests_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_withdrawal_requests_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='student_registration_withdrawal_requests' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'academic_plan_control.chk_plan_control','CHECK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.check_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='academic_plan_control' AND constraint_name='chk_plan_control' AND REGEXP_REPLACE(LOWER(check_clause),'[[:space:]]+','')='`control_id`=1and`schema_version`=1and`is_ready`in(0,1)')
UNION ALL SELECT 'academic_plan_events.chk_plan_event_json','CHECK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.check_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='academic_plan_events' AND constraint_name='chk_plan_event_json' AND REGEXP_REPLACE(LOWER(check_clause),'[[:space:]]+','')='json_valid(`context`)')
UNION ALL SELECT 'sc_plan_epoch_grade_appeals_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_grade_appeals_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='grade_appeals' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_grade_appeals_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_grade_appeals_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='grade_appeals' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_grade_appeals_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_grade_appeals_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='grade_appeals' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_appeal_statuses_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_appeal_statuses_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='appeal_statuses' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_appeal_statuses_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_appeal_statuses_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='appeal_statuses' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_appeal_statuses_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_appeal_statuses_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='appeal_statuses' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_supplementary_exam_registrations_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_supplementary_exam_registrations_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='supplementary_exam_registrations' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_supplementary_exam_registrations_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_supplementary_exam_registrations_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='supplementary_exam_registrations' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_supplementary_exam_registrations_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_supplementary_exam_registrations_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='supplementary_exam_registrations' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_supplementary_exam_materializations_i','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_supplementary_exam_materializations_i' AND action_timing='BEFORE' AND event_manipulation='INSERT' AND event_object_table='supplementary_exam_materializations' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_supplementary_exam_materializations_u','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_supplementary_exam_materializations_u' AND action_timing='BEFORE' AND event_manipulation='UPDATE' AND event_object_table='supplementary_exam_materializations' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'sc_plan_epoch_supplementary_exam_materializations_d','TRIGGER_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema='alrowad_uni_rust' AND trigger_name='sc_plan_epoch_supplementary_exam_materializations_d' AND action_timing='BEFORE' AND event_manipulation='DELETE' AND event_object_table='supplementary_exam_materializations' AND REGEXP_REPLACE(TRIM(action_statement),'[[:space:]]+',' ')='BEGIN CALL sc_plan_touch(); END')
UNION ALL SELECT 'academic_plan_versions.chk_plan_hours','CHECK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.check_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='academic_plan_versions' AND constraint_name='chk_plan_hours' AND REGEXP_REPLACE(LOWER(check_clause),'[[:space:]]+','')='`version_number`>0and(`total_credit_hours`isnullor`total_credit_hours`>0)')
UNION ALL SELECT 'academic_plan_versions.chk_plan_state','CHECK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.check_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='academic_plan_versions' AND constraint_name='chk_plan_state' AND REGEXP_REPLACE(LOWER(check_clause),'[[:space:]]+','')='`status`=''draft''and`fixed_at`isnulland`approved_at`isnulland`approved_by_user_id`isnulland`calculation_policy`=''explicit_zero_v1''or`status`=''transitional''and`fixed_at`isnotnulland`approved_at`isnulland`approved_by_user_id`isnulland`calculation_policy`=''legacy''or`status`=''approved''and`fixed_at`isnotnulland`approved_at`isnotnulland`approved_by_user_id`isnotnulland`calculation_policy`=''explicit_zero_v1''')
UNION ALL SELECT 'student_academic_plan_assignments.chk_student_plan_reason','CHECK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.check_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='student_academic_plan_assignments' AND constraint_name='chk_student_plan_reason' AND REGEXP_REPLACE(LOWER(check_clause),'[[:space:]]+','')='char_length(trim(`reason`))>0')
UNION ALL SELECT 'student_academic_plan_assignments.chk_student_plan_slot','CHECK_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.check_constraints WHERE constraint_schema='alrowad_uni_rust' AND table_name='student_academic_plan_assignments' AND constraint_name='chk_student_plan_slot' AND REGEXP_REPLACE(LOWER(check_clause),'[[:space:]]+','')='`current_slot`isnotnulland`current_slot`=1and`ended_at`isnullor`current_slot`isnulland`ended_at`isnotnull')
UNION ALL SELECT 'academic_plan_versions.academic_plan_version_id','AUTO_INCREMENT_MISSING' WHERE NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_plan_versions' AND column_name='academic_plan_version_id' AND extra LIKE '%auto_increment%')
UNION ALL SELECT 'student_academic_plan_assignments.student_academic_plan_assignment_id','AUTO_INCREMENT_MISSING' WHERE NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='student_academic_plan_assignments' AND column_name='student_academic_plan_assignment_id' AND extra LIKE '%auto_increment%')
UNION ALL SELECT 'academic_plan_events.academic_plan_event_id','AUTO_INCREMENT_MISSING' WHERE NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_plan_events' AND column_name='academic_plan_event_id' AND extra LIKE '%auto_increment%')
UNION ALL SELECT 'program_courses.plan_scope_key','GENERATED_CONTEXT_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='program_courses' AND column_name='plan_scope_key' AND extra LIKE '%STORED GENERATED%' AND REGEXP_REPLACE(LOWER(generation_expression),'[[:space:]]+','')='coalesce(`academic_plan_version_id`,0)')
UNION ALL SELECT 'academic_requirement_groups.plan_scope_key','GENERATED_CONTEXT_MISMATCH' WHERE NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_requirement_groups' AND column_name='plan_scope_key' AND extra LIKE '%STORED GENERATED%' AND REGEXP_REPLACE(LOWER(generation_expression),'[[:space:]]+','')='coalesce(`academic_plan_version_id`,0)')
UNION ALL SELECT 'legacy_uniqueness','OLD_NON_VERSIONED_INDEX_REMAINS' WHERE EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name IN('program_courses','academic_requirement_groups') AND non_unique=0 GROUP BY table_name,index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index)=CASE table_name WHEN 'program_courses' THEN 'academic_program_id,course_id' ELSE 'academic_program_id,requirement_scope,requirement_type' END)
UNION ALL SELECT 'academic_programs.plan_state','INCOMPATIBLE_DEFAULT' WHERE NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_programs' AND column_name='plan_state' AND TRIM(BOTH '''' FROM COALESCE(column_default,''))='legacy')
UNION ALL SELECT 'permission.plans.manage','PERMISSION_DEFINITION_MISMATCH' WHERE (SELECT COUNT(*) FROM permissions p JOIN system_modules m ON m.module_id=p.module_id WHERE p.permission_code='vice_presidency.scientific.programs.plans.manage' AND p.is_active=1 AND m.is_active=1 AND p.module_id=(SELECT module_id FROM permissions WHERE permission_code='academic_structure.manage' AND is_active=1))<>1
UNION ALL SELECT 'permission.plans.approve','PERMISSION_DEFINITION_MISMATCH' WHERE (SELECT COUNT(*) FROM permissions p JOIN system_modules m ON m.module_id=p.module_id WHERE p.permission_code='vice_presidency.scientific.programs.plans.approve' AND p.is_active=1 AND m.is_active=1 AND p.module_id=(SELECT module_id FROM permissions WHERE permission_code='academic_structure.manage' AND is_active=1))<>1
UNION ALL SELECT 'permission.plans.assign','PERMISSION_DEFINITION_MISMATCH' WHERE (SELECT COUNT(*) FROM permissions p JOIN system_modules m ON m.module_id=p.module_id WHERE p.permission_code='vice_presidency.scientific.programs.plans.assign' AND p.is_active=1 AND m.is_active=1 AND p.module_id=(SELECT module_id FROM permissions WHERE permission_code='academic_structure.manage' AND is_active=1))<>1
UNION ALL SELECT 'permission.archive','PERMISSION_DEFINITION_MISMATCH' WHERE (SELECT COUNT(*) FROM permissions p JOIN system_modules m ON m.module_id=p.module_id WHERE p.permission_code='vice_presidency.scientific.programs.archive' AND p.is_active=1 AND m.is_active=1 AND p.module_id=(SELECT module_id FROM permissions WHERE permission_code='academic_structure.manage' AND is_active=1))<>1
UNION ALL SELECT 'permission.delete','PERMISSION_DEFINITION_MISMATCH' WHERE (SELECT COUNT(*) FROM permissions p JOIN system_modules m ON m.module_id=p.module_id WHERE p.permission_code='vice_presidency.scientific.programs.delete' AND p.is_active=1 AND m.is_active=1 AND p.module_id=(SELECT module_id FROM permissions WHERE permission_code='academic_structure.manage' AND is_active=1))<>1
)
SELECT COUNT(*) INTO error_count FROM issues;
  IF error_count<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='academic_plan_schema_verification_failed'; END IF;
END//
DELIMITER ;
CALL sc_plan_verify_before_ready();
-- This flag certifies structural installation only. Program initialization remains an explicit UI operation.
UPDATE academic_plan_control SET is_ready=1 WHERE control_id=1 AND schema_version=1;
SELECT 'OVERALL' AS section,'APPLIED' AS result,'Schema installed; no program or student was initialized' AS detail;
