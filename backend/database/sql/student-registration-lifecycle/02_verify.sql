-- READ ONLY. Continue only when OVERALL returns PASS.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE application data.
-- Do not use DATABASE(), stored procedures, DELIMITER, or SIGNAL.
-- Business-row checks against optional Phase 9 tables use guarded dynamic SQL.

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
            SELECT 'student_course_registrations' AS table_name, 'student_course_registration_id' AS column_name
            UNION ALL SELECT 'registration_statuses', 'status_code'
            UNION ALL SELECT 'student_registration_requests', 'student_registration_request_id'
            UNION ALL SELECT 'student_registration_request_items', 'student_registration_request_item_id'
            UNION ALL SELECT 'student_registration_request_events', 'student_registration_request_event_id'
            UNION ALL SELECT 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'students', 'student_id'
            UNION ALL SELECT 'users', 'user_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'role_permissions', 'role_id'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = required.table_name
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
    ),
    1
);

SET @status_codes_ok := IF(
    @db_ready = 1
    AND (
        SELECT COUNT(DISTINCT status_code)
        FROM `alrowad_uni_rust`.`registration_statuses`
        WHERE status_code IN ('registered', 'dropped', 'withdrawn')
    ) = 3,
    1, 0
);

SET @srwr_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_withdrawal_requests' AND table_type = 'BASE TABLE'), 0);
SET @srwe_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_withdrawal_events' AND table_type = 'BASE TABLE'), 0);

SET @srwr_columns_ok := IF(
    @srwr_exists = 1 AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_withdrawal_request_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'student_course_registration_id', 'int', 'NO'
            UNION ALL SELECT 'student_id', 'int', 'NO'
            UNION ALL SELECT 'status', 'varchar', 'NO'
            UNION ALL SELECT 'submission_version', 'int', 'NO'
            UNION ALL SELECT 'current_slot', 'tinyint', 'YES'
            UNION ALL SELECT 'request_reason', 'text', 'NO'
            UNION ALL SELECT 'requested_by_user_id', 'int', 'NO'
            UNION ALL SELECT 'submitted_at', 'timestamp', 'NO'
            UNION ALL SELECT 'reviewed_by_user_id', 'int', 'YES'
            UNION ALL SELECT 'reviewed_at', 'timestamp', 'YES'
            UNION ALL SELECT 'review_notes', 'text', 'YES'
            UNION ALL SELECT 'approved_at', 'timestamp', 'YES'
            UNION ALL SELECT 'materialized_at', 'timestamp', 'YES'
            UNION ALL SELECT 'superseded_at', 'timestamp', 'YES'
            UNION ALL SELECT 'created_at', 'timestamp', 'NO'
            UNION ALL SELECT 'updated_at', 'timestamp', 'NO'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'student_registration_withdrawal_requests'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR (required.data_type = 'int' AND LOWER(c.data_type) <> 'int')
           OR (required.data_type = 'tinyint' AND LOWER(c.data_type) NOT IN ('tinyint', 'int'))
           OR (required.data_type = 'varchar' AND LOWER(c.data_type) <> 'varchar')
           OR (required.data_type = 'text' AND LOWER(c.data_type) NOT IN ('text', 'mediumtext', 'longtext', 'varchar'))
           OR (required.data_type = 'timestamp' AND LOWER(c.data_type) NOT IN ('timestamp', 'datetime'))
           OR c.is_nullable <> required.is_nullable
           OR (required.data_type IN ('int', 'tinyint') AND LOWER(c.column_type) LIKE '%unsigned%')
           OR (required.column_name = 'status' AND IFNULL(c.character_maximum_length, 0) < 40)
    ) = 0,
    1, 0
);

SET @srwe_columns_ok := IF(
    @srwe_exists = 1 AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_withdrawal_event_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'student_registration_withdrawal_request_id', 'int', 'NO'
            UNION ALL SELECT 'event_type', 'varchar', 'NO'
            UNION ALL SELECT 'actor_user_id', 'int', 'YES'
            UNION ALL SELECT 'from_status', 'varchar', 'YES'
            UNION ALL SELECT 'to_status', 'varchar', 'YES'
            UNION ALL SELECT 'submission_version', 'int', 'YES'
            UNION ALL SELECT 'notes', 'text', 'YES'
            UNION ALL SELECT 'created_at', 'timestamp', 'NO'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'student_registration_withdrawal_events'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR (required.data_type = 'int' AND LOWER(c.data_type) <> 'int')
           OR (required.data_type = 'varchar' AND LOWER(c.data_type) <> 'varchar')
           OR (required.data_type = 'text' AND LOWER(c.data_type) NOT IN ('text', 'mediumtext', 'longtext', 'varchar'))
           OR (required.data_type = 'timestamp' AND LOWER(c.data_type) NOT IN ('timestamp', 'datetime'))
           OR c.is_nullable <> required.is_nullable
           OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%')
           OR (required.column_name = 'event_type' AND IFNULL(c.character_maximum_length, 0) < 40)
    ) = 0,
    1, 0
);

SET @srwr_uq_ok := IF(
    @srwr_exists = 1 AND EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_withdrawal_requests'
          AND non_unique = 0
          AND index_name <> 'PRIMARY'
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_course_registration_id,current_slot'
    ),
    1, 0
);

