-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE application data.
-- SET user variables and temporary reporting tables only.
-- Do not use DATABASE().
--
-- Target workflow tables and RBAC objects are classified ABSENT / COMPATIBLE / CONFLICT.
-- OVERALL is BLOCKED when any target object is CONFLICT or a prerequisite is missing.

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

SELECT 'A_required_tables' AS report_section, table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'course_offerings', 'courses', 'academic_programs', 'departments', 'colleges',
      'users', 'roles', 'permissions', 'role_permissions', 'system_modules',
      'user_roles', 'user_access_scopes'
  )
ORDER BY table_name;

SELECT 'B_missing_required_columns' AS report_section, required_columns.table_name, required_columns.column_name
FROM (
    SELECT 'course_offerings' AS table_name, 'course_offering_id' AS column_name
    UNION ALL SELECT 'course_offerings', 'course_id'
    UNION ALL SELECT 'course_offerings', 'academic_program_id'
    UNION ALL SELECT 'course_offerings', 'academic_year_id'
    UNION ALL SELECT 'course_offerings', 'semester_id'
    UNION ALL SELECT 'course_offerings', 'status'
    UNION ALL SELECT 'courses', 'course_id'
    UNION ALL SELECT 'academic_programs', 'academic_program_id'
    UNION ALL SELECT 'departments', 'department_id'
    UNION ALL SELECT 'colleges', 'college_id'
    UNION ALL SELECT 'users', 'user_id'
    UNION ALL SELECT 'roles', 'role_code'
    UNION ALL SELECT 'permissions', 'permission_code'
    UNION ALL SELECT 'role_permissions', 'permission_id'
    UNION ALL SELECT 'system_modules', 'module_code'
    UNION ALL SELECT 'user_roles', 'user_id'
    UNION ALL SELECT 'user_access_scopes', 'scope_type'
) required_columns
LEFT JOIN information_schema.columns existing
    ON existing.table_schema = 'alrowad_uni_rust'
   AND existing.table_name = required_columns.table_name
   AND existing.column_name = required_columns.column_name
WHERE @db_ready = 1
  AND existing.column_name IS NULL;

SET @requests_rows := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_requests' AND table_type = 'BASE TABLE'), 0);
SET @reviews_rows := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_reviews' AND table_type = 'BASE TABLE'), 0);
SET @events_rows := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_events' AND table_type = 'BASE TABLE'), 0);

SELECT 'C_target_tables' AS report_section, table_name, engine, table_comment
FROM information_schema.tables
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'course_offering_exception_requests',
      'course_offering_exception_reviews',
      'course_offering_exception_events'
  )
ORDER BY table_name;

SET @requests_expected_cols := IF(
    @requests_rows = 1,
    (SELECT COUNT(*) FROM (
        SELECT 'course_offering_exception_request_id' AS column_name UNION ALL SELECT 'course_offering_id'
        UNION ALL SELECT 'requested_by_user_id' UNION ALL SELECT 'reason' UNION ALL SELECT 'status'
        UNION ALL SELECT 'submission_version' UNION ALL SELECT 'current_slot' UNION ALL SELECT 'snapshot_course_id'
        UNION ALL SELECT 'snapshot_academic_program_id' UNION ALL SELECT 'snapshot_academic_year_id'
        UNION ALL SELECT 'snapshot_semester_id' UNION ALL SELECT 'snapshot_department_id'
        UNION ALL SELECT 'submitted_at' UNION ALL SELECT 'approved_at' UNION ALL SELECT 'materialized_at'
        UNION ALL SELECT 'superseded_at' UNION ALL SELECT 'superseded_by_request_id'
        UNION ALL SELECT 'superseded_reason' UNION ALL SELECT 'created_at' UNION ALL SELECT 'updated_at'
    ) expected
    JOIN information_schema.columns existing
        ON existing.table_schema = 'alrowad_uni_rust'
       AND existing.table_name = 'course_offering_exception_requests'
       AND existing.column_name = expected.column_name),
    0
);
SET @reviews_expected_cols := IF(
    @reviews_rows = 1,
    (SELECT COUNT(*) FROM (
        SELECT 'course_offering_exception_review_id' AS column_name UNION ALL SELECT 'course_offering_exception_request_id'
        UNION ALL SELECT 'submission_version' UNION ALL SELECT 'review_authority' UNION ALL SELECT 'status'
        UNION ALL SELECT 'reviewed_by_user_id' UNION ALL SELECT 'reviewed_at' UNION ALL SELECT 'notes'
        UNION ALL SELECT 'created_at' UNION ALL SELECT 'updated_at'
    ) expected
    JOIN information_schema.columns existing
        ON existing.table_schema = 'alrowad_uni_rust'
       AND existing.table_name = 'course_offering_exception_reviews'
       AND existing.column_name = expected.column_name),
    0
);
SET @events_expected_cols := IF(
    @events_rows = 1,
    (SELECT COUNT(*) FROM (
        SELECT 'course_offering_exception_event_id' AS column_name UNION ALL SELECT 'course_offering_exception_request_id'
        UNION ALL SELECT 'event_type' UNION ALL SELECT 'actor_user_id' UNION ALL SELECT 'submission_version'
        UNION ALL SELECT 'notes' UNION ALL SELECT 'created_at'
    ) expected
    JOIN information_schema.columns existing
        ON existing.table_schema = 'alrowad_uni_rust'
       AND existing.table_name = 'course_offering_exception_events'
       AND existing.column_name = expected.column_name),
    0
);

