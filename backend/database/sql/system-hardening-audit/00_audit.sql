-- READ ONLY. Phase 11 cross-phase academic-core audit.
-- SQL NOT EXECUTED by the application, seeders, or Laravel migrations.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE/ALTER/DROP/TRUNCATE.
-- Do not use the DATABASE function, stored procedures, DELIMITER, or SIGNAL.
-- Missing required infrastructure must yield OVERALL = FAIL, never SQL error #1146 / #1054.
-- Every dynamic SELECT against application tables is gated by information_schema
-- TABLE and COLUMN prerequisites for the columns that query references.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @missing_core_objects := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'users' AS table_name, 'user_id' AS column_name
            UNION ALL SELECT 'users', 'email'
            UNION ALL SELECT 'users', 'password_hash'
            UNION ALL SELECT 'users', 'account_status_id'
            UNION ALL SELECT 'roles', 'role_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'roles', 'is_active'
            UNION ALL SELECT 'permissions', 'permission_id'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'permissions', 'is_active'
            UNION ALL SELECT 'role_permissions', 'role_id'
            UNION ALL SELECT 'role_permissions', 'permission_id'
            UNION ALL SELECT 'students', 'student_id'
            UNION ALL SELECT 'students', 'student_status_id'
            UNION ALL SELECT 'student_statuses', 'student_status_id'
            UNION ALL SELECT 'student_statuses', 'status_code'
            UNION ALL SELECT 'student_statuses', 'is_active'
            UNION ALL SELECT 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'course_offerings', 'status'
            UNION ALL SELECT 'course_offerings', 'capacity'
            UNION ALL SELECT 'course_offerings', 'available_seats'
            UNION ALL SELECT 'student_course_registrations', 'student_course_registration_id'
            UNION ALL SELECT 'student_course_registrations', 'student_id'
            UNION ALL SELECT 'student_course_registrations', 'course_offering_id'
            UNION ALL SELECT 'student_course_registrations', 'registration_status_id'
            UNION ALL SELECT 'registration_statuses', 'registration_status_id'
            UNION ALL SELECT 'registration_statuses', 'status_code'
            UNION ALL SELECT 'registration_statuses', 'is_active'
            UNION ALL SELECT 'login_audit_logs', 'login_audit_id'
            UNION ALL SELECT 'login_audit_logs', 'login_status'
            UNION ALL SELECT 'login_audit_logs', 'ip_address'
            UNION ALL SELECT 'login_audit_logs', 'username_attempted'
        ) required
        LEFT JOIN information_schema.tables t
            ON t.table_schema = 'alrowad_uni_rust'
           AND t.table_name = required.table_name
           AND t.table_type = 'BASE TABLE'
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = required.table_name
           AND c.column_name = required.column_name
        WHERE t.table_name IS NULL OR c.column_name IS NULL
    ),
    1
);
SET @core_ready := IF(@missing_core_objects = 0, 1, 0);

SET @tar_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND table_type = 'BASE TABLE'), 0);
SET @srwr_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_withdrawal_requests' AND table_type = 'BASE TABLE'), 0);
SET @sat_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND table_type = 'BASE TABLE'), 0);
SET @spd_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND table_type = 'BASE TABLE'), 0);
SET @sgd_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND table_type = 'BASE TABLE'), 0);

SET @tar_cols_ok := IF(
    @tar_exist = 1
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'course_offering_id')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'instructor_role')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'current_slot')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'),
    1, 0
);
SET @action_type_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), 0);

SET @srwr_cols_ok := IF(
    @srwr_exist = 1
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_withdrawal_requests' AND column_name = 'student_course_registration_id')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_withdrawal_requests' AND column_name = 'current_slot'),
    1, 0
);
SET @sat_cols_ok := IF(
    @sat_exist = 1
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'student_id')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'academic_year_id')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'semester_id')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'is_finalized')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_at')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_by_user_id'),
    1, 0
);
SET @spd_cols_ok := IF(
    @spd_exist = 1
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND column_name = 'student_id')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND column_name = 'academic_year_id')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND column_name = 'current_slot')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND column_name = 'status')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND column_name = 'decision_result'),
    1, 0
);
SET @sgd_cols_ok := IF(
    @sgd_exist = 1
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND column_name = 'student_id')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND column_name = 'current_slot')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND column_name = 'status')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND column_name = 'decision_result')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND column_name = 'materialized_at'),
    1, 0
);

