-- Manual and idempotent. Fail-closed.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- DDL commits implicitly in MariaDB; table CREATE statements are not wrapped
-- in a transaction. RBAC DML is transactional.
-- COMMIT only when post-write RBAC verification succeeds (@phase7_complete = 1).
-- ROLLBACK on unexpected post-write failure so this transaction's permission /
-- role_permission inserts do not persist. Do not DELETE-then-COMMIT leftover RBAC.
-- Do not use stored procedures, DELIMITER, or SIGNAL.
-- Independently recomputes the same critical safety conditions as 00_preflight.sql,
-- including the pre-write forbidden-matrix audit (@rbac_matrix_conflict).
--
-- Does NOT:
--   modify course_offerings rows
--   create users, user_roles, or user_access_scopes
--   create fake workflow requests or approvals
--   create organizational units
--   grant review permissions to generic vice_president or dean
--   insert RBAC when apply_ready = 0 (including rbac_matrix_conflict = 1)
--   insert Phase 7 mappings for super_admin
-- academic_program_id_snapshot is INT NULL so legacy Offerings with
-- course_offerings.academic_program_id IS NULL can be closed. Do not backfill.

SET @apply_ready := 0;
SET @phase7_complete := 0;
SET @apply_status := 'BLOCKED';

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
            SELECT 'course_offerings' AS table_name, 'course_offering_id' AS column_name
            UNION ALL SELECT 'course_offerings', 'course_id'
            UNION ALL SELECT 'course_offerings', 'academic_program_id'
            UNION ALL SELECT 'course_offerings', 'academic_year_id'
            UNION ALL SELECT 'course_offerings', 'semester_id'
            UNION ALL SELECT 'course_offerings', 'department_id'
            UNION ALL SELECT 'course_offerings', 'status'
            UNION ALL SELECT 'courses', 'course_id'
            UNION ALL SELECT 'academic_programs', 'academic_program_id'
            UNION ALL SELECT 'academic_programs', 'department_id'
            UNION ALL SELECT 'departments', 'department_id'
            UNION ALL SELECT 'departments', 'college_id'
            UNION ALL SELECT 'colleges', 'college_id'
            UNION ALL SELECT 'users', 'user_id'
            UNION ALL SELECT 'roles', 'role_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'roles', 'is_active'
            UNION ALL SELECT 'permissions', 'permission_id'
            UNION ALL SELECT 'permissions', 'module_id'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'permissions', 'permission_name'
            UNION ALL SELECT 'permissions', 'description'
            UNION ALL SELECT 'permissions', 'is_active'
            UNION ALL SELECT 'role_permissions', 'role_id'
            UNION ALL SELECT 'role_permissions', 'permission_id'
            UNION ALL SELECT 'system_modules', 'module_id'
            UNION ALL SELECT 'system_modules', 'module_code'
            UNION ALL SELECT 'system_modules', 'is_active'
            UNION ALL SELECT 'user_roles', 'user_id'
            UNION ALL SELECT 'user_roles', 'role_id'
            UNION ALL SELECT 'user_access_scopes', 'scope_type'
            UNION ALL SELECT 'user_access_scopes', 'user_id'
        ) required_columns
        LEFT JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = required_columns.table_name
           AND existing.column_name = required_columns.column_name
        WHERE existing.column_name IS NULL
    ),
    1
);

SET @structure_ok := IF(@db_ready = 1 AND @missing_required_columns = 0, 1, 0);

SET @requests_rows := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_requests' AND table_type = 'BASE TABLE'), 0);
SET @reviews_rows := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_reviews' AND table_type = 'BASE TABLE'), 0);
SET @events_rows := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_events' AND table_type = 'BASE TABLE'), 0);

