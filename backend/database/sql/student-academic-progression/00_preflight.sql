-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- SET user variables only; this file must not CREATE/INSERT/UPDATE/DELETE.
-- Do not use the DATABASE function, stored procedures, DELIMITER, or SIGNAL.
-- Compatibility predicates below must stay equivalent in 01_apply.sql and 02_verify.sql.
-- Phase 9 withdrawal tables are NOT required. Do not invent a student_affairs role.
-- Authority role is the existing registration_officer.
-- Guarded queries use role_code = 'registration_officer' and status_code = 'graduated'.
-- Canonical graduated status_code is graduated.
-- An existing intended NON-UNIQUE index that is UNIQUE is CONFLICT.
-- Do not grant Phase 10 academic mutation permissions to super_admin or vice_president.
-- Missing required tables must yield OVERALL = BLOCKED, never SQL error #1146.
-- Existing Phase 10 tables are COMPATIBLE only when types, defaults, extras, indexes, and FK targets match exactly.
-- NOT NULL columns with no intended DEFAULT reject unexpected defaults.
-- updated_at must include ON UPDATE CURRENT_TIMESTAMP; submitted_at/created_at must not.

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
            SELECT 'students' AS table_name, 'student_id' AS column_name
            UNION ALL SELECT 'students', 'academic_program_id'
            UNION ALL SELECT 'students', 'current_academic_level_id'
            UNION ALL SELECT 'students', 'student_status_id'
            UNION ALL SELECT 'student_statuses', 'student_status_id'
            UNION ALL SELECT 'student_statuses', 'status_code'
            UNION ALL SELECT 'student_statuses', 'is_active'
            UNION ALL SELECT 'student_academic_terms', 'student_academic_term_id'
            UNION ALL SELECT 'student_academic_terms', 'student_id'
            UNION ALL SELECT 'student_academic_terms', 'academic_year_id'
            UNION ALL SELECT 'student_academic_terms', 'semester_id'
            UNION ALL SELECT 'student_academic_terms', 'academic_level_id'
            UNION ALL SELECT 'student_academic_terms', 'term_gpa'
            UNION ALL SELECT 'student_academic_terms', 'cumulative_gpa'
            UNION ALL SELECT 'student_academic_terms', 'total_registered_hours'
            UNION ALL SELECT 'academic_levels', 'academic_level_id'
            UNION ALL SELECT 'academic_levels', 'level_order'
            UNION ALL SELECT 'academic_levels', 'is_active'
            UNION ALL SELECT 'academic_programs', 'academic_program_id'
            UNION ALL SELECT 'academic_years', 'academic_year_id'
            UNION ALL SELECT 'semesters', 'semester_id'
            UNION ALL SELECT 'program_courses', 'program_course_id'
            UNION ALL SELECT 'program_courses', 'academic_program_id'
            UNION ALL SELECT 'program_courses', 'academic_level_id'
            UNION ALL SELECT 'program_courses', 'is_active'
            UNION ALL SELECT 'student_course_registrations', 'student_course_registration_id'
            UNION ALL SELECT 'student_course_registrations', 'student_id'
            UNION ALL SELECT 'student_course_registrations', 'course_offering_id'
            UNION ALL SELECT 'student_course_results', 'student_course_result_id'
            UNION ALL SELECT 'result_statuses', 'result_status_id'
            UNION ALL SELECT 'result_statuses', 'status_code'
            UNION ALL SELECT 'grade_approvals', 'grade_approval_id'
            UNION ALL SELECT 'grade_approvals', 'course_offering_id'
            UNION ALL SELECT 'grade_approvals', 'approval_status_id'
            UNION ALL SELECT 'approval_statuses', 'approval_status_id'
            UNION ALL SELECT 'approval_statuses', 'status_code'
            UNION ALL SELECT 'academic_requirement_groups', 'requirement_group_id'
            UNION ALL SELECT 'program_course_requirement_groups', 'program_course_id'
            UNION ALL SELECT 'roles', 'role_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'roles', 'is_active'
            UNION ALL SELECT 'permissions', 'permission_id'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'permissions', 'module_id'
            UNION ALL SELECT 'permissions', 'is_active'
            UNION ALL SELECT 'role_permissions', 'role_id'
            UNION ALL SELECT 'role_permissions', 'permission_id'
            UNION ALL SELECT 'system_modules', 'module_id'
            UNION ALL SELECT 'system_modules', 'module_code'
            UNION ALL SELECT 'users', 'user_id'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = required.table_name
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
    ),
    1
);

