-- Manual and idempotent. Fail-closed: writes run only when @apply_ready = 1.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- DDL commits implicitly in MariaDB; do not wrap this file in a transaction.
-- Do not use stored procedures, DELIMITER, or SIGNAL.
-- Do NOT create a new academic_advisor role. Reuse the existing one.
-- Do NOT grant registration.manage to dean, academic_advisor, or student.
-- Independently recomputes the same critical safety conditions as 00_preflight.sql.
-- Missing request tables are allowed (this file creates them). Partial tables block all writes.

SET @srr_absent_ok := 1;
SET @srri_absent_ok := 1;
SET @srre_absent_ok := 1;

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @academic_advisor_role_count := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'academic_advisor'),
    0
);
SET @academic_advisor_active_count := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'academic_advisor' AND is_active = 1),
    0
);
SET @dean_active_count := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'dean' AND is_active = 1),
    0
);
SET @student_active_count := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'student' AND is_active = 1),
    0
);
SET @registration_module_count := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'registration' AND is_active = 1),
    0
);

SET @missing_required_columns := (
    SELECT COUNT(*)
    FROM (
        SELECT 'students' AS table_name, 'student_id' AS column_name
        UNION ALL SELECT 'academic_years', 'academic_year_id'
        UNION ALL SELECT 'academic_years', 'is_current'
        UNION ALL SELECT 'semesters', 'semester_id'
        UNION ALL SELECT 'users', 'user_id'
        UNION ALL SELECT 'course_offerings', 'course_offering_id'
        UNION ALL SELECT 'course_offerings', 'course_id'
        UNION ALL SELECT 'course_offerings', 'academic_year_id'
        UNION ALL SELECT 'course_offerings', 'semester_id'
        UNION ALL SELECT 'course_offerings', 'available_seats'
        UNION ALL SELECT 'course_offerings', 'status'
        UNION ALL SELECT 'courses', 'course_id'
        UNION ALL SELECT 'courses', 'credit_hours'
        UNION ALL SELECT 'student_course_registrations', 'student_course_registration_id'
        UNION ALL SELECT 'student_course_registrations', 'student_id'
        UNION ALL SELECT 'student_course_registrations', 'course_offering_id'
        UNION ALL SELECT 'student_course_registrations', 'advisor_user_id'
        UNION ALL SELECT 'student_course_registrations', 'registered_by_user_id'
        UNION ALL SELECT 'roles', 'role_id'
        UNION ALL SELECT 'roles', 'role_code'
        UNION ALL SELECT 'roles', 'is_active'
        UNION ALL SELECT 'system_modules', 'module_id'
        UNION ALL SELECT 'system_modules', 'module_code'
        UNION ALL SELECT 'system_modules', 'is_active'
        UNION ALL SELECT 'permissions', 'permission_id'
        UNION ALL SELECT 'permissions', 'module_id'
        UNION ALL SELECT 'permissions', 'permission_code'
        UNION ALL SELECT 'permissions', 'permission_name'
        UNION ALL SELECT 'permissions', 'description'
        UNION ALL SELECT 'permissions', 'is_active'
        UNION ALL SELECT 'permissions', 'created_at'
        UNION ALL SELECT 'permissions', 'updated_at'
        UNION ALL SELECT 'role_permissions', 'role_id'
        UNION ALL SELECT 'role_permissions', 'permission_id'
        UNION ALL SELECT 'role_permissions', 'granted_at'
    ) required
    LEFT JOIN information_schema.columns c
        ON c.table_schema = 'alrowad_uni_rust'
       AND c.table_name = required.table_name
       AND c.column_name = required.column_name
    WHERE c.column_name IS NULL
);

