-- Academic Calendar Phase 1 conservative rollback.
-- Intended only before calendar data is used in production.
-- Existing academic_years, semesters, course_offerings, users, grading, and
-- supplementary-examination objects are never dropped or recreated.

SET @ac1_target_table_count := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
);
SET @ac1_owned_table_count := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_type = 'BASE TABLE'
      AND table_comment LIKE '%[academic-calendar-phase1]%'
      AND table_name IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
);

SET @ac1_status_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_lifecycle_status'
);
SET @ac1_status_owned := (
    SELECT COUNT(*) = 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_lifecycle_status'
      AND column_comment LIKE '%[academic-calendar-phase1]%'
);
SET @ac1_slot_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_active_slot'
);
SET @ac1_slot_owned := (
    SELECT COUNT(*) = 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_active_slot'
      AND column_comment LIKE '%[academic-calendar-phase1]%'
);
SET @ac1_check_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND constraint_name = 'chk_ay_calendar_lifecycle_status'
      AND constraint_type = 'CHECK'
);
SET @ac1_unique_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND index_name = 'uq_ay_calendar_active_slot'
      AND non_unique = 0
      AND column_name = 'calendar_active_slot'
);

SET @ac1_events_table := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_events'
      AND table_type = 'BASE TABLE'
);
SET @ac1_versions_table := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
      AND table_type = 'BASE TABLE'
);
SET @ac1_year_events_table := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_year_lifecycle_events'
      AND table_type = 'BASE TABLE'
);
SET @ac1_types_table := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_types'
      AND table_type = 'BASE TABLE'
);

SET @ac1_sql := IF(
    @ac1_events_table,
    'SELECT COUNT(*) INTO @ac1_event_rows FROM `alrowad_uni_rust`.`academic_calendar_events`',
    'SELECT 0 INTO @ac1_event_rows'
);
PREPARE ac1_rb_event_rows FROM @ac1_sql;
EXECUTE ac1_rb_event_rows;
DEALLOCATE PREPARE ac1_rb_event_rows;

SET @ac1_sql := IF(
    @ac1_versions_table,
    'SELECT COUNT(*) INTO @ac1_version_rows FROM `alrowad_uni_rust`.`academic_calendar_event_versions`',
    'SELECT 0 INTO @ac1_version_rows'
);
PREPARE ac1_rb_version_rows FROM @ac1_sql;
EXECUTE ac1_rb_version_rows;
DEALLOCATE PREPARE ac1_rb_version_rows;

SET @ac1_sql := IF(
    @ac1_year_events_table,
    'SELECT COUNT(*) INTO @ac1_lifecycle_event_rows FROM `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events`',
    'SELECT 0 INTO @ac1_lifecycle_event_rows'
);
PREPARE ac1_rb_lifecycle_rows FROM @ac1_sql;
EXECUTE ac1_rb_lifecycle_rows;
DEALLOCATE PREPARE ac1_rb_lifecycle_rows;

SET @ac1_sql := IF(
    @ac1_types_table,
    'SELECT COUNT(*) INTO @ac1_custom_type_rows
     FROM `alrowad_uni_rust`.`academic_calendar_event_types`
     WHERE event_type_code NOT IN (
       ''admission_registration'', ''course_registration'', ''withdrawal'',
       ''study_period'', ''exam_preparation'', ''practical_exams'',
       ''theoretical_exams'', ''grade_appeals'', ''supplementary_exams'',
       ''university_break'', ''preparation_period'', ''holiday'', ''general_event''
     )',
    'SELECT 0 INTO @ac1_custom_type_rows'
);
PREPARE ac1_rb_custom_types FROM @ac1_sql;
EXECUTE ac1_rb_custom_types;
DEALLOCATE PREPARE ac1_rb_custom_types;

SET @ac1_any_artifact := @ac1_target_table_count > 0
    OR @ac1_status_exists > 0
    OR @ac1_slot_exists > 0
    OR @ac1_check_exists > 0
    OR @ac1_unique_exists > 0;
SET @ac1_in_use := @ac1_event_rows > 0
    OR @ac1_version_rows > 0
    OR @ac1_lifecycle_event_rows > 0
    OR @ac1_custom_type_rows > 0;
SET @ac1_has_adopted := @ac1_target_table_count <> @ac1_owned_table_count
    OR (@ac1_status_exists > 0 AND NOT @ac1_status_owned)
    OR (@ac1_slot_exists > 0 AND NOT @ac1_slot_owned)
    OR (@ac1_status_exists = 0 AND (@ac1_check_exists > 0 OR @ac1_unique_exists > 0));

SET @ac1_rollback_status := CASE
    WHEN NOT @ac1_any_artifact THEN 'NOTHING_TO_DO'
    WHEN @ac1_in_use THEN 'BLOCKED_IN_USE'
    WHEN @ac1_has_adopted THEN 'BLOCKED_ADOPTED'
    ELSE 'READY'
END;