SET @srwr_fk_ok := IF(
    @srwr_exists = 1 AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'student_course_registration_id' AS column_name, 'student_course_registrations' AS ref_table, 'student_course_registration_id' AS ref_column
            UNION ALL SELECT 'student_id', 'students', 'student_id'
            UNION ALL SELECT 'requested_by_user_id', 'users', 'user_id'
            UNION ALL SELECT 'reviewed_by_user_id', 'users', 'user_id'
        ) required
        LEFT JOIN information_schema.key_column_usage k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = 'student_registration_withdrawal_requests'
           AND k.column_name = required.column_name
           AND k.referenced_table_schema = 'alrowad_uni_rust'
           AND k.referenced_table_name = required.ref_table
           AND k.referenced_column_name = required.ref_column
        WHERE k.column_name IS NULL
    ) = 0,
    1, 0
);

SET @srwe_fk_ok := IF(
    @srwe_exists = 1 AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_withdrawal_request_id' AS column_name, 'student_registration_withdrawal_requests' AS ref_table, 'student_registration_withdrawal_request_id' AS ref_column
            UNION ALL SELECT 'actor_user_id', 'users', 'user_id'
        ) required
        LEFT JOIN information_schema.key_column_usage k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = 'student_registration_withdrawal_events'
           AND k.column_name = required.column_name
           AND k.referenced_table_schema = 'alrowad_uni_rust'
           AND k.referenced_table_name = required.ref_table
           AND k.referenced_column_name = required.ref_column
        WHERE k.column_name IS NULL
    ) = 0,
    1, 0
);

SET @dup_current := 0;
SET @malformed_slot := 0;
SET @unknown_status := 0;
SET @approved_inconsistent := 0;
SET @current_inconsistent := 0;

SET @sql := IF(
    @srwr_exists = 1,
    'SELECT @dup_current := COUNT(*) FROM (
         SELECT student_course_registration_id
         FROM `alrowad_uni_rust`.`student_registration_withdrawal_requests`
         WHERE current_slot = 1
         GROUP BY student_course_registration_id
         HAVING COUNT(*) > 1
     ) d',
    'SELECT @dup_current := 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @srwr_exists = 1,
    'SELECT @malformed_slot := COUNT(*)
     FROM `alrowad_uni_rust`.`student_registration_withdrawal_requests`
     WHERE current_slot IS NOT NULL AND current_slot <> 1',
    'SELECT @malformed_slot := 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @srwr_exists = 1,
    'SELECT @unknown_status := COUNT(*)
     FROM `alrowad_uni_rust`.`student_registration_withdrawal_requests`
     WHERE status NOT IN (''submitted'', ''returned'', ''approved'', ''superseded'')',
    'SELECT @unknown_status := 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @srwr_exists = 1,
    'SELECT @approved_inconsistent := COUNT(*)
     FROM `alrowad_uni_rust`.`student_registration_withdrawal_requests`
     WHERE (status = ''approved'' AND (materialized_at IS NULL OR current_slot IS NOT NULL))
        OR (materialized_at IS NOT NULL AND status <> ''approved'')
        OR (status = ''superseded'' AND current_slot IS NOT NULL)',
    'SELECT @approved_inconsistent := 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @srwr_exists = 1,
    'SELECT @current_inconsistent := COUNT(*)
     FROM `alrowad_uni_rust`.`student_registration_withdrawal_requests`
     WHERE status IN (''submitted'', ''returned'') AND IFNULL(current_slot, 0) <> 1',
    'SELECT @current_inconsistent := 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @invariants_ok := IF(
    @dup_current = 0
    AND @malformed_slot = 0
    AND @unknown_status = 0
    AND @approved_inconsistent = 0
    AND @current_inconsistent = 0,
    1, 0
);

SET @rbac_extra_grants := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code IN ('registration_withdrawals.view', 'registration_withdrawals.review')
          AND r.role_code <> 'academic_advisor'
    ),
    1
);

SET @rbac_ok := IF(
    @db_ready = 1
    AND @rbac_extra_grants = 0
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'registration_withdrawals.view' AND p.is_active = 1 AND sm.module_code = 'registration'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'registration_withdrawals.review' AND p.is_active = 1 AND sm.module_code = 'registration'
    )
    AND (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'academic_advisor'
          AND p.permission_code IN ('registration_withdrawals.view', 'registration_withdrawals.review')
          AND p.is_active = 1
    ) = 2,
    1, 0
);

SELECT 'tables_present' AS check_name, IF(@srwr_exists = 1 AND @srwe_exists = 1, 'PASS', 'FAIL') AS result;
SELECT 'required_infrastructure' AS check_name, IF(@missing_required_columns = 0 AND @status_codes_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'request_columns' AS check_name, IF(@srwr_columns_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'event_columns' AS check_name, IF(@srwe_columns_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'current_slot_unique' AS check_name, IF(@srwr_uq_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'request_foreign_keys' AS check_name, IF(@srwr_fk_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'event_foreign_keys' AS check_name, IF(@srwe_fk_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'business_invariants' AS check_name, IF(@invariants_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'withdrawal_rbac' AS check_name, IF(@rbac_ok = 1, 'PASS', 'FAIL') AS result;

SET @overall := IF(
    @srwr_exists = 1 AND @srwe_exists = 1
    AND @missing_required_columns = 0 AND @status_codes_ok = 1
    AND @srwr_columns_ok = 1 AND @srwe_columns_ok = 1
    AND @srwr_uq_ok = 1 AND @srwr_fk_ok = 1 AND @srwe_fk_ok = 1
    AND @invariants_ok = 1 AND @rbac_ok = 1,
    'PASS',
    'FAIL'
);

SELECT 'OVERALL' AS report_section, @overall AS result;