SET @fk_targets_signed_int := IF(
    @db_ready = 1
    AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'students' AS table_name, 'student_id' AS column_name
            UNION ALL SELECT 'academic_years', 'academic_year_id'
            UNION ALL SELECT 'semesters', 'semester_id'
            UNION ALL SELECT 'users', 'user_id'
            UNION ALL SELECT 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'student_course_registrations', 'student_course_registration_id'
        ) targets
        INNER JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = targets.table_name
           AND c.column_name = targets.column_name
        WHERE LOWER(c.data_type) = 'int'
          AND LOWER(c.column_type) NOT LIKE '%unsigned%'
          AND c.is_nullable = 'NO'
    ) = 6,
    1,
    0
);

SET @permission_code_has_unique := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'permissions'
          AND non_unique = 0
          AND index_name <> 'PRIMARY'
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'permission_code'
    ),
    1,
    0
);
SET @permission_code_no_duplicates := IF(
    @db_ready = 1
    AND NOT EXISTS (
        SELECT permission_code
        FROM `alrowad_uni_rust`.`permissions`
        WHERE permission_code IN (
            'registration_requests.view',
            'registration_requests.review',
            'registration.view',
            'registration.manage'
        )
        GROUP BY permission_code
        HAVING COUNT(*) > 1
    ),
    1,
    0
);
SET @permission_code_unique_ok := IF(
    @permission_code_has_unique = 1
    AND @permission_code_no_duplicates = 1,
    1,
    0
);

SET @role_permissions_has_unique := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'role_permissions'
          AND non_unique = 0
          AND index_name <> 'PRIMARY'
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) IN (
            'role_id,permission_id',
            'permission_id,role_id'
        )
    ),
    1,
    0
);
SET @role_permissions_no_duplicates := IF(
    @db_ready = 1
    AND NOT EXISTS (
        SELECT role_id, permission_id
        FROM `alrowad_uni_rust`.`role_permissions`
        GROUP BY role_id, permission_id
        HAVING COUNT(*) > 1
    ),
    1,
    0
);
SET @role_permissions_unique_ok := IF(
    @role_permissions_has_unique = 1
    AND @role_permissions_no_duplicates = 1,
    1,
    0
);

SET @srr_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'student_registration_requests'
      AND table_type = 'BASE TABLE'
);
SET @srri_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'student_registration_request_items'
      AND table_type = 'BASE TABLE'
);
SET @srre_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'student_registration_request_events'
      AND table_type = 'BASE TABLE'
);

SET @srr_engine_ok := IF(
    @srr_exists = 0,
    @srr_absent_ok,
    IF((
        SELECT ENGINE
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_requests'
    ) = 'InnoDB', 1, 0)
);
SET @srri_engine_ok := IF(
    @srri_exists = 0,
    @srri_absent_ok,
    IF((
        SELECT ENGINE
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_request_items'
    ) = 'InnoDB', 1, 0)
);
SET @srre_engine_ok := IF(
    @srre_exists = 0,
    @srre_absent_ok,
    IF((
        SELECT ENGINE
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_request_events'
    ) = 'InnoDB', 1, 0)
);

SET @srr_columns_ok := IF(
    @srr_exists = 0,
    @srr_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_id' AS column_name
            UNION ALL SELECT 'student_id'
            UNION ALL SELECT 'academic_year_id'
            UNION ALL SELECT 'semester_id'
            UNION ALL SELECT 'status'
            UNION ALL SELECT 'submission_version'
            UNION ALL SELECT 'student_notes'
            UNION ALL SELECT 'advisor_user_id'
            UNION ALL SELECT 'advisor_notes'
            UNION ALL SELECT 'first_submitted_at'
            UNION ALL SELECT 'last_submitted_at'
            UNION ALL SELECT 'reviewed_at'
            UNION ALL SELECT 'approved_at'
            UNION ALL SELECT 'registered_hours_before_approval'
            UNION ALL SELECT 'request_hours_at_approval'
            UNION ALL SELECT 'projected_hours_at_approval'
            UNION ALL SELECT 'max_allowed_hours_at_approval'
            UNION ALL SELECT 'remaining_hours_after_approval'
            UNION ALL SELECT 'created_at'
            UNION ALL SELECT 'updated_at'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'student_registration_requests'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
    ) = 0, 1, 0)
);

