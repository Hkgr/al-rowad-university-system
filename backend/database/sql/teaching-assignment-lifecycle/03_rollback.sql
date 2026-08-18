-- Conservative rollback for Phase 8 Teaching Assignment lifecycle columns.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not use DATABASE(), stored procedures, DELIMITER, or SIGNAL.
--
-- BACKUP FIRST.
-- Never drops original Teaching Assignment tables.
-- Never removes Phase 4 permissions / role_permissions.
--
-- IMPORTANT: optional Phase 8 columns are never referenced inside a
-- statically parsed IF() subquery. Presence is read from information_schema;
-- business-row counts use guarded PREPARE/EXECUTE.
--
-- Safe when Phase 8 objects are absent, partial, or fully present and unused.
-- BLOCKED_IN_USE if any action_type = 'remove' row exists or Phase 8 removal
-- audit events exist. History is never deleted to drop columns.
--
-- Drop order when READY: FK, index, then columns.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @requests_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND table_type = 'BASE TABLE'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_events' AND table_type = 'BASE TABLE'), 0);

SET @action_type_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), 0);
SET @action_reason_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason'), 0);
SET @target_slot_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id'), 0);
SET @fk_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND constraint_name = 'fk_tar_target_instructor'
       AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @idx_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND index_name = 'idx_tar_action_status'),
    0
);

SET @remove_rows := 0;
SET @removal_events := 0;

SET @sql := IF(
    @action_type_exists = 1,
    'SELECT @remove_rows := COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_requests` WHERE `action_type` = ''remove''',
    'SELECT @remove_rows := 0'
);
PREPARE phase8_rb_remove_rows_stmt FROM @sql;
EXECUTE phase8_rb_remove_rows_stmt;
DEALLOCATE PREPARE phase8_rb_remove_rows_stmt;

SET @sql := IF(
    @events_exist = 1,
    'SELECT @removal_events := COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_events`
     WHERE event_type IN (''effective_assignment_removed'', ''removal_withdrawn'', ''removal_stale'')',
    'SELECT @removal_events := 0'
);
PREPARE phase8_rb_events_stmt FROM @sql;
EXECUTE phase8_rb_events_stmt;
DEALLOCATE PREPARE phase8_rb_events_stmt;

SET @in_use := IF(@remove_rows > 0 OR @removal_events > 0, 1, 0);

SET @anything_present := IF(
    @action_type_exists = 1
    OR @action_reason_exists = 1
    OR @target_slot_exists = 1
    OR @fk_exists = 1
    OR @idx_exists = 1,
    1, 0
);

SET @rollback_status := IF(
    @db_ready = 0,
    'BLOCKED',
    IF(@in_use = 1, 'BLOCKED_IN_USE', IF(@anything_present = 1, 'READY', 'SKIPPED_ABSENT'))
);

SELECT 'ROLLBACK_GUARDS' AS report_section,
       @rollback_status AS rollback_status,
       @remove_rows AS remove_rows,
       @removal_events AS removal_events,
       @action_type_exists AS action_type_exists,
       @action_reason_exists AS action_reason_exists,
       @target_slot_exists AS target_slot_exists,
       @fk_exists AS fk_exists,
       @idx_exists AS idx_exists;

SET @sql := IF(
    @rollback_status = 'READY' AND @fk_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`teaching_assignment_requests` DROP FOREIGN KEY `fk_tar_target_instructor`',
    'SELECT ''SKIPPED_DROP_FK'' AS rollback_result'
);
PREPARE phase8_rb_fk_stmt FROM @sql;
EXECUTE phase8_rb_fk_stmt;
DEALLOCATE PREPARE phase8_rb_fk_stmt;

SET @sql := IF(
    @rollback_status = 'READY' AND @idx_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`teaching_assignment_requests` DROP INDEX `idx_tar_action_status`',
    'SELECT ''SKIPPED_DROP_IDX'' AS rollback_result'
);
PREPARE phase8_rb_idx_stmt FROM @sql;
EXECUTE phase8_rb_idx_stmt;
DEALLOCATE PREPARE phase8_rb_idx_stmt;

SET @sql := IF(
    @rollback_status = 'READY' AND @target_slot_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`teaching_assignment_requests` DROP COLUMN `target_course_offering_instructor_id`',
    'SELECT ''SKIPPED_DROP_TARGET_SLOT'' AS rollback_result'
);
PREPARE phase8_rb_target_stmt FROM @sql;
EXECUTE phase8_rb_target_stmt;
DEALLOCATE PREPARE phase8_rb_target_stmt;

SET @sql := IF(
    @rollback_status = 'READY' AND @action_reason_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`teaching_assignment_requests` DROP COLUMN `action_reason`',
    'SELECT ''SKIPPED_DROP_ACTION_REASON'' AS rollback_result'
);
PREPARE phase8_rb_reason_stmt FROM @sql;
EXECUTE phase8_rb_reason_stmt;
DEALLOCATE PREPARE phase8_rb_reason_stmt;

SET @sql := IF(
    @rollback_status = 'READY' AND @action_type_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`teaching_assignment_requests` DROP COLUMN `action_type`',
    'SELECT ''SKIPPED_DROP_ACTION_TYPE'' AS rollback_result'
);
PREPARE phase8_rb_type_stmt FROM @sql;
EXECUTE phase8_rb_type_stmt;
DEALLOCATE PREPARE phase8_rb_type_stmt;

SET @action_type_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), 0);
SET @action_reason_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason'), 0);
SET @target_slot_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id'), 0);
SET @fk_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND constraint_name = 'fk_tar_target_instructor'
       AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @idx_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND index_name = 'idx_tar_action_status'),
    0
);

SET @final_status := IF(
    @in_use = 1,
    'BLOCKED_IN_USE',
    IF(@db_ready = 0, 'BLOCKED',
        IF(@action_type_exists = 0 AND @action_reason_exists = 0 AND @target_slot_exists = 0 AND @fk_exists = 0 AND @idx_exists = 0,
            IF(@rollback_status = 'SKIPPED_ABSENT', 'SKIPPED_ABSENT', 'ROLLED_BACK'),
            'BLOCKED'
        )
    )
);

SELECT 'ROLLBACK_RESULT' AS report_section,
       @final_status AS rollback_status,
       @remove_rows AS remove_rows,
       @removal_events AS removal_events,
       @action_type_exists AS action_type_remaining,
       @action_reason_exists AS action_reason_remaining,
       @target_slot_exists AS target_slot_remaining;
