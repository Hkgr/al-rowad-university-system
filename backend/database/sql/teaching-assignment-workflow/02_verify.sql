-- READ ONLY. Continue only when OVERALL returns PASS.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE application data.
-- Do not use DATABASE().
-- Individual named checks and OVERALL share the same SET variables.
-- OVERALL cannot PASS if any required named check is FAIL.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @requests_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND table_type = 'BASE TABLE'), 0);
SET @reviews_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_reviews' AND table_type = 'BASE TABLE'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_events' AND table_type = 'BASE TABLE'), 0);

SELECT 'workflow_tables' AS report_section, table_name, engine, table_collation, table_comment
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

SELECT DISTINCT 'RBAC_MATRIX_CONFLICT' AS report_section, r.role_code, p.permission_code
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @db_ready = 1
  AND p.permission_code IN (
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
ORDER BY r.role_code, p.permission_code;

-- ---------------------------------------------------------------------------
-- Structural contract
-- ---------------------------------------------------------------------------
SET @workflow_tables_exactly_once := IF(
    @requests_exist = 1 AND @reviews_exist = 1 AND @events_exist = 1,
    1,
    0
);

SET @workflow_tables_innodb := IF(
    @db_ready = 1
    AND (
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name IN (
              'teaching_assignment_requests',
              'teaching_assignment_reviews',
              'teaching_assignment_events'
          )
          AND table_type = 'BASE TABLE'
          AND engine = 'InnoDB'
    ) = 3,
    1,
    0
);

SET @requests_primary_key := IF(
    @requests_exist = 1
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'PRIMARY'
    ) <=> 'teaching_assignment_request_id',
    1,
    0
);
SET @reviews_primary_key := IF(
    @reviews_exist = 1
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_reviews'
          AND index_name = 'PRIMARY'
    ) <=> 'teaching_assignment_review_id',
    1,
    0
);
SET @events_primary_key := IF(
    @events_exist = 1
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_events'
          AND index_name = 'PRIMARY'
    ) <=> 'teaching_assignment_event_id',
    1,
    0
);

SET @request_current_slot_unique := IF(
    @requests_exist = 1
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'uq_tar_current_slot'
          AND non_unique = 0
    ) <=> 'course_offering_id,instructor_role,current_slot',
    1,
    0
);
SET @review_authority_unique := IF(
    @reviews_exist = 1
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_reviews'
          AND index_name = 'uq_tarv_request_authority'
          AND non_unique = 0
    ) <=> 'teaching_assignment_request_id,review_authority',
    1,
    0
);

SET @offering_identity_unique := IF(
    @db_ready = 1
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
SET @coi_role_unique := IF(
    @db_ready = 1
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_instructors'
          AND index_name = 'uq_course_offering_role'
          AND non_unique = 0
    ) <=> 'course_offering_id,instructor_role',
    1,
    0
);