SET @srr_types_ok := IF(
    @srr_exists = 0,
    @srr_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable, 0 AS min_length
            UNION ALL SELECT 'student_id', 'int', 'NO', 0
            UNION ALL SELECT 'academic_year_id', 'int', 'NO', 0
            UNION ALL SELECT 'semester_id', 'int', 'NO', 0
            UNION ALL SELECT 'status', 'varchar', 'NO', 40
            UNION ALL SELECT 'submission_version', 'int', 'NO', 0
            UNION ALL SELECT 'student_notes', 'text', 'YES', 0
            UNION ALL SELECT 'advisor_user_id', 'int', 'YES', 0
            UNION ALL SELECT 'advisor_notes', 'text', 'YES', 0
            UNION ALL SELECT 'first_submitted_at', 'datetime', 'YES', 0
            UNION ALL SELECT 'last_submitted_at', 'datetime', 'YES', 0
            UNION ALL SELECT 'reviewed_at', 'datetime', 'YES', 0
            UNION ALL SELECT 'approved_at', 'datetime', 'YES', 0
            UNION ALL SELECT 'registered_hours_before_approval', 'int', 'YES', 0
            UNION ALL SELECT 'request_hours_at_approval', 'int', 'YES', 0
            UNION ALL SELECT 'projected_hours_at_approval', 'int', 'YES', 0
            UNION ALL SELECT 'max_allowed_hours_at_approval', 'int', 'YES', 0
            UNION ALL SELECT 'remaining_hours_after_approval', 'int', 'YES', 0
            UNION ALL SELECT 'created_at', 'timestamp', 'NO', 0
            UNION ALL SELECT 'updated_at', 'timestamp', 'NO', 0
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'student_registration_requests'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR LOWER(c.data_type) <> required.data_type
           OR c.is_nullable <> required.is_nullable
           OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%')
           OR (required.min_length > 0 AND IFNULL(c.character_maximum_length, 0) < required.min_length)
    ) = 0, 1, 0)
);

SET @srri_columns_ok := IF(
    @srri_exists = 0,
    @srri_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_item_id' AS column_name
            UNION ALL SELECT 'student_registration_request_id'
            UNION ALL SELECT 'course_offering_id'
            UNION ALL SELECT 'student_course_registration_id'
            UNION ALL SELECT 'created_at'
            UNION ALL SELECT 'updated_at'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'student_registration_request_items'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
    ) = 0, 1, 0)
);

SET @srri_types_ok := IF(
    @srri_exists = 0,
    @srri_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_item_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable, 0 AS min_length
            UNION ALL SELECT 'student_registration_request_id', 'int', 'NO', 0
            UNION ALL SELECT 'course_offering_id', 'int', 'NO', 0
            UNION ALL SELECT 'student_course_registration_id', 'int', 'YES', 0
            UNION ALL SELECT 'created_at', 'timestamp', 'NO', 0
            UNION ALL SELECT 'updated_at', 'timestamp', 'NO', 0
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'student_registration_request_items'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR LOWER(c.data_type) <> required.data_type
           OR c.is_nullable <> required.is_nullable
           OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%')
           OR (required.min_length > 0 AND IFNULL(c.character_maximum_length, 0) < required.min_length)
    ) = 0, 1, 0)
);

SET @srre_columns_ok := IF(
    @srre_exists = 0,
    @srre_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_event_id' AS column_name
            UNION ALL SELECT 'student_registration_request_id'
            UNION ALL SELECT 'event_type'
            UNION ALL SELECT 'actor_user_id'
            UNION ALL SELECT 'from_status'
            UNION ALL SELECT 'to_status'
            UNION ALL SELECT 'submission_version'
            UNION ALL SELECT 'notes'
            UNION ALL SELECT 'created_at'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'student_registration_request_events'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
    ) = 0, 1, 0)
);