SET @registration_officer_active := 0;
SET @graduated_status_ok := 0;
SET @students_module_ok := 0;
SET @permission_code_unique_ok := 0;
SET @role_permissions_unique_ok := 0;
SET @legacy_term_duplicates := 1;
SET @legacy_term_null_identity := 1;
SET @legacy_invalid_levels := 1;
SET @legacy_malformed_gpa := 1;
SET @perm_view_records_exists := 0;
SET @perm_finalize_exists := 0;
SET @perm_prog_view_exists := 0;
SET @perm_prog_review_exists := 0;
SET @perm_grad_view_exists := 0;
SET @perm_grad_review_exists := 0;
SET @perm_module_ok := 0;
SET @rbac_extra_grants := 1;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @registration_officer_active := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = ''registration_officer'' AND is_active = 1) = 1, 1, 0)',
    'SELECT @registration_officer_active := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @graduated_status_ok := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_statuses` WHERE status_code = ''graduated'' AND is_active = 1) = 1, 1, 0)',
    'SELECT @graduated_status_ok := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @students_module_ok := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = ''students'' AND is_active = 1) = 1, 1, 0)',
    'SELECT @students_module_ok := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @permission_code_unique_ok := IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = ''alrowad_uni_rust'' AND table_name = ''permissions'' AND non_unique = 0 AND index_name <> ''PRIMARY'' GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = ''permission_code'') AND NOT EXISTS (SELECT permission_code FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN (''academic_records.view'', ''academic_records.finalize'', ''academic_progression.view'', ''academic_progression.review'', ''graduation_decisions.view'', ''graduation_decisions.review'') GROUP BY permission_code HAVING COUNT(*) > 1), 1, 0)',
    'SELECT @permission_code_unique_ok := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @role_permissions_unique_ok := IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = ''alrowad_uni_rust'' AND table_name = ''role_permissions'' AND non_unique = 0 AND index_name <> ''PRIMARY'' GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) IN (''role_id,permission_id'', ''permission_id,role_id'')), 1, 0)',
    'SELECT @role_permissions_unique_ok := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @legacy_term_duplicates := (SELECT COUNT(*) FROM (SELECT student_id, academic_year_id, semester_id FROM `alrowad_uni_rust`.`student_academic_terms` GROUP BY student_id, academic_year_id, semester_id HAVING COUNT(*) > 1) d)',
    'SELECT @legacy_term_duplicates := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @legacy_term_null_identity := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_academic_terms` WHERE student_id IS NULL OR academic_year_id IS NULL OR semester_id IS NULL OR academic_level_id IS NULL)',
    'SELECT @legacy_term_null_identity := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @legacy_invalid_levels := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_academic_terms` t LEFT JOIN `alrowad_uni_rust`.`academic_levels` l ON l.academic_level_id = t.academic_level_id WHERE l.academic_level_id IS NULL)',
    'SELECT @legacy_invalid_levels := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @legacy_malformed_gpa := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_academic_terms` WHERE (term_gpa IS NOT NULL AND (term_gpa < 0 OR term_gpa > 4.00)) OR (cumulative_gpa IS NOT NULL AND (cumulative_gpa < 0 OR cumulative_gpa > 4.00)))',
    'SELECT @legacy_malformed_gpa := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @perm_view_records_exists := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''academic_records.view''), @perm_finalize_exists := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''academic_records.finalize''), @perm_prog_view_exists := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''academic_progression.view''), @perm_prog_review_exists := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''academic_progression.review''), @perm_grad_view_exists := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''graduation_decisions.view''), @perm_grad_review_exists := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''graduation_decisions.review'')',
    'SELECT @perm_view_records_exists := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @perm_module_ok := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code IN (''academic_records.view'', ''academic_records.finalize'', ''academic_progression.view'', ''academic_progression.review'', ''graduation_decisions.view'', ''graduation_decisions.review'') AND NOT (p.is_active = 1 AND sm.module_code = ''students'')) = 0, 1, 0)',
    'SELECT @perm_module_ok := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @missing_required_columns = 0,
    'SELECT @rbac_extra_grants := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE p.permission_code IN (''academic_records.view'', ''academic_records.finalize'', ''academic_progression.view'', ''academic_progression.review'', ''graduation_decisions.view'', ''graduation_decisions.review'') AND r.role_code <> ''registration_officer'')',
    'SELECT @rbac_extra_grants := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @uq_student_term_ok := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_academic_terms'
          AND index_name = 'uq_student_term'
          AND non_unique = 0
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,academic_year_id,semester_id'
    ),
    1, 0
);

SET @uq_student_term_conflict := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_academic_terms'
          AND index_name = 'uq_student_term'
    )
    AND @uq_student_term_ok = 0,
    1, 0
);

SET @col_is_finalized := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'is_finalized'), 0);
SET @col_finalized_at := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_at'), 0);
SET @col_finalized_by := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_by_user_id'), 0);
SET @col_earned_hours := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'earned_hours'), 0);
SET @col_attempted_hours := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'attempted_hours'), 0);

