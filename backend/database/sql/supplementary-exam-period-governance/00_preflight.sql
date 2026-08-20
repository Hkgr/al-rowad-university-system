-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE/ALTER application data.
-- SET user variables and reporting SELECTs only.
-- Do not use DATABASE().
--
-- Phase 1 Supplementary Examination Period governance.
-- Extends existing supplementary_exam_periods. Does not recreate that table.
-- Does not touch supplementary_exam_results.
-- New objects classified independently: ABSENT / COMPATIBLE / CONFLICT.
-- Duplicate academic_year_id+semester_id pairs BLOCK unique identity and OVERALL.
-- Unresolved Student Affairs / Exam Affairs view mappings are reported; they do not BLOCK.

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
            UNION ALL SELECT 'supplementary_exam_results', 'supplementary_exam_period_id'
            UNION ALL SELECT 'users', 'user_id'
            UNION ALL SELECT 'academic_years', 'academic_year_id'
            UNION ALL SELECT 'semesters', 'semester_id'
            UNION ALL SELECT 'roles', 'role_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'roles', 'is_active'
            UNION ALL SELECT 'permissions', 'permission_id'
            UNION ALL SELECT 'permissions', 'module_id'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'permissions', 'permission_name'
            UNION ALL SELECT 'permissions', 'description'
            UNION ALL SELECT 'permissions', 'is_active'
            UNION ALL SELECT 'role_permissions', 'role_id'
            UNION ALL SELECT 'role_permissions', 'permission_id'
            UNION ALL SELECT 'system_modules', 'module_id'
            UNION ALL SELECT 'system_modules', 'module_code'
            UNION ALL SELECT 'system_modules', 'is_active'
            UNION ALL SELECT 'user_roles', 'user_id'
            UNION ALL SELECT 'user_roles', 'role_id'
            UNION ALL SELECT 'user_access_scopes', 'scope_type'
            UNION ALL SELECT 'user_access_scopes', 'user_id'
            UNION ALL SELECT 'organizational_units', 'organizational_unit_id'
            UNION ALL SELECT 'organizational_units', 'unit_code'
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
SET @users_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'users' AND table_type = 'BASE TABLE'), 0);
SET @years_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_years' AND table_type = 'BASE TABLE'), 0);
SET @semesters_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'semesters' AND table_type = 'BASE TABLE'), 0);
SET @roles_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'roles' AND table_type = 'BASE TABLE'), 0);
SET @permissions_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'permissions' AND table_type = 'BASE TABLE'), 0);
SET @role_permissions_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'role_permissions' AND table_type = 'BASE TABLE'), 0);
SET @user_roles_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'user_roles' AND table_type = 'BASE TABLE'), 0);
SET @scopes_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'user_access_scopes' AND table_type = 'BASE TABLE'), 0);
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
     AND (SELECT LOWER(column_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id') NOT LIKE '%unsigned%'
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
SET @events_state := CASE
    WHEN @events_exist = 0 THEN 'ABSENT'
    WHEN @events_exist = 1 AND @events_expected_cols = 8 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

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
SET @vp_role := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president' AND is_active = 1), 0);
SET @adm_role := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative' AND is_active = 1), 0);
SET @dean_role := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'dean' AND is_active = 1), 0);
SET @student_affairs_role := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code IN ('student_affairs', 'students_affairs', 'شؤون_الطلاب') AND is_active = 1), 0);
SET @exam_affairs_role := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code IN ('exam_affairs', 'exams_affairs') AND is_active = 1), 0);
SET @exam_officer_role := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'exam_officer' AND is_active = 1), 0);
SET @registration_officer_role := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'registration_officer' AND is_active = 1), 0);
SET @exams_module_ok := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'exams' AND is_active = 1), 0);
SET @pres_root := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`organizational_units` WHERE unit_code = 'PRES' AND is_active = 1), 0);

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

SET @period_rows := 0;
SET @result_rows := 0;
SET @duplicate_pairs := 0;
SET @orphan_years := 0;
SET @orphan_semesters := 0;

SET @sql := IF(
    @periods_exist = 1,
    'SELECT @period_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods`',
    'SELECT @period_rows := 0'
);
PREPARE phase1_pf_period_rows FROM @sql;
EXECUTE phase1_pf_period_rows;
DEALLOCATE PREPARE phase1_pf_period_rows;

SET @sql := IF(
    @results_exist = 1,
    'SELECT @result_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_results`',
    'SELECT @result_rows := 0'
);
PREPARE phase1_pf_result_rows FROM @sql;
EXECUTE phase1_pf_result_rows;
DEALLOCATE PREPARE phase1_pf_result_rows;