SET @requests_expected_cols := IF(
    @requests_rows = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'course_offering_closure_request_id' AS column_name
            UNION ALL SELECT 'course_offering_id'
            UNION ALL SELECT 'requested_by_user_id'
            UNION ALL SELECT 'request_reason'
            UNION ALL SELECT 'status'
            UNION ALL SELECT 'submission_version'
            UNION ALL SELECT 'current_slot'
            UNION ALL SELECT 'course_id_snapshot'
            UNION ALL SELECT 'academic_program_id_snapshot'
            UNION ALL SELECT 'academic_year_id_snapshot'
            UNION ALL SELECT 'semester_id_snapshot'
            UNION ALL SELECT 'department_id_snapshot'
            UNION ALL SELECT 'submitted_at'
            UNION ALL SELECT 'approved_at'
            UNION ALL SELECT 'materialized_at'
            UNION ALL SELECT 'superseded_at'
            UNION ALL SELECT 'superseded_by_request_id'
            UNION ALL SELECT 'supersede_reason'
            UNION ALL SELECT 'created_at'
            UNION ALL SELECT 'updated_at'
        ) expected
        JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = 'course_offering_closure_requests'
           AND existing.column_name = expected.column_name
    ),
    0
);
SET @reviews_expected_cols := IF(
    @reviews_rows = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'course_offering_closure_review_id' AS column_name
            UNION ALL SELECT 'course_offering_closure_request_id'
            UNION ALL SELECT 'submission_version'
            UNION ALL SELECT 'review_authority'
            UNION ALL SELECT 'status'
            UNION ALL SELECT 'reviewed_by_user_id'
            UNION ALL SELECT 'reviewed_at'
            UNION ALL SELECT 'reason'
            UNION ALL SELECT 'created_at'
            UNION ALL SELECT 'updated_at'
        ) expected
        JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = 'course_offering_closure_reviews'
           AND existing.column_name = expected.column_name
    ),
    0
);
SET @events_expected_cols := IF(
    @events_rows = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'course_offering_closure_event_id' AS column_name
            UNION ALL SELECT 'course_offering_closure_request_id'
            UNION ALL SELECT 'event_type'
            UNION ALL SELECT 'actor_user_id'
            UNION ALL SELECT 'submission_version'
            UNION ALL SELECT 'notes'
            UNION ALL SELECT 'created_at'
        ) expected
        JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = 'course_offering_closure_events'
           AND existing.column_name = expected.column_name
    ),
    0
);

