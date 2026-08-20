-- Manual and idempotent. Fail-closed.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- DDL commits implicitly in MariaDB. RBAC DML is transactional.
-- Do not use stored procedures, DELIMITER, SIGNAL, or DATABASE().
-- Independently recomputes the same critical safety conditions as 00_preflight.sql.
--
-- Does NOT:
--   DROP supplementary_exam_periods or supplementary_exam_results
--   DELETE period or result rows
--   fabricate Scientific VP decisions
--   create sample periods
--   map decide to vice_president / vice_president_administrative / dean / super_admin
--   map view to student_affairs / exam_affairs / exam_officer / registration_officer

SET @apply_ready := 0;
SET @phase1_complete := 0;
SET @apply_status := 'BLOCKED';

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @missing_required_columns := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'supplementary_exam_periods' AS table_name, 'supplementary_exam_period_id' AS column_name
            UNION ALL SELECT 'supplementary_exam_periods', 'academic_year_id'
            UNION ALL SELECT 'supplementary_exam_periods', 'semester_id'
            UNION ALL SELECT 'supplementary_exam_periods', 'period_name'
            UNION ALL SELECT 'supplementary_exam_periods', 'start_date'
            UNION ALL SELECT 'supplementary_exam_periods', 'end_date'
            UNION ALL SELECT 'supplementary_exam_periods', 'is_active'
            UNION ALL SELECT 'supplementary_exam_results', 'supplementary_exam_result_id'
            UNION ALL SELECT 'users', 'user_id'
            UNION ALL SELECT 'academic_years', 'academic_year_id'
            UNION ALL SELECT 'semesters', 'semester_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'role_permissions', 'role_id'
            UNION ALL SELECT 'system_modules', 'module_code'
        ) required_columns
        LEFT JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = required_columns.table_name
           AND existing.column_name = required_columns.column_name
        WHERE existing.column_name IS NULL
    ),
    1
);

SET @structure_ok := IF(@db_ready = 1 AND @missing_required_columns = 0, 1, 0);

SET @periods_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND table_type = 'BASE TABLE'), 0);
SET @results_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_results' AND table_type = 'BASE TABLE'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND table_type = 'BASE TABLE'), 0);

SET @status_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status'), 0);
SET @opened_by_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id'), 0);
SET @opened_at_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_at'), 0);
SET @decision_note_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'decision_note'), 0);

SET @status_state := CASE
    WHEN @status_exists = 0 THEN 'ABSENT'
    WHEN @status_exists = 1
     AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status') = 'varchar'
     AND (SELECT IFNULL(character_maximum_length, 0) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status') >= 16
     AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status') = 'NO'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;
SET @opened_by_state := CASE
    WHEN @opened_by_exists = 0 THEN 'ABSENT'
    WHEN @opened_by_exists = 1
     AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id') = 'int'
     AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id') = 'YES'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;
SET @opened_at_state := CASE
    WHEN @opened_at_exists = 0 THEN 'ABSENT'
    WHEN @opened_at_exists = 1
     AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_at') IN ('datetime', 'timestamp')
     AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_at') = 'YES'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;
SET @decision_note_state := CASE
    WHEN @decision_note_exists = 0 THEN 'ABSENT'
    WHEN @decision_note_exists = 1
     AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'decision_note') IN ('text', 'varchar', 'mediumtext', 'longtext')
     AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'decision_note') = 'YES'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @fk_opened_by_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND constraint_name = 'fk_sep_opened_by'
       AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @fk_opened_by_state := CASE
    WHEN @fk_opened_by_exists = 0 THEN 'ABSENT'
    WHEN @fk_opened_by_exists = 1
     AND (SELECT COUNT(*) FROM information_schema.key_column_usage
          WHERE table_schema = 'alrowad_uni_rust'
            AND table_name = 'supplementary_exam_periods'
            AND constraint_name = 'fk_sep_opened_by'
            AND column_name = 'opened_by_user_id'
            AND referenced_table_name = 'users'
            AND referenced_column_name = 'user_id') = 1
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @uq_name_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND index_name = 'uq_sep_year_semester'),
    0
);
SET @uq_cols := IF(
    @uq_name_exists = 1,
    (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_periods'
          AND index_name = 'uq_sep_year_semester'
          AND non_unique = 0
    ),
    NULL
);
SET @identity_unique_other := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT index_name
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'supplementary_exam_periods'
              AND non_unique = 0
              AND index_name <> 'PRIMARY'
            GROUP BY index_name
            HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'academic_year_id,semester_id'
        ) existing_identity
    ),
    0
);
SET @unique_state := CASE
    WHEN @uq_name_exists = 0 AND @identity_unique_other = 0 THEN 'ABSENT'
    WHEN @uq_cols <=> 'academic_year_id,semester_id' OR @identity_unique_other > 0 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @events_expected_cols := IF(
    @events_exist = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'supplementary_exam_period_event_id' AS column_name
            UNION ALL SELECT 'supplementary_exam_period_id'
            UNION ALL SELECT 'event_type'
            UNION ALL SELECT 'from_status'
            UNION ALL SELECT 'to_status'
            UNION ALL SELECT 'actor_user_id'
            UNION ALL SELECT 'notes'
            UNION ALL SELECT 'created_at'
        ) expected
        JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = 'supplementary_exam_period_events'
           AND existing.column_name = expected.column_name
    ),
    0
);
SET @events_state := IF(@events_exist = 0, 'ABSENT', IF(@events_exist = 1 AND @events_expected_cols = 8, 'COMPATIBLE', 'CONFLICT'));