SET @requests_engine_ok := IF(@requests_rows = 1, IF((SELECT engine FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_requests' AND table_type = 'BASE TABLE') <=> 'InnoDB', 1, 0), 0);
SET @reviews_engine_ok := IF(@reviews_rows = 1, IF((SELECT engine FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_reviews' AND table_type = 'BASE TABLE') <=> 'InnoDB', 1, 0), 0);
SET @events_engine_ok := IF(@events_rows = 1, IF((SELECT engine FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_events' AND table_type = 'BASE TABLE') <=> 'InnoDB', 1, 0), 0);

SET @requests_pk_ok := IF(@requests_rows = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_requests' AND index_name = 'PRIMARY') <=> 'course_offering_exception_request_id', 1, 0);
SET @reviews_pk_ok := IF(@reviews_rows = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_reviews' AND index_name = 'PRIMARY') <=> 'course_offering_exception_review_id', 1, 0);
SET @events_pk_ok := IF(@events_rows = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_events' AND index_name = 'PRIMARY') <=> 'course_offering_exception_event_id', 1, 0);

SET @requests_unique_ok := IF(@requests_rows = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_requests' AND index_name = 'uq_coer_current_slot' AND non_unique = 0) <=> 'course_offering_id,current_slot', 1, 0);
SET @reviews_unique_ok := IF(@reviews_rows = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_reviews' AND index_name = 'uq_coerv_request_authority_version' AND non_unique = 0) <=> 'course_offering_exception_request_id,review_authority,submission_version', 1, 0);

SET @requests_fk_ok := IF(
    @requests_rows = 1 AND (
        SELECT COUNT(*) FROM (
            SELECT 'fk_coer_course_offering' AS constraint_name UNION ALL SELECT 'fk_coer_requested_by' UNION ALL SELECT 'fk_coer_superseded_by'
        ) required
        LEFT JOIN information_schema.table_constraints k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = 'course_offering_exception_requests'
           AND k.constraint_name = required.constraint_name
           AND k.constraint_type = 'FOREIGN KEY'
        WHERE k.constraint_name IS NULL
    ) = 0, 1, 0
);
SET @reviews_fk_ok := IF(
    @reviews_rows = 1 AND (
        SELECT COUNT(*) FROM (
            SELECT 'fk_coerv_request' AS constraint_name UNION ALL SELECT 'fk_coerv_reviewer'
        ) required
        LEFT JOIN information_schema.table_constraints k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = 'course_offering_exception_reviews'
           AND k.constraint_name = required.constraint_name
           AND k.constraint_type = 'FOREIGN KEY'
        WHERE k.constraint_name IS NULL
    ) = 0, 1, 0
);
SET @events_fk_ok := IF(
    @events_rows = 1 AND (
        SELECT COUNT(*) FROM (
            SELECT 'fk_coee_request' AS constraint_name UNION ALL SELECT 'fk_coee_actor'
        ) required
        LEFT JOIN information_schema.table_constraints k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = 'course_offering_exception_events'
           AND k.constraint_name = required.constraint_name
           AND k.constraint_type = 'FOREIGN KEY'
        WHERE k.constraint_name IS NULL
    ) = 0, 1, 0
);

SET @requests_queue_ok := IF(
    @requests_rows = 1 AND (
        SELECT COUNT(*) FROM (
            SELECT 'idx_coer_status' AS index_name UNION ALL SELECT 'idx_coer_requested_by'
            UNION ALL SELECT 'idx_coer_submitted_at' UNION ALL SELECT 'idx_coer_offering_status'
        ) required
        JOIN information_schema.statistics existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = 'course_offering_exception_requests'
           AND existing.index_name = required.index_name
        GROUP BY required.index_name
    ) = 4, 1, 0
);
SET @reviews_queue_ok := IF(@reviews_rows = 1 AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_reviews' AND index_name = 'idx_coerv_authority_status'), 1, 0);
SET @events_queue_ok := IF(@events_rows = 1 AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_events' AND index_name = 'idx_coee_request_created'), 1, 0);

SET @requests_state := IF(@requests_rows = 0, 'ABSENT', IF(@requests_expected_cols = 20 AND @requests_engine_ok = 1 AND @requests_pk_ok = 1 AND @requests_unique_ok = 1 AND @requests_fk_ok = 1 AND @requests_queue_ok = 1, 'COMPATIBLE', 'CONFLICT'));
SET @reviews_state := IF(@reviews_rows = 0, 'ABSENT', IF(@reviews_expected_cols = 10 AND @reviews_engine_ok = 1 AND @reviews_pk_ok = 1 AND @reviews_unique_ok = 1 AND @reviews_fk_ok = 1 AND @reviews_queue_ok = 1, 'COMPATIBLE', 'CONFLICT'));
SET @events_state := IF(@events_rows = 0, 'ABSENT', IF(@events_expected_cols = 7 AND @events_engine_ok = 1 AND @events_pk_ok = 1 AND @events_fk_ok = 1 AND @events_queue_ok = 1, 'COMPATIBLE', 'CONFLICT'));

SELECT 'D_target_classification' AS report_section,
       @requests_state AS requests_state,
       @reviews_state AS reviews_state,
       @events_state AS events_state;

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
SET @permissions_code_unique := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'permissions' AND column_name = 'permission_code' AND non_unique = 0),
    0
);

SET @view_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.exceptional_open.view'), 0);
SET @request_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.exceptional_open.request'), 0);
SET @sci_review_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.exceptional_open.review_scientific'), 0);
SET @adm_review_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'course_offerings.exceptional_open.review_administrative'), 0);

SET @view_perm_compatible := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.exceptional_open.view' AND p.is_active = 1 AND sm.module_code = 'courses' AND LOWER(p.permission_name) LIKE '%exceptional%' AND LOWER(p.permission_name) LIKE '%view%'), 0);
SET @request_perm_compatible := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.exceptional_open.request' AND p.is_active = 1 AND sm.module_code = 'courses' AND LOWER(p.permission_name) LIKE '%exceptional%' AND LOWER(p.permission_name) LIKE '%request%'), 0);
SET @sci_review_perm_compatible := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.exceptional_open.review_scientific' AND p.is_active = 1 AND sm.module_code = 'courses' AND LOWER(p.permission_name) LIKE '%scientific%' AND LOWER(p.permission_name) LIKE '%review%'), 0);
SET @adm_review_perm_compatible := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.exceptional_open.review_administrative' AND p.is_active = 1 AND sm.module_code = 'courses' AND LOWER(p.permission_name) LIKE '%administrative%' AND LOWER(p.permission_name) LIKE '%review%'), 0);

SET @view_perm_state := IF(@view_perm_rows = 0, 'ABSENT', IF(@view_perm_rows = 1 AND @view_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @request_perm_state := IF(@request_perm_rows = 0, 'ABSENT', IF(@request_perm_rows = 1 AND @request_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @sci_review_perm_state := IF(@sci_review_perm_rows = 0, 'ABSENT', IF(@sci_review_perm_rows = 1 AND @sci_review_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @adm_review_perm_state := IF(@adm_review_perm_rows = 0, 'ABSENT', IF(@adm_review_perm_rows = 1 AND @adm_review_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));

SELECT 'E_permission_classification' AS report_section,
       @view_perm_state AS view_perm_state,
       @request_perm_state AS request_perm_state,
       @sci_review_perm_state AS sci_review_perm_state,
       @adm_review_perm_state AS adm_review_perm_state;

SELECT 'F_roles_modules' AS report_section,
       @dean_role_exists AS dean_role_exists,
       @sci_role_exists AS sci_role_exists,
       @adm_role_exists AS adm_role_exists,
       @phase3_sci_perm AS phase3_sci_perm,
       @phase3_adm_perm AS phase3_adm_perm,
       @courses_module_ok AS courses_module_ok,
       @offering_identity_index AS offering_identity_index;

SET @overall := IF(
    @db_ready = 1
    AND @missing_required_columns = 0
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
    AND @courses_module_ok = 1
    AND @permissions_code_unique > 0,
    'READY',
    'BLOCKED'
);

SELECT 'OVERALL' AS report_section,
       @overall AS result,
       @missing_required_columns AS missing_required_columns,
       @requests_state AS requests_state,
       @reviews_state AS reviews_state,
       @events_state AS events_state,
       @view_perm_state AS view_perm_state,
       @request_perm_state AS request_perm_state,
       @sci_review_perm_state AS sci_review_perm_state,
       @adm_review_perm_state AS adm_review_perm_state,
       @dean_role_exists AS dean_role_exists,
       @sci_role_exists AS sci_role_exists,
       @adm_role_exists AS adm_role_exists,
       @courses_module_ok AS courses_module_ok,
       @offering_identity_index AS offering_identity_index,
       @engines_ok AS engines_ok;
