-- READ ONLY. Continue only when OVERALL returns PASS.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE application data.
-- Do not use DATABASE().

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @requests_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests'), 0);
SET @reviews_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_reviews'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_events'), 0);

SELECT 'workflow_tables' AS report_section, table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'teaching_assignment_requests',
      'teaching_assignment_reviews',
      'teaching_assignment_events'
  )
ORDER BY table_name;

SELECT 'request_columns' AS report_section, column_name, column_type, is_nullable, column_key
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'teaching_assignment_requests'
ORDER BY ordinal_position;

SELECT 'review_columns' AS report_section, column_name, column_type, is_nullable, column_key
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'teaching_assignment_reviews'
ORDER BY ordinal_position;

SELECT 'event_columns' AS report_section, column_name, column_type, is_nullable, column_key
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'teaching_assignment_events'
ORDER BY ordinal_position;

SELECT 'primary_keys' AS report_section, table_name, column_name
FROM information_schema.key_column_usage
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'teaching_assignment_requests',
      'teaching_assignment_reviews',
      'teaching_assignment_events'
  )
  AND constraint_name = 'PRIMARY'
ORDER BY table_name, ordinal_position;

SELECT 'foreign_keys' AS report_section,
       table_name, constraint_name, column_name, referenced_table_name, referenced_column_name
FROM information_schema.key_column_usage
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'teaching_assignment_requests',
      'teaching_assignment_reviews',
      'teaching_assignment_events'
  )
  AND referenced_table_name IS NOT NULL
ORDER BY table_name, constraint_name;

SELECT 'unique_indexes' AS report_section, table_name, index_name,
       GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'teaching_assignment_requests',
      'teaching_assignment_reviews',
      'teaching_assignment_events'
  )
  AND non_unique = 0
  AND index_name <> 'PRIMARY'
GROUP BY table_name, index_name
ORDER BY table_name, index_name;

SELECT 'queue_indexes' AS report_section, table_name, index_name,
       GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND (
      (table_name = 'teaching_assignment_requests' AND index_name IN ('idx_tar_status', 'idx_tar_faculty_member', 'idx_tar_requested_by', 'idx_tar_submitted_at', 'uq_tar_current_slot'))
      OR (table_name = 'teaching_assignment_reviews' AND index_name IN ('idx_tarv_authority_status', 'uq_tarv_request_authority'))
      OR (table_name = 'teaching_assignment_events' AND index_name = 'idx_tae_request_created')
  )
GROUP BY table_name, index_name
ORDER BY table_name, index_name;

SELECT 'phase4_permissions' AS report_section, p.permission_code, p.permission_name, sm.module_code, p.is_active
FROM `alrowad_uni_rust`.`permissions` p
JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
WHERE p.permission_code IN (
    'teaching_assignments.view',
    'teaching_assignments.manage',
    'teaching_assignments.review_scientific',
    'teaching_assignments.review_administrative'
)
ORDER BY p.permission_code;

SELECT 'role_permission_mappings' AS report_section, r.role_code, p.permission_code
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code IN (
    'teaching_assignments.view',
    'teaching_assignments.manage',
    'teaching_assignments.review_scientific',
    'teaching_assignments.review_administrative'
)
ORDER BY r.role_code, p.permission_code;

SET @current_slot_unique := IF(
    @requests_exist = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'uq_tar_current_slot'
          AND non_unique = 0
          AND (
              (seq_in_index = 1 AND column_name = 'course_offering_id')
           OR (seq_in_index = 2 AND column_name = 'instructor_role')
           OR (seq_in_index = 3 AND column_name = 'current_slot')
          )
    ) = 3,
    0
);

SET @review_unique := IF(
    @reviews_exist = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_reviews'
          AND index_name = 'uq_tarv_request_authority'
          AND non_unique = 0
          AND (
              (seq_in_index = 1 AND column_name = 'teaching_assignment_request_id')
           OR (seq_in_index = 2 AND column_name = 'review_authority')
          )
    ) = 2,
    0
);

SET @fk_count := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.referential_constraints
        WHERE constraint_schema = 'alrowad_uni_rust'
          AND constraint_name IN (
              'fk_tar_course_offering',
              'fk_tar_faculty_member',
              'fk_tar_requested_by',
              'fk_tarv_request',
              'fk_tarv_reviewer',
              'fk_tae_request',
              'fk_tae_actor'
          )
    ),
    0
);

SET @perm_count := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions`
        WHERE permission_code IN (
            'teaching_assignments.view',
            'teaching_assignments.manage',
            'teaching_assignments.review_scientific',
            'teaching_assignments.review_administrative'
        )
          AND is_active = 1
    ),
    0
);

SET @dean_manage := IF(
    @db_ready = 1,
    EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'dean' AND p.permission_code = 'teaching_assignments.manage'
    ),
    0
);

SET @sci_review_only := IF(
    @db_ready = 1,
    EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'teaching_assignments.review_scientific'
    )
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'teaching_assignments.review_administrative'
    ),
    0
);

SET @adm_review_only := IF(
    @db_ready = 1,
    EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'teaching_assignments.review_administrative'
    )
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'teaching_assignments.review_scientific'
    ),
    0
);

SET @generic_vp_clean := IF(
    @db_ready = 1,
    NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president'
          AND p.permission_code IN (
              'teaching_assignments.review_scientific',
              'teaching_assignments.review_administrative'
          )
    ),
    0
);

SET @workflow_request_rows := IF(@requests_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_requests`), 0);
SET @workflow_review_rows := IF(@reviews_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_reviews`), 0);
SET @workflow_event_rows := IF(@events_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_events`), 0);
SET @coi_count := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offering_instructors`), 0);

SELECT 'safety_counts' AS report_section,
       @workflow_request_rows AS request_rows,
       @workflow_review_rows AS review_rows,
       @workflow_event_rows AS event_rows,
       @coi_count AS course_offering_instructors_rows;

SET @no_fake_users := IF(
    @db_ready = 1,
    NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`users`
        WHERE username LIKE '%phase4%'
           OR email LIKE '%phase4%'
           OR COALESCE(username, '') LIKE '%teaching.assignment%'
    ),
    0
);

SET @overall := IF(
    @db_ready = 1
    AND @requests_exist = 1
    AND @reviews_exist = 1
    AND @events_exist = 1
    AND @current_slot_unique = 1
    AND @review_unique = 1
    AND @fk_count = 7
    AND @perm_count = 4
    AND @dean_manage = 1
    AND @sci_review_only = 1
    AND @adm_review_only = 1
    AND @generic_vp_clean = 1
    AND @workflow_request_rows = 0
    AND @workflow_review_rows = 0
    AND @workflow_event_rows = 0
    AND @no_fake_users = 1,
    'PASS',
    'FAIL'
);

SELECT 'OVERALL' AS report_section,
       @overall AS result,
       @requests_exist AS requests_exist,
       @reviews_exist AS reviews_exist,
       @events_exist AS events_exist,
       @current_slot_unique AS current_slot_unique,
       @review_unique AS review_unique,
       @fk_count AS fk_count,
       @perm_count AS perm_count,
       @dean_manage AS dean_manage,
       @sci_review_only AS sci_review_only,
       @adm_review_only AS adm_review_only,
       @generic_vp_clean AS generic_vp_clean,
       @workflow_request_rows AS workflow_request_rows,
       @workflow_review_rows AS workflow_review_rows,
       @workflow_event_rows AS workflow_event_rows,
       @coi_count AS course_offering_instructors_rows,
       @no_fake_users AS no_fake_users;
