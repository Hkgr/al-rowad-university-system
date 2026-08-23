-- Deployment rollback only. It never restores or edits official academic grades.
SET @phase6_owner := 'owned:supplementary-exam-materialization-phase6';
SET @phase6_permission := 'supplementary_exams.results.materialize';
SET @phase6_noop := 0;

SET @mat_any := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materializations'
);
SET @event_any := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materialization_events'
);
SET @mat_exists := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materializations'
      AND table_type = 'BASE TABLE'
);
SET @event_exists := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materialization_events'
      AND table_type = 'BASE TABLE'
);
SET @period_table_exists := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_periods'
      AND table_type = 'BASE TABLE'
);
SET @period_event_table_exists := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_period_events'
      AND table_type = 'BASE TABLE'
);
SET @rbac_tables_ready := (
    SELECT COUNT(*) = 3 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust' AND table_type = 'BASE TABLE'
      AND table_name IN ('permissions', 'role_permissions', 'roles')
);

SET @mat_rows := 0;
SET @event_rows := 0;
SET @terminal_periods := 0;
SET @terminal_events := 0;
SET @sql := IF(
    @mat_exists,
    'SELECT COUNT(*) INTO @mat_rows FROM `alrowad_uni_rust`.`supplementary_exam_materializations`',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_rollback_count_materializations FROM @sql;
EXECUTE phase6_rollback_count_materializations;
DEALLOCATE PREPARE phase6_rollback_count_materializations;

SET @sql := IF(
    @event_exists,
    'SELECT COUNT(*) INTO @event_rows FROM `alrowad_uni_rust`.`supplementary_exam_materialization_events`',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_rollback_count_events FROM @sql;
EXECUTE phase6_rollback_count_events;
DEALLOCATE PREPARE phase6_rollback_count_events;

SET @sql := IF(
    @period_table_exists,
    'SELECT COUNT(*) INTO @terminal_periods FROM `alrowad_uni_rust`.`supplementary_exam_periods` WHERE status = ''results_materialized''',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_rollback_count_periods FROM @sql;
EXECUTE phase6_rollback_count_periods;
DEALLOCATE PREPARE phase6_rollback_count_periods;

SET @sql := IF(
    @period_event_table_exists,
    'SELECT COUNT(*) INTO @terminal_events FROM `alrowad_uni_rust`.`supplementary_exam_period_events` WHERE event_type = ''results_materialized'' OR to_status = ''results_materialized''',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_rollback_count_period_events FROM @sql;
EXECUTE phase6_rollback_count_period_events;
DEALLOCATE PREPARE phase6_rollback_count_period_events;

SET @owned_tables := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name IN ('supplementary_exam_materializations', 'supplementary_exam_materialization_events')
      AND table_type = 'BASE TABLE'
      AND table_comment = 'owned:supplementary-exam-materialization-phase6'
);
SET @present_tables := @mat_any + @event_any;
SET @adopted_tables := @present_tables - @owned_tables;

SET @permission_exists := 0;
SET @permission_owned := 0;
SET @sql := IF(
    @rbac_tables_ready,
    'SELECT (COUNT(*) = 1), (COALESCE(SUM(description = ''owned:supplementary-exam-materialization-phase6''), 0) = 1) INTO @permission_exists, @permission_owned FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''supplementary_exams.results.materialize''',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_rollback_permission_state FROM @sql;
EXECUTE phase6_rollback_permission_state;
DEALLOCATE PREPARE phase6_rollback_permission_state;

SET @in_use := (@mat_rows + @event_rows + @terminal_periods + @terminal_events) > 0;
SET @adopted_objects := @adopted_tables > 0 OR (@permission_exists AND NOT @permission_owned);
SET @owned_objects := @owned_tables + @permission_owned;
SET @can_rollback := NOT @in_use AND NOT @adopted_objects AND @owned_objects > 0;

START TRANSACTION;
SET @sql := IF(
    @can_rollback AND @permission_owned,
    'DELETE rp FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE p.permission_code = ''supplementary_exams.results.materialize'' AND p.description = ''owned:supplementary-exam-materialization-phase6''',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_rollback_delete_mapping FROM @sql;
EXECUTE phase6_rollback_delete_mapping;
DEALLOCATE PREPARE phase6_rollback_delete_mapping;

SET @sql := IF(
    @can_rollback AND @permission_owned,
    'DELETE FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''supplementary_exams.results.materialize'' AND description = ''owned:supplementary-exam-materialization-phase6''',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_rollback_delete_permission FROM @sql;
EXECUTE phase6_rollback_delete_permission;
DEALLOCATE PREPARE phase6_rollback_delete_permission;
COMMIT;

SET @sql := IF(
    @can_rollback AND @event_exists,
    'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_materialization_events`',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_rollback_drop_events FROM @sql;
EXECUTE phase6_rollback_drop_events;
DEALLOCATE PREPARE phase6_rollback_drop_events;

SET @sql := IF(
    @can_rollback AND @mat_exists,
    'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_materializations`',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_rollback_drop_materializations FROM @sql;
EXECUTE phase6_rollback_drop_materializations;
DEALLOCATE PREPARE phase6_rollback_drop_materializations;

-- This is the only visible operator result.
SELECT 'ROLLBACK_RESULT' AS report_section,
    IF(
        @in_use,
        'BLOCKED_IN_USE',
        IF(
            @adopted_objects,
            'BLOCKED_ADOPTED',
            IF(@can_rollback, 'ROLLED_BACK', 'NOTHING_TO_DO')
        )
    ) AS result;
