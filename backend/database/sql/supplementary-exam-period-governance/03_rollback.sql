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
-- NEVER drop adopted pre-existing columns, indexes, FKs, or event tables.
--
-- BLOCKED_IN_USE when any governed data exists:
--   status = announced
--   opened_by_user_id IS NOT NULL
--   opened_at IS NOT NULL
--   any supplementary_exam_period_events row
--
-- Rollback may remove only objects whose COMMENT contains
-- [phase1-supplementary-exam-period-governance].
--
-- Columns without that marker: ADOPTED / DO NOT DROP.
-- Indexes and foreign keys cannot carry a reliable ownership marker:
-- leave them in place, including equivalent identity UNIQUE indexes.
-- Event table: drop only when table COMMENT proves Phase 1 ownership
-- and the table is empty.
-- Permissions: keep existing description-marker logic.

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

SET @status_owned := IF(
    @status_exists = 1
    AND (
        SELECT COALESCE(column_comment, '')
        FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_periods'
          AND column_name = 'status'
    ) LIKE '%[phase1-supplementary-exam-period-governance]%',
    1, 0
);
SET @opened_by_owned := IF(
    @opened_by_exists = 1
    AND (
        SELECT COALESCE(column_comment, '')
        FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_periods'
          AND column_name = 'opened_by_user_id'
    ) LIKE '%[phase1-supplementary-exam-period-governance]%',
    1, 0
);
SET @opened_at_owned := IF(
    @opened_at_exists = 1
    AND (
        SELECT COALESCE(column_comment, '')
        FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_periods'
          AND column_name = 'opened_at'
    ) LIKE '%[phase1-supplementary-exam-period-governance]%',
    1, 0
);
SET @decision_note_owned := IF(
    @decision_note_exists = 1
    AND (
        SELECT COALESCE(column_comment, '')
        FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_periods'
          AND column_name = 'decision_note'
    ) LIKE '%[phase1-supplementary-exam-period-governance]%',
    1, 0
);

SET @fk_opened_by := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND constraint_name = 'fk_sep_opened_by'
       AND constraint_type = 'FOREIGN KEY'),
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
       @events_owned AS events_owned,
       @status_owned AS status_owned,
       @opened_by_owned AS opened_by_owned,
       @opened_at_owned AS opened_at_owned,
       @decision_note_owned AS decision_note_owned;

SELECT 'ROLLBACK_ADOPTED' AS report_section, object_name,
       CASE
           WHEN exists_flag = 0 THEN 'ABSENT'
           WHEN owned_flag = 1 THEN 'PHASE1_OWNED'
           ELSE 'ADOPTED_DO_NOT_DROP'
       END AS ownership
FROM (
    SELECT 'status' AS object_name, @status_exists AS exists_flag, @status_owned AS owned_flag
    UNION ALL SELECT 'opened_by_user_id', @opened_by_exists, @opened_by_owned
    UNION ALL SELECT 'opened_at', @opened_at_exists, @opened_at_owned
    UNION ALL SELECT 'decision_note', @decision_note_exists, @decision_note_owned
    UNION ALL SELECT 'supplementary_exam_period_events', @events_exist, @events_owned
) objects;

SELECT 'ROLLBACK_PRECONDITION' AS report_section,
       'If BLOCKED_IN_USE, operator must decide manually. Adopted objects without the Phase 1 COMMENT marker are never dropped. Identity UNIQUE indexes are never dropped.' AS operator_note;

SET @sql := IF(
    @rollback_status = 'READY' AND @events_exist = 1 AND @events_owned = 1 AND @event_rows = 0,
    'DROP TABLE IF EXISTS `alrowad_uni_rust`.`supplementary_exam_period_events`',
    'SELECT ''SKIPPED_DROP_EVENTS'' AS rollback_result'
);
PREPARE phase1_rb_drop_events FROM @sql;
EXECUTE phase1_rb_drop_events;
DEALLOCATE PREPARE phase1_rb_drop_events;

SET @sql := IF(
    @rollback_status = 'READY' AND @fk_opened_by = 1 AND @opened_by_owned = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods` DROP FOREIGN KEY `fk_sep_opened_by`',
    'SELECT ''SKIPPED_DROP_FK'' AS rollback_result'
);
PREPARE phase1_rb_drop_fk FROM @sql;
EXECUTE phase1_rb_drop_fk;
DEALLOCATE PREPARE phase1_rb_drop_fk;

SET @sql := IF(
    @rollback_status = 'READY' AND @decision_note_exists = 1 AND @decision_note_owned = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods` DROP COLUMN `decision_note`',
    'SELECT ''SKIPPED_DROP_DECISION_NOTE'' AS rollback_result'
);
PREPARE phase1_rb_drop_note FROM @sql;
EXECUTE phase1_rb_drop_note;
DEALLOCATE PREPARE phase1_rb_drop_note;

SET @sql := IF(
    @rollback_status = 'READY' AND @opened_at_exists = 1 AND @opened_at_owned = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods` DROP COLUMN `opened_at`',
    'SELECT ''SKIPPED_DROP_OPENED_AT'' AS rollback_result'
);
PREPARE phase1_rb_drop_opened_at FROM @sql;
EXECUTE phase1_rb_drop_opened_at;
DEALLOCATE PREPARE phase1_rb_drop_opened_at;

SET @sql := IF(
    @rollback_status = 'READY' AND @opened_by_exists = 1 AND @opened_by_owned = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods` DROP COLUMN `opened_by_user_id`',
    'SELECT ''SKIPPED_DROP_OPENED_BY'' AS rollback_result'
);
PREPARE phase1_rb_drop_opened_by FROM @sql;
EXECUTE phase1_rb_drop_opened_by;
DEALLOCATE PREPARE phase1_rb_drop_opened_by;

SET @sql := IF(
    @rollback_status = 'READY' AND @status_exists = 1 AND @status_owned = 1,
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
