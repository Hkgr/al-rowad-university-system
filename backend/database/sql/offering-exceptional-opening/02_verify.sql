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

SET @requests_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_requests' AND table_type = 'BASE TABLE'), 0);
SET @reviews_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_reviews' AND table_type = 'BASE TABLE'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_events' AND table_type = 'BASE TABLE'), 0);

SELECT 'workflow_tables' AS report_section, table_name, engine, table_collation, table_comment
FROM information_schema.tables
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'course_offering_exception_requests',
      'course_offering_exception_reviews',
      'course_offering_exception_events'
  )
ORDER BY table_name;

SELECT 'request_columns' AS report_section, column_name, column_type, is_nullable, column_key
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offering_exception_requests'
ORDER BY ordinal_position;

SELECT 'review_columns' AS report_section, column_name, column_type, is_nullable, column_key
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offering_exception_reviews'
ORDER BY ordinal_position;

SELECT 'event_columns' AS report_section, column_name, column_type, is_nullable, column_key
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offering_exception_events'
ORDER BY ordinal_position;

SELECT 'primary_keys' AS report_section, table_name, column_name
FROM information_schema.key_column_usage
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'course_offering_exception_requests',
      'course_offering_exception_reviews',
      'course_offering_exception_events'
  )
  AND constraint_name = 'PRIMARY'
ORDER BY table_name, ordinal_position;

SELECT 'foreign_keys' AS report_section,
       table_name, constraint_name, column_name, referenced_table_name, referenced_column_name
FROM information_schema.key_column_usage
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'course_offering_exception_requests',
      'course_offering_exception_reviews',
      'course_offering_exception_events'
  )
  AND referenced_table_name IS NOT NULL
ORDER BY table_name, constraint_name;

SELECT 'unique_indexes' AS report_section, table_name, index_name,
       GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'course_offering_exception_requests',
      'course_offering_exception_reviews',
      'course_offering_exception_events'
  )
  AND non_unique = 0
  AND index_name <> 'PRIMARY'
GROUP BY table_name, index_name
ORDER BY table_name, index_name;

SELECT 'phase6_permissions' AS report_section, p.permission_code, p.permission_name, sm.module_code, p.is_active
FROM `alrowad_uni_rust`.`permissions` p
JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
WHERE p.permission_code IN (
    'course_offerings.exceptional_open.view',
    'course_offerings.exceptional_open.request',
    'course_offerings.exceptional_open.review_scientific',
    'course_offerings.exceptional_open.review_administrative'
)
ORDER BY p.permission_code;

SELECT 'role_permission_mappings' AS report_section, r.role_code, p.permission_code
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code IN (
    'course_offerings.exceptional_open.view',
    'course_offerings.exceptional_open.request',
    'course_offerings.exceptional_open.review_scientific',
    'course_offerings.exceptional_open.review_administrative'
)
ORDER BY r.role_code, p.permission_code;

