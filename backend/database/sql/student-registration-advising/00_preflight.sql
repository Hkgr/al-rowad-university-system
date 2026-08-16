-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not create a new academic_advisor role. Reuse the existing one.
-- SET user variables only; this file must not CREATE/INSERT/UPDATE/DELETE.

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

SET @permission_code_unique_ok := IF(
    @db_ready = 0,
    0,
    IF(
        EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'permissions'
              AND non_unique = 0
              AND index_name <> 'PRIMARY'
            GROUP BY index_name
            HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'permission_code'
        )
        OR NOT EXISTS (
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
    )
);

SET @role_permissions_unique_ok := IF(
    @db_ready = 0,
    0,
    IF(
        EXISTS (
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
        )
        OR NOT EXISTS (
            SELECT role_id, permission_id
            FROM `alrowad_uni_rust`.`role_permissions`
            GROUP BY role_id, permission_id
            HAVING COUNT(*) > 1
        ),
        1,
        0
    )
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
    1,
    IF((
        SELECT ENGINE
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_requests'
    ) = 'InnoDB', 1, 0)
);
SET @srri_engine_ok := IF(
    @srri_exists = 0,
    1,
    IF((
        SELECT ENGINE
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_request_items'
    ) = 'InnoDB', 1, 0)
);
SET @srre_engine_ok := IF(
    @srre_exists = 0,
    1,
    IF((
        SELECT ENGINE
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_registration_request_events'
    ) = 'InnoDB', 1, 0)
);

SET @srr_columns_ok := IF(
    @srr_exists = 0,
    1,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'student_id', 'int', 'NO'
            UNION ALL SELECT 'academic_year_id', 'int', 'NO'
            UNION ALL SELECT 'semester_id', 'int', 'NO'
            UNION ALL SELECT 'status', 'varchar', 'NO'
            UNION ALL SELECT 'submission_version', 'int', 'NO'
            UNION ALL SELECT 'student_notes', 'text', 'YES'
            UNION ALL SELECT 'advisor_user_id', 'int', 'YES'
            UNION ALL SELECT 'advisor_notes', 'text', 'YES'
            UNION ALL SELECT 'first_submitted_at', 'datetime', 'YES'
            UNION ALL SELECT 'last_submitted_at', 'datetime', 'YES'
            UNION ALL SELECT 'reviewed_at', 'datetime', 'YES'
            UNION ALL SELECT 'approved_at', 'datetime', 'YES'
            UNION ALL SELECT 'registered_hours_before_approval', 'int', 'YES'
            UNION ALL SELECT 'request_hours_at_approval', 'int', 'YES'
            UNION ALL SELECT 'projected_hours_at_approval', 'int', 'YES'
            UNION ALL SELECT 'max_allowed_hours_at_approval', 'int', 'YES'
            UNION ALL SELECT 'remaining_hours_after_approval', 'int', 'YES'
            UNION ALL SELECT 'created_at', 'timestamp', 'NO'
            UNION ALL SELECT 'updated_at', 'timestamp', 'NO'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'student_registration_requests'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR LOWER(c.data_type) <> required.data_type
           OR c.is_nullable <> required.is_nullable
           OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%')
           OR (required.column_name = 'status' AND IFNULL(c.character_maximum_length, 0) < 40)
    ) = 0, 1, 0)
);

SET @srri_columns_ok := IF(
    @srri_exists = 0,
    1,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_item_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'student_registration_request_id', 'int', 'NO'
            UNION ALL SELECT 'course_offering_id', 'int', 'NO'
            UNION ALL SELECT 'student_course_registration_id', 'int', 'YES'
            UNION ALL SELECT 'created_at', 'timestamp', 'NO'
            UNION ALL SELECT 'updated_at', 'timestamp', 'NO'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'student_registration_request_items'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR LOWER(c.data_type) <> required.data_type
           OR c.is_nullable <> required.is_nullable
           OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%')
    ) = 0, 1, 0)
);

SET @srre_columns_ok := IF(
    @srre_exists = 0,
    1,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_event_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'student_registration_request_id', 'int', 'NO'
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
           AND c.table_name = 'student_registration_request_events'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR LOWER(c.data_type) <> required.data_type
           OR c.is_nullable <> required.is_nullable
           OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%')
           OR (required.column_name = 'event_type' AND IFNULL(c.character_maximum_length, 0) < 40)
    ) = 0, 1, 0)
);

