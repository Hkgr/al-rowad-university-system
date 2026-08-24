-- Emergency-only rollback to the known August 24 deployed layout.
-- Fails closed after any logical event or revision exists.

SET @acr_tables_ok := (
    SELECT COUNT(*) = 4 FROM information_schema.tables
    WHERE table_schema='alrowad_uni_rust' AND table_type='BASE TABLE'
      AND table_name IN ('academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events')
);
SET @acr_target_context := (
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_events' AND column_name IN ('semester_id','academic_calendar_event_type_id')) = 2
    AND
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_versions' AND column_name IN ('semester_id','academic_calendar_event_type_id')) = 0
);
SET @acr_exact_calendar_columns := (
    SELECT COUNT(*) = 42 FROM information_schema.columns
    WHERE table_schema='alrowad_uni_rust'
      AND table_name IN ('academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events')
);
SET @acr_target_context_fks := (
    SELECT COUNT(*) = 2 FROM information_schema.key_column_usage
    WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_events'
      AND referenced_table_name IS NOT NULL
      AND ((constraint_name='fk_ace_semester' AND column_name='semester_id' AND referenced_table_name='semesters')
        OR (constraint_name='fk_ace_event_type' AND column_name='academic_calendar_event_type_id' AND referenced_table_name='academic_calendar_event_types'))
);
SET @acr_target_repair_indexes := (
    SELECT COUNT(*) = 3 FROM information_schema.statistics
    WHERE table_schema='alrowad_uni_rust' AND seq_in_index=1
      AND ((table_name='academic_calendar_events' AND index_name='idx_ace_year_semester' AND column_name='academic_year_id')
        OR (table_name='academic_calendar_events' AND index_name='idx_ace_event_type' AND column_name='academic_calendar_event_type_id')
        OR (table_name='academic_calendar_year_lifecycle_events' AND index_name='idx_acyle_status_occurred' AND column_name='to_status'))
);
SET @acr_target_checks := (
    SELECT COUNT(*) = 12 FROM information_schema.table_constraints
    WHERE table_schema='alrowad_uni_rust' AND constraint_type='CHECK'
      AND ((table_name='academic_years' AND constraint_name='chk_ay_calendar_lifecycle_status')
        OR (table_name='academic_calendar_event_types' AND constraint_name IN ('chk_acet_kind','chk_acet_flags'))
        OR (table_name='academic_calendar_events' AND constraint_name='chk_ace_cancellation')
        OR (table_name='academic_calendar_event_versions' AND constraint_name IN ('chk_acev_version_number','chk_acev_window','chk_acev_enforcement','chk_acev_change_reason','chk_acev_publication'))
        OR (table_name='academic_calendar_year_lifecycle_events' AND constraint_name IN ('chk_acyle_from_status','chk_acyle_to_status','chk_acyle_reason')))
);
SET @acr_target_states := (
    SELECT COUNT(*) = 5 FROM information_schema.columns
    WHERE table_schema='alrowad_uni_rust' AND data_type='varchar'
      AND ((table_name='academic_years' AND column_name='calendar_lifecycle_status')
        OR (table_name='academic_calendar_event_types' AND column_name='event_type_kind')
        OR (table_name='academic_calendar_event_versions' AND column_name='publication_status')
        OR (table_name='academic_calendar_year_lifecycle_events' AND column_name IN ('from_status','to_status')))
);
SET @acr_rows_sql := IF(@acr_tables_ok,
 'SELECT
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events`),
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions`),
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years` WHERE calendar_lifecycle_status NOT IN (''draft'',''active'',''closed'')),
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_types` WHERE event_type_kind NOT IN (''system'',''general'')),
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` WHERE (from_status IS NOT NULL AND from_status NOT IN (''draft'',''active'',''closed'')) OR to_status NOT IN (''draft'',''active'',''closed''))
  INTO @acr_event_rows,@acr_version_rows,@acr_bad_year_states,@acr_bad_type_states,@acr_bad_history_states',
 'SELECT 1,1,1,1,1 INTO @acr_event_rows,@acr_version_rows,@acr_bad_year_states,@acr_bad_type_states,@acr_bad_history_states');
PREPARE acr_rollback_rows FROM @acr_rows_sql; EXECUTE acr_rollback_rows; DEALLOCATE PREPARE acr_rollback_rows;

SET @acr_rollback_ready := @acr_tables_ok AND @acr_exact_calendar_columns
    AND @acr_target_context AND @acr_target_context_fks AND @acr_target_repair_indexes
    AND @acr_target_checks AND @acr_target_states
    AND @acr_event_rows=0 AND @acr_version_rows=0
    AND @acr_bad_year_states=0 AND @acr_bad_type_states=0 AND @acr_bad_history_states=0;

SELECT 'ROLLBACK_PREFLIGHT' AS report_section,
       CASE
         WHEN @acr_event_rows>0 OR @acr_version_rows>0 THEN 'BLOCKED_IN_USE'
         WHEN NOT @acr_rollback_ready THEN 'BLOCKED_CONFLICTING_STRUCTURE'
         ELSE 'READY'
       END AS result,
       @acr_event_rows AS logical_event_rows,
       @acr_version_rows AS revision_rows;

SET @acr_sql := IF(@acr_rollback_ready,
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events`
    DROP FOREIGN KEY `fk_ace_semester`,
    DROP FOREIGN KEY `fk_ace_event_type`,
    DROP CONSTRAINT `chk_ace_cancellation`,
    DROP INDEX `idx_ace_year_semester`,
    DROP INDEX `idx_ace_event_type`,
    DROP COLUMN `semester_id`,
    DROP COLUMN `academic_calendar_event_type_id`,
    ADD KEY `idx_ace_year` (`academic_year_id`)',
 'SELECT ''SKIPPED_ROLLBACK_EVENT_CONTEXT'' AS rollback_step');