SET @srre_types_ok := IF(
    @srre_exists = 0,
    @srre_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_event_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable, 0 AS min_length
            UNION ALL SELECT 'student_registration_request_id', 'int', 'NO', 0
            UNION ALL SELECT 'event_type', 'varchar', 'NO', 40
            UNION ALL SELECT 'actor_user_id', 'int', 'YES', 0
            UNION ALL SELECT 'from_status', 'varchar', 'YES', 40
            UNION ALL SELECT 'to_status', 'varchar', 'YES', 40
            UNION ALL SELECT 'submission_version', 'int', 'YES', 0
            UNION ALL SELECT 'notes', 'text', 'YES', 0
            UNION ALL SELECT 'created_at', 'timestamp', 'NO', 0
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'student_registration_request_events'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR LOWER(c.data_type) <> required.data_type
           OR c.is_nullable <> required.is_nullable
           OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%')
           OR (required.min_length > 0 AND IFNULL(c.character_maximum_length, 0) < required.min_length)
    ) = 0, 1, 0)
);

SET @srr_pk_ok := IF(
    @srr_exists = 0,
    @srr_absent_ok,
    IF((
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_requests'
          AND index_name = 'PRIMARY'
    ) = 'student_registration_request_id', 1, 0)
);
SET @srri_pk_ok := IF(
    @srri_exists = 0,
    @srri_absent_ok,
    IF((
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_request_items'
          AND index_name = 'PRIMARY'
    ) = 'student_registration_request_item_id', 1, 0)
);
SET @srre_pk_ok := IF(
    @srre_exists = 0,
    @srre_absent_ok,
    IF((
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_request_events'
          AND index_name = 'PRIMARY'
    ) = 'student_registration_request_event_id', 1, 0)
);

SET @srr_unique_ok := IF(
    @srr_exists = 0,
    @srr_absent_ok,
    IF(EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_requests'
          AND non_unique = 0
          AND index_name <> 'PRIMARY'
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,academic_year_id,semester_id'
    ), 1, 0)
);
SET @srri_unique_ok := IF(
    @srri_exists = 0,
    @srri_absent_ok,
    IF(EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_request_items'
          AND non_unique = 0
          AND index_name <> 'PRIMARY'
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_registration_request_id,course_offering_id'
    ), 1, 0)
);

SET @srr_fk_ok := IF(
    @srr_exists = 0,
    @srr_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_id' AS column_name, 'students' AS ref_table, 'student_id' AS ref_column
            UNION ALL SELECT 'academic_year_id', 'academic_years', 'academic_year_id'
            UNION ALL SELECT 'semester_id', 'semesters', 'semester_id'
            UNION ALL SELECT 'advisor_user_id', 'users', 'user_id'
        ) required
        LEFT JOIN information_schema.key_column_usage k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = 'student_registration_requests'
           AND k.column_name = required.column_name
           AND k.referenced_table_schema = 'alrowad_uni_rust'
           AND k.referenced_table_name = required.ref_table
           AND k.referenced_column_name = required.ref_column
        WHERE k.column_name IS NULL
    ) = 0, 1, 0)
);
SET @srri_fk_ok := IF(
    @srri_exists = 0,
    @srri_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_id' AS column_name, 'student_registration_requests' AS ref_table, 'student_registration_request_id' AS ref_column
            UNION ALL SELECT 'course_offering_id', 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'student_course_registration_id', 'student_course_registrations', 'student_course_registration_id'
        ) required
        LEFT JOIN information_schema.key_column_usage k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = 'student_registration_request_items'
           AND k.column_name = required.column_name
           AND k.referenced_table_schema = 'alrowad_uni_rust'
           AND k.referenced_table_name = required.ref_table
           AND k.referenced_column_name = required.ref_column
        WHERE k.column_name IS NULL
    ) = 0, 1, 0)
);
SET @srre_fk_ok := IF(
    @srre_exists = 0,
    @srre_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_id' AS column_name, 'student_registration_requests' AS ref_table, 'student_registration_request_id' AS ref_column
            UNION ALL SELECT 'actor_user_id', 'users', 'user_id'
        ) required
        LEFT JOIN information_schema.key_column_usage k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = 'student_registration_request_events'
           AND k.column_name = required.column_name
           AND k.referenced_table_schema = 'alrowad_uni_rust'
           AND k.referenced_table_name = required.ref_table
           AND k.referenced_column_name = required.ref_column
        WHERE k.column_name IS NULL
    ) = 0, 1, 0)
);

