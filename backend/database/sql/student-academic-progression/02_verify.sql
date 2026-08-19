-- READ ONLY. Continue only when OVERALL returns PASS.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE application data.
-- Do not use the DATABASE function, stored procedures, DELIMITER, or SIGNAL.
-- Compatibility predicates below must stay equivalent in 00_preflight.sql and 01_apply.sql.
-- Named NON-UNIQUE indexes are FAIL when they exist as UNIQUE.
-- Business-row checks against optional Phase 10 tables use guarded dynamic SQL.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @spd_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND table_type = 'BASE TABLE'), 0);
SET @spe_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events' AND table_type = 'BASE TABLE'), 0);
SET @sgd_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND table_type = 'BASE TABLE'), 0);
SET @sge_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events' AND table_type = 'BASE TABLE'), 0);

SET @term_cols_ok := IF(
    @db_ready = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'is_finalized' AND data_type = 'tinyint' AND is_nullable = 'NO') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_at' AND data_type = 'timestamp' AND is_nullable = 'YES') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_by_user_id' AND data_type = 'int' AND is_nullable = 'YES') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'earned_hours' AND data_type = 'int' AND is_nullable = 'NO') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'attempted_hours' AND data_type = 'int' AND is_nullable = 'NO') = 1,
    1, 0
);

SET @uq_student_term_ok := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms'
          AND index_name = 'uq_student_term' AND non_unique = 0
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,academic_year_id,semester_id'
    ),
    1, 0
);