SET @request_fk_course_offering := IF(
    @requests_exist = 1
    AND EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage k
        WHERE k.table_schema = 'alrowad_uni_rust'
          AND k.table_name = 'teaching_assignment_requests'
          AND k.constraint_name = 'fk_tar_course_offering'
          AND k.column_name = 'course_offering_id'
          AND k.referenced_table_schema = 'alrowad_uni_rust'
          AND k.referenced_table_name = 'course_offerings'
          AND k.referenced_column_name = 'course_offering_id'
    )
    AND (
        SELECT COUNT(*)
        FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND constraint_name = 'fk_tar_course_offering'
          AND referenced_table_name IS NOT NULL
    ) = 1,
    1,
    0
);
SET @request_fk_faculty_member := IF(
    @requests_exist = 1
    AND EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage k
        WHERE k.table_schema = 'alrowad_uni_rust'
          AND k.table_name = 'teaching_assignment_requests'
          AND k.constraint_name = 'fk_tar_faculty_member'
          AND k.column_name = 'faculty_member_id'
          AND k.referenced_table_schema = 'alrowad_uni_rust'
          AND k.referenced_table_name = 'faculty_members'
          AND k.referenced_column_name = 'faculty_member_id'
    )
    AND (
        SELECT COUNT(*)
        FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND constraint_name = 'fk_tar_faculty_member'
          AND referenced_table_name IS NOT NULL
    ) = 1,
    1,
    0
);
SET @request_fk_requester := IF(
    @requests_exist = 1
    AND EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage k
        WHERE k.table_schema = 'alrowad_uni_rust'
          AND k.table_name = 'teaching_assignment_requests'
          AND k.constraint_name = 'fk_tar_requested_by'
          AND k.column_name = 'requested_by_user_id'
          AND k.referenced_table_schema = 'alrowad_uni_rust'
          AND k.referenced_table_name = 'users'
          AND k.referenced_column_name = 'user_id'
    )
    AND (
        SELECT COUNT(*)
        FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND constraint_name = 'fk_tar_requested_by'
          AND referenced_table_name IS NOT NULL
    ) = 1,
    1,
    0
);
SET @request_fk_superseded_by := IF(
    @requests_exist = 1
    AND EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage k
        WHERE k.table_schema = 'alrowad_uni_rust'
          AND k.table_name = 'teaching_assignment_requests'
          AND k.constraint_name = 'fk_tar_superseded_by'
          AND k.column_name = 'superseded_by_request_id'
          AND k.referenced_table_schema = 'alrowad_uni_rust'
          AND k.referenced_table_name = 'teaching_assignment_requests'
          AND k.referenced_column_name = 'teaching_assignment_request_id'
    )
    AND (
        SELECT COUNT(*)
        FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND constraint_name = 'fk_tar_superseded_by'
          AND referenced_table_name IS NOT NULL
    ) = 1,
    1,
    0
);
SET @review_fk_request := IF(
    @reviews_exist = 1
    AND EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage k
        WHERE k.table_schema = 'alrowad_uni_rust'
          AND k.table_name = 'teaching_assignment_reviews'
          AND k.constraint_name = 'fk_tarv_request'
          AND k.column_name = 'teaching_assignment_request_id'
          AND k.referenced_table_schema = 'alrowad_uni_rust'
          AND k.referenced_table_name = 'teaching_assignment_requests'
          AND k.referenced_column_name = 'teaching_assignment_request_id'
    )
    AND (
        SELECT COUNT(*)
        FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_reviews'
          AND constraint_name = 'fk_tarv_request'
          AND referenced_table_name IS NOT NULL
    ) = 1,
    1,
    0
);
SET @review_fk_reviewer := IF(
    @reviews_exist = 1
    AND EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage k
        WHERE k.table_schema = 'alrowad_uni_rust'
          AND k.table_name = 'teaching_assignment_reviews'
          AND k.constraint_name = 'fk_tarv_reviewer'
          AND k.column_name = 'reviewed_by_user_id'
          AND k.referenced_table_schema = 'alrowad_uni_rust'
          AND k.referenced_table_name = 'users'
          AND k.referenced_column_name = 'user_id'
    )
    AND (
        SELECT COUNT(*)
        FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_reviews'
          AND constraint_name = 'fk_tarv_reviewer'
          AND referenced_table_name IS NOT NULL
    ) = 1,
    1,
    0
);
SET @event_fk_request := IF(
    @events_exist = 1
    AND EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage k
        WHERE k.table_schema = 'alrowad_uni_rust'
          AND k.table_name = 'teaching_assignment_events'
          AND k.constraint_name = 'fk_tae_request'
          AND k.column_name = 'teaching_assignment_request_id'
          AND k.referenced_table_schema = 'alrowad_uni_rust'
          AND k.referenced_table_name = 'teaching_assignment_requests'
          AND k.referenced_column_name = 'teaching_assignment_request_id'
    )
    AND (
        SELECT COUNT(*)
        FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_events'
          AND constraint_name = 'fk_tae_request'
          AND referenced_table_name IS NOT NULL
    ) = 1,
    1,
    0
);
SET @event_fk_actor := IF(
    @events_exist = 1
    AND EXISTS (
        SELECT 1
        FROM information_schema.key_column_usage k
        WHERE k.table_schema = 'alrowad_uni_rust'
          AND k.table_name = 'teaching_assignment_events'
          AND k.constraint_name = 'fk_tae_actor'
          AND k.column_name = 'actor_user_id'
          AND k.referenced_table_schema = 'alrowad_uni_rust'
          AND k.referenced_table_name = 'users'
          AND k.referenced_column_name = 'user_id'
    )
    AND (
        SELECT COUNT(*)
        FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_events'
          AND constraint_name = 'fk_tae_actor'
          AND referenced_table_name IS NOT NULL
    ) = 1,
    1,
    0
);