SET @srr_fk_types_ok := IF(
    @srr_exists = 0,
    @srr_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_requests' AS src_table, 'student_id' AS src_column, 'students' AS dst_table, 'student_id' AS dst_column
            UNION ALL SELECT 'student_registration_requests', 'academic_year_id', 'academic_years', 'academic_year_id'
            UNION ALL SELECT 'student_registration_requests', 'semester_id', 'semesters', 'semester_id'
            UNION ALL SELECT 'student_registration_requests', 'advisor_user_id', 'users', 'user_id'
        ) pairs
        LEFT JOIN information_schema.columns src
            ON src.table_schema = 'alrowad_uni_rust'
           AND src.table_name = pairs.src_table
           AND src.column_name = pairs.src_column
        LEFT JOIN information_schema.columns dst
            ON dst.table_schema = 'alrowad_uni_rust'
           AND dst.table_name = pairs.dst_table
           AND dst.column_name = pairs.dst_column
        WHERE src.column_name IS NULL
           OR dst.column_name IS NULL
           OR src.column_type <> dst.column_type
           OR LOWER(src.data_type) <> 'int'
           OR LOWER(dst.data_type) <> 'int'
    ) = 0, 1, 0)
);
SET @srri_fk_types_ok := IF(
    @srri_exists = 0,
    @srri_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_items' AS src_table, 'student_registration_request_id' AS src_column, 'student_registration_requests' AS dst_table, 'student_registration_request_id' AS dst_column
            UNION ALL SELECT 'student_registration_request_items', 'course_offering_id', 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'student_registration_request_items', 'student_course_registration_id', 'student_course_registrations', 'student_course_registration_id'
        ) pairs
        LEFT JOIN information_schema.columns src
            ON src.table_schema = 'alrowad_uni_rust'
           AND src.table_name = pairs.src_table
           AND src.column_name = pairs.src_column
        LEFT JOIN information_schema.columns dst
            ON dst.table_schema = 'alrowad_uni_rust'
           AND dst.table_name = pairs.dst_table
           AND dst.column_name = pairs.dst_column
        WHERE src.column_name IS NULL
           OR dst.column_name IS NULL
           OR src.column_type <> dst.column_type
           OR LOWER(src.data_type) <> 'int'
           OR LOWER(dst.data_type) <> 'int'
    ) = 0, 1, 0)
);
SET @srre_fk_types_ok := IF(
    @srre_exists = 0,
    @srre_absent_ok,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_events' AS src_table, 'student_registration_request_id' AS src_column, 'student_registration_requests' AS dst_table, 'student_registration_request_id' AS dst_column
            UNION ALL SELECT 'student_registration_request_events', 'actor_user_id', 'users', 'user_id'
        ) pairs
        LEFT JOIN information_schema.columns src
            ON src.table_schema = 'alrowad_uni_rust'
           AND src.table_name = pairs.src_table
           AND src.column_name = pairs.src_column
        LEFT JOIN information_schema.columns dst
            ON dst.table_schema = 'alrowad_uni_rust'
           AND dst.table_name = pairs.dst_table
           AND dst.column_name = pairs.dst_column
        WHERE src.column_name IS NULL
           OR dst.column_name IS NULL
           OR src.column_type <> dst.column_type
           OR LOWER(src.data_type) <> 'int'
           OR LOWER(dst.data_type) <> 'int'
    ) = 0, 1, 0)
);