SELECT 'ROLLBACK_PREFLIGHT' AS report_section,
       @ac1_rollback_status AS result,
       @ac1_event_rows AS logical_event_rows,
       @ac1_version_rows AS revision_rows,
       @ac1_lifecycle_event_rows AS year_lifecycle_history_rows,
       @ac1_custom_type_rows AS custom_event_type_rows,
       'Rollback never removes existing academic years, semesters, offerings, grades, or supplementary data.' AS operator_note;

SET @ac1_sql := IF(
    @ac1_rollback_status = 'READY' AND @ac1_versions_table,
    'DROP TABLE `alrowad_uni_rust`.`academic_calendar_event_versions`',
    'SELECT ''SKIPPED_DROP_EVENT_VERSIONS'' AS rollback_step'
);
PREPARE ac1_rb_drop_versions FROM @ac1_sql;
EXECUTE ac1_rb_drop_versions;
DEALLOCATE PREPARE ac1_rb_drop_versions;

SET @ac1_sql := IF(
    @ac1_rollback_status = 'READY' AND @ac1_events_table,
    'DROP TABLE `alrowad_uni_rust`.`academic_calendar_events`',
    'SELECT ''SKIPPED_DROP_EVENTS'' AS rollback_step'
);
PREPARE ac1_rb_drop_events FROM @ac1_sql;
EXECUTE ac1_rb_drop_events;
DEALLOCATE PREPARE ac1_rb_drop_events;

SET @ac1_sql := IF(
    @ac1_rollback_status = 'READY' AND @ac1_year_events_table,
    'DROP TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events`',
    'SELECT ''SKIPPED_DROP_YEAR_LIFECYCLE_EVENTS'' AS rollback_step'
);
PREPARE ac1_rb_drop_year_events FROM @ac1_sql;
EXECUTE ac1_rb_drop_year_events;
DEALLOCATE PREPARE ac1_rb_drop_year_events;

SET @ac1_sql := IF(
    @ac1_rollback_status = 'READY' AND @ac1_types_table,
    'DROP TABLE `alrowad_uni_rust`.`academic_calendar_event_types`',
    'SELECT ''SKIPPED_DROP_EVENT_TYPES'' AS rollback_step'
);
PREPARE ac1_rb_drop_types FROM @ac1_sql;
EXECUTE ac1_rb_drop_types;
DEALLOCATE PREPARE ac1_rb_drop_types;

SET @ac1_sql := IF(
    @ac1_rollback_status = 'READY' AND @ac1_unique_exists = 1 AND @ac1_slot_owned,
    'ALTER TABLE `alrowad_uni_rust`.`academic_years`
       DROP INDEX `uq_ay_calendar_active_slot`',
    'SELECT ''SKIPPED_DROP_ACTIVE_UNIQUE'' AS rollback_step'
);
PREPARE ac1_rb_drop_unique FROM @ac1_sql;
EXECUTE ac1_rb_drop_unique;
DEALLOCATE PREPARE ac1_rb_drop_unique;

SET @ac1_sql := IF(
    @ac1_rollback_status = 'READY' AND @ac1_slot_exists = 1 AND @ac1_slot_owned,
    'ALTER TABLE `alrowad_uni_rust`.`academic_years`
       DROP COLUMN `calendar_active_slot`',
    'SELECT ''SKIPPED_DROP_ACTIVE_SLOT'' AS rollback_step'
);
PREPARE ac1_rb_drop_slot FROM @ac1_sql;
EXECUTE ac1_rb_drop_slot;
DEALLOCATE PREPARE ac1_rb_drop_slot;

SET @ac1_sql := IF(
    @ac1_rollback_status = 'READY' AND @ac1_check_exists = 1 AND @ac1_status_owned,
    'ALTER TABLE `alrowad_uni_rust`.`academic_years`
       DROP CONSTRAINT `chk_ay_calendar_lifecycle_status`',
    'SELECT ''SKIPPED_DROP_LIFECYCLE_CHECK'' AS rollback_step'
);
PREPARE ac1_rb_drop_check FROM @ac1_sql;
EXECUTE ac1_rb_drop_check;
DEALLOCATE PREPARE ac1_rb_drop_check;

SET @ac1_sql := IF(
    @ac1_rollback_status = 'READY' AND @ac1_status_exists = 1 AND @ac1_status_owned,
    'ALTER TABLE `alrowad_uni_rust`.`academic_years`
       DROP COLUMN `calendar_lifecycle_status`',
    'SELECT ''SKIPPED_DROP_LIFECYCLE_STATUS'' AS rollback_step'
);
PREPARE ac1_rb_drop_status FROM @ac1_sql;
EXECUTE ac1_rb_drop_status;
DEALLOCATE PREPARE ac1_rb_drop_status;

SET @ac1_rollback_result := CASE
    WHEN @ac1_rollback_status = 'READY' THEN 'ROLLED_BACK'
    ELSE @ac1_rollback_status
END;

SELECT 'ROLLBACK_RESULT' AS report_section,
       @ac1_rollback_result AS result;