SET @tables_ok := IF(@requests_exist = 1 AND @reviews_exist = 1 AND @events_exist = 1, 1, 0);
SET @innodb_ok := IF(
    @db_ready = 1 AND (
        SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name IN (
              'course_offering_exception_requests',
              'course_offering_exception_reviews',
              'course_offering_exception_events'
          )
          AND table_type = 'BASE TABLE'
          AND engine = 'InnoDB'
    ) = 3, 1, 0
);
SET @pk_requests := IF(@requests_exist = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_requests' AND index_name = 'PRIMARY') <=> 'course_offering_exception_request_id', 1, 0);
SET @pk_reviews := IF(@reviews_exist = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_reviews' AND index_name = 'PRIMARY') <=> 'course_offering_exception_review_id', 1, 0);
SET @pk_events := IF(@events_exist = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_events' AND index_name = 'PRIMARY') <=> 'course_offering_exception_event_id', 1, 0);
SET @uq_current_slot := IF(@requests_exist = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_requests' AND index_name = 'uq_coer_current_slot' AND non_unique = 0) <=> 'course_offering_id,current_slot', 1, 0);
SET @uq_review_version := IF(@reviews_exist = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_reviews' AND index_name = 'uq_coerv_request_authority_version' AND non_unique = 0) <=> 'course_offering_exception_request_id,review_authority,submission_version', 1, 0);

SET @fk_ok := IF(
    @requests_exist = 1 AND @reviews_exist = 1 AND @events_exist = 1 AND (
        SELECT COUNT(*) FROM (
            SELECT 'course_offering_exception_requests' AS table_name, 'fk_coer_course_offering' AS constraint_name, 'course_offering_id' AS column_name, 'course_offerings' AS ref_table, 'course_offering_id' AS ref_column
            UNION ALL SELECT 'course_offering_exception_requests', 'fk_coer_requested_by', 'requested_by_user_id', 'users', 'user_id'
            UNION ALL SELECT 'course_offering_exception_requests', 'fk_coer_superseded_by', 'superseded_by_request_id', 'course_offering_exception_requests', 'course_offering_exception_request_id'
            UNION ALL SELECT 'course_offering_exception_reviews', 'fk_coerv_request', 'course_offering_exception_request_id', 'course_offering_exception_requests', 'course_offering_exception_request_id'
            UNION ALL SELECT 'course_offering_exception_reviews', 'fk_coerv_reviewer', 'reviewed_by_user_id', 'users', 'user_id'
            UNION ALL SELECT 'course_offering_exception_events', 'fk_coee_request', 'course_offering_exception_request_id', 'course_offering_exception_requests', 'course_offering_exception_request_id'
            UNION ALL SELECT 'course_offering_exception_events', 'fk_coee_actor', 'actor_user_id', 'users', 'user_id'
        ) required
        LEFT JOIN information_schema.key_column_usage k
            ON k.table_schema = 'alrowad_uni_rust'
           AND k.table_name = required.table_name
           AND k.constraint_name = required.constraint_name
           AND k.column_name = required.column_name
           AND k.referenced_table_schema = 'alrowad_uni_rust'
           AND k.referenced_table_name = required.ref_table
           AND k.referenced_column_name = required.ref_column
        WHERE k.column_name IS NULL
    ) = 0, 1, 0
);

SET @queue_ok := IF(
    @requests_exist = 1 AND @reviews_exist = 1 AND @events_exist = 1
    AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_requests' AND index_name = 'idx_coer_status') <=> 'status'
    AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_reviews' AND index_name = 'idx_coerv_authority_status') <=> 'review_authority,status'
    AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_events' AND index_name = 'idx_coee_request_created') <=> 'course_offering_exception_request_id,created_at',
    1, 0
);

SET @cols_ok := IF(
    @requests_exist = 1 AND (
        SELECT COUNT(*) FROM (
            SELECT 'course_offering_exception_request_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'course_offering_id', 'int', 'NO'
            UNION ALL SELECT 'requested_by_user_id', 'int', 'NO'
            UNION ALL SELECT 'reason', 'text', 'NO'
            UNION ALL SELECT 'status', 'varchar', 'NO'
            UNION ALL SELECT 'submission_version', 'int', 'NO'
            UNION ALL SELECT 'current_slot', 'tinyint', 'YES'
            UNION ALL SELECT 'snapshot_course_id', 'int', 'NO'
            UNION ALL SELECT 'snapshot_academic_program_id', 'int', 'NO'
            UNION ALL SELECT 'snapshot_academic_year_id', 'int', 'NO'
            UNION ALL SELECT 'snapshot_semester_id', 'int', 'NO'
            UNION ALL SELECT 'materialized_at', 'timestamp', 'YES'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'course_offering_exception_requests'
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
           OR c.is_nullable <> required.is_nullable
           OR (required.data_type = 'timestamp' AND LOWER(c.data_type) NOT IN ('timestamp', 'datetime'))
           OR (required.data_type <> 'timestamp' AND LOWER(c.data_type) <> required.data_type)
    ) = 0, 1, 0
);

SET @perm_ok := IF(
    @db_ready = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.exceptional_open.view' AND p.is_active = 1 AND sm.module_code = 'courses') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.exceptional_open.request' AND p.is_active = 1 AND sm.module_code = 'courses') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.exceptional_open.review_scientific' AND p.is_active = 1 AND sm.module_code = 'courses') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'course_offerings.exceptional_open.review_administrative' AND p.is_active = 1 AND sm.module_code = 'courses') = 1,
    1, 0
);

SET @matrix_ok := IF(
    @db_ready = 1
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'dean' AND p.permission_code = 'course_offerings.exceptional_open.view')
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'dean' AND p.permission_code = 'course_offerings.exceptional_open.request')
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'course_offerings.exceptional_open.review_scientific')
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'course_offerings.exceptional_open.review_administrative'),
    1, 0
);

SET @isolation_ok := IF(
    @db_ready = 1
    AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'dean' AND p.permission_code IN ('course_offerings.exceptional_open.review_scientific', 'course_offerings.exceptional_open.review_administrative'))
    AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'course_offerings.exceptional_open.review_administrative')
    AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'course_offerings.exceptional_open.review_scientific')
    AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'vice_president' AND p.permission_code IN ('course_offerings.exceptional_open.review_scientific', 'course_offerings.exceptional_open.review_administrative'))
    AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE p.permission_code = 'course_offerings.exceptional_open.review_scientific' AND r.role_code <> 'vice_president_scientific')
    AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE p.permission_code = 'course_offerings.exceptional_open.review_administrative' AND r.role_code <> 'vice_president_administrative'),
    1, 0
);

SELECT 'tables_present' AS check_name, IF(@tables_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'tables_innodb' AS check_name, IF(@innodb_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'primary_keys' AS check_name, IF(@pk_requests = 1 AND @pk_reviews = 1 AND @pk_events = 1, 'PASS', 'FAIL') AS result;
SELECT 'current_slot_unique' AS check_name, IF(@uq_current_slot = 1, 'PASS', 'FAIL') AS result;
SELECT 'review_version_unique' AS check_name, IF(@uq_review_version = 1, 'PASS', 'FAIL') AS result;
SELECT 'foreign_keys' AS check_name, IF(@fk_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'queue_indexes' AS check_name, IF(@queue_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'required_columns' AS check_name, IF(@cols_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'permissions' AS check_name, IF(@perm_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'role_permission_matrix' AS check_name, IF(@matrix_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'cross_authority_isolation' AS check_name, IF(@isolation_ok = 1, 'PASS', 'FAIL') AS result;

SET @overall := IF(
    @tables_ok = 1 AND @innodb_ok = 1 AND @pk_requests = 1 AND @pk_reviews = 1 AND @pk_events = 1
    AND @uq_current_slot = 1 AND @uq_review_version = 1 AND @fk_ok = 1 AND @queue_ok = 1
    AND @cols_ok = 1 AND @perm_ok = 1 AND @matrix_ok = 1 AND @isolation_ok = 1,
    'PASS',
    'FAIL'
);

SELECT 'OVERALL' AS report_section, @overall AS result;
