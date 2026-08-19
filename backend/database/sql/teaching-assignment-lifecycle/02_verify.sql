-- READ ONLY. Continue only when OVERALL returns PASS.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE application data.
-- Do not use DATABASE().
-- Business-row checks against optional Phase 8 columns use guarded dynamic SQL.
-- OVERALL PASS requires Phase 7 closure BASE TABLES and idx_tar_action_status
-- as NON-UNIQUE (action_type, status). Does not inspect Phase 7 business rows.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @requests_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND table_type = 'BASE TABLE'), 0);
SET @reviews_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_reviews' AND table_type = 'BASE TABLE'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_events' AND table_type = 'BASE TABLE'), 0);
SET @coi_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_instructors' AND table_type = 'BASE TABLE'), 0);

SET @action_type_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), 0);
SET @action_reason_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason'), 0);
SET @target_slot_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id'), 0);

SET @action_type_ok := IF(
    @action_type_exists = 1
    AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type') = 'varchar'
    AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type') = 'NO'
    AND (SELECT IFNULL(character_maximum_length, 0) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type') >= 16
    AND TRIM(BOTH '''' FROM IFNULL((SELECT column_default FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), '')) = 'assign',
    1, 0
);
SET @action_reason_ok := IF(
    @action_reason_exists = 1
    AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason') = 'YES',
    1, 0
);
SET @target_slot_ok := IF(
    @target_slot_exists = 1
    AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id') = 'int'
    AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id') = 'YES',
    1, 0
);

SET @fk_ok := IF(
    @db_ready = 1 AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND constraint_name = 'fk_tar_target_instructor'
          AND column_name = 'target_course_offering_instructor_id'
          AND referenced_table_name = 'course_offering_instructors'
          AND referenced_column_name = 'course_offering_instructor_id'
    ) = 1,
    1, 0
);
SET @idx_ok := IF(
    @db_ready = 1
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'idx_tar_action_status'
    ) <=> 'action_type,status'
    AND (
        SELECT MIN(non_unique)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'idx_tar_action_status'
    ) = 1,
    1, 0
);
SET @uq_current_slot := IF(
    @requests_exist = 1 AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'uq_tar_current_slot'
          AND non_unique = 0
    ) <=> 'course_offering_id,instructor_role,current_slot',
    1, 0
);

SET @phase4_fk_ok := IF(
    @requests_exist = 1 AND @reviews_exist = 1 AND @events_exist = 1
    AND (SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND constraint_name = 'fk_tar_course_offering' AND referenced_table_name = 'course_offerings') = 1
    AND (SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_reviews' AND constraint_name = 'fk_tarv_request' AND referenced_table_name = 'teaching_assignment_requests') = 1,
    1, 0
);

SET @unknown_action := 1;
SET @invalid_remove := 1;
SET @current_dup := 1;

SET @sql := IF(
    @action_type_exists = 1 AND @action_reason_exists = 1 AND @target_slot_exists = 1,
    'SELECT @unknown_action := (
         SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_requests`
         WHERE action_type NOT IN (''assign'', ''remove'')
     ),
     @invalid_remove := (
         SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_requests`
         WHERE action_type = ''remove''
           AND (
               faculty_member_id IS NULL
               OR target_course_offering_instructor_id IS NULL
               OR action_reason IS NULL
               OR TRIM(action_reason) = ''''
           )
     ),
     @current_dup := (
         SELECT COUNT(*) FROM (
             SELECT course_offering_id, instructor_role
             FROM `alrowad_uni_rust`.`teaching_assignment_requests`
             WHERE current_slot = 1
             GROUP BY course_offering_id, instructor_role
             HAVING COUNT(*) > 1
         ) dups
     )',
    'SELECT @unknown_action := 1, @invalid_remove := 1, @current_dup := 1'
);
PREPARE phase8_verify_rows_stmt FROM @sql;
EXECUTE phase8_verify_rows_stmt;
DEALLOCATE PREPARE phase8_verify_rows_stmt;

