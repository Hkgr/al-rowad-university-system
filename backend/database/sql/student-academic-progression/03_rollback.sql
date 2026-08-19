-- Conservative rollback for Phase 10 academic record / progression / graduation.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not use the DATABASE function, stored procedures, DELIMITER, or SIGNAL.
--
-- BACKUP FIRST.
-- Never deletes students, student_academic_terms legacy rows, grades,
-- results, registrations, attendance, or academic history.
--
-- IMPORTANT: optional Phase 10 tables/columns are never referenced inside a
-- statically parsed IF() subquery. Presence is read from information_schema;
-- business-row counts use guarded PREPARE/EXECUTE.
--
-- BLOCKED_IN_USE if any progression/graduation history exists, or if any
-- academic term already uses finalization metadata.
--
-- RBAC OWNERSHIP:
-- 00_preflight.sql classifies an already-existing compatible permission as
-- COMPATIBLE. This file cannot prove that a matching permission_code or
-- registration_officer grant was created by Phase 10 apply.
-- Therefore rollback NEVER deletes permissions or role_permissions.
-- Unused leftover permissions are safer than destroying pre-existing RBAC.
--
-- Never drops uq_student_term (it pre-existed Phase 10).
-- Drop order when READY: events tables, decision tables, then unused term columns.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @spd_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND table_type = 'BASE TABLE'), 0);
SET @spe_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events' AND table_type = 'BASE TABLE'), 0);
SET @sgd_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND table_type = 'BASE TABLE'), 0);
SET @sge_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events' AND table_type = 'BASE TABLE'), 0);

SET @spd_rows := 0;
SET @spe_rows := 0;
SET @sgd_rows := 0;
SET @sge_rows := 0;
SET @finalized_terms := 0;

SET @sql := IF(@spd_exists = 1, 'SELECT @spd_rows := COUNT(*) FROM `alrowad_uni_rust`.`student_progression_decisions`', 'SELECT @spd_rows := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@spe_exists = 1, 'SELECT @spe_rows := COUNT(*) FROM `alrowad_uni_rust`.`student_progression_events`', 'SELECT @spe_rows := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@sgd_exists = 1, 'SELECT @sgd_rows := COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions`', 'SELECT @sgd_rows := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@sge_exists = 1, 'SELECT @sge_rows := COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_events`', 'SELECT @sge_rows := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_is_finalized := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'is_finalized'), 0);
SET @col_finalized_at := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_at'), 0);
SET @col_finalized_by := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_by_user_id'), 0);
SET @finalized_is := 0;
SET @finalized_at_rows := 0;
SET @finalized_by_rows := 0;
SET @sql := IF(@col_is_finalized = 1, 'SELECT @finalized_is := COUNT(*) FROM `alrowad_uni_rust`.`student_academic_terms` WHERE is_finalized = 1', 'SELECT @finalized_is := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@col_finalized_at = 1, 'SELECT @finalized_at_rows := COUNT(*) FROM `alrowad_uni_rust`.`student_academic_terms` WHERE finalized_at IS NOT NULL', 'SELECT @finalized_at_rows := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@col_finalized_by = 1, 'SELECT @finalized_by_rows := COUNT(*) FROM `alrowad_uni_rust`.`student_academic_terms` WHERE finalized_by_user_id IS NOT NULL', 'SELECT @finalized_by_rows := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @finalized_terms := @finalized_is + @finalized_at_rows + @finalized_by_rows;

SET @in_use := IF(@spd_rows > 0 OR @spe_rows > 0 OR @sgd_rows > 0 OR @sge_rows > 0 OR @finalized_terms > 0, 1, 0);
SET @rollback_ready := IF(@db_ready = 1 AND @in_use = 0, 1, 0);

SELECT
    'rollback_precheck' AS report_section,
    @spd_exists AS progression_decisions_present,
    @spe_exists AS progression_events_present,
    @sgd_exists AS graduation_decisions_present,
    @sge_exists AS graduation_events_present,
    @spd_rows AS progression_decision_rows,
    @sgd_rows AS graduation_decision_rows,
    @finalized_terms AS finalized_term_rows,
    IF(@in_use = 1, 'BLOCKED_IN_USE', IF(@rollback_ready = 1, 'READY', 'BLOCKED')) AS rollback_status,
    'Phase 10 permissions and role_permissions are retained; provenance cannot be proven.' AS rbac_policy;

SET @sql := IF(@rollback_ready = 1 AND @sge_exists = 1, 'DROP TABLE `alrowad_uni_rust`.`student_graduation_events`', 'SELECT ''skip_drop_graduation_events'' AS rollback_step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rollback_ready = 1 AND @sgd_exists = 1, 'DROP TABLE `alrowad_uni_rust`.`student_graduation_decisions`', 'SELECT ''skip_drop_graduation_decisions'' AS rollback_step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rollback_ready = 1 AND @spe_exists = 1, 'DROP TABLE `alrowad_uni_rust`.`student_progression_events`', 'SELECT ''skip_drop_progression_events'' AS rollback_step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rollback_ready = 1 AND @spd_exists = 1, 'DROP TABLE `alrowad_uni_rust`.`student_progression_decisions`', 'SELECT ''skip_drop_progression_decisions'' AS rollback_step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_sat_finalized_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms'
       AND constraint_name = 'fk_sat_finalized_by' AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @sql := IF(
    @rollback_ready = 1 AND @fk_sat_finalized_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` DROP FOREIGN KEY `fk_sat_finalized_by`',
    'SELECT ''skip_drop_fk_sat_finalized_by'' AS rollback_step'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_finalized_by := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_by_user_id'), 0);
SET @col_finalized_at := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_at'), 0);
SET @col_attempted := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'attempted_hours'), 0);
SET @col_earned := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'earned_hours'), 0);
SET @col_is_finalized := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'is_finalized'), 0);

SET @sql := IF(@rollback_ready = 1 AND @col_finalized_by = 1, 'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` DROP COLUMN `finalized_by_user_id`', 'SELECT ''skip_drop_finalized_by'' AS rollback_step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rollback_ready = 1 AND @col_finalized_at = 1, 'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` DROP COLUMN `finalized_at`', 'SELECT ''skip_drop_finalized_at'' AS rollback_step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rollback_ready = 1 AND @col_attempted = 1, 'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` DROP COLUMN `attempted_hours`', 'SELECT ''skip_drop_attempted_hours'' AS rollback_step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rollback_ready = 1 AND @col_earned = 1, 'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` DROP COLUMN `earned_hours`', 'SELECT ''skip_drop_earned_hours'' AS rollback_step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rollback_ready = 1 AND @col_is_finalized = 1, 'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` DROP COLUMN `is_finalized`', 'SELECT ''skip_drop_is_finalized'' AS rollback_step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'skip_delete_role_permissions' AS rollback_step, 'retained_no_provenance' AS reason;
SELECT 'skip_delete_permissions' AS rollback_step, 'retained_no_provenance' AS reason;
SELECT 'skip_drop_uq_student_term' AS rollback_step, 'pre_existing_unique_retained' AS reason;

SELECT
    'rollback_complete' AS report_section,
    IF(@in_use = 1, 'BLOCKED_IN_USE', IF(@rollback_ready = 1, 'ROLLED_BACK', 'BLOCKED')) AS rollback_status,
    'Students, academic terms, grades, results, registrations, and attendance were not deleted. Phase 10 RBAC rows were retained.' AS note;