SET @idx_status_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND index_name = 'idx_sep_status'),
    0
);
SET @idx_status_state := CASE
    WHEN @idx_status_exists = 0 THEN 'ABSENT'
    WHEN @idx_status_exists = 1
     AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_periods'
          AND index_name = 'idx_sep_status'
     ) <=> 'status'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @sci_role := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_scientific' AND is_active = 1), 0);
SET @dean_role := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'dean' AND is_active = 1), 0);
SET @exams_module_ok := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'exams' AND is_active = 1), 0);

SET @view_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'supplementary_exams.periods.view'), 0);
SET @decide_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'supplementary_exams.periods.decide'), 0);
SET @view_perm_compatible := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'supplementary_exams.periods.view' AND p.is_active = 1 AND sm.module_code = 'exams'),
    0
);
SET @decide_perm_compatible := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'supplementary_exams.periods.decide' AND p.is_active = 1 AND sm.module_code = 'exams'),
    0
);
SET @view_perm_state := IF(@view_perm_rows = 0, 'ABSENT', IF(@view_perm_rows = 1 AND @view_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @decide_perm_state := IF(@decide_perm_rows = 0, 'ABSENT', IF(@decide_perm_rows = 1 AND @decide_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));

SET @rbac_matrix_conflict := IF(
    @structure_ok = 1,
    (
        SELECT IF(COUNT(*) > 0, 1, 0)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code IN (
            'supplementary_exams.periods.view',
            'supplementary_exams.periods.decide'
        )
          AND NOT (
              (
                  p.permission_code = 'supplementary_exams.periods.view'
                  AND r.role_code IN ('dean', 'vice_president_scientific')
              )
              OR (
                  p.permission_code = 'supplementary_exams.periods.decide'
                  AND r.role_code = 'vice_president_scientific'
              )
          )
    ),
    0
);

SET @duplicate_pairs := 0;
SET @orphan_years := 0;
SET @orphan_semesters := 0;
SET @period_rows_before := 0;
SET @result_rows_before := 0;

SET @sql := IF(@periods_exist = 1, 'SELECT @period_rows_before := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods`', 'SELECT @period_rows_before := 0');
PREPARE phase1_ap_period_before FROM @sql;
EXECUTE phase1_ap_period_before;
DEALLOCATE PREPARE phase1_ap_period_before;

SET @sql := IF(@results_exist = 1, 'SELECT @result_rows_before := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_results`', 'SELECT @result_rows_before := 0');
PREPARE phase1_ap_result_before FROM @sql;
EXECUTE phase1_ap_result_before;
DEALLOCATE PREPARE phase1_ap_result_before;

SET @sql := IF(
    @periods_exist = 1,
    'SELECT @duplicate_pairs := COUNT(*) FROM (SELECT academic_year_id, semester_id FROM `alrowad_uni_rust`.`supplementary_exam_periods` GROUP BY academic_year_id, semester_id HAVING COUNT(*) > 1) d',
    'SELECT @duplicate_pairs := 0'
);
PREPARE phase1_ap_dup FROM @sql;
EXECUTE phase1_ap_dup;
DEALLOCATE PREPARE phase1_ap_dup;

SET @sql := IF(
    @periods_exist = 1,
    'SELECT @orphan_years := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods` p LEFT JOIN `alrowad_uni_rust`.`academic_years` y ON y.academic_year_id = p.academic_year_id WHERE y.academic_year_id IS NULL',
    'SELECT @orphan_years := 0'
);
PREPARE phase1_ap_orphan_year FROM @sql;
EXECUTE phase1_ap_orphan_year;
DEALLOCATE PREPARE phase1_ap_orphan_year;

SET @sql := IF(
    @periods_exist = 1,
    'SELECT @orphan_semesters := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods` p LEFT JOIN `alrowad_uni_rust`.`semesters` s ON s.semester_id = p.semester_id WHERE s.semester_id IS NULL',
    'SELECT @orphan_semesters := 0'
);
PREPARE phase1_ap_orphan_sem FROM @sql;
EXECUTE phase1_ap_orphan_sem;
DEALLOCATE PREPARE phase1_ap_orphan_sem;

SET @phase1_conflict := IF(
    @status_state = 'CONFLICT' OR @opened_by_state = 'CONFLICT' OR @opened_at_state = 'CONFLICT'
    OR @decision_note_state = 'CONFLICT' OR @fk_opened_by_state = 'CONFLICT' OR @unique_state = 'CONFLICT'
    OR @events_state = 'CONFLICT' OR @idx_status_state = 'CONFLICT'
    OR @view_perm_state = 'CONFLICT' OR @decide_perm_state = 'CONFLICT',
    1, 0
);

SET @apply_ready := IF(
    @structure_ok = 1
    AND @periods_exist = 1
    AND @results_exist = 1
    AND @sci_role = 1
    AND @dean_role = 1
    AND @exams_module_ok = 1
    AND @duplicate_pairs = 0
    AND @orphan_years = 0
    AND @orphan_semesters = 0
    AND @rbac_matrix_conflict = 0
    AND @phase1_conflict = 0,
    1, 0
);

SELECT 'APPLY_GUARD' AS report_section,
       IF(@apply_ready = 1, 'READY', 'BLOCKED') AS apply_ready,
       @duplicate_pairs AS duplicate_pairs,
       @orphan_years AS orphan_years,
       @orphan_semesters AS orphan_semesters,
       @rbac_matrix_conflict AS rbac_matrix_conflict,
       @phase1_conflict AS phase1_conflict,
       @period_rows_before AS period_rows_before,
       @result_rows_before AS result_rows_before;

SET @sql := IF(
    @apply_ready = 1 AND @status_state = 'ABSENT',
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods`
        ADD COLUMN `status` VARCHAR(32) NOT NULL DEFAULT ''legacy''
        COMMENT ''[phase1-supplementary-exam-period-governance]''',
    'SELECT ''SKIPPED_STATUS'' AS apply_result'
);
PREPARE phase1_status_stmt FROM @sql;
EXECUTE phase1_status_stmt;
DEALLOCATE PREPARE phase1_status_stmt;

SET @sql := IF(
    @apply_ready = 1 AND @opened_by_state = 'ABSENT',
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods`
        ADD COLUMN `opened_by_user_id` INT NULL DEFAULT NULL
        COMMENT ''[phase1-supplementary-exam-period-governance]''',
    'SELECT ''SKIPPED_OPENED_BY'' AS apply_result'
);
PREPARE phase1_opened_by_stmt FROM @sql;
EXECUTE phase1_opened_by_stmt;
DEALLOCATE PREPARE phase1_opened_by_stmt;

SET @sql := IF(
    @apply_ready = 1 AND @opened_at_state = 'ABSENT',
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods`
        ADD COLUMN `opened_at` DATETIME NULL DEFAULT NULL
        COMMENT ''[phase1-supplementary-exam-period-governance]''',
    'SELECT ''SKIPPED_OPENED_AT'' AS apply_result'
);
PREPARE phase1_opened_at_stmt FROM @sql;
EXECUTE phase1_opened_at_stmt;
DEALLOCATE PREPARE phase1_opened_at_stmt;

SET @sql := IF(
    @apply_ready = 1 AND @decision_note_state = 'ABSENT',
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods`
        ADD COLUMN `decision_note` TEXT NULL
        COMMENT ''[phase1-supplementary-exam-period-governance]''',
    'SELECT ''SKIPPED_DECISION_NOTE'' AS apply_result'
);
PREPARE phase1_note_stmt FROM @sql;
EXECUTE phase1_note_stmt;
DEALLOCATE PREPARE phase1_note_stmt;

SET @status_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status'), 0);
SET @sql := IF(
    @apply_ready = 1 AND @status_exists = 1,
    'UPDATE `alrowad_uni_rust`.`supplementary_exam_periods` SET `status` = ''legacy'' WHERE `status` IS NULL OR TRIM(`status`) = ''''',
    'SELECT ''SKIPPED_STATUS_BACKFILL'' AS apply_result'
);
PREPARE phase1_status_backfill FROM @sql;
EXECUTE phase1_status_backfill;
DEALLOCATE PREPARE phase1_status_backfill;

SET @opened_by_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id'), 0);
SET @fk_opened_by_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND constraint_name = 'fk_sep_opened_by'
       AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @sql := IF(
    @apply_ready = 1 AND @fk_opened_by_exists = 0 AND @opened_by_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods`
        ADD CONSTRAINT `fk_sep_opened_by`
        FOREIGN KEY (`opened_by_user_id`)
        REFERENCES `alrowad_uni_rust`.`users` (`user_id`)',
    'SELECT ''SKIPPED_FK_OPENED_BY'' AS apply_result'
);
PREPARE phase1_fk_opened_by FROM @sql;
EXECUTE phase1_fk_opened_by;
DEALLOCATE PREPARE phase1_fk_opened_by;

SET @status_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status'), 0);
SET @idx_status_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND index_name = 'idx_sep_status'),
    0
);
SET @sql := IF(
    @apply_ready = 1 AND @idx_status_exists = 0 AND @status_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods`
        ADD INDEX `idx_sep_status` (`status`)',
    'SELECT ''SKIPPED_IDX_STATUS'' AS apply_result'
);
PREPARE phase1_idx_status FROM @sql;
EXECUTE phase1_idx_status;
DEALLOCATE PREPARE phase1_idx_status;

SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND table_type = 'BASE TABLE'), 0);
SET @sql := IF(
    @apply_ready = 1 AND @events_exist = 0,
    'CREATE TABLE `alrowad_uni_rust`.`supplementary_exam_period_events` (
        `supplementary_exam_period_event_id` INT NOT NULL AUTO_INCREMENT,
        `supplementary_exam_period_id` INT NOT NULL,
        `event_type` VARCHAR(64) NOT NULL,
        `from_status` VARCHAR(32) NULL,
        `to_status` VARCHAR(32) NOT NULL,
        `actor_user_id` INT NOT NULL,
        `notes` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`supplementary_exam_period_event_id`),
        KEY `idx_sepe_period` (`supplementary_exam_period_id`),
        KEY `idx_sepe_actor` (`actor_user_id`),
        KEY `idx_sepe_event_type` (`event_type`, `to_status`),
        CONSTRAINT `fk_sepe_period` FOREIGN KEY (`supplementary_exam_period_id`) REFERENCES `alrowad_uni_rust`.`supplementary_exam_periods` (`supplementary_exam_period_id`),
        CONSTRAINT `fk_sepe_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''[phase1-supplementary-exam-period-governance]''',
    'SELECT ''SKIPPED_EVENTS'' AS apply_result'
);
PREPARE phase1_events_stmt FROM @sql;
EXECUTE phase1_events_stmt;
DEALLOCATE PREPARE phase1_events_stmt;

SET @duplicate_pairs := 0;
SET @sql := IF(
    @periods_exist = 1,
    'SELECT @duplicate_pairs := COUNT(*) FROM (SELECT academic_year_id, semester_id FROM `alrowad_uni_rust`.`supplementary_exam_periods` GROUP BY academic_year_id, semester_id HAVING COUNT(*) > 1) d',
    'SELECT @duplicate_pairs := 0'
);
PREPARE phase1_ap_dup2 FROM @sql;
EXECUTE phase1_ap_dup2;
DEALLOCATE PREPARE phase1_ap_dup2;

SET @uq_name_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND index_name = 'uq_sep_year_semester'),
    0
);
SET @identity_unique_other := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT index_name
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'supplementary_exam_periods'
              AND non_unique = 0
              AND index_name <> 'PRIMARY'
            GROUP BY index_name
            HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'academic_year_id,semester_id'
        ) existing_identity
    ),
    0
);
SET @sql := IF(
    @apply_ready = 1 AND @duplicate_pairs = 0 AND @uq_name_exists = 0 AND @identity_unique_other = 0,
    'ALTER TABLE `alrowad_uni_rust`.`supplementary_exam_periods`
        ADD UNIQUE KEY `uq_sep_year_semester` (`academic_year_id`, `semester_id`)',
    'SELECT ''SKIPPED_UNIQUE_IDENTITY'' AS apply_result'
);
PREPARE phase1_unique_stmt FROM @sql;
EXECUTE phase1_unique_stmt;
DEALLOCATE PREPARE phase1_unique_stmt;

START TRANSACTION;

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id, permission_code, permission_name, description, is_active, created_at, updated_at
)
SELECT sm.module_id, 'supplementary_exams.periods.view', 'View supplementary examination periods',
       'Read supplementary examination periods [phase1-supplementary-exam-period-governance]',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @view_perm_state = 'ABSENT'
  AND sm.module_code = 'exams'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'supplementary_exams.periods.view'
  );

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id, permission_code, permission_name, description, is_active, created_at, updated_at
)
SELECT sm.module_id, 'supplementary_exams.periods.decide', 'Announce supplementary examination periods',
       'Scientific VP announce supplementary examination periods [phase1-supplementary-exam-period-governance]',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @decide_perm_state = 'ABSENT'
  AND sm.module_code = 'exams'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'supplementary_exams.periods.decide'
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
CROSS JOIN `alrowad_uni_rust`.`permissions` p
WHERE @apply_ready = 1
  AND r.role_code IN ('vice_president_scientific', 'dean')
  AND r.is_active = 1
  AND p.permission_code = 'supplementary_exams.periods.view'
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
CROSS JOIN `alrowad_uni_rust`.`permissions` p
WHERE @apply_ready = 1
  AND r.role_code = 'vice_president_scientific'
  AND r.is_active = 1
  AND p.permission_code = 'supplementary_exams.periods.decide'
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

SET @view_mapped_sci := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'supplementary_exams.periods.view' AND p.is_active = 1),
    0
);
SET @view_mapped_dean := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'dean' AND p.permission_code = 'supplementary_exams.periods.view' AND p.is_active = 1),
    0
);
SET @decide_mapped_sci := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'supplementary_exams.periods.decide' AND p.is_active = 1),
    0
);
SET @decide_mapped_forbidden := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE p.permission_code = 'supplementary_exams.periods.decide' AND r.role_code IN ('vice_president', 'vice_president_administrative', 'dean', 'super_admin')),
    0
);

SET @phase1_complete := IF(
    @apply_ready = 1
    AND @view_mapped_sci >= 1
    AND @view_mapped_dean >= 1
    AND @decide_mapped_sci >= 1
    AND @decide_mapped_forbidden = 0,
    1, 0
);

SET @sql := IF(@phase1_complete = 1, 'COMMIT', 'ROLLBACK');
PREPARE phase1_rbac_finish FROM @sql;
EXECUTE phase1_rbac_finish;
DEALLOCATE PREPARE phase1_rbac_finish;

SET @status_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status'), 0);
SET @opened_by_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id'), 0);
SET @opened_at_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_at'), 0);
SET @decision_note_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'decision_note'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND table_type = 'BASE TABLE'), 0);
SET @uq_name_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND index_name = 'uq_sep_year_semester'),
    0
);
SET @period_rows_after := 0;
SET @result_rows_after := 0;
SET @sql := IF(@periods_exist = 1, 'SELECT @period_rows_after := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods`', 'SELECT @period_rows_after := 0');
PREPARE phase1_ap_period_after FROM @sql;
EXECUTE phase1_ap_period_after;
DEALLOCATE PREPARE phase1_ap_period_after;
SET @sql := IF(@results_exist = 1, 'SELECT @result_rows_after := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_results`', 'SELECT @result_rows_after := 0');
PREPARE phase1_ap_result_after FROM @sql;
EXECUTE phase1_ap_result_after;
DEALLOCATE PREPARE phase1_ap_result_after;

SET @apply_status := IF(
    @apply_ready = 1
    AND @status_exists = 1
    AND @opened_by_exists = 1
    AND @opened_at_exists = 1
    AND @decision_note_exists = 1
    AND @events_exist = 1
    AND @uq_name_exists = 1
    AND @phase1_complete = 1
    AND @period_rows_after = @period_rows_before
    AND @result_rows_after = @result_rows_before,
    'APPLIED',
    'BLOCKED'
);

SELECT 'APPLY_RESULT' AS report_section,
       @apply_status AS apply_status,
       @phase1_complete AS rbac_complete,
       @status_exists AS status_exists,
       @opened_by_exists AS opened_by_exists,
       @opened_at_exists AS opened_at_exists,
       @decision_note_exists AS decision_note_exists,
       @events_exist AS events_exist,
       @uq_name_exists AS unique_exists,
       @period_rows_before AS period_rows_before,
       @period_rows_after AS period_rows_after,
       @result_rows_before AS result_rows_before,
       @result_rows_after AS result_rows_after;