SET @srr_pk_ok := IF(
    @srr_exists = 0,
    1,
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
    1,
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
    1,
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
    1,
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
    1,
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
    1,
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
    1,
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
    1,
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
    1,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_requests' AS src_table, 'student_id' AS src_column, 'students' AS dst_table, 'student_id' AS dst_column
            UNION ALL SELECT 'student_registration_requests', 'academic_year_id', 'academic_years', 'academic_year_id'
            UNION ALL SELECT 'student_registration_requests', 'semester_id', 'semesters', 'semester_id'
            UNION ALL SELECT 'student_registration_requests', 'advisor_user_id', 'users', 'user_id'
        ) pairs
        INNER JOIN information_schema.columns src
            ON src.table_schema = 'alrowad_uni_rust'
           AND src.table_name = pairs.src_table
           AND src.column_name = pairs.src_column
        INNER JOIN information_schema.columns dst
            ON dst.table_schema = 'alrowad_uni_rust'
           AND dst.table_name = pairs.dst_table
           AND dst.column_name = pairs.dst_column
        WHERE src.column_type <> dst.column_type
           OR LOWER(src.data_type) <> 'int'
           OR LOWER(dst.data_type) <> 'int'
    ) = 0, 1, 0)
);
SET @srri_fk_types_ok := IF(
    @srri_exists = 0,
    1,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_items' AS src_table, 'student_registration_request_id' AS src_column, 'student_registration_requests' AS dst_table, 'student_registration_request_id' AS dst_column
            UNION ALL SELECT 'student_registration_request_items', 'course_offering_id', 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'student_registration_request_items', 'student_course_registration_id', 'student_course_registrations', 'student_course_registration_id'
        ) pairs
        INNER JOIN information_schema.columns src
            ON src.table_schema = 'alrowad_uni_rust'
           AND src.table_name = pairs.src_table
           AND src.column_name = pairs.src_column
        INNER JOIN information_schema.columns dst
            ON dst.table_schema = 'alrowad_uni_rust'
           AND dst.table_name = pairs.dst_table
           AND dst.column_name = pairs.dst_column
        WHERE src.column_type <> dst.column_type
           OR LOWER(src.data_type) <> 'int'
           OR LOWER(dst.data_type) <> 'int'
    ) = 0, 1, 0)
);
SET @srre_fk_types_ok := IF(
    @srre_exists = 0,
    1,
    IF((
        SELECT COUNT(*)
        FROM (
            SELECT 'student_registration_request_events' AS src_table, 'student_registration_request_id' AS src_column, 'student_registration_requests' AS dst_table, 'student_registration_request_id' AS dst_column
            UNION ALL SELECT 'student_registration_request_events', 'actor_user_id', 'users', 'user_id'
        ) pairs
        INNER JOIN information_schema.columns src
            ON src.table_schema = 'alrowad_uni_rust'
           AND src.table_name = pairs.src_table
           AND src.column_name = pairs.src_column
        INNER JOIN information_schema.columns dst
            ON dst.table_schema = 'alrowad_uni_rust'
           AND dst.table_name = pairs.dst_table
           AND dst.column_name = pairs.dst_column
        WHERE src.column_type <> dst.column_type
           OR LOWER(src.data_type) <> 'int'
           OR LOWER(dst.data_type) <> 'int'
    ) = 0, 1, 0)
);

SET @srr_compatible := IF(
    @srr_engine_ok = 1
    AND @srr_columns_ok = 1
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

SELECT
    'database_alrowad_uni_rust' AS check_name,
    IF(@db_ready = 1, 'READY', 'BLOCKED') AS result;

SELECT
    'academic_advisor_role_exactly_once_active' AS check_name,
    IF(@academic_advisor_active_count = 1, 'READY', 'BLOCKED') AS result,
    @academic_advisor_active_count AS actual;

SELECT
    'academic_advisor_role_not_duplicated' AS check_name,
    IF(@academic_advisor_role_count = 1, 'READY', 'BLOCKED') AS result,
    @academic_advisor_role_count AS actual;

SELECT
    'dean_role_active' AS check_name,
    IF(@dean_active_count = 1, 'READY', 'BLOCKED') AS result,
    @dean_active_count AS actual;

SELECT
    'student_role_active' AS check_name,
    IF(@student_active_count >= 1, 'READY', 'BLOCKED') AS result,
    @student_active_count AS actual;

SELECT
    'registration_module_active' AS check_name,
    IF(@registration_module_count = 1, 'READY', 'BLOCKED') AS result,
    @registration_module_count AS actual;

SELECT
    'required_structure' AS report_section,
    required.table_name,
    required.column_name,
    COALESCE(c.column_type, 'MISSING') AS observed_type,
    IF(c.column_name IS NULL, 'BLOCKED', 'READY') AS result
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
ORDER BY required.table_name, required.column_name;

SELECT
    'fk_targets_signed_int' AS check_name,
    IF(@fk_targets_signed_int = 1, 'READY', 'BLOCKED') AS result;

SELECT
    'permission_code_unique_compatible' AS check_name,
    IF(@permission_code_unique_ok = 1, 'READY', 'BLOCKED') AS result;

SELECT
    'role_permissions_unique_compatible' AS check_name,
    IF(@role_permissions_unique_ok = 1, 'READY', 'BLOCKED') AS result;

SELECT
    'new_or_compatible_student_registration_requests' AS check_name,
    IF(@srr_compatible = 1, 'READY', 'BLOCKED') AS result,
    @srr_exists AS table_exists,
    @srr_engine_ok AS engine_ok,
    @srr_columns_ok AS columns_ok,
    @srr_pk_ok AS pk_ok,
    @srr_unique_ok AS unique_ok,
    @srr_fk_ok AS fk_ok,
    @srr_fk_types_ok AS fk_types_ok;

SELECT
    'new_or_compatible_student_registration_request_items' AS check_name,
    IF(@srri_compatible = 1, 'READY', 'BLOCKED') AS result,
    @srri_exists AS table_exists,
    @srri_engine_ok AS engine_ok,
    @srri_columns_ok AS columns_ok,
    @srri_pk_ok AS pk_ok,
    @srri_unique_ok AS unique_ok,
    @srri_fk_ok AS fk_ok,
    @srri_fk_types_ok AS fk_types_ok;

SELECT
    'new_or_compatible_student_registration_request_events' AS check_name,
    IF(@srre_compatible = 1, 'READY', 'BLOCKED') AS result,
    @srre_exists AS table_exists,
    @srre_engine_ok AS engine_ok,
    @srre_columns_ok AS columns_ok,
    @srre_pk_ok AS pk_ok,
    @srre_fk_ok AS fk_ok,
    @srre_fk_types_ok AS fk_types_ok;

SELECT
    'OVERALL' AS check_name,
    IF(@overall_ready = 1, 'READY', 'BLOCKED') AS result;
