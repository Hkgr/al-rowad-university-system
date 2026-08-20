-- Conservative rollback for Phase 1 supplementary exam period governance.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not use DATABASE(), stored procedures, DELIMITER, or SIGNAL.
--
-- BACKUP FIRST.
-- Emergency only.
--
-- NEVER delete supplementary_exam_results.
-- NEVER drop supplementary_exam_periods.
-- NEVER drop a populated event table.
-- NEVER delete period rows.
--
-- BLOCKED_IN_USE when any governed data exists:
--   status = announced
--   opened_by_user_id IS NOT NULL
--   opened_at IS NOT NULL
--   any supplementary_exam_period_events row
--
-- Safe rollback removes only Phase 1 schema/RBAC additions:
--   event table (empty + ownership marker)
--   unique identity, status index, opened_by FK
--   governance columns
--   Phase 1 permissions / role_permissions (description marker)

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @periods_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND table_type = 'BASE TABLE'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND table_type = 'BASE TABLE'), 0);
SET @results_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_results' AND table_type = 'BASE TABLE'), 0);

SET @status_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status'), 0);
SET @opened_by_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id'), 0);
SET @opened_at_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_at'), 0);
SET @decision_note_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'decision_note'), 0);

SET @fk_opened_by := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND constraint_name = 'fk_sep_opened_by'
       AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @uq_identity := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND index_name = 'uq_sep_year_semester'),
    0
);
SET @idx_status := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND index_name = 'idx_sep_status'),
    0
);

SET @events_owned := IF(
    @events_exist = 1,
    IF((
        SELECT COALESCE(table_comment, '')
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_period_events'
          AND table_type = 'BASE TABLE'
    ) LIKE '%[phase1-supplementary-exam-period-governance]%', 1, 0),
    0
);

SET @announced_rows := 0;
SET @opened_by_rows := 0;
SET @opened_at_rows := 0;
SET @event_rows := 0;
SET @result_rows := 0;

SET @sql := IF(
    @status_exists = 1,
    'SELECT @announced_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods` WHERE status = ''announced''',
    'SELECT @announced_rows := 0'
);
PREPARE phase1_rb_announced FROM @sql;
EXECUTE phase1_rb_announced;
DEALLOCATE PREPARE phase1_rb_announced;

SET @sql := IF(
    @opened_by_exists = 1,
    'SELECT @opened_by_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods` WHERE opened_by_user_id IS NOT NULL',
    'SELECT @opened_by_rows := 0'
);
PREPARE phase1_rb_opened_by FROM @sql;
EXECUTE phase1_rb_opened_by;
DEALLOCATE PREPARE phase1_rb_opened_by;

SET @sql := IF(
    @opened_at_exists = 1,
    'SELECT @opened_at_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods` WHERE opened_at IS NOT NULL',
    'SELECT @opened_at_rows := 0'
);
PREPARE phase1_rb_opened_at FROM @sql;
EXECUTE phase1_rb_opened_at;
DEALLOCATE PREPARE phase1_rb_opened_at;

SET @sql := IF(
    @events_exist = 1,
    'SELECT @event_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_period_events`',
    'SELECT @event_rows := 0'
);
PREPARE phase1_rb_events FROM @sql;
EXECUTE phase1_rb_events;
DEALLOCATE PREPARE phase1_rb_events;

SET @sql := IF(
    @results_exist = 1,
    'SELECT @result_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_results`',
    'SELECT @result_rows := 0'
);
PREPARE phase1_rb_results FROM @sql;
EXECUTE phase1_rb_results;
DEALLOCATE PREPARE phase1_rb_results;

SET @in_use := IF(
    @announced_rows > 0 OR @opened_by_rows > 0 OR @opened_at_rows > 0 OR @event_rows > 0,
    1, 0
);

SET @rollback_status := IF(
    @db_ready = 0,
    'BLOCKED',
    IF(@in_use = 1, 'BLOCKED_IN_USE', 'READY')
);

SELECT 'ROLLBACK_GUARD' AS report_section,
       @rollback_status AS result,
       @announced_rows AS announced_rows,
       @opened_by_rows AS opened_by_rows,
       @opened_at_rows AS opened_at_rows,
       @event_rows AS event_rows,
       @result_rows AS supplementary_exam_results_rows,
       @events_owned AS events_owned;