SET @request_queue_indexes := IF(
    @requests_exist = 1
    AND (
        SELECT COUNT(*)
        FROM (
            SELECT 'idx_tar_status' AS index_name, 'status' AS columns
            UNION ALL SELECT 'idx_tar_faculty_member', 'faculty_member_id'
            UNION ALL SELECT 'idx_tar_requested_by', 'requested_by_user_id'
            UNION ALL SELECT 'idx_tar_submitted_at', 'submitted_at'
        ) required
        JOIN (
            SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'teaching_assignment_requests'
            GROUP BY index_name
        ) existing
            ON existing.index_name = required.index_name
           AND existing.columns = required.columns
    ) = 4,
    1,
    0
);
SET @review_queue_indexes := IF(
    @reviews_exist = 1
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_reviews'
          AND index_name = 'idx_tarv_authority_status'
    ) <=> 'review_authority,status',
    1,
    0
);
SET @event_queue_index := IF(
    @events_exist = 1
    AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_events'
          AND index_name = 'idx_tae_request_created'
    ) <=> 'teaching_assignment_request_id,created_at',
    1,
    0
);

SET @phase4_permissions_exactly_once_active := IF(
    @db_ready = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.view') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.view' AND is_active = 1) = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.manage') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.manage' AND is_active = 1) = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_scientific') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_scientific' AND is_active = 1) = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_administrative') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_administrative' AND is_active = 1) = 1,
    1,
    0
);

SET @phase4_permissions_on_hr_module := IF(
    @db_ready = 1
    AND (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code IN (
            'teaching_assignments.view',
            'teaching_assignments.manage',
            'teaching_assignments.review_scientific',
            'teaching_assignments.review_administrative'
        )
          AND p.is_active = 1
          AND sm.module_code = 'hr'
          AND (
              (p.permission_code = 'teaching_assignments.view' AND LOWER(p.permission_name) LIKE '%teaching%' AND LOWER(p.permission_name) LIKE '%view%')
           OR (p.permission_code = 'teaching_assignments.manage' AND LOWER(p.permission_name) LIKE '%teaching%' AND LOWER(p.permission_name) LIKE '%manage%')
           OR (p.permission_code = 'teaching_assignments.review_scientific' AND LOWER(p.permission_name) LIKE '%scientific%' AND LOWER(p.permission_name) LIKE '%review%')
           OR (p.permission_code = 'teaching_assignments.review_administrative' AND LOWER(p.permission_name) LIKE '%administrative%' AND LOWER(p.permission_name) LIKE '%review%')
          )
    ) = 4,
    1,
    0
);

SET @dean_view := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'dean' AND p.permission_code = 'teaching_assignments.view'
    ),
    1,
    0
);
SET @dean_manage := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'dean' AND p.permission_code = 'teaching_assignments.manage'
    ),
    1,
    0
);
SET @dean_no_vp_review := IF(
    @db_ready = 1
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'dean'
          AND p.permission_code IN (
              'teaching_assignments.review_scientific',
              'teaching_assignments.review_administrative'
          )
    ),
    1,
    0
);
SET @scientific_view := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'teaching_assignments.view'
    ),
    1,
    0
);
SET @scientific_review := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'teaching_assignments.review_scientific'
    ),
    1,
    0
);
SET @scientific_no_administrative_review := IF(
    @db_ready = 1
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'teaching_assignments.review_administrative'
    ),
    1,
    0
);
SET @administrative_view := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'teaching_assignments.view'
    ),
    1,
    0
);
SET @administrative_review := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'teaching_assignments.review_administrative'
    ),
    1,
    0
);
SET @administrative_no_scientific_review := IF(
    @db_ready = 1
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'teaching_assignments.review_scientific'
    ),
    1,
    0
);
SET @generic_vp_no_review := IF(
    @db_ready = 1
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president'
          AND p.permission_code IN (
              'teaching_assignments.review_scientific',
              'teaching_assignments.review_administrative'
          )
    ),
    1,
    0
);
SET @no_other_role_has_vp_review := IF(
    @db_ready = 1
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code = 'teaching_assignments.review_scientific'
          AND r.role_code <> 'vice_president_scientific'
    )
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code = 'teaching_assignments.review_administrative'
          AND r.role_code <> 'vice_president_administrative'
    ),
    1,
    0
);

