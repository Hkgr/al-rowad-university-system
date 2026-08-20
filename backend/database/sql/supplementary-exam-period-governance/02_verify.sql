-- READ ONLY. Must say OVERALL | PASS after a successful apply.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE/ALTER application data.
-- Do not use DATABASE().
--
-- Does not require zero announced rows (production may create them after apply).
-- Fails if historical legacy attribution was fabricated (legacy + opened_by/opened_at).

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @periods_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND table_type = 'BASE TABLE'), 0);
SET @results_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_results' AND table_type = 'BASE TABLE'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND table_type = 'BASE TABLE'), 0);

SET @status_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status'), 0);
SET @opened_by_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id'), 0);
SET @opened_at_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_at'), 0);
SET @decision_note_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'decision_note'), 0);

SET @status_valid := IF(
    @status_exists = 1
    AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status') = 'varchar'
    AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status') = 'NO',
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
SET @uq_identity := IF(
    @db_ready = 1
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_periods'
          AND index_name = 'uq_sep_year_semester'
          AND non_unique = 0
    ) <=> 'academic_year_id,semester_id',
    1, 0
);
SET @idx_status := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_periods'
       AND index_name = 'idx_sep_status'),
    0
);

SET @fk_event_period := IF(
    @events_exist = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_period_events'
       AND constraint_name = 'fk_sepe_period'
       AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @fk_event_actor := IF(
    @events_exist = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_period_events'
       AND constraint_name = 'fk_sepe_actor'
       AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @idx_event_period := IF(
    @events_exist = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_period_events'
       AND index_name = 'idx_sepe_period'),
    0
);
SET @idx_event_actor := IF(
    @events_exist = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_period_events'
       AND index_name = 'idx_sepe_actor'),
    0
);
SET @idx_event_type := IF(
    @events_exist = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'supplementary_exam_period_events'
       AND index_name = 'idx_sepe_event_type'),
    0
);

SET @view_perm := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'supplementary_exams.periods.view' AND is_active = 1), 0);
SET @decide_perm := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'supplementary_exams.periods.decide' AND is_active = 1), 0);

SET @sci_decide := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'supplementary_exams.periods.decide'),
    0
);
SET @adm_decide := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'supplementary_exams.periods.decide'),
    0
);
SET @vp_decide := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'vice_president' AND p.permission_code = 'supplementary_exams.periods.decide'),
    0
);
SET @super_admin_decide := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'super_admin' AND p.permission_code = 'supplementary_exams.periods.decide'),
    0
);
SET @dean_decide := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'dean' AND p.permission_code = 'supplementary_exams.periods.decide'),
    0
);
SET @sci_view := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'supplementary_exams.periods.view'),
    0
);
SET @dean_view := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'dean' AND p.permission_code = 'supplementary_exams.periods.view'),
    0
);

SET @duplicate_pairs := 0;
SET @orphan_events := 0;
SET @legacy_fabricated := 0;
SET @invalid_status := 0;
SET @period_rows := 0;
SET @result_rows := 0;

SET @sql := IF(@periods_exist = 1, 'SELECT @period_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods`', 'SELECT @period_rows := 0');
PREPARE phase1_vf_periods FROM @sql;
EXECUTE phase1_vf_periods;
DEALLOCATE PREPARE phase1_vf_periods;

SET @sql := IF(@results_exist = 1, 'SELECT @result_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_results`', 'SELECT @result_rows := 0');
PREPARE phase1_vf_results FROM @sql;
EXECUTE phase1_vf_results;
DEALLOCATE PREPARE phase1_vf_results;

SET @sql := IF(
    @periods_exist = 1,
    'SELECT @duplicate_pairs := COUNT(*) FROM (SELECT academic_year_id, semester_id FROM `alrowad_uni_rust`.`supplementary_exam_periods` GROUP BY academic_year_id, semester_id HAVING COUNT(*) > 1) d',
    'SELECT @duplicate_pairs := 0'
);
PREPARE phase1_vf_dup FROM @sql;
EXECUTE phase1_vf_dup;
DEALLOCATE PREPARE phase1_vf_dup;

SET @sql := IF(
    @events_exist = 1,
    'SELECT @orphan_events := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_period_events` e LEFT JOIN `alrowad_uni_rust`.`supplementary_exam_periods` p ON p.supplementary_exam_period_id = e.supplementary_exam_period_id WHERE p.supplementary_exam_period_id IS NULL',
    'SELECT @orphan_events := 0'
);
PREPARE phase1_vf_orphan_events FROM @sql;
EXECUTE phase1_vf_orphan_events;
DEALLOCATE PREPARE phase1_vf_orphan_events;