SELECT 'ROLLBACK_PRECONDITION' AS report_section,
       'If BLOCKED_IN_USE, operator must decide manually. This script will not destroy governed periods, events, or results.' AS operator_note;

SET @sql := IF(
    @rollback_status = 'READY' AND @events_exist = 1 AND @events_owned = 1 AND @event_rows = 0,
    'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_period_events`',
    'SELECT ''SKIPPED_DROP_EVENTS'' AS rollback_result'
);
PREPARE phase1_rb_drop_events FROM @sql;
EXECUTE phase1_rb_drop_events;
DEALLOCATE PREPARE phase1_rb_drop_events;

SET @sql := IF(
    @rollback_status = 'READY' AND @fk_opened_by = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods` DROP FOREIGN KEY `fk_sep_opened_by`',
    'SELECT ''SKIPPED_DROP_FK'' AS rollback_result'
);
PREPARE phase1_rb_drop_fk FROM @sql;
EXECUTE phase1_rb_drop_fk;
DEALLOCATE PREPARE phase1_rb_drop_fk;

SET @sql := IF(
    @rollback_status = 'READY' AND @uq_identity = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods` DROP INDEX `uq_sep_year_semester`',
    'SELECT ''SKIPPED_DROP_UNIQUE'' AS rollback_result'
);
PREPARE phase1_rb_drop_uq FROM @sql;
EXECUTE phase1_rb_drop_uq;
DEALLOCATE PREPARE phase1_rb_drop_uq;

SET @sql := IF(
    @rollback_status = 'READY' AND @idx_status = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods` DROP INDEX `idx_sep_status`',
    'SELECT ''SKIPPED_DROP_IDX_STATUS'' AS rollback_result'
);
PREPARE phase1_rb_drop_idx FROM @sql;
EXECUTE phase1_rb_drop_idx;
DEALLOCATE PREPARE phase1_rb_drop_idx;

SET @sql := IF(
    @rollback_status = 'READY' AND @decision_note_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods` DROP COLUMN `decision_note`',
    'SELECT ''SKIPPED_DROP_DECISION_NOTE'' AS rollback_result'
);
PREPARE phase1_rb_drop_note FROM @sql;
EXECUTE phase1_rb_drop_note;
DEALLOCATE PREPARE phase1_rb_drop_note;

SET @sql := IF(
    @rollback_status = 'READY' AND @opened_at_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods` DROP COLUMN `opened_at`',
    'SELECT ''SKIPPED_DROP_OPENED_AT'' AS rollback_result'
);
PREPARE phase1_rb_drop_opened_at FROM @sql;
EXECUTE phase1_rb_drop_opened_at;
DEALLOCATE PREPARE phase1_rb_drop_opened_at;

SET @sql := IF(
    @rollback_status = 'READY' AND @opened_by_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods` DROP COLUMN `opened_by_user_id`',
    'SELECT ''SKIPPED_DROP_OPENED_BY'' AS rollback_result'
);
PREPARE phase1_rb_drop_opened_by FROM @sql;
EXECUTE phase1_rb_drop_opened_by;
DEALLOCATE PREPARE phase1_rb_drop_opened_by;

SET @sql := IF(
    @rollback_status = 'READY' AND @status_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods` DROP COLUMN `status`',
    'SELECT ''SKIPPED_DROP_STATUS'' AS rollback_result'
);
PREPARE phase1_rb_drop_status FROM @sql;
EXECUTE phase1_rb_drop_status;
DEALLOCATE PREPARE phase1_rb_drop_status;

START TRANSACTION;

DELETE rp FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @rollback_status = 'READY'
  AND p.permission_code IN ('supplementary_exams.periods.view', 'supplementary_exams.periods.decide')
  AND p.description LIKE '%[phase1-supplementary-exam-period-governance]%';

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @rollback_status = 'READY'
  AND permission_code IN ('supplementary_exams.periods.view', 'supplementary_exams.periods.decide')
  AND description LIKE '%[phase1-supplementary-exam-period-governance]%';

SET @sql := IF(@rollback_status = 'READY', 'COMMIT', 'ROLLBACK');
PREPARE phase1_rb_rbac FROM @sql;
EXECUTE phase1_rb_rbac;
DEALLOCATE PREPARE phase1_rb_rbac;

SELECT 'ROLLBACK_RESULT' AS report_section,
       @rollback_status AS result,
       @result_rows AS supplementary_exam_results_untouched;