SET @col_is_finalized_ok := IF(@col_is_finalized = 0, 1, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'is_finalized'
      AND LOWER(data_type) = 'tinyint' AND LOWER(column_type) = 'tinyint(1)' AND is_nullable = 'NO'
      AND TRIM(BOTH '''' FROM IFNULL(column_default, '')) IN ('0', '0.0')
) = 1, 1, 0));
SET @col_earned_hours_ok := IF(@col_earned_hours = 0, 1, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'earned_hours'
      AND LOWER(data_type) = 'int' AND LOWER(column_type) NOT LIKE '%unsigned%' AND is_nullable = 'NO'
      AND TRIM(BOTH '''' FROM IFNULL(column_default, '')) IN ('0', '0.0')
) = 1, 1, 0));
SET @col_attempted_hours_ok := IF(@col_attempted_hours = 0, 1, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'attempted_hours'
      AND LOWER(data_type) = 'int' AND LOWER(column_type) NOT LIKE '%unsigned%' AND is_nullable = 'NO'
      AND TRIM(BOTH '''' FROM IFNULL(column_default, '')) IN ('0', '0.0')
) = 1, 1, 0));
SET @col_finalized_at_ok := IF(@col_finalized_at = 0, 1, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_at'
      AND LOWER(data_type) = 'timestamp' AND is_nullable = 'YES'
      AND (column_default IS NULL OR LOWER(column_default) = 'null')
) = 1, 1, 0));
SET @col_finalized_by_ok := IF(@col_finalized_by = 0, 1, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_by_user_id'
      AND LOWER(data_type) = 'int' AND LOWER(column_type) NOT LIKE '%unsigned%' AND is_nullable = 'YES'
      AND (column_default IS NULL OR LOWER(column_default) = 'null')
) = 1, 1, 0));

SET @fk_sat_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms'
       AND constraint_name = 'fk_sat_finalized_by' AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @fk_sat_ok := IF(
    @fk_sat_exists = 0,
    1,
    IF((
        SELECT COUNT(*) FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_academic_terms'
          AND constraint_name = 'fk_sat_finalized_by'
          AND column_name = 'finalized_by_user_id'
          AND referenced_table_schema = 'alrowad_uni_rust'
          AND referenced_table_name = 'users'
          AND referenced_column_name = 'user_id'
    ) = 1, 1, 0)
);

SET @spd_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND table_type = 'BASE TABLE'), 0);
SET @spe_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events' AND table_type = 'BASE TABLE'), 0);
SET @sgd_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND table_type = 'BASE TABLE'), 0);
SET @sge_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events' AND table_type = 'BASE TABLE'), 0);

SET @spd_columns_ok := IF(@spd_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'student_progression_decision_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable, CAST(NULL AS CHAR) AS dflt, CAST(NULL AS UNSIGNED) AS maxlen, CAST(NULL AS UNSIGNED) AS prec, CAST(NULL AS UNSIGNED) AS scale, 1 AS autoinc, 0 AS onupd
        UNION ALL SELECT 'student_id', 'int', 'NO', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'academic_program_id', 'int', 'NO', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'academic_year_id', 'int', 'NO', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'from_academic_level_id', 'int', 'NO', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'to_academic_level_id', 'int', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'status', 'varchar', 'NO', NULL, 40, NULL, NULL, 0, 0
        UNION ALL SELECT 'decision_result', 'varchar', 'YES', NULL, 40, NULL, NULL, 0, 0
        UNION ALL SELECT 'current_slot', 'tinyint', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'term_gpa_snapshot', 'decimal', 'YES', NULL, NULL, 4, 2, 0, 0
        UNION ALL SELECT 'cumulative_gpa_snapshot', 'decimal', 'YES', NULL, NULL, 4, 2, 0, 0
        UNION ALL SELECT 'earned_hours_snapshot', 'int', 'NO', '0', NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'attempted_hours_snapshot', 'int', 'NO', '0', NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'failed_courses_count_snapshot', 'int', 'NO', '0', NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'evidence_snapshot', 'text', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'submitted_by_user_id', 'int', 'NO', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'submitted_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP', NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'reviewed_by_user_id', 'int', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'reviewed_at', 'timestamp', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'review_notes', 'text', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'approved_at', 'timestamp', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'materialized_at', 'timestamp', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'superseded_at', 'timestamp', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'created_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP', NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'updated_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP', NULL, NULL, NULL, 0, 1
    ) required
    LEFT JOIN information_schema.columns c
        ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'student_progression_decisions' AND c.column_name = required.column_name
    WHERE c.column_name IS NULL
       OR LOWER(c.data_type) <> required.data_type
       OR c.is_nullable <> required.is_nullable
       OR (required.data_type IN ('int', 'tinyint') AND LOWER(c.column_type) LIKE '%unsigned%')
       OR (required.maxlen IS NOT NULL AND IFNULL(c.character_maximum_length, 0) <> required.maxlen)
       OR (required.prec IS NOT NULL AND (IFNULL(c.numeric_precision, 0) <> required.prec OR IFNULL(c.numeric_scale, 0) <> required.scale))
       OR (required.autoinc = 1 AND LOWER(IFNULL(c.extra, '')) NOT LIKE '%auto_increment%')
       OR (required.autoinc = 0 AND LOWER(IFNULL(c.extra, '')) LIKE '%auto_increment%')
       OR (required.onupd = 1 AND LOWER(IFNULL(c.extra, '')) NOT LIKE '%on update current_timestamp%')
       OR (required.onupd = 0 AND LOWER(IFNULL(c.extra, '')) LIKE '%on update current_timestamp%')
       OR (required.dflt = '0' AND TRIM(BOTH '''' FROM IFNULL(c.column_default, '')) NOT IN ('0', '0.0'))
       OR (required.dflt = 'CURRENT_TIMESTAMP' AND LOWER(IFNULL(c.column_default, '')) NOT LIKE 'current_timestamp%')
       OR (required.dflt IS NULL AND required.is_nullable = 'YES' AND c.column_default IS NOT NULL AND LOWER(c.column_default) <> 'null')
       OR (required.dflt IS NULL AND required.is_nullable = 'NO' AND c.column_default IS NOT NULL)
) = 0 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions') = 25, 1, 0));

SET @spe_columns_ok := IF(@spe_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'student_progression_event_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable, CAST(NULL AS CHAR) AS dflt, CAST(NULL AS UNSIGNED) AS maxlen, 1 AS autoinc, 0 AS onupd
        UNION ALL SELECT 'student_progression_decision_id', 'int', 'NO', NULL, NULL, 0, 0
        UNION ALL SELECT 'event_type', 'varchar', 'NO', NULL, 40, 0, 0
        UNION ALL SELECT 'actor_user_id', 'int', 'YES', NULL, NULL, 0, 0
        UNION ALL SELECT 'from_status', 'varchar', 'YES', NULL, 40, 0, 0
        UNION ALL SELECT 'to_status', 'varchar', 'YES', NULL, 40, 0, 0
        UNION ALL SELECT 'notes', 'text', 'YES', NULL, NULL, 0, 0
        UNION ALL SELECT 'created_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP', NULL, 0, 0
    ) required
    LEFT JOIN information_schema.columns c
        ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'student_progression_events' AND c.column_name = required.column_name
    WHERE c.column_name IS NULL
       OR LOWER(c.data_type) <> required.data_type
       OR c.is_nullable <> required.is_nullable
       OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%')
       OR (required.maxlen IS NOT NULL AND IFNULL(c.character_maximum_length, 0) <> required.maxlen)
       OR (required.autoinc = 1 AND LOWER(IFNULL(c.extra, '')) NOT LIKE '%auto_increment%')
       OR (required.autoinc = 0 AND LOWER(IFNULL(c.extra, '')) LIKE '%auto_increment%')
       OR (required.onupd = 1 AND LOWER(IFNULL(c.extra, '')) NOT LIKE '%on update current_timestamp%')
       OR (required.onupd = 0 AND LOWER(IFNULL(c.extra, '')) LIKE '%on update current_timestamp%')
       OR (required.dflt = 'CURRENT_TIMESTAMP' AND LOWER(IFNULL(c.column_default, '')) NOT LIKE 'current_timestamp%')
       OR (required.dflt IS NULL AND required.is_nullable = 'YES' AND c.column_default IS NOT NULL AND LOWER(c.column_default) <> 'null')
       OR (required.dflt IS NULL AND required.is_nullable = 'NO' AND c.column_default IS NOT NULL)
) = 0 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events') = 8, 1, 0));