SET @sql := IF(
    @periods_exist = 1,
    'SELECT @duplicate_pairs := COUNT(*) FROM (SELECT academic_year_id, semester_id FROM `alrowad_uni_rust`.`supplementary_exam_periods` GROUP BY academic_year_id, semester_id HAVING COUNT(*) > 1) d',
    'SELECT @duplicate_pairs := 0'
);
PREPARE phase1_pf_dup FROM @sql;
EXECUTE phase1_pf_dup;
DEALLOCATE PREPARE phase1_pf_dup;

SET @sql := IF(
    @periods_exist = 1 AND @years_exist = 1,
    'SELECT @orphan_years := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods` p LEFT JOIN `alrowad_uni_rust`.`academic_years` y ON y.academic_year_id = p.academic_year_id WHERE y.academic_year_id IS NULL',
    'SELECT @orphan_years := 0'
);
PREPARE phase1_pf_orphan_year FROM @sql;
EXECUTE phase1_pf_orphan_year;
DEALLOCATE PREPARE phase1_pf_orphan_year;

SET @sql := IF(
    @periods_exist = 1 AND @semesters_exist = 1,
    'SELECT @orphan_semesters := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods` p LEFT JOIN `alrowad_uni_rust`.`semesters` s ON s.semester_id = p.semester_id WHERE s.semester_id IS NULL',
    'SELECT @orphan_semesters := 0'
);
PREPARE phase1_pf_orphan_sem FROM @sql;
EXECUTE phase1_pf_orphan_sem;
DEALLOCATE PREPARE phase1_pf_orphan_sem;