SET @requests_engine_ok := IF(@requests_rows = 1, IF((SELECT engine FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_requests' AND table_type = 'BASE TABLE') <=> 'InnoDB', 1, 0), 0);
SET @reviews_engine_ok := IF(@reviews_rows = 1, IF((SELECT engine FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_reviews' AND table_type = 'BASE TABLE') <=> 'InnoDB', 1, 0), 0);
SET @events_engine_ok := IF(@events_rows = 1, IF((SELECT engine FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_events' AND table_type = 'BASE TABLE') <=> 'InnoDB', 1, 0), 0);

SET @requests_pk_ok := IF(@requests_rows = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_requests' AND index_name = 'PRIMARY') <=> 'course_offering_closure_request_id', 1, 0);
SET @reviews_pk_ok := IF(@reviews_rows = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_reviews' AND index_name = 'PRIMARY') <=> 'course_offering_closure_review_id', 1, 0);
SET @events_pk_ok := IF(@events_rows = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_events' AND index_name = 'PRIMARY') <=> 'course_offering_closure_event_id', 1, 0);

SET @requests_types_ok := IF(
    @requests_rows = 1 AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'course_offering_closure_request_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'course_offering_id', 'int', 'NO'
            UNION ALL SELECT 'requested_by_user_id', 'int', 'NO'
            UNION ALL SELECT 'status', 'varchar', 'NO'
            UNION ALL SELECT 'submission_version', 'int', 'NO'
            UNION ALL SELECT 'current_slot', 'tinyint', 'YES'
            UNION ALL SELECT 'course_id_snapshot', 'int', 'NO'
            UNION ALL SELECT 'academic_program_id_snapshot', 'int', 'YES'
            UNION ALL SELECT 'academic_year_id_snapshot', 'int', 'NO'
            UNION ALL SELECT 'semester_id_snapshot', 'int', 'NO'
            UNION ALL SELECT 'request_reason', 'text', 'NO'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'course_offering_closure_requests'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR c.is_nullable <> required.is_nullable
           OR LOWER(c.data_type) <> required.data_type
           OR (required.data_type IN ('int', 'tinyint') AND LOWER(c.column_type) LIKE '%unsigned%')
           OR (required.column_name = 'status' AND IFNULL(c.character_maximum_length, 0) < 32)
    ) = 0,
    1,
    0
);
SET @reviews_types_ok := IF(
    @reviews_rows = 1 AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'course_offering_closure_review_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'course_offering_closure_request_id', 'int', 'NO'
            UNION ALL SELECT 'submission_version', 'int', 'NO'
            UNION ALL SELECT 'status', 'varchar', 'NO'
            UNION ALL SELECT 'reviewed_by_user_id', 'int', 'YES'
            UNION ALL SELECT 'review_authority', 'enum', 'NO'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'course_offering_closure_reviews'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR c.is_nullable <> required.is_nullable
           OR (
               required.column_name = 'review_authority'
               AND NOT (
                   (LOWER(c.data_type) = 'enum' AND LOWER(c.column_type) LIKE '%scientific%' AND LOWER(c.column_type) LIKE '%administrative%')
                   OR (LOWER(c.data_type) IN ('varchar', 'char') AND IFNULL(c.character_maximum_length, 0) >= 14)
               )
           )
           OR (
               required.column_name <> 'review_authority'
               AND (
                   LOWER(c.data_type) <> required.data_type
                   OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%')
                   OR (required.column_name = 'status' AND IFNULL(c.character_maximum_length, 0) < 32)
               )
           )
    ) = 0,
    1,
    0
);
SET @events_types_ok := IF(
    @events_rows = 1 AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'course_offering_closure_event_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'course_offering_closure_request_id', 'int', 'NO'
            UNION ALL SELECT 'event_type', 'varchar', 'NO'
            UNION ALL SELECT 'actor_user_id', 'int', 'YES'
            UNION ALL SELECT 'created_at', 'timestamp', 'NO'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'course_offering_closure_events'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR (
               required.column_name <> 'created_at'
               AND c.is_nullable <> required.is_nullable
           )
           OR (
               required.column_name = 'created_at'
               AND LOWER(c.data_type) NOT IN ('timestamp', 'datetime')
           )
           OR (
               required.column_name <> 'created_at'
               AND (
                   LOWER(c.data_type) <> required.data_type
                   OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%')
                   OR (required.column_name = 'event_type' AND IFNULL(c.character_maximum_length, 0) < 64)
               )
           )
    ) = 0,
    1,
    0
);

SET @requests_unique_ok := IF(
    @requests_rows = 1
    AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_requests' AND index_name = 'uq_cocr_current_slot' AND non_unique = 0) <=> 'course_offering_id,current_slot',
    1,
    0
);
SET @reviews_unique_ok := IF(
    @reviews_rows = 1
    AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_reviews' AND index_name = 'uq_cocrv_request_authority_version' AND non_unique = 0) <=> 'course_offering_closure_request_id,review_authority,submission_version',
    1,
    0
);

SET @requests_fk_ok := IF(
    @requests_rows = 1 AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'fk_cocr_course_offering' AS constraint_name, 'course_offering_id' AS column_name, 'course_offerings' AS ref_table, 'course_offering_id' AS ref_column
            UNION ALL SELECT 'fk_cocr_requested_by', 'requested_by_user_id', 'users', 'user_id'
            UNION ALL SELECT 'fk_cocr_superseded_by', 'superseded_by_request_id', 'course_offering_closure_requests', 'course_offering_closure_request_id'
        ) required
        LEFT JOIN information_schema.key_column_usage k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = 'course_offering_closure_requests'
           AND k.constraint_name = required.constraint_name
           AND k.column_name = required.column_name
           AND k.referenced_table_schema = 'alrowad_uni_rust'
           AND k.referenced_table_name = required.ref_table
           AND k.referenced_column_name = required.ref_column
        WHERE k.column_name IS NULL
    ) = 0,
    1,
    0
);
SET @reviews_fk_ok := IF(
    @reviews_rows = 1 AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'fk_cocrv_request' AS constraint_name, 'course_offering_closure_request_id' AS column_name, 'course_offering_closure_requests' AS ref_table, 'course_offering_closure_request_id' AS ref_column
            UNION ALL SELECT 'fk_cocrv_reviewer', 'reviewed_by_user_id', 'users', 'user_id'
        ) required
        LEFT JOIN information_schema.key_column_usage k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = 'course_offering_closure_reviews'
           AND k.constraint_name = required.constraint_name
           AND k.column_name = required.column_name
           AND k.referenced_table_schema = 'alrowad_uni_rust'
           AND k.referenced_table_name = required.ref_table
           AND k.referenced_column_name = required.ref_column
        WHERE k.column_name IS NULL
    ) = 0,
    1,
    0
);
SET @events_fk_ok := IF(
    @events_rows = 1 AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'fk_coce_request' AS constraint_name, 'course_offering_closure_request_id' AS column_name, 'course_offering_closure_requests' AS ref_table, 'course_offering_closure_request_id' AS ref_column
            UNION ALL SELECT 'fk_coce_actor', 'actor_user_id', 'users', 'user_id'
        ) required
        LEFT JOIN information_schema.key_column_usage k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = 'course_offering_closure_events'
           AND k.constraint_name = required.constraint_name
           AND k.column_name = required.column_name
           AND k.referenced_table_schema = 'alrowad_uni_rust'
           AND k.referenced_table_name = required.ref_table
           AND k.referenced_column_name = required.ref_column
        WHERE k.column_name IS NULL
    ) = 0,
    1,
    0
);

SET @requests_queue_ok := IF(
    @requests_rows = 1 AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'idx_cocr_status' AS index_name, 'status' AS columns
            UNION ALL SELECT 'idx_cocr_requested_by', 'requested_by_user_id'
            UNION ALL SELECT 'idx_cocr_submitted_at', 'submitted_at'
            UNION ALL SELECT 'idx_cocr_offering_status', 'course_offering_id,status'
        ) required
        JOIN (
            SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'course_offering_closure_requests'
            GROUP BY index_name
        ) existing
            ON existing.index_name = required.index_name
           AND existing.columns = required.columns
    ) = 4,
    1,
    0
);
SET @reviews_queue_ok := IF(
    @reviews_rows = 1
    AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_reviews' AND index_name = 'idx_cocrv_authority_status') <=> 'review_authority,status',
    1,
    0
);
SET @events_queue_ok := IF(
    @events_rows = 1
    AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_events' AND index_name = 'idx_coce_request_created') <=> 'course_offering_closure_request_id,created_at',
    1,
    0
);

SET @requests_state := IF(
    @requests_rows = 0,
    'ABSENT',
    IF(
        @requests_expected_cols = 20
        AND @requests_engine_ok = 1
        AND @requests_pk_ok = 1
        AND @requests_types_ok = 1
        AND @requests_unique_ok = 1
        AND @requests_fk_ok = 1
        AND @requests_queue_ok = 1,
        'COMPATIBLE',
        'CONFLICT'
    )
);
SET @reviews_state := IF(
    @reviews_rows = 0,
    'ABSENT',
    IF(
        @reviews_expected_cols = 10
        AND @reviews_engine_ok = 1
        AND @reviews_pk_ok = 1
        AND @reviews_types_ok = 1
        AND @reviews_unique_ok = 1
        AND @reviews_fk_ok = 1
        AND @reviews_queue_ok = 1,
        'COMPATIBLE',
        'CONFLICT'
    )
);
SET @events_state := IF(
    @events_rows = 0,
    'ABSENT',
    IF(
        @events_expected_cols = 7
        AND @events_engine_ok = 1
        AND @events_pk_ok = 1
        AND @events_types_ok = 1
        AND @events_fk_ok = 1
        AND @events_queue_ok = 1,
        'COMPATIBLE',
        'CONFLICT'
    )
);

SET @dean_role_exists := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'dean' AND is_active = 1), 0);
SET @sci_role_exists := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_scientific' AND is_active = 1), 0);
SET @adm_role_exists := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative' AND is_active = 1), 0);
SET @phase3_sci_perm := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'vice_presidency.scientific.access' AND is_active = 1), 0);
SET @phase3_adm_perm := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'vice_presidency.administrative.access' AND is_active = 1), 0);
SET @courses_module_ok := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'courses' AND is_active = 1), 0);
SET @offering_identity_index := IF(
    @structure_ok = 1
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offerings'
          AND index_name = 'uq_course_offering_program_term'
          AND non_unique = 0
    ) <=> 'course_id,academic_program_id,academic_year_id,semester_id',
    1,
    0
);
SET @engines_ok := IF(
    @structure_ok = 1
    AND (
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name IN ('users', 'course_offerings', 'courses', 'academic_programs', 'departments', 'colleges', 'roles', 'permissions', 'role_permissions')
          AND engine = 'InnoDB'
    ) = 9,
    1,
    0
);
SET @offering_status_ok := IF(
    @structure_ok = 1
    AND (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offerings'
          AND column_name = 'status'
          AND LOWER(data_type) IN ('varchar', 'enum', 'char')
          AND (
              (LOWER(data_type) <> 'enum' AND IFNULL(character_maximum_length, 0) >= 6)
              OR (LOWER(data_type) = 'enum' AND LOWER(column_type) LIKE '%open%' AND LOWER(column_type) LIKE '%closed%')
          )
    ) = 1,
    1,
    0
);

