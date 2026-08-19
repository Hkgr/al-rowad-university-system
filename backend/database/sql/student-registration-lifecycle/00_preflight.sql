-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- SET user variables only; this file must not CREATE/INSERT/UPDATE/DELETE.
-- Do not use DATABASE(), stored procedures, DELIMITER, or SIGNAL.
-- Compatibility predicates below must stay equivalent in 01_apply.sql and 02_verify.sql.
-- Missing Phase 9 tables/permissions are READY (apply will create them).
-- Partial or conflicting Phase 9 objects are BLOCKED.
-- An existing intended NON-UNIQUE index that is UNIQUE is CONFLICT.
-- Do not grant registration_withdrawals.* to dean, student, vice_president, or super_admin.

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
            UNION ALL SELECT 'student_course_registrations', 'student_id'
            UNION ALL SELECT 'student_course_registrations', 'course_offering_id'
            UNION ALL SELECT 'student_course_registrations', 'registration_status_id'
            UNION ALL SELECT 'registration_statuses', 'registration_status_id'
            UNION ALL SELECT 'registration_statuses', 'status_code'
            UNION ALL SELECT 'student_registration_requests', 'student_registration_request_id'
            UNION ALL SELECT 'student_registration_request_items', 'student_registration_request_item_id'
            UNION ALL SELECT 'student_registration_request_events', 'student_registration_request_event_id'
            UNION ALL SELECT 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'course_offerings', 'status'
            UNION ALL SELECT 'course_offerings', 'available_seats'
            UNION ALL SELECT 'students', 'student_id'
            UNION ALL SELECT 'users', 'user_id'
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

SET @advising_tables_ok := IF(
    @db_ready = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_requests' AND table_type = 'BASE TABLE') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_request_items' AND table_type = 'BASE TABLE') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_request_events' AND table_type = 'BASE TABLE') = 1,
    1, 0
);

SET @academic_advisor_active := IF(
    @db_ready = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'academic_advisor' AND is_active = 1) = 1,
    1, 0
);

SET @registration_module_ok := IF(
    @db_ready = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'registration' AND is_active = 1) = 1,
    1, 0
);

SET @permission_code_unique_ok := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'permissions'
          AND non_unique = 0
          AND index_name <> 'PRIMARY'
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'permission_code'
    )
    AND NOT EXISTS (
        SELECT permission_code
        FROM `alrowad_uni_rust`.`permissions`
        WHERE permission_code IN ('registration_withdrawals.view', 'registration_withdrawals.review')
        GROUP BY permission_code
        HAVING COUNT(*) > 1
    ),
    1, 0
);

SET @role_permissions_unique_ok := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'role_permissions'
          AND non_unique = 0
          AND index_name <> 'PRIMARY'
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) IN ('role_id,permission_id', 'permission_id,role_id')
    ),
    1, 0
);

SET @srwr_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_withdrawal_requests' AND table_type = 'BASE TABLE'), 0);
SET @srwe_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_withdrawal_events' AND table_type = 'BASE TABLE'), 0);

