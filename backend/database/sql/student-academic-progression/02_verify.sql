-- READ ONLY. Continue only when OVERALL returns PASS.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE application data.
-- Do not use the DATABASE function, stored procedures, DELIMITER, or SIGNAL.
-- Compatibility predicates below must stay equivalent in 00_preflight.sql and 01_apply.sql.
-- Named NON-UNIQUE indexes are FAIL when they exist as UNIQUE.
-- Exact column types, defaults, AUTO_INCREMENT, extras (ON UPDATE), PKs, named FKs (source and target), and indexes are required.
-- NOT NULL columns with no intended DEFAULT reject unexpected defaults.
-- updated_at must include ON UPDATE CURRENT_TIMESTAMP; submitted_at/created_at must not.
-- student_academic_terms.finalized_at must not have ON UPDATE CURRENT_TIMESTAMP or AUTO_INCREMENT.
-- Missing required tables must yield OVERALL = FAIL, never SQL error #1146.
-- Guarded queries use role_code = 'registration_officer' and status_code = 'graduated'.
-- Progression decision_result values: NULL, 'promoted', 'retained'.
-- Graduation decision_result values: NULL before approval, 'graduated' after materialization.

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
            UNION ALL SELECT 'student_statuses', 'student_status_id'
            UNION ALL SELECT 'student_statuses', 'status_code'
            UNION ALL SELECT 'student_statuses', 'is_active'
            UNION ALL SELECT 'roles', 'role_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'permissions', 'permission_id'
            UNION ALL SELECT 'permissions', 'permission_code'
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
SET @rbac_officer := 0;
SET @rbac_extra := 1;
SET @sql := IF(@missing_required_columns = 0, 'SELECT @registration_officer_active := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = ''registration_officer'' AND is_active = 1) = 1, 1, 0)', 'SELECT @registration_officer_active := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@missing_required_columns = 0, 'SELECT @graduated_status_ok := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_statuses` WHERE status_code = ''graduated'' AND is_active = 1) = 1, 1, 0)', 'SELECT @graduated_status_ok := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@missing_required_columns = 0, 'SELECT @rbac_officer := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE r.role_code = ''registration_officer'' AND r.is_active = 1 AND p.is_active = 1 AND sm.module_code = ''students'' AND p.permission_code IN (''academic_records.view'', ''academic_records.finalize'', ''academic_progression.view'', ''academic_progression.review'', ''graduation_decisions.view'', ''graduation_decisions.review'')) = 6, 1, 0)', 'SELECT @rbac_officer := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@missing_required_columns = 0, 'SELECT @rbac_extra := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE p.permission_code IN (''academic_records.view'', ''academic_records.finalize'', ''academic_progression.view'', ''academic_progression.review'', ''graduation_decisions.view'', ''graduation_decisions.review'') AND r.role_code <> ''registration_officer'')', 'SELECT @rbac_extra := 1');
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

SET @col_is_finalized_ok := IF(@col_is_finalized = 0, 0, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'is_finalized'
      AND LOWER(data_type) = 'tinyint' AND LOWER(column_type) = 'tinyint(1)' AND is_nullable = 'NO'
      AND TRIM(BOTH '''' FROM IFNULL(column_default, '')) IN ('0', '0.0')
      AND LOWER(IFNULL(extra, '')) NOT LIKE '%auto_increment%'
      AND LOWER(IFNULL(extra, '')) NOT LIKE '%on update current_timestamp%'
) = 1, 1, 0));
SET @col_earned_hours_ok := IF(@col_earned_hours = 0, 0, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'earned_hours'
      AND LOWER(data_type) = 'int' AND LOWER(column_type) NOT LIKE '%unsigned%' AND is_nullable = 'NO'
      AND TRIM(BOTH '''' FROM IFNULL(column_default, '')) IN ('0', '0.0')
      AND LOWER(IFNULL(extra, '')) NOT LIKE '%auto_increment%'
      AND LOWER(IFNULL(extra, '')) NOT LIKE '%on update current_timestamp%'
) = 1, 1, 0));
SET @col_attempted_hours_ok := IF(@col_attempted_hours = 0, 0, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'attempted_hours'
      AND LOWER(data_type) = 'int' AND LOWER(column_type) NOT LIKE '%unsigned%' AND is_nullable = 'NO'
      AND TRIM(BOTH '''' FROM IFNULL(column_default, '')) IN ('0', '0.0')
      AND LOWER(IFNULL(extra, '')) NOT LIKE '%auto_increment%'
      AND LOWER(IFNULL(extra, '')) NOT LIKE '%on update current_timestamp%'
) = 1, 1, 0));
SET @col_finalized_at_ok := IF(@col_finalized_at = 0, 0, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_at'
      AND LOWER(data_type) = 'timestamp' AND is_nullable = 'YES'
      AND (column_default IS NULL OR LOWER(column_default) = 'null')
      AND LOWER(IFNULL(extra, '')) NOT LIKE '%auto_increment%'
      AND LOWER(IFNULL(extra, '')) NOT LIKE '%on update current_timestamp%'
) = 1, 1, 0));
SET @col_finalized_by_ok := IF(@col_finalized_by = 0, 0, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_by_user_id'
      AND LOWER(data_type) = 'int' AND LOWER(column_type) NOT LIKE '%unsigned%' AND is_nullable = 'YES'
      AND (column_default IS NULL OR LOWER(column_default) = 'null')
      AND LOWER(IFNULL(extra, '')) NOT LIKE '%auto_increment%'
      AND LOWER(IFNULL(extra, '')) NOT LIKE '%on update current_timestamp%'
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
    0,
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

SET @spd_columns_ok := IF(@spd_exists = 0, 0, IF((
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

SET @spe_columns_ok := IF(@spe_exists = 0, 0, IF((
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

SET @sgd_columns_ok := IF(@sgd_exists = 0, 0, IF((
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

SET @sge_columns_ok := IF(@sge_exists = 0, 0, IF((
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

SET @spd_engine_ok := IF(@spd_exists = 0, 0, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions') = 'innodb', 1, 0));
SET @spe_engine_ok := IF(@spe_exists = 0, 0, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events') = 'innodb', 1, 0));
SET @sgd_engine_ok := IF(@sgd_exists = 0, 0, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions') = 'innodb', 1, 0));
SET @sge_engine_ok := IF(@sge_exists = 0, 0, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events') = 'innodb', 1, 0));

SET @idx_spd_pk_ok := IF(@spd_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND index_name = 'PRIMARY' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_progression_decision_id') x) = 1, 1, 0));
SET @idx_spe_pk_ok := IF(@spe_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events' AND index_name = 'PRIMARY' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_progression_event_id') x) = 1, 1, 0));
SET @idx_sgd_pk_ok := IF(@sgd_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND index_name = 'PRIMARY' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_graduation_decision_id') x) = 1, 1, 0));
SET @idx_sge_pk_ok := IF(@sge_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events' AND index_name = 'PRIMARY' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_graduation_event_id') x) = 1, 1, 0));

SET @idx_spd_current_ok := IF(@spd_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND index_name = 'uq_spd_current_slot' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,academic_year_id,current_slot') x) = 1, 1, 0));
SET @idx_spd_status_ok := IF(@spd_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND index_name = 'idx_spd_student_status' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,status') x) = 1, 1, 0));
SET @idx_spd_reviewer_ok := IF(@spd_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND index_name = 'idx_spd_reviewer' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'reviewed_by_user_id') x) = 1, 1, 0));
SET @idx_spe_decision_ok := IF(@spe_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events' AND index_name = 'idx_spe_decision' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_progression_decision_id,created_at') x) = 1, 1, 0));
SET @idx_spe_actor_ok := IF(@spe_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events' AND index_name = 'idx_spe_actor' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'actor_user_id') x) = 1, 1, 0));
SET @idx_sgd_current_ok := IF(@sgd_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND index_name = 'uq_sgd_current_slot' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,current_slot') x) = 1, 1, 0));
SET @idx_sgd_status_ok := IF(@sgd_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND index_name = 'idx_sgd_student_status' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,status') x) = 1, 1, 0));
SET @idx_sgd_reviewer_ok := IF(@sgd_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND index_name = 'idx_sgd_reviewer' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'reviewed_by_user_id') x) = 1, 1, 0));
SET @idx_sge_decision_ok := IF(@sge_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events' AND index_name = 'idx_sge_decision' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_graduation_decision_id,created_at') x) = 1, 1, 0));
SET @idx_sge_actor_ok := IF(@sge_exists = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events' AND index_name = 'idx_sge_actor' AND non_unique = 1 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'actor_user_id') x) = 1, 1, 0));

SET @spd_fk_ok := IF(@spd_exists = 0, 0, IF((
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
SET @spe_fk_ok := IF(@spe_exists = 0, 0, IF((
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
SET @sgd_fk_ok := IF(@sgd_exists = 0, 0, IF((
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
SET @sge_fk_ok := IF(@sge_exists = 0, 0, IF((
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


SET @structure_ok := IF(
    @db_ready = 1 AND @missing_required_columns = 0
    AND @col_is_finalized_ok = 1 AND @col_earned_hours_ok = 1 AND @col_attempted_hours_ok = 1
    AND @col_finalized_at_ok = 1 AND @col_finalized_by_ok = 1 AND @fk_sat_ok = 1
    AND @uq_student_term_ok = 1
    AND @spd_exists = 1 AND @spe_exists = 1 AND @sgd_exists = 1 AND @sge_exists = 1
    AND @spd_columns_ok = 1 AND @spe_columns_ok = 1 AND @sgd_columns_ok = 1 AND @sge_columns_ok = 1
    AND @spd_engine_ok = 1 AND @spe_engine_ok = 1 AND @sgd_engine_ok = 1 AND @sge_engine_ok = 1
    AND @idx_spd_pk_ok = 1 AND @idx_spe_pk_ok = 1 AND @idx_sgd_pk_ok = 1 AND @idx_sge_pk_ok = 1
    AND @idx_spd_current_ok = 1 AND @idx_spd_status_ok = 1 AND @idx_spd_reviewer_ok = 1
    AND @idx_spe_decision_ok = 1 AND @idx_spe_actor_ok = 1
    AND @idx_sgd_current_ok = 1 AND @idx_sgd_status_ok = 1 AND @idx_sgd_reviewer_ok = 1
    AND @idx_sge_decision_ok = 1 AND @idx_sge_actor_ok = 1
    AND @spd_fk_ok = 1 AND @spe_fk_ok = 1 AND @sgd_fk_ok = 1 AND @sge_fk_ok = 1
    AND @registration_officer_active = 1 AND @graduated_status_ok = 1
    AND @rbac_officer = 1 AND @rbac_extra = 0,
    1, 0
);

SET @term_dupes := 1;
SET @finalized_incomplete := 1;
SET @unfinalized_pretend := 1;
SET @spd_slot_bad := 1;
SET @spd_current_dup := 1;
SET @spd_identity_bad := 1;
SET @spd_materialized_mismatch := 1;
SET @spd_unknown_status := 1;
SET @spd_unknown_result := 1;
SET @spd_materialized_missing_at := 1;
SET @sgd_slot_bad := 1;
SET @sgd_current_dup := 1;
SET @sgd_identity_bad := 1;
SET @sgd_status_mismatch := 1;
SET @sgd_unknown_status := 1;
SET @sgd_unknown_result := 1;
SET @sgd_approved_result_bad := 1;
SET @sgd_materialized_missing_at := 1;

SET @sql := IF(@missing_required_columns = 0, 'SELECT @term_dupes := COUNT(*) FROM (SELECT student_id, academic_year_id, semester_id FROM `alrowad_uni_rust`.`student_academic_terms` GROUP BY student_id, academic_year_id, semester_id HAVING COUNT(*) > 1) d', 'SELECT @term_dupes := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@col_is_finalized_ok = 1 AND @col_finalized_at_ok = 1 AND @col_finalized_by_ok = 1, 'SELECT @finalized_incomplete := COUNT(*) FROM `alrowad_uni_rust`.`student_academic_terms` WHERE is_finalized = 1 AND (finalized_at IS NULL OR finalized_by_user_id IS NULL)', 'SELECT @finalized_incomplete := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@col_is_finalized_ok = 1 AND @col_finalized_at_ok = 1 AND @col_finalized_by_ok = 1, 'SELECT @unfinalized_pretend := COUNT(*) FROM `alrowad_uni_rust`.`student_academic_terms` WHERE is_finalized = 0 AND (finalized_at IS NOT NULL OR finalized_by_user_id IS NOT NULL)', 'SELECT @unfinalized_pretend := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@spd_exists = 1, 'SELECT @spd_slot_bad := COUNT(*) FROM `alrowad_uni_rust`.`student_progression_decisions` WHERE current_slot IS NOT NULL AND current_slot <> 1', 'SELECT @spd_slot_bad := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@spd_exists = 1, 'SELECT @spd_current_dup := COUNT(*) FROM (SELECT student_id, academic_year_id FROM `alrowad_uni_rust`.`student_progression_decisions` WHERE current_slot = 1 GROUP BY student_id, academic_year_id HAVING COUNT(*) > 1) d', 'SELECT @spd_current_dup := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@spd_exists = 1, 'SELECT @spd_identity_bad := COUNT(*) FROM `alrowad_uni_rust`.`student_progression_decisions` d JOIN `alrowad_uni_rust`.`students` s ON s.student_id = d.student_id WHERE d.academic_program_id <> s.academic_program_id AND d.current_slot = 1', 'SELECT @spd_identity_bad := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@spd_exists = 1, 'SELECT @spd_materialized_mismatch := COUNT(*) FROM `alrowad_uni_rust`.`student_progression_decisions` latest JOIN `alrowad_uni_rust`.`students` s ON s.student_id = latest.student_id WHERE latest.materialized_at IS NOT NULL AND latest.decision_result = ''promoted'' AND latest.to_academic_level_id IS NOT NULL AND latest.to_academic_level_id <> s.current_academic_level_id AND latest.student_progression_decision_id = (SELECT MAX(prior.student_progression_decision_id) FROM `alrowad_uni_rust`.`student_progression_decisions` prior WHERE prior.student_id = latest.student_id AND prior.materialized_at IS NOT NULL AND prior.decision_result = ''promoted'')', 'SELECT @spd_materialized_mismatch := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@spd_exists = 1, 'SELECT @spd_unknown_status := COUNT(*) FROM `alrowad_uni_rust`.`student_progression_decisions` WHERE status NOT IN (''submitted'',''returned'',''approved'',''superseded'')', 'SELECT @spd_unknown_status := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@spd_exists = 1, 'SELECT @spd_unknown_result := COUNT(*) FROM `alrowad_uni_rust`.`student_progression_decisions` WHERE decision_result IS NOT NULL AND decision_result NOT IN (''promoted'',''retained'')', 'SELECT @spd_unknown_result := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@spd_exists = 1, 'SELECT @spd_materialized_missing_at := COUNT(*) FROM `alrowad_uni_rust`.`student_progression_decisions` WHERE status = ''approved'' AND materialized_at IS NULL', 'SELECT @spd_materialized_missing_at := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@sgd_exists = 1, 'SELECT @sgd_slot_bad := COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions` WHERE current_slot IS NOT NULL AND current_slot <> 1', 'SELECT @sgd_slot_bad := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@sgd_exists = 1, 'SELECT @sgd_current_dup := COUNT(*) FROM (SELECT student_id FROM `alrowad_uni_rust`.`student_graduation_decisions` WHERE current_slot = 1 GROUP BY student_id HAVING COUNT(*) > 1) d', 'SELECT @sgd_current_dup := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@sgd_exists = 1, 'SELECT @sgd_identity_bad := COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions` d JOIN `alrowad_uni_rust`.`students` s ON s.student_id = d.student_id WHERE d.academic_program_id <> s.academic_program_id AND d.current_slot = 1', 'SELECT @sgd_identity_bad := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@sgd_exists = 1 AND @graduated_status_ok = 1, 'SELECT @sgd_status_mismatch := COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions` d JOIN `alrowad_uni_rust`.`students` s ON s.student_id = d.student_id JOIN `alrowad_uni_rust`.`student_statuses` ss ON ss.student_status_id = s.student_status_id WHERE d.materialized_at IS NOT NULL AND ss.status_code <> ''graduated''', 'SELECT @sgd_status_mismatch := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@sgd_exists = 1, 'SELECT @sgd_unknown_status := COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions` WHERE status NOT IN (''submitted'',''returned'',''approved'',''superseded'')', 'SELECT @sgd_unknown_status := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@sgd_exists = 1, 'SELECT @sgd_unknown_result := COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions` WHERE decision_result IS NOT NULL AND decision_result <> ''graduated''', 'SELECT @sgd_unknown_result := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@sgd_exists = 1, 'SELECT @sgd_approved_result_bad := COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions` WHERE status = ''approved'' AND materialized_at IS NOT NULL AND (decision_result IS NULL OR decision_result <> ''graduated'')', 'SELECT @sgd_approved_result_bad := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@sgd_exists = 1, 'SELECT @sgd_materialized_missing_at := COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions` WHERE status = ''approved'' AND materialized_at IS NULL', 'SELECT @sgd_materialized_missing_at := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @invariants_ok := IF(
    @term_dupes = 0 AND @finalized_incomplete = 0 AND @unfinalized_pretend = 0
    AND @spd_slot_bad = 0 AND @spd_current_dup = 0 AND @spd_identity_bad = 0
    AND @spd_materialized_mismatch = 0 AND @spd_unknown_status = 0 AND @spd_unknown_result = 0 AND @spd_materialized_missing_at = 0
    AND @sgd_slot_bad = 0 AND @sgd_current_dup = 0 AND @sgd_identity_bad = 0
    AND @sgd_status_mismatch = 0 AND @sgd_unknown_status = 0 AND @sgd_unknown_result = 0
    AND @sgd_approved_result_bad = 0 AND @sgd_materialized_missing_at = 0,
    1, 0
);

SELECT 'term_columns' AS check_name, IF(@col_is_finalized_ok = 1 AND @col_earned_hours_ok = 1 AND @col_attempted_hours_ok = 1 AND @col_finalized_at_ok = 1 AND @col_finalized_by_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'fk_sat_finalized_by' AS check_name, IF(@fk_sat_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'uq_student_term' AS check_name, IF(@uq_student_term_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'progression_columns_exact' AS check_name, IF(@spd_columns_ok = 1 AND @spe_columns_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'graduation_columns_exact' AS check_name, IF(@sgd_columns_ok = 1 AND @sge_columns_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'progression_tables_innodb' AS check_name, IF(@spd_engine_ok = 1 AND @spe_engine_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'graduation_tables_innodb' AS check_name, IF(@sgd_engine_ok = 1 AND @sge_engine_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'uq_spd_current_slot' AS check_name, IF(@idx_spd_current_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'idx_spd_student_status_non_unique' AS check_name, IF(@idx_spd_status_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'idx_spd_reviewer_non_unique' AS check_name, IF(@idx_spd_reviewer_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'idx_spe_decision_non_unique' AS check_name, IF(@idx_spe_decision_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'idx_spe_actor_non_unique' AS check_name, IF(@idx_spe_actor_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'uq_sgd_current_slot' AS check_name, IF(@idx_sgd_current_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'idx_sgd_student_status_non_unique' AS check_name, IF(@idx_sgd_status_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'idx_sgd_reviewer_non_unique' AS check_name, IF(@idx_sgd_reviewer_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'idx_sge_decision_non_unique' AS check_name, IF(@idx_sge_decision_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'idx_sge_actor_non_unique' AS check_name, IF(@idx_sge_actor_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'spd_fk_targets' AS check_name, IF(@spd_fk_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'sgd_fk_targets' AS check_name, IF(@sgd_fk_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'registration_officer_rbac' AS check_name, IF(@rbac_officer = 1 AND @rbac_extra = 0, 'PASS', 'FAIL') AS result;
SELECT 'no_duplicate_terms' AS check_name, IF(@term_dupes = 0, 'PASS', 'FAIL') AS result;
SELECT 'finalized_term_metadata' AS check_name, IF(@finalized_incomplete = 0, 'PASS', 'FAIL') AS result;
SELECT 'unfinalized_term_metadata' AS check_name, IF(@unfinalized_pretend = 0, 'PASS', 'FAIL') AS result;
SELECT 'progression_current_slot' AS check_name, IF(@spd_slot_bad = 0 AND @spd_current_dup = 0, 'PASS', 'FAIL') AS result;
SELECT 'progression_identity' AS check_name, IF(@spd_identity_bad = 0, 'PASS', 'FAIL') AS result;
SELECT 'progression_materialized_level' AS check_name, IF(@spd_materialized_mismatch = 0, 'PASS', 'FAIL') AS result;
SELECT 'progression_statuses' AS check_name, IF(@spd_unknown_status = 0 AND @spd_materialized_missing_at = 0, 'PASS', 'FAIL') AS result;
SELECT 'progression_decision_results' AS check_name, IF(@spd_unknown_result = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_current_slot' AS check_name, IF(@sgd_slot_bad = 0 AND @sgd_current_dup = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_identity' AS check_name, IF(@sgd_identity_bad = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_materialized_status' AS check_name, IF(@sgd_status_mismatch = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_statuses' AS check_name, IF(@sgd_unknown_status = 0 AND @sgd_materialized_missing_at = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_decision_results' AS check_name, IF(@sgd_unknown_result = 0 AND @sgd_approved_result_bad = 0, 'PASS', 'FAIL') AS result;
SELECT 'OVERALL' AS report_section, IF(@structure_ok = 1 AND @invariants_ok = 1, 'PASS', 'FAIL') AS result;