SET @srr_compatible := IF(
    @srr_engine_ok = 1
    AND @srr_columns_ok = 1
    AND @srr_types_ok = 1
    AND @srr_pk_ok = 1
    AND @srr_unique_ok = 1
    AND @srr_fk_ok = 1
    AND @srr_fk_types_ok = 1,
    1,
    0
);
SET @srri_compatible := IF(
    @srri_engine_ok = 1
    AND @srri_columns_ok = 1
    AND @srri_types_ok = 1
    AND @srri_pk_ok = 1
    AND @srri_unique_ok = 1
    AND @srri_fk_ok = 1
    AND @srri_fk_types_ok = 1,
    1,
    0
);
SET @srre_compatible := IF(
    @srre_engine_ok = 1
    AND @srre_columns_ok = 1
    AND @srre_types_ok = 1
    AND @srre_pk_ok = 1
    AND @srre_fk_ok = 1
    AND @srre_fk_types_ok = 1,
    1,
    0
);

SET @overall_ready := IF(
    @db_ready = 1
    AND @academic_advisor_role_count = 1
    AND @academic_advisor_active_count = 1
    AND @dean_active_count = 1
    AND @student_active_count >= 1
    AND @registration_module_count = 1
    AND IFNULL(@missing_required_columns, 1) = 0
    AND @fk_targets_signed_int = 1
    AND @permission_code_unique_ok = 1
    AND @role_permissions_unique_ok = 1
    AND @srr_compatible = 1
    AND @srri_compatible = 1
    AND @srre_compatible = 1,
    1,
    0
);

SET @apply_ready := @overall_ready;

SELECT
    'apply_ready' AS check_name,
    IF(@apply_ready = 1, 'READY', 'BLOCKED') AS result,
    @apply_ready AS apply_ready,
    @permission_code_unique_ok AS permission_code_unique_ok,
    @role_permissions_unique_ok AS role_permissions_unique_ok,
    @srr_compatible AS srr_compatible,
    @srri_compatible AS srri_compatible,
    @srre_compatible AS srre_compatible;