SET @unknown_action_ok := IF(@unknown_action = 0, 1, 0);
SET @remove_rows_ok := IF(@invalid_remove = 0, 1, 0);
SET @current_dup_ok := IF(@current_dup = 0, 1, 0);

SET @phase7_requests := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_requests' AND table_type = 'BASE TABLE'), 0);
SET @phase7_reviews := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_reviews' AND table_type = 'BASE TABLE'), 0);
SET @phase7_events := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_events' AND table_type = 'BASE TABLE'), 0);
SET @phase7_ok := IF(@phase7_requests = 1 AND @phase7_reviews = 1 AND @phase7_events = 1, 1, 0);

SET @rbac_tables_ok := IF(
    @db_ready = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'roles' AND table_type = 'BASE TABLE') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'permissions' AND table_type = 'BASE TABLE') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'role_permissions' AND table_type = 'BASE TABLE') = 1,
    1, 0
);

SET @rbac_matrix_conflict := IF(
    @rbac_tables_ok = 1,
    (
        SELECT IF(COUNT(*) > 0, 1, 0)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code IN (
            'teaching_assignments.review_scientific',
            'teaching_assignments.review_administrative'
        )
          AND NOT (
              (
                  p.permission_code = 'teaching_assignments.review_scientific'
                  AND r.role_code = 'vice_president_scientific'
              )
              OR (
                  p.permission_code = 'teaching_assignments.review_administrative'
                  AND r.role_code = 'vice_president_administrative'
              )
          )
    ),
    1
);

SET @rbac_ok := IF(
    @rbac_tables_ok = 1
    AND @rbac_matrix_conflict = 0
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.manage' AND is_active = 1)
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_scientific' AND is_active = 1)
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_administrative' AND is_active = 1)
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'dean' AND p.permission_code = 'teaching_assignments.manage'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'teaching_assignments.review_scientific'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'teaching_assignments.review_administrative'
    ),
    1, 0
);

SELECT 'tables_present' AS check_name, IF(@requests_exist = 1 AND @reviews_exist = 1 AND @events_exist = 1 AND @coi_exist = 1, 'PASS', 'FAIL') AS result;
SELECT 'phase7_closure_infrastructure' AS check_name, IF(@phase7_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'action_type_not_null_default_assign' AS check_name, IF(@action_type_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'action_reason_nullable' AS check_name, IF(@action_reason_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'target_slot_nullable' AS check_name, IF(@target_slot_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'target_slot_fk' AS check_name, IF(@fk_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'action_status_index' AS check_name, IF(@idx_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'current_slot_unique' AS check_name, IF(@uq_current_slot = 1, 'PASS', 'FAIL') AS result;
SELECT 'phase4_foreign_keys' AS check_name, IF(@phase4_fk_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'known_action_types' AS check_name, IF(@unknown_action_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'remove_rows_complete' AS check_name, IF(@remove_rows_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'no_duplicate_current' AS check_name, IF(@current_dup_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'teaching_assignment_rbac' AS check_name, IF(@rbac_ok = 1, 'PASS', 'FAIL') AS result;

SET @overall := IF(
    @requests_exist = 1 AND @reviews_exist = 1 AND @events_exist = 1 AND @coi_exist = 1
    AND @phase7_ok = 1
    AND @action_type_ok = 1 AND @action_reason_ok = 1 AND @target_slot_ok = 1
    AND @fk_ok = 1 AND @idx_ok = 1 AND @uq_current_slot = 1 AND @phase4_fk_ok = 1
    AND @unknown_action_ok = 1 AND @remove_rows_ok = 1 AND @current_dup_ok = 1
    AND @rbac_ok = 1,
    'PASS',
    'FAIL'
);

SELECT 'OVERALL' AS report_section, @overall AS result;