SET @srwr_columns_ok := IF(
    @srwr_exists = 0,
    1,
    IF((
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
           OR (required.column_name = 'submission_version' AND TRIM(BOTH '''' FROM IFNULL(c.column_default, '')) <> '1')
    ) = 0, 1, 0)
);

SET @srwe_columns_ok := IF(
    @srwe_exists = 0,
    1,
    IF((
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
    ) = 0, 1, 0)
);

SET @srwr_engine_ok := IF(
    @srwr_exists = 0,
    1,
    IF((SELECT ENGINE FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_withdrawal_requests') = 'InnoDB', 1, 0)
);
SET @srwe_engine_ok := IF(
    @srwe_exists = 0,
    1,
    IF((SELECT ENGINE FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_withdrawal_events') = 'InnoDB', 1, 0)
);

SET @idx_srwr_current_slot_ok := IF(
    @srwr_exists = 0,
    1,
    IF(
        (
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
    )
);
SET @idx_srwr_student_status_ok := IF(
    @srwr_exists = 0,
    1,
    IF(
        (
            SELECT MIN(non_unique)
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_withdrawal_requests'
              AND index_name = 'idx_srwr_student_status'
        ) = 1
        AND (
            SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_withdrawal_requests'
              AND index_name = 'idx_srwr_student_status'
        ) <=> 'student_id,status',
        1, 0
    )
);
SET @idx_srwr_reviewer_ok := IF(
    @srwr_exists = 0,
    1,
    IF(
        (
            SELECT MIN(non_unique)
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_withdrawal_requests'
              AND index_name = 'idx_srwr_reviewer'
        ) = 1
        AND (
            SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_withdrawal_requests'
              AND index_name = 'idx_srwr_reviewer'
        ) <=> 'reviewed_by_user_id',
        1, 0
    )
);
SET @idx_srwe_request_ok := IF(
    @srwe_exists = 0,
    1,
    IF(
        (
            SELECT MIN(non_unique)
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_withdrawal_events'
              AND index_name = 'idx_srwe_request'
        ) = 1
        AND (
            SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_withdrawal_events'
              AND index_name = 'idx_srwe_request'
        ) <=> 'student_registration_withdrawal_request_id,created_at',
        1, 0
    )
);
SET @idx_srwe_actor_ok := IF(
    @srwe_exists = 0,
    1,
    IF(
        (
            SELECT MIN(non_unique)
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_withdrawal_events'
              AND index_name = 'idx_srwe_actor'
        ) = 1
        AND (
            SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_withdrawal_events'
              AND index_name = 'idx_srwe_actor'
        ) <=> 'actor_user_id',
        1, 0
    )
);

SET @srwr_uq_ok := @idx_srwr_current_slot_ok;

SET @srwr_fk_ok := IF(
    @srwr_exists = 0,
    1,
    IF((
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
    ) = 0, 1, 0)
);

SET @srwe_fk_ok := IF(
    @srwe_exists = 0,
    1,
    IF((
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
    ) = 0, 1, 0)
);

SET @perm_view_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'registration_withdrawals.view'),
    0
);
SET @perm_review_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'registration_withdrawals.review'),
    0
);

SET @perm_view_ok := IF(
    @perm_view_exists = 0,
    1,
    IF(
        @perm_view_exists = 1
        AND EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`permissions` p
            JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
            WHERE p.permission_code = 'registration_withdrawals.view'
              AND p.is_active = 1
              AND sm.module_code = 'registration'
        ),
        1, 0
    )
);
SET @perm_review_ok := IF(
    @perm_review_exists = 0,
    1,
    IF(
        @perm_review_exists = 1
        AND EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`permissions` p
            JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
            WHERE p.permission_code = 'registration_withdrawals.review'
              AND p.is_active = 1
              AND sm.module_code = 'registration'
        ),
        1, 0
    )
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

SET @requests_state := CASE
    WHEN @srwr_exists = 0 THEN 'ABSENT'
    WHEN @srwr_columns_ok = 1 AND @srwr_engine_ok = 1 AND @idx_srwr_current_slot_ok = 1 AND @idx_srwr_student_status_ok = 1 AND @idx_srwr_reviewer_ok = 1 AND @srwr_fk_ok = 1 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;
SET @events_state := CASE
    WHEN @srwe_exists = 0 THEN 'ABSENT'
    WHEN @srwe_columns_ok = 1 AND @srwe_engine_ok = 1 AND @idx_srwe_request_ok = 1 AND @idx_srwe_actor_ok = 1 AND @srwe_fk_ok = 1 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;
SET @perm_view_state := CASE
    WHEN @perm_view_exists = 0 THEN 'ABSENT'
    WHEN @perm_view_ok = 1 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;
SET @perm_review_state := CASE
    WHEN @perm_review_exists = 0 THEN 'ABSENT'
    WHEN @perm_review_ok = 1 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;
SET @rbac_extra_state := CASE
    WHEN @rbac_extra_grants = 0 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @phase9_conflict := IF(
    @requests_state = 'CONFLICT'
    OR @events_state = 'CONFLICT'
    OR @perm_view_state = 'CONFLICT'
    OR @perm_review_state = 'CONFLICT'
    OR @rbac_extra_state = 'CONFLICT',
    1, 0
);

SET @overall_ready := IF(
    @db_ready = 1
    AND @missing_required_columns = 0
    AND @status_codes_ok = 1
    AND @advising_tables_ok = 1
    AND @academic_advisor_active = 1
    AND @registration_module_ok = 1
    AND @permission_code_unique_ok = 1
    AND @role_permissions_unique_ok = 1
    AND @phase9_conflict = 0,
    1, 0
);

SELECT 'required_infrastructure' AS check_name, IF(@missing_required_columns = 0 AND @advising_tables_ok = 1 AND @status_codes_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'academic_advisor_role' AS check_name, IF(@academic_advisor_active = 1, 'PASS', 'FAIL') AS result;
SELECT 'registration_module' AS check_name, IF(@registration_module_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'permission_code_unique' AS check_name, IF(@permission_code_unique_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'student_registration_withdrawal_requests' AS object_name, @requests_state AS classification;
SELECT 'student_registration_withdrawal_events' AS object_name, @events_state AS classification;
SELECT 'registration_withdrawals.view' AS object_name, @perm_view_state AS classification;
SELECT 'registration_withdrawals.review' AS object_name, @perm_review_state AS classification;
SELECT 'withdrawal_rbac_extra_grants' AS object_name, @rbac_extra_state AS classification;
SELECT 'OVERALL' AS report_section, IF(@overall_ready = 1, 'READY', 'BLOCKED') AS result;