SET @workflow_request_rows := IF(@requests_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_requests`), 0);
SET @workflow_review_rows := IF(@reviews_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_reviews`), 0);
SET @workflow_event_rows := IF(@events_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_events`), 0);
SET @coi_count := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offering_instructors`), 0);

SET @workflow_request_rows_zero := IF(@workflow_request_rows = 0, 1, 0);
SET @workflow_review_rows_zero := IF(@workflow_review_rows = 0, 1, 0);
SET @workflow_event_rows_zero := IF(@workflow_event_rows = 0, 1, 0);

SET @no_fake_users := IF(
    @db_ready = 1
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`users`
        WHERE username LIKE '%phase4%'
           OR email LIKE '%phase4%'
           OR COALESCE(username, '') LIKE '%teaching.assignment%'
    ),
    1,
    0
);

SELECT 'safety_counts' AS report_section,
       @workflow_request_rows AS request_rows,
       @workflow_review_rows AS review_rows,
       @workflow_event_rows AS event_rows,
       @coi_count AS course_offering_instructors_rows;

SELECT 'workflow_tables_exactly_once' AS check_name, IF(@workflow_tables_exactly_once = 1, 'PASS', 'FAIL') AS result;
SELECT 'workflow_tables_innodb' AS check_name, IF(@workflow_tables_innodb = 1, 'PASS', 'FAIL') AS result;
SELECT 'requests_primary_key' AS check_name, IF(@requests_primary_key = 1, 'PASS', 'FAIL') AS result;
SELECT 'reviews_primary_key' AS check_name, IF(@reviews_primary_key = 1, 'PASS', 'FAIL') AS result;
SELECT 'events_primary_key' AS check_name, IF(@events_primary_key = 1, 'PASS', 'FAIL') AS result;
SELECT 'request_current_slot_unique' AS check_name, IF(@request_current_slot_unique = 1, 'PASS', 'FAIL') AS result;
SELECT 'review_authority_unique' AS check_name, IF(@review_authority_unique = 1, 'PASS', 'FAIL') AS result;
SELECT 'offering_identity_unique' AS check_name, IF(@offering_identity_unique = 1, 'PASS', 'FAIL') AS result;
SELECT 'effective_assignment_role_unique' AS check_name, IF(@coi_role_unique = 1, 'PASS', 'FAIL') AS result;
SELECT 'request_fk_course_offering' AS check_name, IF(@request_fk_course_offering = 1, 'PASS', 'FAIL') AS result;
SELECT 'request_fk_faculty_member' AS check_name, IF(@request_fk_faculty_member = 1, 'PASS', 'FAIL') AS result;
SELECT 'request_fk_requester' AS check_name, IF(@request_fk_requester = 1, 'PASS', 'FAIL') AS result;
SELECT 'request_fk_superseded_by' AS check_name, IF(@request_fk_superseded_by = 1, 'PASS', 'FAIL') AS result;
SELECT 'review_fk_request' AS check_name, IF(@review_fk_request = 1, 'PASS', 'FAIL') AS result;
SELECT 'review_fk_reviewer' AS check_name, IF(@review_fk_reviewer = 1, 'PASS', 'FAIL') AS result;
SELECT 'event_fk_request' AS check_name, IF(@event_fk_request = 1, 'PASS', 'FAIL') AS result;
SELECT 'event_fk_actor' AS check_name, IF(@event_fk_actor = 1, 'PASS', 'FAIL') AS result;
SELECT 'request_queue_indexes' AS check_name, IF(@request_queue_indexes = 1, 'PASS', 'FAIL') AS result;
SELECT 'review_queue_indexes' AS check_name, IF(@review_queue_indexes = 1, 'PASS', 'FAIL') AS result;
SELECT 'event_queue_index' AS check_name, IF(@event_queue_index = 1, 'PASS', 'FAIL') AS result;
SELECT 'phase4_permissions_exactly_once_active' AS check_name, IF(@phase4_permissions_exactly_once_active = 1, 'PASS', 'FAIL') AS result;
SELECT 'phase4_permissions_on_hr_module' AS check_name, IF(@phase4_permissions_on_hr_module = 1, 'PASS', 'FAIL') AS result;
SELECT 'dean_view' AS check_name, IF(@dean_view = 1, 'PASS', 'FAIL') AS result;
SELECT 'dean_manage' AS check_name, IF(@dean_manage = 1, 'PASS', 'FAIL') AS result;
SELECT 'dean_no_vp_review' AS check_name, IF(@dean_no_vp_review = 1, 'PASS', 'FAIL') AS result;
SELECT 'scientific_view' AS check_name, IF(@scientific_view = 1, 'PASS', 'FAIL') AS result;
SELECT 'scientific_review' AS check_name, IF(@scientific_review = 1, 'PASS', 'FAIL') AS result;
SELECT 'scientific_no_administrative_review' AS check_name, IF(@scientific_no_administrative_review = 1, 'PASS', 'FAIL') AS result;
SELECT 'administrative_view' AS check_name, IF(@administrative_view = 1, 'PASS', 'FAIL') AS result;
SELECT 'administrative_review' AS check_name, IF(@administrative_review = 1, 'PASS', 'FAIL') AS result;
SELECT 'administrative_no_scientific_review' AS check_name, IF(@administrative_no_scientific_review = 1, 'PASS', 'FAIL') AS result;
SELECT 'generic_vp_no_review' AS check_name, IF(@generic_vp_no_review = 1, 'PASS', 'FAIL') AS result;
SELECT 'no_other_role_has_vp_review' AS check_name, IF(@no_other_role_has_vp_review = 1, 'PASS', 'FAIL') AS result;
SELECT 'workflow_request_rows' AS check_name, IF(@workflow_request_rows_zero = 1, 'PASS', 'FAIL') AS result, @workflow_request_rows AS actual;
SELECT 'workflow_review_rows' AS check_name, IF(@workflow_review_rows_zero = 1, 'PASS', 'FAIL') AS result, @workflow_review_rows AS actual;
SELECT 'workflow_event_rows' AS check_name, IF(@workflow_event_rows_zero = 1, 'PASS', 'FAIL') AS result, @workflow_event_rows AS actual;