SET @sql := IF(
    @status_exists = 1 AND @opened_by_exists = 1 AND @opened_at_exists = 1,
    'SELECT @legacy_fabricated := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods` WHERE status = ''legacy'' AND (opened_by_user_id IS NOT NULL OR opened_at IS NOT NULL)',
    'SELECT @legacy_fabricated := 1'
);
PREPARE phase1_vf_legacy FROM @sql;
EXECUTE phase1_vf_legacy;
DEALLOCATE PREPARE phase1_vf_legacy;

SET @sql := IF(
    @status_exists = 1,
    'SELECT @invalid_status := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_periods` WHERE status NOT IN (''legacy'', ''announced'', ''registration_open'', ''registration_closed'', ''in_progress'', ''results_processing'', ''locked'')',
    'SELECT @invalid_status := 1'
);
PREPARE phase1_vf_status FROM @sql;
EXECUTE phase1_vf_status;
DEALLOCATE PREPARE phase1_vf_status;

SELECT 'VERIFY_TABLES' AS check_name, IF(@periods_exist = 1 AND @results_exist = 1, 'PASS', 'FAIL') AS result;
SELECT 'governance_columns' AS check_name, IF(@status_exists = 1 AND @opened_by_exists = 1 AND @opened_at_exists = 1 AND @decision_note_exists = 1 AND @status_valid = 1, 'PASS', 'FAIL') AS result;
SELECT 'opened_by_fk' AS check_name, IF(@fk_opened_by = 1, 'PASS', 'FAIL') AS result;
SELECT 'unique_identity' AS check_name, IF(@uq_identity = 1, 'PASS', 'FAIL') AS result;
SELECT 'event_table' AS check_name, IF(@events_exist = 1 AND @fk_event_period = 1 AND @fk_event_actor = 1 AND @idx_event_period = 1 AND @idx_event_actor = 1 AND @idx_event_type = 1, 'PASS', 'FAIL') AS result;
SELECT 'idx_status' AS check_name, IF(@idx_status = 1, 'PASS', 'FAIL') AS result;
SELECT 'view_permission' AS check_name, IF(@view_perm = 1 AND @sci_view >= 1 AND @dean_view >= 1, 'PASS', 'FAIL') AS result;
SELECT 'decide_permission' AS check_name, IF(@decide_perm = 1 AND @sci_decide >= 1, 'PASS', 'FAIL') AS result;
SELECT 'administrative_vp_no_decide' AS check_name, IF(@adm_decide = 0, 'PASS', 'FAIL') AS result;
SELECT 'generic_vp_no_decide' AS check_name, IF(@vp_decide = 0, 'PASS', 'FAIL') AS result;
SELECT 'super_admin_no_decide_mapping' AS check_name, IF(@super_admin_decide = 0, 'PASS', 'FAIL') AS result;
SELECT 'dean_no_decide' AS check_name, IF(@dean_decide = 0, 'PASS', 'FAIL') AS result;
SELECT 'duplicate_identities' AS check_name, IF(@duplicate_pairs = 0, 'PASS', 'FAIL') AS result, @duplicate_pairs AS actual;
SELECT 'orphan_events' AS check_name, IF(@orphan_events = 0, 'PASS', 'FAIL') AS result, @orphan_events AS actual;
SELECT 'legacy_not_attributed' AS check_name, IF(@legacy_fabricated = 0, 'PASS', 'FAIL') AS result, @legacy_fabricated AS actual;
SELECT 'status_values' AS check_name, IF(@invalid_status = 0, 'PASS', 'FAIL') AS result, @invalid_status AS actual;
SELECT 'results_preserved' AS check_name, IF(@results_exist = 1, 'PASS', 'FAIL') AS result, @result_rows AS supplementary_exam_results_rows;
SELECT 'periods_preserved' AS check_name, IF(@periods_exist = 1, 'PASS', 'FAIL') AS result, @period_rows AS supplementary_exam_periods_rows;

SET @overall := IF(
    @db_ready = 1
    AND @periods_exist = 1
    AND @results_exist = 1
    AND @status_exists = 1
    AND @opened_by_exists = 1
    AND @opened_at_exists = 1
    AND @decision_note_exists = 1
    AND @status_valid = 1
    AND @fk_opened_by = 1
    AND @uq_identity = 1
    AND @idx_status = 1
    AND @events_exist = 1
    AND @fk_event_period = 1
    AND @fk_event_actor = 1
    AND @idx_event_period = 1
    AND @idx_event_actor = 1
    AND @idx_event_type = 1
    AND @view_perm = 1
    AND @decide_perm = 1
    AND @sci_decide >= 1
    AND @sci_view >= 1
    AND @dean_view >= 1
    AND @adm_decide = 0
    AND @vp_decide = 0
    AND @super_admin_decide = 0
    AND @dean_decide = 0
    AND @duplicate_pairs = 0
    AND @orphan_events = 0
    AND @legacy_fabricated = 0
    AND @invalid_status = 0,
    'PASS',
    'FAIL'
);

SELECT 'OVERALL' AS report_section, @overall AS result;