SET @spd_engine_ok := IF(@spd_exists = 1 AND (SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions') = 'innodb', 1, 0);
SET @spe_engine_ok := IF(@spe_exists = 1 AND (SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events') = 'innodb', 1, 0);
SET @sgd_engine_ok := IF(@sgd_exists = 1 AND (SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions') = 'innodb', 1, 0);
SET @sge_engine_ok := IF(@sge_exists = 1 AND (SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events') = 'innodb', 1, 0);

SET @idx_spd_current_ok := IF(@spd_exists = 1 AND (
    SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions'
          AND index_name = 'uq_spd_current_slot' AND non_unique = 0
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,academic_year_id,current_slot'
    ) x
) = 1, 1, 0);
SET @idx_spd_status_ok := IF(@spd_exists = 1 AND (
    SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions'
          AND index_name = 'idx_spd_student_status' AND non_unique = 1
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,status'
    ) x
) = 1, 1, 0);
SET @idx_sgd_current_ok := IF(@sgd_exists = 1 AND (
    SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions'
          AND index_name = 'uq_sgd_current_slot' AND non_unique = 0
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,current_slot'
    ) x
) = 1, 1, 0);
SET @idx_sgd_status_ok := IF(@sgd_exists = 1 AND (
    SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions'
          AND index_name = 'idx_sgd_student_status' AND non_unique = 1
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,status'
    ) x
) = 1, 1, 0);

SET @registration_officer_active := IF(@db_ready = 1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'registration_officer' AND is_active = 1) = 1, 1, 0);
SET @graduated_status_ok := IF(@db_ready = 1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_statuses` WHERE status_code = 'graduated' AND is_active = 1) = 1, 1, 0);

SET @rbac_officer := IF(
    @db_ready = 1 AND (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE r.role_code = 'registration_officer' AND r.is_active = 1
          AND p.is_active = 1 AND sm.module_code = 'students'
          AND p.permission_code IN (
              'academic_records.view', 'academic_records.finalize',
              'academic_progression.view', 'academic_progression.review',
              'graduation_decisions.view', 'graduation_decisions.review'
          )
    ) = 6,
    1, 0
);

SET @rbac_extra := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code IN (
            'academic_records.view', 'academic_records.finalize',
            'academic_progression.view', 'academic_progression.review',
            'graduation_decisions.view', 'graduation_decisions.review'
        )
          AND r.role_code <> 'registration_officer'
    ),
    1
);

SET @term_dupes := 0;
SET @finalized_incomplete := 0;
SET @unfinalized_pretend := 0;
SET @spd_slot_bad := 0;
SET @spd_current_dup := 0;
SET @spd_identity_bad := 0;
SET @spd_materialized_mismatch := 0;
SET @spd_unknown_status := 0;
SET @spd_materialized_missing_at := 0;
SET @sgd_slot_bad := 0;
SET @sgd_current_dup := 0;
SET @sgd_identity_bad := 0;
SET @sgd_status_mismatch := 0;
SET @sgd_unknown_status := 0;
SET @sgd_materialized_missing_at := 0;

SET @sql := IF(@db_ready = 1, 'SELECT @term_dupes := COUNT(*) FROM (SELECT student_id, academic_year_id, semester_id FROM `alrowad_uni_rust`.`student_academic_terms` GROUP BY student_id, academic_year_id, semester_id HAVING COUNT(*) > 1) d', 'SELECT @term_dupes := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @term_cols_ok = 1,
    'SELECT @finalized_incomplete := COUNT(*) FROM `alrowad_uni_rust`.`student_academic_terms` WHERE is_finalized = 1 AND (finalized_at IS NULL OR finalized_by_user_id IS NULL)',
    'SELECT @finalized_incomplete := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @term_cols_ok = 1,
    'SELECT @unfinalized_pretend := COUNT(*) FROM `alrowad_uni_rust`.`student_academic_terms` WHERE is_finalized = 0 AND (finalized_at IS NOT NULL OR finalized_by_user_id IS NOT NULL)',
    'SELECT @unfinalized_pretend := 1'
);
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

SET @sql := IF(@sgd_exists = 1, 'SELECT @sgd_materialized_missing_at := COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions` WHERE status = ''approved'' AND materialized_at IS NULL', 'SELECT @sgd_materialized_missing_at := 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sgd_fk_ok := IF(@sgd_exists = 1 AND (
    SELECT COUNT(DISTINCT constraint_name) FROM information_schema.referential_constraints
    WHERE constraint_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions'
      AND constraint_name IN ('fk_sgd_student','fk_sgd_program','fk_sgd_level','fk_sgd_submitted_by','fk_sgd_reviewed_by')
) = 5, 1, 0);
SET @sge_fk_ok := IF(@sge_exists = 1 AND (
    SELECT COUNT(DISTINCT constraint_name) FROM information_schema.referential_constraints
    WHERE constraint_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events'
      AND constraint_name IN ('fk_sge_decision','fk_sge_actor')
) = 2, 1, 0);
SET @spd_fk_ok := IF(@spd_exists = 1 AND (
    SELECT COUNT(DISTINCT constraint_name) FROM information_schema.referential_constraints
    WHERE constraint_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions'
      AND constraint_name IN ('fk_spd_student','fk_spd_program','fk_spd_year','fk_spd_from_level','fk_spd_to_level','fk_spd_submitted_by','fk_spd_reviewed_by')
) = 7, 1, 0);
SET @spe_fk_ok := IF(@spe_exists = 1 AND (
    SELECT COUNT(DISTINCT constraint_name) FROM information_schema.referential_constraints
    WHERE constraint_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events'
      AND constraint_name IN ('fk_spe_decision','fk_spe_actor')
) = 2, 1, 0);

SET @structure_ok := IF(
    @db_ready = 1 AND @term_cols_ok = 1 AND @uq_student_term_ok = 1
    AND @spd_exists = 1 AND @spe_exists = 1 AND @sgd_exists = 1 AND @sge_exists = 1
    AND @spd_engine_ok = 1 AND @spe_engine_ok = 1 AND @sgd_engine_ok = 1 AND @sge_engine_ok = 1
    AND @idx_spd_current_ok = 1 AND @idx_spd_status_ok = 1
    AND @idx_sgd_current_ok = 1 AND @idx_sgd_status_ok = 1
    AND @spd_fk_ok = 1 AND @spe_fk_ok = 1 AND @sgd_fk_ok = 1 AND @sge_fk_ok = 1
    AND @registration_officer_active = 1 AND @graduated_status_ok = 1
    AND @rbac_officer = 1 AND @rbac_extra = 0,
    1, 0
);

SET @invariants_ok := IF(
    @term_dupes = 0 AND @finalized_incomplete = 0 AND @unfinalized_pretend = 0
    AND @spd_slot_bad = 0 AND @spd_current_dup = 0 AND @spd_identity_bad = 0
    AND @spd_materialized_mismatch = 0 AND @spd_unknown_status = 0 AND @spd_materialized_missing_at = 0
    AND @sgd_slot_bad = 0 AND @sgd_current_dup = 0 AND @sgd_identity_bad = 0
    AND @sgd_status_mismatch = 0 AND @sgd_unknown_status = 0 AND @sgd_materialized_missing_at = 0,
    1, 0
);

SELECT 'term_columns' AS check_name, IF(@term_cols_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'uq_student_term' AS check_name, IF(@uq_student_term_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'progression_tables_innodb' AS check_name, IF(@spd_engine_ok = 1 AND @spe_engine_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'graduation_tables_innodb' AS check_name, IF(@sgd_engine_ok = 1 AND @sge_engine_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'uq_spd_current_slot' AS check_name, IF(@idx_spd_current_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'idx_spd_student_status_non_unique' AS check_name, IF(@idx_spd_status_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'uq_sgd_current_slot' AS check_name, IF(@idx_sgd_current_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'idx_sgd_student_status_non_unique' AS check_name, IF(@idx_sgd_status_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'registration_officer_rbac' AS check_name, IF(@rbac_officer = 1 AND @rbac_extra = 0, 'PASS', 'FAIL') AS result;
SELECT 'no_duplicate_terms' AS check_name, IF(@term_dupes = 0, 'PASS', 'FAIL') AS result;
SELECT 'finalized_term_metadata' AS check_name, IF(@finalized_incomplete = 0, 'PASS', 'FAIL') AS result;
SELECT 'unfinalized_term_metadata' AS check_name, IF(@unfinalized_pretend = 0, 'PASS', 'FAIL') AS result;
SELECT 'progression_current_slot' AS check_name, IF(@spd_slot_bad = 0 AND @spd_current_dup = 0, 'PASS', 'FAIL') AS result;
SELECT 'progression_identity' AS check_name, IF(@spd_identity_bad = 0, 'PASS', 'FAIL') AS result;
SELECT 'progression_materialized_level' AS check_name, IF(@spd_materialized_mismatch = 0, 'PASS', 'FAIL') AS result;
SELECT 'progression_statuses' AS check_name, IF(@spd_unknown_status = 0 AND @spd_materialized_missing_at = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_current_slot' AS check_name, IF(@sgd_slot_bad = 0 AND @sgd_current_dup = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_identity' AS check_name, IF(@sgd_identity_bad = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_materialized_status' AS check_name, IF(@sgd_status_mismatch = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_statuses' AS check_name, IF(@sgd_unknown_status = 0 AND @sgd_materialized_missing_at = 0, 'PASS', 'FAIL') AS result;
SELECT 'OVERALL' AS report_section, IF(@structure_ok = 1 AND @invariants_ok = 1, 'PASS', 'FAIL') AS result;