PREPARE acr_rollback_step FROM @acr_sql; EXECUTE acr_rollback_step; DEALLOCATE PREPARE acr_rollback_step;

SET @acr_sql := IF(@acr_rollback_ready,
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions`
    DROP CONSTRAINT `chk_acev_version_number`,
    DROP CONSTRAINT `chk_acev_window`,
    DROP CONSTRAINT `chk_acev_enforcement`,
    DROP CONSTRAINT `chk_acev_change_reason`,
    DROP CONSTRAINT `chk_acev_publication`,
    ADD COLUMN `semester_id` INT NULL AFTER `replaces_version_id`,
    ADD COLUMN `academic_calendar_event_type_id` INT NOT NULL AFTER `semester_id`,
    MODIFY COLUMN `publication_status` ENUM(''draft'',''published'',''superseded'') NOT NULL DEFAULT ''draft'',
    ADD KEY `idx_acev_semester` (`semester_id`),
    ADD KEY `idx_acev_event_type` (`academic_calendar_event_type_id`),
    ADD CONSTRAINT `fk_acev_semester` FOREIGN KEY (`semester_id`) REFERENCES `alrowad_uni_rust`.`semesters` (`semester_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT `fk_acev_event_type` FOREIGN KEY (`academic_calendar_event_type_id`) REFERENCES `alrowad_uni_rust`.`academic_calendar_event_types` (`academic_calendar_event_type_id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
 'SELECT ''SKIPPED_ROLLBACK_VERSION_CONTEXT'' AS rollback_step');
PREPARE acr_rollback_step FROM @acr_sql; EXECUTE acr_rollback_step; DEALLOCATE PREPARE acr_rollback_step;

SET @acr_sql := IF(@acr_rollback_ready,
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_types`
    DROP CONSTRAINT `chk_acet_kind`,
    DROP CONSTRAINT `chk_acet_flags`,
    MODIFY COLUMN `event_type_kind` ENUM(''system'',''general'') NOT NULL',
 'SELECT ''SKIPPED_ROLLBACK_EVENT_TYPES'' AS rollback_step');
PREPARE acr_rollback_step FROM @acr_sql; EXECUTE acr_rollback_step; DEALLOCATE PREPARE acr_rollback_step;

SET @acr_sql := IF(@acr_rollback_ready,
 'ALTER TABLE `alrowad_uni_rust`.`academic_years`
    DROP CONSTRAINT `chk_ay_calendar_lifecycle_status`,
    MODIFY COLUMN `calendar_lifecycle_status` ENUM(''draft'',''active'',''closed'') NOT NULL DEFAULT ''draft'' COMMENT ''[academic-calendar-phase1] calendar lifecycle''',
 'SELECT ''SKIPPED_ROLLBACK_YEAR_STATE'' AS rollback_step');
PREPARE acr_rollback_step FROM @acr_sql; EXECUTE acr_rollback_step; DEALLOCATE PREPARE acr_rollback_step;

SET @acr_sql := IF(@acr_rollback_ready,
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events`
    DROP CONSTRAINT `chk_acyle_from_status`,
    DROP CONSTRAINT `chk_acyle_to_status`,
    DROP CONSTRAINT `chk_acyle_reason`,
    DROP INDEX `idx_acyle_status_occurred`,
    MODIFY COLUMN `from_status` ENUM(''draft'',''active'',''closed'') NULL,
    MODIFY COLUMN `to_status` ENUM(''draft'',''active'',''closed'') NOT NULL,
    ADD KEY `idx_acyle_to_status` (`to_status`,`occurred_at`)',
 'SELECT ''SKIPPED_ROLLBACK_LIFECYCLE_STATE'' AS rollback_step');
PREPARE acr_rollback_step FROM @acr_sql; EXECUTE acr_rollback_step; DEALLOCATE PREPARE acr_rollback_step;

SET @acr_sql := IF(@acr_rollback_ready, 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_types` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci, COMMENT=''''', 'SELECT ''SKIPPED_ROLLBACK_TYPES_COMMENT'' AS rollback_step');
PREPARE acr_rollback_step FROM @acr_sql; EXECUTE acr_rollback_step; DEALLOCATE PREPARE acr_rollback_step;
SET @acr_sql := IF(@acr_rollback_ready, 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci, COMMENT=''''', 'SELECT ''SKIPPED_ROLLBACK_EVENTS_COMMENT'' AS rollback_step');
PREPARE acr_rollback_step FROM @acr_sql; EXECUTE acr_rollback_step; DEALLOCATE PREPARE acr_rollback_step;
SET @acr_sql := IF(@acr_rollback_ready, 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci, COMMENT=''''', 'SELECT ''SKIPPED_ROLLBACK_VERSIONS_COMMENT'' AS rollback_step');
PREPARE acr_rollback_step FROM @acr_sql; EXECUTE acr_rollback_step; DEALLOCATE PREPARE acr_rollback_step;
SET @acr_sql := IF(@acr_rollback_ready, 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci, COMMENT=''''', 'SELECT ''SKIPPED_ROLLBACK_LIFECYCLE_COMMENT'' AS rollback_step');
PREPARE acr_rollback_step FROM @acr_sql; EXECUTE acr_rollback_step; DEALLOCATE PREPARE acr_rollback_step;

SELECT 'ROLLBACK_RESULT' AS report_section,
       IF(@acr_rollback_ready,'ROLLED_BACK','BLOCKED') AS result;