SET @sgd_columns_ok := IF(@sgd_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'student_graduation_decision_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable, CAST(NULL AS CHAR) AS dflt, CAST(NULL AS UNSIGNED) AS maxlen, CAST(NULL AS UNSIGNED) AS prec, CAST(NULL AS UNSIGNED) AS scale, 1 AS autoinc, 0 AS onupd
        UNION ALL SELECT 'student_id', 'int', 'NO', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'academic_program_id', 'int', 'NO', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'current_academic_level_id', 'int', 'NO', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'status', 'varchar', 'NO', NULL, 40, NULL, NULL, 0, 0
        UNION ALL SELECT 'decision_result', 'varchar', 'YES', NULL, 40, NULL, NULL, 0, 0
        UNION ALL SELECT 'current_slot', 'tinyint', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'cumulative_gpa_snapshot', 'decimal', 'YES', NULL, NULL, 4, 2, 0, 0
        UNION ALL SELECT 'earned_hours_snapshot', 'int', 'NO', '0', NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'required_hours_snapshot', 'int', 'NO', '0', NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'eligibility_snapshot', 'text', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'submitted_by_user_id', 'int', 'NO', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'submitted_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP', NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'reviewed_by_user_id', 'int', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'reviewed_at', 'timestamp', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'review_notes', 'text', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'approved_at', 'timestamp', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'materialized_at', 'timestamp', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'superseded_at', 'timestamp', 'YES', NULL, NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'created_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP', NULL, NULL, NULL, 0, 0
        UNION ALL SELECT 'updated_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP', NULL, NULL, NULL, 0, 1
    ) required
    LEFT JOIN information_schema.columns c
        ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'student_graduation_decisions' AND c.column_name = required.column_name
    WHERE c.column_name IS NULL
       OR LOWER(c.data_type) <> required.data_type
       OR c.is_nullable <> required.is_nullable
       OR (required.data_type IN ('int', 'tinyint') AND LOWER(c.column_type) LIKE '%unsigned%')
       OR (required.maxlen IS NOT NULL AND IFNULL(c.character_maximum_length, 0) <> required.maxlen)
       OR (required.prec IS NOT NULL AND (IFNULL(c.numeric_precision, 0) <> required.prec OR IFNULL(c.numeric_scale, 0) <> required.scale))
       OR (required.autoinc = 1 AND LOWER(IFNULL(c.extra, '')) NOT LIKE '%auto_increment%')
       OR (required.autoinc = 0 AND LOWER(IFNULL(c.extra, '')) LIKE '%auto_increment%')
       OR (required.onupd = 1 AND LOWER(IFNULL(c.extra, '')) NOT LIKE '%on update current_timestamp%')
       OR (required.onupd = 0 AND LOWER(IFNULL(c.extra, '')) LIKE '%on update current_timestamp%')
       OR (required.dflt = '0' AND TRIM(BOTH '''' FROM IFNULL(c.column_default, '')) NOT IN ('0', '0.0'))
       OR (required.dflt = 'CURRENT_TIMESTAMP' AND LOWER(IFNULL(c.column_default, '')) NOT LIKE 'current_timestamp%')
       OR (required.dflt IS NULL AND required.is_nullable = 'YES' AND c.column_default IS NOT NULL AND LOWER(c.column_default) <> 'null')
       OR (required.dflt IS NULL AND required.is_nullable = 'NO' AND c.column_default IS NOT NULL)
) = 0 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions') = 21, 1, 0));

SET @sge_columns_ok := IF(@sge_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'student_graduation_event_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable, CAST(NULL AS CHAR) AS dflt, CAST(NULL AS UNSIGNED) AS maxlen, 1 AS autoinc, 0 AS onupd
        UNION ALL SELECT 'student_graduation_decision_id', 'int', 'NO', NULL, NULL, 0, 0
        UNION ALL SELECT 'event_type', 'varchar', 'NO', NULL, 40, 0, 0
        UNION ALL SELECT 'actor_user_id', 'int', 'YES', NULL, NULL, 0, 0
        UNION ALL SELECT 'from_status', 'varchar', 'YES', NULL, 40, 0, 0
        UNION ALL SELECT 'to_status', 'varchar', 'YES', NULL, 40, 0, 0
        UNION ALL SELECT 'notes', 'text', 'YES', NULL, NULL, 0, 0
        UNION ALL SELECT 'created_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP', NULL, 0, 0
    ) required
    LEFT JOIN information_schema.columns c
        ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'student_graduation_events' AND c.column_name = required.column_name
    WHERE c.column_name IS NULL
       OR LOWER(c.data_type) <> required.data_type
       OR c.is_nullable <> required.is_nullable
       OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%')
       OR (required.maxlen IS NOT NULL AND IFNULL(c.character_maximum_length, 0) <> required.maxlen)
       OR (required.autoinc = 1 AND LOWER(IFNULL(c.extra, '')) NOT LIKE '%auto_increment%')
       OR (required.autoinc = 0 AND LOWER(IFNULL(c.extra, '')) LIKE '%auto_increment%')
       OR (required.onupd = 1 AND LOWER(IFNULL(c.extra, '')) NOT LIKE '%on update current_timestamp%')
       OR (required.onupd = 0 AND LOWER(IFNULL(c.extra, '')) LIKE '%on update current_timestamp%')
       OR (required.dflt = 'CURRENT_TIMESTAMP' AND LOWER(IFNULL(c.column_default, '')) NOT LIKE 'current_timestamp%')
       OR (required.dflt IS NULL AND required.is_nullable = 'YES' AND c.column_default IS NOT NULL AND LOWER(c.column_default) <> 'null')
       OR (required.dflt IS NULL AND required.is_nullable = 'NO' AND c.column_default IS NOT NULL)
) = 0 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events') = 8, 1, 0));

SET @spd_engine_ok := IF(@spd_exists = 0, 1, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions') = 'innodb', 1, 0));
SET @spe_engine_ok := IF(@spe_exists = 0, 1, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events') = 'innodb', 1, 0));
SET @sgd_engine_ok := IF(@sgd_exists = 0, 1, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions') = 'innodb', 1, 0));
SET @sge_engine_ok := IF(@sge_exists = 0, 1, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events') = 'innodb', 1, 0));

SET @idx_spd_pk_ok := IF(@spd_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND index_name = 'PRIMARY' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_progression_decision_id') x) = 1, 1, 0));
SET @idx_spe_pk_ok := IF(@spe_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events' AND index_name = 'PRIMARY' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_progression_event_id') x) = 1, 1, 0));
SET @idx_sgd_pk_ok := IF(@sgd_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND index_name = 'PRIMARY' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_graduation_decision_id') x) = 1, 1, 0));
SET @idx_sge_pk_ok := IF(@sge_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events' AND index_name = 'PRIMARY' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_graduation_event_id') x) = 1, 1, 0));

SET @idx_spd_current_ok := IF(@spd_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND index_name = 'uq_spd_current_slot' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,academic_year_id,current_slot') x) = 1, 1, 0));
SET @idx_spd_status_ok := IF(@spd_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND index_name = 'idx_spd_student_status' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,status') x) = 1, 1, 0));
SET @idx_spd_reviewer_ok := IF(@spd_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND index_name = 'idx_spd_reviewer' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'reviewed_by_user_id') x) = 1, 1, 0));
SET @idx_spe_decision_ok := IF(@spe_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events' AND index_name = 'idx_spe_decision' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_progression_decision_id,created_at') x) = 1, 1, 0));
SET @idx_spe_actor_ok := IF(@spe_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events' AND index_name = 'idx_spe_actor' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'actor_user_id') x) = 1, 1, 0));
SET @idx_sgd_current_ok := IF(@sgd_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND index_name = 'uq_sgd_current_slot' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,current_slot') x) = 1, 1, 0));
SET @idx_sgd_status_ok := IF(@sgd_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND index_name = 'idx_sgd_student_status' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,status') x) = 1, 1, 0));
SET @idx_sgd_reviewer_ok := IF(@sgd_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND index_name = 'idx_sgd_reviewer' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'reviewed_by_user_id') x) = 1, 1, 0));
SET @idx_sge_decision_ok := IF(@sge_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events' AND index_name = 'idx_sge_decision' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_graduation_decision_id,created_at') x) = 1, 1, 0));
SET @idx_sge_actor_ok := IF(@sge_exists = 0, 1, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events' AND index_name = 'idx_sge_actor' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'actor_user_id') x) = 1, 1, 0));

SET @spd_fk_ok := IF(@spd_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'fk_spd_student' AS constraint_name, 'student_id' AS column_name, 'students' AS ref_table, 'student_id' AS ref_column
        UNION ALL SELECT 'fk_spd_program', 'academic_program_id', 'academic_programs', 'academic_program_id'
        UNION ALL SELECT 'fk_spd_year', 'academic_year_id', 'academic_years', 'academic_year_id'
        UNION ALL SELECT 'fk_spd_from_level', 'from_academic_level_id', 'academic_levels', 'academic_level_id'
        UNION ALL SELECT 'fk_spd_to_level', 'to_academic_level_id', 'academic_levels', 'academic_level_id'
        UNION ALL SELECT 'fk_spd_submitted_by', 'submitted_by_user_id', 'users', 'user_id'
        UNION ALL SELECT 'fk_spd_reviewed_by', 'reviewed_by_user_id', 'users', 'user_id'
    ) required
    LEFT JOIN information_schema.key_column_usage k
        ON k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'student_progression_decisions'
       AND k.constraint_name = required.constraint_name AND k.column_name = required.column_name
       AND k.referenced_table_schema = 'alrowad_uni_rust' AND k.referenced_table_name = required.ref_table
       AND k.referenced_column_name = required.ref_column
    WHERE k.column_name IS NULL
) = 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND constraint_type = 'FOREIGN KEY') = 7, 1, 0));
SET @spe_fk_ok := IF(@spe_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'fk_spe_decision' AS constraint_name, 'student_progression_decision_id' AS column_name, 'student_progression_decisions' AS ref_table, 'student_progression_decision_id' AS ref_column
        UNION ALL SELECT 'fk_spe_actor', 'actor_user_id', 'users', 'user_id'
    ) required
    LEFT JOIN information_schema.key_column_usage k
        ON k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'student_progression_events'
       AND k.constraint_name = required.constraint_name AND k.column_name = required.column_name
       AND k.referenced_table_schema = 'alrowad_uni_rust' AND k.referenced_table_name = required.ref_table
       AND k.referenced_column_name = required.ref_column
    WHERE k.column_name IS NULL
) = 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events' AND constraint_type = 'FOREIGN KEY') = 2, 1, 0));
SET @sgd_fk_ok := IF(@sgd_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'fk_sgd_student' AS constraint_name, 'student_id' AS column_name, 'students' AS ref_table, 'student_id' AS ref_column
        UNION ALL SELECT 'fk_sgd_program', 'academic_program_id', 'academic_programs', 'academic_program_id'
        UNION ALL SELECT 'fk_sgd_level', 'current_academic_level_id', 'academic_levels', 'academic_level_id'
        UNION ALL SELECT 'fk_sgd_submitted_by', 'submitted_by_user_id', 'users', 'user_id'
        UNION ALL SELECT 'fk_sgd_reviewed_by', 'reviewed_by_user_id', 'users', 'user_id'
    ) required
    LEFT JOIN information_schema.key_column_usage k
        ON k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'student_graduation_decisions'
       AND k.constraint_name = required.constraint_name AND k.column_name = required.column_name
       AND k.referenced_table_schema = 'alrowad_uni_rust' AND k.referenced_table_name = required.ref_table
       AND k.referenced_column_name = required.ref_column
    WHERE k.column_name IS NULL
) = 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND constraint_type = 'FOREIGN KEY') = 5, 1, 0));
SET @sge_fk_ok := IF(@sge_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'fk_sge_decision' AS constraint_name, 'student_graduation_decision_id' AS column_name, 'student_graduation_decisions' AS ref_table, 'student_graduation_decision_id' AS ref_column
        UNION ALL SELECT 'fk_sge_actor', 'actor_user_id', 'users', 'user_id'
    ) required
    LEFT JOIN information_schema.key_column_usage k
        ON k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'student_graduation_events'
       AND k.constraint_name = required.constraint_name AND k.column_name = required.column_name
       AND k.referenced_table_schema = 'alrowad_uni_rust' AND k.referenced_table_name = required.ref_table
       AND k.referenced_column_name = required.ref_column
    WHERE k.column_name IS NULL
) = 0 AND (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events' AND constraint_type = 'FOREIGN KEY') = 2, 1, 0));

SET @terms_columns_state := CASE
    WHEN @fk_sat_ok = 0 THEN 'CONFLICT'
    WHEN @col_is_finalized_ok = 1 AND @col_finalized_at_ok = 1 AND @col_finalized_by_ok = 1 AND @col_earned_hours_ok = 1 AND @col_attempted_hours_ok = 1
        AND (@col_is_finalized + @col_finalized_at + @col_finalized_by + @col_earned_hours + @col_attempted_hours) = 0
        AND @fk_sat_exists = 0 THEN 'ABSENT'
    WHEN @col_is_finalized_ok = 1 AND @col_finalized_at_ok = 1 AND @col_finalized_by_ok = 1 AND @col_earned_hours_ok = 1 AND @col_attempted_hours_ok = 1 AND @fk_sat_ok = 1 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;
SET @uq_term_state := CASE
    WHEN @uq_student_term_conflict = 1 THEN 'CONFLICT'
    WHEN @uq_student_term_ok = 1 THEN 'COMPATIBLE'
    ELSE 'ABSENT'
END;
SET @spd_state := CASE WHEN @spd_exists = 0 THEN 'ABSENT' WHEN @spd_columns_ok = 1 AND @spd_engine_ok = 1 AND @idx_spd_pk_ok = 1 AND @idx_spd_current_ok = 1 AND @idx_spd_status_ok = 1 AND @idx_spd_reviewer_ok = 1 AND @spd_fk_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @spe_state := CASE WHEN @spe_exists = 0 THEN 'ABSENT' WHEN @spe_columns_ok = 1 AND @spe_engine_ok = 1 AND @idx_spe_pk_ok = 1 AND @idx_spe_decision_ok = 1 AND @idx_spe_actor_ok = 1 AND @spe_fk_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @sgd_state := CASE WHEN @sgd_exists = 0 THEN 'ABSENT' WHEN @sgd_columns_ok = 1 AND @sgd_engine_ok = 1 AND @idx_sgd_pk_ok = 1 AND @idx_sgd_current_ok = 1 AND @idx_sgd_status_ok = 1 AND @idx_sgd_reviewer_ok = 1 AND @sgd_fk_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @sge_state := CASE WHEN @sge_exists = 0 THEN 'ABSENT' WHEN @sge_columns_ok = 1 AND @sge_engine_ok = 1 AND @idx_sge_pk_ok = 1 AND @idx_sge_decision_ok = 1 AND @idx_sge_actor_ok = 1 AND @sge_fk_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_records_view_state := CASE WHEN @perm_view_records_exists = 0 THEN 'ABSENT' WHEN @perm_view_records_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_records_finalize_state := CASE WHEN @perm_finalize_exists = 0 THEN 'ABSENT' WHEN @perm_finalize_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_prog_view_state := CASE WHEN @perm_prog_view_exists = 0 THEN 'ABSENT' WHEN @perm_prog_view_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_prog_review_state := CASE WHEN @perm_prog_review_exists = 0 THEN 'ABSENT' WHEN @perm_prog_review_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_grad_view_state := CASE WHEN @perm_grad_view_exists = 0 THEN 'ABSENT' WHEN @perm_grad_view_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_grad_review_state := CASE WHEN @perm_grad_review_exists = 0 THEN 'ABSENT' WHEN @perm_grad_review_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @rbac_extra_state := CASE WHEN @rbac_extra_grants = 0 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;

SET @phase10_conflict := IF(
    @terms_columns_state = 'CONFLICT'
    OR @uq_term_state = 'CONFLICT'
    OR @spd_state = 'CONFLICT'
    OR @spe_state = 'CONFLICT'
    OR @sgd_state = 'CONFLICT'
    OR @sge_state = 'CONFLICT'
    OR @perm_records_view_state = 'CONFLICT'
    OR @perm_records_finalize_state = 'CONFLICT'
    OR @perm_prog_view_state = 'CONFLICT'
    OR @perm_prog_review_state = 'CONFLICT'
    OR @perm_grad_view_state = 'CONFLICT'
    OR @perm_grad_review_state = 'CONFLICT'
    OR @rbac_extra_state = 'CONFLICT',
    1, 0
);

SET @overall_ready := IF(
    @db_ready = 1
    AND @missing_required_columns = 0
    AND @registration_officer_active = 1
    AND @graduated_status_ok = 1
    AND @students_module_ok = 1
    AND @permission_code_unique_ok = 1
    AND @role_permissions_unique_ok = 1
    AND @legacy_term_duplicates = 0
    AND @legacy_term_null_identity = 0
    AND @legacy_invalid_levels = 0
    AND @legacy_malformed_gpa = 0
    AND @phase10_conflict = 0,
    1, 0
);

SELECT 'required_infrastructure' AS check_name, IF(@missing_required_columns = 0, 'PASS', 'FAIL') AS result;
SELECT 'registration_officer_role' AS check_name, IF(@registration_officer_active = 1, 'PASS', 'FAIL') AS result;
SELECT 'graduated_student_status' AS check_name, IF(@graduated_status_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'students_module' AS check_name, IF(@students_module_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'legacy_term_duplicates' AS check_name, IF(@legacy_term_duplicates = 0, 'PASS', 'FAIL') AS result, @legacy_term_duplicates AS duplicate_groups;
SELECT 'legacy_term_null_identity' AS check_name, IF(@legacy_term_null_identity = 0, 'PASS', 'FAIL') AS result;
SELECT 'legacy_invalid_academic_levels' AS check_name, IF(@legacy_invalid_levels = 0, 'PASS', 'FAIL') AS result;
SELECT 'legacy_malformed_gpa' AS check_name, IF(@legacy_malformed_gpa = 0, 'PASS', 'FAIL') AS result;
SELECT 'student_academic_terms_new_columns' AS object_name, @terms_columns_state AS classification;
SELECT 'fk_sat_finalized_by' AS object_name, CASE WHEN @fk_sat_ok = 0 THEN 'CONFLICT' WHEN @fk_sat_exists = 0 THEN 'ABSENT' ELSE 'COMPATIBLE' END AS classification;
SELECT 'uq_student_term' AS object_name, @uq_term_state AS classification;
SELECT 'student_progression_decisions' AS object_name, @spd_state AS classification;
SELECT 'student_progression_events' AS object_name, @spe_state AS classification;
SELECT 'student_graduation_decisions' AS object_name, @sgd_state AS classification;
SELECT 'student_graduation_events' AS object_name, @sge_state AS classification;
SELECT 'academic_records.view' AS object_name, @perm_records_view_state AS classification;
SELECT 'academic_records.finalize' AS object_name, @perm_records_finalize_state AS classification;
SELECT 'academic_progression.view' AS object_name, @perm_prog_view_state AS classification;
SELECT 'academic_progression.review' AS object_name, @perm_prog_review_state AS classification;
SELECT 'graduation_decisions.view' AS object_name, @perm_grad_view_state AS classification;
SELECT 'graduation_decisions.review' AS object_name, @perm_grad_review_state AS classification;
SELECT 'phase10_rbac_extra_grants' AS object_name, @rbac_extra_state AS classification;
SELECT 'OVERALL' AS report_section, IF(@overall_ready = 1, 'READY', 'BLOCKED') AS result;