SET @overall := IF(
    @db_ready = 1
    AND @workflow_tables_exactly_once = 1
    AND @workflow_tables_innodb = 1
    AND @requests_primary_key = 1
    AND @reviews_primary_key = 1
    AND @events_primary_key = 1
    AND @request_current_slot_unique = 1
    AND @review_authority_unique = 1
    AND @offering_identity_unique = 1
    AND @coi_role_unique = 1
    AND @request_fk_course_offering = 1
    AND @request_fk_faculty_member = 1
    AND @request_fk_requester = 1
    AND @request_fk_superseded_by = 1
    AND @review_fk_request = 1
    AND @review_fk_reviewer = 1
    AND @event_fk_request = 1
    AND @event_fk_actor = 1
    AND @request_queue_indexes = 1
    AND @review_queue_indexes = 1
    AND @event_queue_index = 1
    AND @phase4_permissions_exactly_once_active = 1
    AND @phase4_permissions_on_hr_module = 1
    AND @dean_view = 1
    AND @dean_manage = 1
    AND @dean_no_vp_review = 1
    AND @scientific_view = 1
    AND @scientific_review = 1
    AND @scientific_no_administrative_review = 1
    AND @administrative_view = 1
    AND @administrative_review = 1
    AND @administrative_no_scientific_review = 1
    AND @generic_vp_no_review = 1
    AND @no_other_role_has_vp_review = 1
    AND @workflow_request_rows_zero = 1
    AND @workflow_review_rows_zero = 1
    AND @workflow_event_rows_zero = 1
    AND @no_fake_users = 1,
    'PASS',
    'FAIL'
);

SELECT 'OVERALL' AS report_section,
       @overall AS result,
       @workflow_tables_exactly_once AS workflow_tables_exactly_once,
       @workflow_tables_innodb AS workflow_tables_innodb,
       @request_fk_superseded_by AS request_fk_superseded_by,
       @dean_view AS dean_view,
       @dean_manage AS dean_manage,
       @scientific_view AS scientific_view,
       @scientific_review AS scientific_review,
       @administrative_view AS administrative_view,
       @administrative_review AS administrative_review,
       @generic_vp_no_review AS generic_vp_no_review,
       @no_other_role_has_vp_review AS no_other_role_has_vp_review,
       @workflow_request_rows AS workflow_request_rows,
       @workflow_review_rows AS workflow_review_rows,
       @workflow_event_rows AS workflow_event_rows,
       @coi_count AS course_offering_instructors_rows,
       @no_fake_users AS no_fake_users;