SET @required_status_codes := 0;
SET @sql := IF(
    @core_ready = 1,
    'SELECT @required_status_codes := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`registration_statuses` WHERE status_code IN (''registered'',''dropped'',''withdrawn'') AND is_active = 1) = 3 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_statuses` WHERE status_code = ''graduated'' AND is_active = 1) = 1, 1, 0)',
    'SELECT @required_status_codes := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @vp_scientific := 0;
SET @vp_administrative := 0;
SET @generic_vp_forbidden := 1;
SET @super_admin_forbidden := 1;
SET @registration_officer_phase10 := 0;
SET @academic_advisor_withdrawals := 0;
SET @sql := IF(
    @core_ready = 1,
    'SELECT @vp_scientific := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = ''vice_president_scientific'' AND is_active = 1) = 1, 1, 0), @vp_administrative := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = ''vice_president_administrative'' AND is_active = 1) = 1, 1, 0)',
    'SELECT @vp_scientific := 0, @vp_administrative := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Exact dedicated scientific/administrative mutation authority from Phases 3/6/7/8.
SET @sql := IF(
    @core_ready = 1,
    'SELECT @generic_vp_forbidden := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = ''vice_president'' AND p.permission_code IN (''teaching_assignments.manage'',''teaching_assignments.review_scientific'',''teaching_assignments.review_administrative'',''course_offerings.exceptional_open.request'',''course_offerings.exceptional_open.review_scientific'',''course_offerings.exceptional_open.review_administrative'',''course_offerings.closure.request'',''course_offerings.closure.review_scientific'',''course_offerings.closure.review_administrative'',''registration_withdrawals.review'',''academic_records.finalize'',''academic_progression.review'',''graduation_decisions.review'',''vice_presidency.scientific.access'',''vice_presidency.administrative.access''))',
    'SELECT @generic_vp_forbidden := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Exact academic-governance mutation codes from Phases 6–10 (not view permissions).
SET @sql := IF(
    @core_ready = 1,
    'SELECT @super_admin_forbidden := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = ''super_admin'' AND p.permission_code IN (''teaching_assignments.manage'',''teaching_assignments.review_scientific'',''teaching_assignments.review_administrative'',''course_offerings.exceptional_open.request'',''course_offerings.exceptional_open.review_scientific'',''course_offerings.exceptional_open.review_administrative'',''course_offerings.closure.request'',''course_offerings.closure.review_scientific'',''course_offerings.closure.review_administrative'',''registration_withdrawals.review'',''academic_records.finalize'',''academic_progression.review'',''graduation_decisions.review''))',
    'SELECT @super_admin_forbidden := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @core_ready = 1,
    'SELECT @registration_officer_phase10 := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = ''registration_officer'' AND r.is_active = 1 AND p.is_active = 1 AND p.permission_code IN (''academic_records.view'',''academic_records.finalize'',''academic_progression.view'',''academic_progression.review'',''graduation_decisions.view'',''graduation_decisions.review'')) = 6 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE p.permission_code IN (''academic_records.view'',''academic_records.finalize'',''academic_progression.view'',''academic_progression.review'',''graduation_decisions.view'',''graduation_decisions.review'') AND r.role_code <> ''registration_officer'') = 0, 1, 0)',
    'SELECT @registration_officer_phase10 := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @core_ready = 1,
    'SELECT @academic_advisor_withdrawals := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = ''academic_advisor'' AND r.is_active = 1 AND p.is_active = 1 AND p.permission_code IN (''registration_withdrawals.view'',''registration_withdrawals.review'')) = 2 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE p.permission_code IN (''registration_withdrawals.view'',''registration_withdrawals.review'') AND r.role_code <> ''academic_advisor'') = 0, 1, 0)',
    'SELECT @academic_advisor_withdrawals := 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @malformed_offering_status := 1;
SET @offering_capacity_bounds := 1;
SET @offering_seat_bounds := 1;
SET @offering_occupancy_mismatch := 1;
SET @sql := IF(
    @core_ready = 1,
    'SELECT @malformed_offering_status := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings` WHERE status IS NULL OR status NOT IN (''open'',''closed'')), @offering_capacity_bounds := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings` WHERE capacity IS NULL OR capacity < 1), @offering_seat_bounds := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings` WHERE available_seats IS NULL OR available_seats < 0 OR available_seats > capacity)',
    'SELECT @malformed_offering_status := 1, @offering_capacity_bounds := 1, @offering_seat_bounds := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Occupancy uses canonical current registered rows only (dropped/withdrawn excluded).
-- student_course_registrations has no current_slot; "current" means status_code = registered.
-- Included in OVERALL: drifted counters must be repaired by the DBA, not by Phase 11 apply.
SET @sql := IF(
    @core_ready = 1,
    'SELECT @offering_occupancy_mismatch := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings` o WHERE o.available_seats <> (o.capacity - (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_course_registrations` scr JOIN `alrowad_uni_rust`.`registration_statuses` rs ON rs.registration_status_id = scr.registration_status_id WHERE scr.course_offering_id = o.course_offering_id AND rs.status_code = ''registered'')))',
    'SELECT @offering_occupancy_mismatch := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Exact Phase 8 uq_tar_current_slot contract.
SET @tar_uq := IF(
    @tar_exist = 1 AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'uq_tar_current_slot'
          AND non_unique = 0
    ) <=> 'course_offering_id,instructor_role,current_slot',
    1, 0
);
SET @tar_dup := 1;
SET @tar_bad_slot := 1;
SET @tar_bad_action := 1;
SET @sql := IF(
    @tar_cols_ok = 1,
    'SELECT @tar_dup := (SELECT COUNT(*) FROM (SELECT course_offering_id, instructor_role FROM `alrowad_uni_rust`.`teaching_assignment_requests` WHERE current_slot = 1 GROUP BY course_offering_id, instructor_role HAVING COUNT(*) > 1) x), @tar_bad_slot := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_requests` WHERE current_slot IS NOT NULL AND current_slot <> 1)',
    'SELECT @tar_dup := 1, @tar_bad_slot := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    @action_type_exists = 1,
    'SELECT @tar_bad_action := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_requests` WHERE action_type NOT IN (''assign'',''replace'',''remove''))',
    'SELECT @tar_bad_action := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Exact Phase 9 uq_srwr_current_slot contract.
SET @srwr_uq := IF(
    @srwr_exist = 1
    AND (
        SELECT MIN(non_unique)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_withdrawal_requests'
          AND index_name = 'uq_srwr_current_slot'
    ) = 0
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_withdrawal_requests'
          AND index_name = 'uq_srwr_current_slot'
    ) <=> 'student_course_registration_id,current_slot',
    1, 0
);
SET @srwr_dup := 1;
SET @srwr_bad_slot := 1;
SET @sql := IF(
    @srwr_cols_ok = 1,
    'SELECT @srwr_dup := (SELECT COUNT(*) FROM (SELECT student_course_registration_id FROM `alrowad_uni_rust`.`student_registration_withdrawal_requests` WHERE current_slot = 1 GROUP BY student_course_registration_id HAVING COUNT(*) > 1) x), @srwr_bad_slot := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_registration_withdrawal_requests` WHERE current_slot IS NOT NULL AND current_slot <> 1)',
    'SELECT @srwr_dup := 1, @srwr_bad_slot := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sat_uq := IF(
    @sat_exist = 1
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
SET @sat_final_inconsistent := 1;
SET @sql := IF(
    @sat_cols_ok = 1,
    'SELECT @sat_final_inconsistent := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_academic_terms` WHERE (is_finalized = 1 AND finalized_at IS NULL) OR (is_finalized = 0 AND (finalized_at IS NOT NULL OR finalized_by_user_id IS NOT NULL)))',
    'SELECT @sat_final_inconsistent := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Exact Phase 10 uq_spd_current_slot / uq_sgd_current_slot contracts.
SET @spd_uq := IF(@spd_exist = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND index_name = 'uq_spd_current_slot' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,academic_year_id,current_slot') x) = 1, 1, 0));
SET @sgd_uq := IF(@sgd_exist = 0, 0, IF((SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND index_name = 'uq_sgd_current_slot' AND non_unique = 0 GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,current_slot') x) = 1, 1, 0));

SET @spd_dup := 1;
SET @spd_bad_slot := 1;
SET @spd_bad_status := 1;
SET @sgd_dup := 1;
SET @sgd_bad_slot := 1;
SET @sgd_bad_status := 1;
SET @sgd_materialized_mismatch := 1;
SET @sql := IF(
    @spd_cols_ok = 1,
    'SELECT @spd_dup := (SELECT COUNT(*) FROM (SELECT student_id, academic_year_id FROM `alrowad_uni_rust`.`student_progression_decisions` WHERE current_slot = 1 GROUP BY student_id, academic_year_id HAVING COUNT(*) > 1) x), @spd_bad_slot := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_progression_decisions` WHERE current_slot IS NOT NULL AND current_slot <> 1), @spd_bad_status := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_progression_decisions` WHERE status NOT IN (''submitted'',''returned'',''approved'',''superseded'') OR (decision_result IS NOT NULL AND decision_result NOT IN (''promoted'',''retained'')))',
    'SELECT @spd_dup := 1, @spd_bad_slot := 1, @spd_bad_status := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    @sgd_cols_ok = 1,
    'SELECT @sgd_dup := (SELECT COUNT(*) FROM (SELECT student_id FROM `alrowad_uni_rust`.`student_graduation_decisions` WHERE current_slot = 1 GROUP BY student_id HAVING COUNT(*) > 1) x), @sgd_bad_slot := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions` WHERE current_slot IS NOT NULL AND current_slot <> 1), @sgd_bad_status := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions` WHERE status NOT IN (''submitted'',''returned'',''approved'',''superseded'') OR (decision_result IS NOT NULL AND decision_result <> ''graduated''))',
    'SELECT @sgd_dup := 1, @sgd_bad_slot := 1, @sgd_bad_status := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    @sgd_cols_ok = 1 AND @core_ready = 1,
    'SELECT @sgd_materialized_mismatch := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_graduation_decisions` g JOIN `alrowad_uni_rust`.`students` s ON s.student_id = g.student_id JOIN `alrowad_uni_rust`.`student_statuses` st ON st.student_status_id = s.student_status_id WHERE g.status = ''approved'' AND g.decision_result = ''graduated'' AND g.materialized_at IS NOT NULL AND st.status_code <> ''graduated'')',
    'SELECT @sgd_materialized_mismatch := 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @login_audit_ok := IF(
    @core_ready = 1
    AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'login_audit_logs' AND column_name IN ('password','password_hash','token','plain_text_token')),
    1, 0
);

SELECT 'database_exists' AS check_name, IF(@db_ready = 1, 'PASS', 'FAIL') AS result;
SELECT 'required_core_tables' AS check_name, IF(@core_ready = 1, 'PASS', 'FAIL') AS result;
SELECT 'required_core_columns' AS check_name, IF(@core_ready = 1, 'PASS', 'FAIL') AS result;
SELECT 'required_core_status_codes' AS check_name, IF(@required_status_codes = 1, 'PASS', 'FAIL') AS result;
SELECT 'dedicated_vp_roles' AS check_name, IF(@vp_scientific = 1 AND @vp_administrative = 1, 'PASS', 'FAIL') AS result;
SELECT 'generic_vp_without_dedicated_reviews' AS check_name, IF(@generic_vp_forbidden = 0, 'PASS', 'FAIL') AS result;
SELECT 'super_admin_without_explicit_academic_mutations' AS check_name, IF(@super_admin_forbidden = 0, 'PASS', 'FAIL') AS result;
SELECT 'registration_officer_phase10_permissions' AS check_name, IF(@registration_officer_phase10 = 1, 'PASS', 'FAIL') AS result;
SELECT 'academic_advisor_withdrawal_permissions' AS check_name, IF(@academic_advisor_withdrawals = 1, 'PASS', 'FAIL') AS result;
SELECT 'course_offering_canonical_status' AS check_name, IF(@malformed_offering_status = 0, 'PASS', 'FAIL') AS result;
SELECT 'course_offering_capacity_bounds' AS check_name, IF(@offering_capacity_bounds = 0, 'PASS', 'FAIL') AS result;
SELECT 'course_offering_available_seats_bounds' AS check_name, IF(@offering_seat_bounds = 0, 'PASS', 'FAIL') AS result;
SELECT 'course_offering_seat_occupancy' AS check_name, IF(@offering_occupancy_mismatch = 0, 'PASS', 'FAIL') AS result;
SELECT 'teaching_assignment_action_type_present' AS check_name, IF(@action_type_exists = 1, 'PASS', 'FAIL') AS result;
SELECT 'teaching_assignment_current_slot_unique' AS check_name, IF(@tar_exist = 1 AND @tar_uq = 1 AND @tar_cols_ok = 1 AND @tar_dup = 0 AND @tar_bad_slot = 0, 'PASS', 'FAIL') AS result;
SELECT 'teaching_assignment_action_types' AS check_name, IF(@tar_exist = 1 AND @action_type_exists = 1 AND @tar_bad_action = 0, 'PASS', 'FAIL') AS result;
SELECT 'withdrawal_current_slot_unique' AS check_name, IF(@srwr_exist = 1 AND @srwr_uq = 1 AND @srwr_cols_ok = 1 AND @srwr_dup = 0 AND @srwr_bad_slot = 0, 'PASS', 'FAIL') AS result;
SELECT 'student_academic_term_identity' AS check_name, IF(@sat_exist = 1 AND @sat_uq = 1 AND @sat_cols_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'student_academic_term_finalized_metadata' AS check_name, IF(@sat_exist = 1 AND @sat_cols_ok = 1 AND @sat_final_inconsistent = 0, 'PASS', 'FAIL') AS result;
SELECT 'progression_current_slot_unique' AS check_name, IF(@spd_exist = 1 AND @spd_uq = 1 AND @spd_cols_ok = 1 AND @spd_dup = 0 AND @spd_bad_slot = 0, 'PASS', 'FAIL') AS result;
SELECT 'progression_status_result' AS check_name, IF(@spd_exist = 1 AND @spd_cols_ok = 1 AND @spd_bad_status = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_current_slot_unique' AS check_name, IF(@sgd_exist = 1 AND @sgd_uq = 1 AND @sgd_cols_ok = 1 AND @sgd_dup = 0 AND @sgd_bad_slot = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_status_result' AS check_name, IF(@sgd_exist = 1 AND @sgd_cols_ok = 1 AND @sgd_bad_status = 0, 'PASS', 'FAIL') AS result;
SELECT 'graduation_materialized_matches_student_status' AS check_name, IF(@sgd_exist = 1 AND @sgd_cols_ok = 1 AND @sgd_materialized_mismatch = 0, 'PASS', 'FAIL') AS result;
SELECT 'login_audit_contract' AS check_name, IF(@login_audit_ok = 1, 'PASS', 'FAIL') AS result;

SELECT 'OVERALL' AS check_name, IF(
    @db_ready = 1
    AND @core_ready = 1
    AND @required_status_codes = 1
    AND @vp_scientific = 1
    AND @vp_administrative = 1
    AND @generic_vp_forbidden = 0
    AND @super_admin_forbidden = 0
    AND @registration_officer_phase10 = 1
    AND @academic_advisor_withdrawals = 1
    AND @malformed_offering_status = 0
    AND @offering_capacity_bounds = 0
    AND @offering_seat_bounds = 0
    AND @offering_occupancy_mismatch = 0
    AND @tar_exist = 1 AND @tar_uq = 1 AND @tar_cols_ok = 1 AND @tar_dup = 0 AND @tar_bad_slot = 0 AND @action_type_exists = 1 AND @tar_bad_action = 0
    AND @srwr_exist = 1 AND @srwr_uq = 1 AND @srwr_cols_ok = 1 AND @srwr_dup = 0 AND @srwr_bad_slot = 0
    AND @sat_exist = 1 AND @sat_uq = 1 AND @sat_cols_ok = 1 AND @sat_final_inconsistent = 0
    AND @spd_exist = 1 AND @spd_uq = 1 AND @spd_cols_ok = 1 AND @spd_dup = 0 AND @spd_bad_slot = 0 AND @spd_bad_status = 0
    AND @sgd_exist = 1 AND @sgd_uq = 1 AND @sgd_cols_ok = 1 AND @sgd_dup = 0 AND @sgd_bad_slot = 0 AND @sgd_bad_status = 0 AND @sgd_materialized_mismatch = 0
    AND @login_audit_ok = 1,
    'PASS',
    'FAIL'
) AS result;