SET @periods_engine_ok := IF(
    @periods_exist = 1
    AND (SELECT engine FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods') = 'InnoDB',
    1, 0
);
SET @users_engine_ok := IF(
    @users_exist = 1
    AND (SELECT engine FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'users') = 'InnoDB',
    1, 0
);

SET @alter_safe := IF(
    @structure_ok = 1
    AND @periods_engine_ok = 1
    AND @users_engine_ok = 1
    AND @status_state IN ('ABSENT', 'COMPATIBLE')
    AND @opened_by_state IN ('ABSENT', 'COMPATIBLE')
    AND @opened_at_state IN ('ABSENT', 'COMPATIBLE')
    AND @decision_note_state IN ('ABSENT', 'COMPATIBLE')
    AND @fk_opened_by_state IN ('ABSENT', 'COMPATIBLE')
    AND @unique_state IN ('ABSENT', 'COMPATIBLE')
    AND @events_state IN ('ABSENT', 'COMPATIBLE')
    AND @idx_status_state IN ('ABSENT', 'COMPATIBLE')
    AND @view_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @decide_perm_state IN ('ABSENT', 'COMPATIBLE'),
    1, 0
);

SET @unique_can_create := IF(
    @alter_safe = 1
    AND @duplicate_pairs = 0
    AND @orphan_years = 0
    AND @orphan_semesters = 0
    AND @unique_state IN ('ABSENT', 'COMPATIBLE'),
    1, 0
);

SET @phase1_conflict := IF(
    @status_state = 'CONFLICT'
    OR @opened_by_state = 'CONFLICT'
    OR @opened_at_state = 'CONFLICT'
    OR @decision_note_state = 'CONFLICT'
    OR @fk_opened_by_state = 'CONFLICT'
    OR @unique_state = 'CONFLICT'
    OR @events_state = 'CONFLICT'
    OR @idx_status_state = 'CONFLICT'
    OR @view_perm_state = 'CONFLICT'
    OR @decide_perm_state = 'CONFLICT',
    1, 0
);

SELECT 'DATABASE' AS report_section, 'alrowad_uni_rust' AS database_name, @db_ready AS db_ready;

SELECT 'A_required_tables' AS report_section, wanted.wanted AS table_name,
       IF(existing.table_name IS NULL, 0, 1) AS exists_flag, existing.engine
FROM (
    SELECT 'supplementary_exam_periods' AS wanted
    UNION ALL SELECT 'supplementary_exam_results'
    UNION ALL SELECT 'users'
    UNION ALL SELECT 'academic_years'
    UNION ALL SELECT 'semesters'
    UNION ALL SELECT 'roles'
    UNION ALL SELECT 'permissions'
    UNION ALL SELECT 'role_permissions'
    UNION ALL SELECT 'user_roles'
    UNION ALL SELECT 'user_access_scopes'
    UNION ALL SELECT 'organizational_units'
    UNION ALL SELECT 'system_modules'
    UNION ALL SELECT 'supplementary_exam_period_events'
) wanted
LEFT JOIN information_schema.tables existing
    ON existing.table_schema = 'alrowad_uni_rust'
   AND existing.table_name = wanted.wanted
   AND existing.table_type = 'BASE TABLE';

SELECT 'B_missing_required_columns' AS report_section, required_columns.table_name, required_columns.column_name
FROM (
    SELECT 'supplementary_exam_periods' AS table_name, 'supplementary_exam_period_id' AS column_name
    UNION ALL SELECT 'supplementary_exam_periods', 'academic_year_id'
    UNION ALL SELECT 'supplementary_exam_periods', 'semester_id'
    UNION ALL SELECT 'supplementary_exam_periods', 'period_name'
    UNION ALL SELECT 'supplementary_exam_periods', 'start_date'
    UNION ALL SELECT 'supplementary_exam_periods', 'end_date'
    UNION ALL SELECT 'supplementary_exam_periods', 'is_active'
    UNION ALL SELECT 'supplementary_exam_results', 'supplementary_exam_result_id'
    UNION ALL SELECT 'supplementary_exam_results', 'supplementary_exam_period_id'
    UNION ALL SELECT 'users', 'user_id'
    UNION ALL SELECT 'academic_years', 'academic_year_id'
    UNION ALL SELECT 'semesters', 'semester_id'
    UNION ALL SELECT 'roles', 'role_id'
    UNION ALL SELECT 'roles', 'role_code'
    UNION ALL SELECT 'permissions', 'permission_code'
    UNION ALL SELECT 'role_permissions', 'role_id'
    UNION ALL SELECT 'system_modules', 'module_code'
    UNION ALL SELECT 'user_roles', 'user_id'
    UNION ALL SELECT 'user_access_scopes', 'scope_type'
    UNION ALL SELECT 'organizational_units', 'unit_code'
) required_columns
LEFT JOIN information_schema.columns existing
    ON existing.table_schema = 'alrowad_uni_rust'
   AND existing.table_name = required_columns.table_name
   AND existing.column_name = required_columns.column_name
WHERE existing.column_name IS NULL
ORDER BY required_columns.table_name, required_columns.column_name;

SELECT 'C_existing_period_columns' AS report_section, column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'supplementary_exam_periods'
ORDER BY ordinal_position;

SELECT 'D_roles' AS report_section,
       @sci_role AS vice_president_scientific,
       @vp_role AS vice_president,
       @adm_role AS vice_president_administrative,
       @dean_role AS dean,
       @student_affairs_role AS student_affairs_role,
       @exam_affairs_role AS exam_affairs_role,
       @exam_officer_role AS exam_officer,
       @registration_officer_role AS registration_officer,
       @pres_root AS pres_organizational_root,
       @exams_module_ok AS exams_module;

SELECT 'E_counts' AS report_section,
       @period_rows AS supplementary_exam_periods_rows,
       @result_rows AS supplementary_exam_results_rows,
       @duplicate_pairs AS duplicate_identity_pairs,
       @orphan_years AS orphan_academic_year_id,
       @orphan_semesters AS orphan_semester_id;

SET @sql := IF(
    @periods_exist = 1 AND @duplicate_pairs > 0,
    'SELECT ''F_duplicate_identities'' AS report_section, academic_year_id, semester_id, COUNT(*) AS duplicate_count, GROUP_CONCAT(supplementary_exam_period_id ORDER BY supplementary_exam_period_id) AS supplementary_exam_period_ids FROM `alrowad_uni_rust`.`supplementary_exam_periods` GROUP BY academic_year_id, semester_id HAVING COUNT(*) > 1 ORDER BY academic_year_id, semester_id',
    'SELECT ''F_duplicate_identities'' AS report_section, NULL AS academic_year_id, NULL AS semester_id, 0 AS duplicate_count, NULL AS supplementary_exam_period_ids'
);
PREPARE phase1_pf_dup_detail FROM @sql;
EXECUTE phase1_pf_dup_detail;
DEALLOCATE PREPARE phase1_pf_dup_detail;

SELECT 'G_phase1_objects' AS report_section, object_name, object_state
FROM (
    SELECT 'status' AS object_name, @status_state AS object_state
    UNION ALL SELECT 'opened_by_user_id', @opened_by_state
    UNION ALL SELECT 'opened_at', @opened_at_state
    UNION ALL SELECT 'decision_note', @decision_note_state
    UNION ALL SELECT 'fk_sep_opened_by', @fk_opened_by_state
    UNION ALL SELECT 'uq_sep_year_semester', @unique_state
    UNION ALL SELECT 'idx_sep_status', @idx_status_state
    UNION ALL SELECT 'supplementary_exam_period_events', @events_state
    UNION ALL SELECT 'supplementary_exams.periods.view', @view_perm_state
    UNION ALL SELECT 'supplementary_exams.periods.decide', @decide_perm_state
) objects;

SELECT 'H_rbac_matrix_conflict' AS report_section, @rbac_matrix_conflict AS rbac_matrix_conflict;

SET @sql := IF(
    @structure_ok = 1,
    'SELECT DISTINCT ''H_rbac_matrix_conflict_rows'' AS report_section, r.role_code, p.permission_code
     FROM `alrowad_uni_rust`.`roles` r
     JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
     JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
     WHERE p.permission_code IN (
         ''supplementary_exams.periods.view'',
         ''supplementary_exams.periods.decide''
     )
       AND NOT (
           (
               p.permission_code = ''supplementary_exams.periods.view''
               AND r.role_code IN (''dean'', ''vice_president_scientific'')
           )
           OR (
               p.permission_code = ''supplementary_exams.periods.decide''
               AND r.role_code = ''vice_president_scientific''
           )
       )',
    'SELECT ''H_rbac_matrix_conflict_rows'' AS report_section, NULL AS role_code, NULL AS permission_code'
);
PREPARE phase1_pf_rbac_rows FROM @sql;
EXECUTE phase1_pf_rbac_rows;
DEALLOCATE PREPARE phase1_pf_rbac_rows;

SELECT 'I_unresolved_view_mapping' AS report_section,
       'UNRESOLVED' AS student_affairs,
       'No student_affairs role exists. registration_officer is not mapped in Phase 1.' AS student_affairs_detail,
       'UNRESOLVED' AS exam_affairs,
       'No exam_affairs role exists. exam_officer is not mapped in Phase 1.' AS exam_affairs_detail;

SELECT 'J_alter_safety' AS report_section,
       @alter_safe AS alter_safe,
       @unique_can_create AS unique_identity_can_be_created_safely,
       @periods_engine_ok AS periods_innodb,
       @duplicate_pairs AS duplicate_pairs,
       @orphan_years AS orphan_years,
       @orphan_semesters AS orphan_semesters;

SET @overall := IF(
    @db_ready = 1
    AND @structure_ok = 1
    AND @periods_exist = 1
    AND @results_exist = 1
    AND @sci_role = 1
    AND @dean_role = 1
    AND @exams_module_ok = 1
    AND @duplicate_pairs = 0
    AND @orphan_years = 0
    AND @orphan_semesters = 0
    AND @unique_can_create = 1
    AND @alter_safe = 1
    AND @rbac_matrix_conflict = 0
    AND @phase1_conflict = 0,
    'READY',
    'BLOCKED'
);

SELECT 'OVERALL' AS report_section,
       @overall AS result,
       @missing_required_columns AS missing_required_columns,
       @duplicate_pairs AS duplicate_identity_pairs,
       @orphan_years AS orphan_academic_year_id,
       @orphan_semesters AS orphan_semester_id,
       @sci_role AS vice_president_scientific,
       @rbac_matrix_conflict AS rbac_matrix_conflict,
       @phase1_conflict AS phase1_conflict,
       @alter_safe AS alter_safe,
       @unique_can_create AS unique_can_create;

SELECT 'BLOCKERS' AS report_section,
       CASE
           WHEN @overall = 'READY' THEN 'none'
           WHEN @db_ready = 0 THEN 'database alrowad_uni_rust is missing'
           WHEN @structure_ok = 0 THEN 'required tables or columns are missing'
           WHEN @duplicate_pairs > 0 THEN 'duplicate academic_year_id+semester_id pairs exist; review F_duplicate_identities; do not delete or merge automatically'
           WHEN @orphan_years > 0 OR @orphan_semesters > 0 THEN 'orphan academic_year_id or semester_id values exist'
           WHEN @sci_role <> 1 THEN 'vice_president_scientific role is missing or inactive'
           WHEN @dean_role <> 1 THEN 'dean role is missing or inactive'
           WHEN @exams_module_ok <> 1 THEN 'exams system module is missing or inactive'
           WHEN @rbac_matrix_conflict = 1 THEN 'existing permission mappings conflict with the Phase 1 matrix'
           WHEN @phase1_conflict = 1 THEN 'an existing Phase 1 object has an incompatible definition'
           WHEN @unique_can_create = 0 THEN 'canonical unique identity cannot be created safely'
           ELSE 'preflight conditions failed'
       END AS blocker_report;