SET @view_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.view'), 0);
SET @request_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.request'), 0);
SET @sci_review_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.review_scientific'), 0);
SET @adm_review_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.review_administrative'), 0);

SET @view_perm_compatible := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.closure.view' AND p.is_active = 1 AND sm.module_code = 'courses' AND LOWER(p.permission_name) LIKE '%closure%' AND LOWER(p.permission_name) LIKE '%view%'),
    0
);
SET @request_perm_compatible := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.closure.request' AND p.is_active = 1 AND sm.module_code = 'courses' AND LOWER(p.permission_name) LIKE '%closure%' AND LOWER(p.permission_name) LIKE '%request%'),
    0
);
SET @sci_review_perm_compatible := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.closure.review_scientific' AND p.is_active = 1 AND sm.module_code = 'courses' AND LOWER(p.permission_name) LIKE '%scientific%' AND LOWER(p.permission_name) LIKE '%review%'),
    0
);
SET @adm_review_perm_compatible := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.closure.review_administrative' AND p.is_active = 1 AND sm.module_code = 'courses' AND LOWER(p.permission_name) LIKE '%administrative%' AND LOWER(p.permission_name) LIKE '%review%'),
    0
);

SET @view_perm_state := IF(@view_perm_rows = 0, 'ABSENT', IF(@view_perm_rows = 1 AND @view_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @request_perm_state := IF(@request_perm_rows = 0, 'ABSENT', IF(@request_perm_rows = 1 AND @request_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @sci_review_perm_state := IF(@sci_review_perm_rows = 0, 'ABSENT', IF(@sci_review_perm_rows = 1 AND @sci_review_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @adm_review_perm_state := IF(@adm_review_perm_rows = 0, 'ABSENT', IF(@adm_review_perm_rows = 1 AND @adm_review_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));

SET @rbac_matrix_conflict := IF(
    @structure_ok = 1,
    (
        SELECT IF(COUNT(*) > 0, 1, 0)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code IN (
            'course_offerings.closure.view',
            'course_offerings.closure.request',
            'course_offerings.closure.review_scientific',
            'course_offerings.closure.review_administrative'
        )
          AND NOT (
              (
                  p.permission_code = 'course_offerings.closure.view'
                  AND r.role_code IN ('dean', 'vice_president_scientific', 'vice_president_administrative')
              )
              OR (
                  p.permission_code = 'course_offerings.closure.request'
                  AND r.role_code = 'dean'
              )
              OR (
                  p.permission_code = 'course_offerings.closure.review_scientific'
                  AND r.role_code = 'vice_president_scientific'
              )
              OR (
                  p.permission_code = 'course_offerings.closure.review_administrative'
                  AND r.role_code = 'vice_president_administrative'
              )
          )
    ),
    0
);

SET @sql := IF(
    @structure_ok = 1,
    'SELECT DISTINCT ''RBAC_MATRIX_CONFLICT'' AS report_section, r.role_code, p.permission_code
     FROM `alrowad_uni_rust`.`roles` r
     JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
     JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
     WHERE p.permission_code IN (
         ''course_offerings.closure.view'',
         ''course_offerings.closure.request'',
         ''course_offerings.closure.review_scientific'',
         ''course_offerings.closure.review_administrative''
     )
       AND NOT (
           (
               p.permission_code = ''course_offerings.closure.view''
               AND r.role_code IN (''dean'', ''vice_president_scientific'', ''vice_president_administrative'')
           )
           OR (
               p.permission_code = ''course_offerings.closure.request''
               AND r.role_code = ''dean''
           )
           OR (
               p.permission_code = ''course_offerings.closure.review_scientific''
               AND r.role_code = ''vice_president_scientific''
           )
           OR (
               p.permission_code = ''course_offerings.closure.review_administrative''
               AND r.role_code = ''vice_president_administrative''
           )
       )
     ORDER BY r.role_code, p.permission_code',
    'SELECT ''RBAC_MATRIX_CONFLICT'' AS report_section, CAST(NULL AS CHAR) AS role_code, CAST(NULL AS CHAR) AS permission_code WHERE 0'
);
PREPARE phase7_rbac_conflict_stmt FROM @sql;
EXECUTE phase7_rbac_conflict_stmt;
DEALLOCATE PREPARE phase7_rbac_conflict_stmt;

SET @permissions_code_unique := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'permissions' AND column_name = 'permission_code' AND non_unique = 0),
    0
);

SET @apply_ready := IF(
    @db_ready = 1
    AND @missing_required_columns = 0
    AND @rbac_matrix_conflict = 0
    AND @requests_state IN ('ABSENT', 'COMPATIBLE')
    AND @reviews_state IN ('ABSENT', 'COMPATIBLE')
    AND @events_state IN ('ABSENT', 'COMPATIBLE')
    AND @view_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @request_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @sci_review_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @adm_review_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @dean_role_exists = 1
    AND @sci_role_exists = 1
    AND @adm_role_exists = 1
    AND @phase3_sci_perm = 1
    AND @phase3_adm_perm = 1
    AND @offering_identity_index = 1
    AND @engines_ok = 1
    AND @offering_status_ok = 1
    AND @courses_module_ok = 1
    AND @permissions_code_unique > 0,
    1,
    0
);

SELECT 'APPLY_GUARD' AS report_section,
       IF(@apply_ready = 1, 'READY', 'BLOCKED') AS result,
       @apply_ready AS apply_ready,
       @rbac_matrix_conflict AS rbac_matrix_conflict,
       @missing_required_columns AS missing_required_columns,
       @requests_state AS requests_state,
       @reviews_state AS reviews_state,
       @events_state AS events_state,
       @offering_status_ok AS offering_status_ok;

SET @sql := IF(
    @apply_ready = 1 AND @requests_state = 'ABSENT',
    'CREATE TABLE `alrowad_uni_rust`.`course_offering_closure_requests` (
        `course_offering_closure_request_id` INT NOT NULL AUTO_INCREMENT,
        `course_offering_id` INT NOT NULL,
        `requested_by_user_id` INT NOT NULL,
        `request_reason` TEXT NOT NULL,
        `status` VARCHAR(32) NOT NULL,
        `submission_version` INT NOT NULL DEFAULT 1,
        `current_slot` TINYINT NULL,
        `course_id_snapshot` INT NOT NULL,
        `academic_program_id_snapshot` INT NULL,
        `academic_year_id_snapshot` INT NOT NULL,
        `semester_id_snapshot` INT NOT NULL,
        `department_id_snapshot` INT NULL,
        `submitted_at` TIMESTAMP NULL,
        `approved_at` TIMESTAMP NULL,
        `materialized_at` TIMESTAMP NULL,
        `superseded_at` TIMESTAMP NULL,
        `superseded_by_request_id` INT NULL,
        `supersede_reason` VARCHAR(64) NULL,
        `created_at` TIMESTAMP NULL,
        `updated_at` TIMESTAMP NULL,
        PRIMARY KEY (`course_offering_closure_request_id`),
        UNIQUE KEY `uq_cocr_current_slot` (`course_offering_id`, `current_slot`),
        KEY `idx_cocr_status` (`status`),
        KEY `idx_cocr_requested_by` (`requested_by_user_id`),
        KEY `idx_cocr_submitted_at` (`submitted_at`),
        KEY `idx_cocr_offering_status` (`course_offering_id`, `status`),
        CONSTRAINT `fk_cocr_course_offering` FOREIGN KEY (`course_offering_id`) REFERENCES `alrowad_uni_rust`.`course_offerings` (`course_offering_id`),
        CONSTRAINT `fk_cocr_requested_by` FOREIGN KEY (`requested_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`),
        CONSTRAINT `fk_cocr_superseded_by` FOREIGN KEY (`superseded_by_request_id`) REFERENCES `alrowad_uni_rust`.`course_offering_closure_requests` (`course_offering_closure_request_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''[phase7-course-offering-closure]''',
    'SELECT ''SKIPPED_REQUESTS'' AS apply_result'
);
PREPARE phase7_cocr_stmt FROM @sql;
EXECUTE phase7_cocr_stmt;
DEALLOCATE PREPARE phase7_cocr_stmt;

SET @sql := IF(
    @apply_ready = 1 AND @reviews_state = 'ABSENT',
    'CREATE TABLE `alrowad_uni_rust`.`course_offering_closure_reviews` (
        `course_offering_closure_review_id` INT NOT NULL AUTO_INCREMENT,
        `course_offering_closure_request_id` INT NOT NULL,
        `submission_version` INT NOT NULL,
        `review_authority` ENUM(''scientific'',''administrative'') NOT NULL,
        `status` VARCHAR(32) NOT NULL,
        `reviewed_by_user_id` INT NULL,
        `reviewed_at` TIMESTAMP NULL,
        `reason` TEXT NULL,
        `created_at` TIMESTAMP NULL,
        `updated_at` TIMESTAMP NULL,
        PRIMARY KEY (`course_offering_closure_review_id`),
        UNIQUE KEY `uq_cocrv_request_authority_version` (`course_offering_closure_request_id`, `review_authority`, `submission_version`),
        KEY `idx_cocrv_authority_status` (`review_authority`, `status`),
        CONSTRAINT `fk_cocrv_request` FOREIGN KEY (`course_offering_closure_request_id`) REFERENCES `alrowad_uni_rust`.`course_offering_closure_requests` (`course_offering_closure_request_id`),
        CONSTRAINT `fk_cocrv_reviewer` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''[phase7-course-offering-closure]''',
    'SELECT ''SKIPPED_REVIEWS'' AS apply_result'
);
PREPARE phase7_cocrv_stmt FROM @sql;
EXECUTE phase7_cocrv_stmt;
DEALLOCATE PREPARE phase7_cocrv_stmt;

SET @sql := IF(
    @apply_ready = 1 AND @events_state = 'ABSENT',
    'CREATE TABLE `alrowad_uni_rust`.`course_offering_closure_events` (
        `course_offering_closure_event_id` INT NOT NULL AUTO_INCREMENT,
        `course_offering_closure_request_id` INT NOT NULL,
        `event_type` VARCHAR(64) NOT NULL,
        `actor_user_id` INT NULL,
        `submission_version` INT NULL,
        `notes` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`course_offering_closure_event_id`),
        KEY `idx_coce_request_created` (`course_offering_closure_request_id`, `created_at`),
        CONSTRAINT `fk_coce_request` FOREIGN KEY (`course_offering_closure_request_id`) REFERENCES `alrowad_uni_rust`.`course_offering_closure_requests` (`course_offering_closure_request_id`),
        CONSTRAINT `fk_coce_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''[phase7-course-offering-closure]''',
    'SELECT ''SKIPPED_EVENTS'' AS apply_result'
);
PREPARE phase7_coce_stmt FROM @sql;
EXECUTE phase7_coce_stmt;
DEALLOCATE PREPARE phase7_coce_stmt;

START TRANSACTION;

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id, permission_code, permission_name, description, is_active, created_at, updated_at
)
SELECT sm.module_id, 'course_offerings.closure.view', 'View course offering closure',
       'Read closure workflow queues and details [phase7-course-offering-closure]',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @view_perm_state = 'ABSENT'
  AND sm.module_code = 'courses'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'course_offerings.closure.view'
  );

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id, permission_code, permission_name, description, is_active, created_at, updated_at
)
SELECT sm.module_id, 'course_offerings.closure.request', 'Request course offering closure',
       'Dean-side create and resubmit of closure requests [phase7-course-offering-closure]',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @request_perm_state = 'ABSENT'
  AND sm.module_code = 'courses'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'course_offerings.closure.request'
  );

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id, permission_code, permission_name, description, is_active, created_at, updated_at
)
SELECT sm.module_id, 'course_offerings.closure.review_scientific', 'Scientific course offering closure review',
       'Scientific VP approve or return closure requests [phase7-course-offering-closure]',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @sci_review_perm_state = 'ABSENT'
  AND sm.module_code = 'courses'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'course_offerings.closure.review_scientific'
  );

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id, permission_code, permission_name, description, is_active, created_at, updated_at
)
SELECT sm.module_id, 'course_offerings.closure.review_administrative', 'Administrative course offering closure review',
       'Administrative VP approve or return closure requests [phase7-course-offering-closure]',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @adm_review_perm_state = 'ABSENT'
  AND sm.module_code = 'courses'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'course_offerings.closure.review_administrative'
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = 'course_offerings.closure.view'
WHERE @apply_ready = 1
  AND r.role_code IN ('dean', 'vice_president_scientific', 'vice_president_administrative')
  AND r.is_active = 1
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = 'course_offerings.closure.request'
WHERE @apply_ready = 1
  AND r.role_code = 'dean'
  AND r.is_active = 1
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = 'course_offerings.closure.review_scientific'
WHERE @apply_ready = 1
  AND r.role_code = 'vice_president_scientific'
  AND r.is_active = 1
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = 'course_offerings.closure.review_administrative'
WHERE @apply_ready = 1
  AND r.role_code = 'vice_president_administrative'
  AND r.is_active = 1
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

SET @phase7_complete := IF(
    @apply_ready = 1,
    IF(
        EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_requests' AND table_type = 'BASE TABLE')
    AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_reviews' AND table_type = 'BASE TABLE')
    AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_events' AND table_type = 'BASE TABLE')
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.view') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.view' AND is_active = 1) = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.request') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.request' AND is_active = 1) = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.review_scientific') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.review_scientific' AND is_active = 1) = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.review_administrative') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.closure.review_administrative' AND is_active = 1) = 1
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'dean' AND p.permission_code = 'course_offerings.closure.view'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'dean' AND p.permission_code = 'course_offerings.closure.request'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'course_offerings.closure.view'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'course_offerings.closure.review_scientific'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'course_offerings.closure.view'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'course_offerings.closure.review_administrative'
    )
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code IN (
            'course_offerings.closure.view',
            'course_offerings.closure.request',
            'course_offerings.closure.review_scientific',
            'course_offerings.closure.review_administrative'
        )
          AND NOT (
              (
                  p.permission_code = 'course_offerings.closure.view'
                  AND r.role_code IN ('dean', 'vice_president_scientific', 'vice_president_administrative')
              )
              OR (
                  p.permission_code = 'course_offerings.closure.request'
                  AND r.role_code = 'dean'
              )
              OR (
                  p.permission_code = 'course_offerings.closure.review_scientific'
                  AND r.role_code = 'vice_president_scientific'
              )
              OR (
                  p.permission_code = 'course_offerings.closure.review_administrative'
                  AND r.role_code = 'vice_president_administrative'
              )
          )
        ),
        1,
        0
    ),
    0
);

SET @sql := IF(
    @apply_ready = 1 AND @phase7_complete = 1,
    'COMMIT',
    'ROLLBACK'
);
PREPARE phase7_txn_end_stmt FROM @sql;
EXECUTE phase7_txn_end_stmt;
DEALLOCATE PREPARE phase7_txn_end_stmt;

SET @apply_status := IF(
    @apply_ready = 0,
    'BLOCKED',
    IF(@phase7_complete = 1, 'APPLIED', 'ROLLED_BACK')
);

SELECT 'APPLY_RESULT' AS report_section,
       @apply_status AS apply_status,
       @apply_ready AS apply_ready,
       @phase7_complete AS phase7_complete,
       @rbac_matrix_conflict AS rbac_matrix_conflict,
       @missing_required_columns AS missing_required_columns,
       @requests_state AS requests_state,
       @reviews_state AS reviews_state,
       @events_state AS events_state,
       @view_perm_state AS view_perm_state,
       @request_perm_state AS request_perm_state,
       @sci_review_perm_state AS sci_review_perm_state,
       @adm_review_perm_state AS adm_review_perm_state;