SET @sql := IF(
    @apply_ready = 1,
    'CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`student_registration_requests` (
        `student_registration_request_id` INT NOT NULL AUTO_INCREMENT,
        `student_id` INT NOT NULL,
        `academic_year_id` INT NOT NULL,
        `semester_id` INT NOT NULL,
        `status` VARCHAR(40) NOT NULL DEFAULT ''draft'',
        `submission_version` INT NOT NULL DEFAULT 0,
        `student_notes` TEXT NULL,
        `advisor_user_id` INT NULL,
        `advisor_notes` TEXT NULL,
        `first_submitted_at` DATETIME NULL,
        `last_submitted_at` DATETIME NULL,
        `reviewed_at` DATETIME NULL,
        `approved_at` DATETIME NULL,
        `registered_hours_before_approval` INT NULL,
        `request_hours_at_approval` INT NULL,
        `projected_hours_at_approval` INT NULL,
        `max_allowed_hours_at_approval` INT NULL,
        `remaining_hours_after_approval` INT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`student_registration_request_id`),
        UNIQUE KEY `uq_student_registration_request_term` (`student_id`, `academic_year_id`, `semester_id`),
        KEY `idx_student_registration_requests_status` (`status`, `last_submitted_at`),
        KEY `idx_student_registration_requests_advisor` (`advisor_user_id`),
        CONSTRAINT `fk_srr_student` FOREIGN KEY (`student_id`) REFERENCES `alrowad_uni_rust`.`students` (`student_id`),
        CONSTRAINT `fk_srr_year` FOREIGN KEY (`academic_year_id`) REFERENCES `alrowad_uni_rust`.`academic_years` (`academic_year_id`),
        CONSTRAINT `fk_srr_semester` FOREIGN KEY (`semester_id`) REFERENCES `alrowad_uni_rust`.`semesters` (`semester_id`),
        CONSTRAINT `fk_srr_advisor_user` FOREIGN KEY (`advisor_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''BLOCKED'' AS apply_status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @apply_ready = 1,
    'CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`student_registration_request_items` (
        `student_registration_request_item_id` INT NOT NULL AUTO_INCREMENT,
        `student_registration_request_id` INT NOT NULL,
        `course_offering_id` INT NOT NULL,
        `student_course_registration_id` INT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`student_registration_request_item_id`),
        UNIQUE KEY `uq_srr_item_offering` (`student_registration_request_id`, `course_offering_id`),
        KEY `idx_srr_item_offering` (`course_offering_id`),
        KEY `idx_srr_item_registration` (`student_course_registration_id`),
        CONSTRAINT `fk_srri_request` FOREIGN KEY (`student_registration_request_id`)
            REFERENCES `alrowad_uni_rust`.`student_registration_requests` (`student_registration_request_id`),
        CONSTRAINT `fk_srri_offering` FOREIGN KEY (`course_offering_id`)
            REFERENCES `alrowad_uni_rust`.`course_offerings` (`course_offering_id`),
        CONSTRAINT `fk_srri_registration` FOREIGN KEY (`student_course_registration_id`)
            REFERENCES `alrowad_uni_rust`.`student_course_registrations` (`student_course_registration_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''BLOCKED'' AS apply_status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @apply_ready = 1,
    'CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`student_registration_request_events` (
        `student_registration_request_event_id` INT NOT NULL AUTO_INCREMENT,
        `student_registration_request_id` INT NOT NULL,
        `event_type` VARCHAR(40) NOT NULL,
        `actor_user_id` INT NULL,
        `from_status` VARCHAR(40) NULL,
        `to_status` VARCHAR(40) NULL,
        `submission_version` INT NULL,
        `notes` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`student_registration_request_event_id`),
        KEY `idx_srr_events_request` (`student_registration_request_id`, `created_at`),
        KEY `idx_srr_events_actor` (`actor_user_id`),
        CONSTRAINT `fk_srre_request` FOREIGN KEY (`student_registration_request_id`)
            REFERENCES `alrowad_uni_rust`.`student_registration_requests` (`student_registration_request_id`),
        CONSTRAINT `fk_srre_actor` FOREIGN KEY (`actor_user_id`)
            REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''BLOCKED'' AS apply_status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id,
    permission_code,
    permission_name,
    description,
    is_active,
    created_at,
    updated_at
)
SELECT
    sm.module_id,
    'registration_requests.view',
    'View registration requests',
    'View student registration requests within authorized academic scope.',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND sm.module_code = 'registration'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'registration_requests.view'
  );

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id,
    permission_code,
    permission_name,
    description,
    is_active,
    created_at,
    updated_at
)
SELECT
    sm.module_id,
    'registration_requests.review',
    'Review registration requests',
    'Return or approve student registration requests within authorized academic scope.',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND sm.module_code = 'registration'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'registration_requests.review'
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT
    r.role_id,
    p.permission_id,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
CROSS JOIN `alrowad_uni_rust`.`permissions` p
WHERE @apply_ready = 1
  AND r.role_code = 'academic_advisor'
  AND r.is_active = 1
  AND p.permission_code IN (
      'registration.view',
      'registration_requests.view',
      'registration_requests.review'
  )
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id
        AND existing.permission_id = p.permission_id
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT
    r.role_id,
    p.permission_id,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
CROSS JOIN `alrowad_uni_rust`.`permissions` p
WHERE @apply_ready = 1
  AND r.role_code = 'dean'
  AND r.is_active = 1
  AND p.permission_code IN (
      'registration_requests.view',
      'registration_requests.review'
  )
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id
        AND existing.permission_id = p.permission_id
  );

SELECT
    'apply_complete' AS report_section,
    @apply_ready AS apply_ready,
    'Run 02_verify.sql now. Do not execute this file from application code.' AS next_step;
