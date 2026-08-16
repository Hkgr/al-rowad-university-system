-- READ ONLY. Require OVERALL = PASS after 01_apply.sql.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Verifies resulting structure (types, nullability, PK, uniques, FKs, InnoDB), not only column presence.

SELECT
    'A_requests_table_columns' AS check_name,
    IF(COUNT(*) = 20, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_requests'
  AND column_name IN (
      'student_registration_request_id',
      'student_id',
      'academic_year_id',
      'semester_id',
      'status',
      'submission_version',
      'student_notes',
      'advisor_user_id',
      'advisor_notes',
      'first_submitted_at',
      'last_submitted_at',
      'reviewed_at',
      'approved_at',
      'registered_hours_before_approval',
      'request_hours_at_approval',
      'projected_hours_at_approval',
      'max_allowed_hours_at_approval',
      'remaining_hours_after_approval',
      'created_at',
      'updated_at'
  );

SELECT
    'A2_requests_column_types' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS mismatches
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
   OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%');

SELECT
    'A3_requests_engine_innodb' AS check_name,
    IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result
FROM information_schema.tables
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_requests'
  AND engine = 'InnoDB'
  AND table_type = 'BASE TABLE';

SELECT
    'A4_requests_primary_key' AS check_name,
    IF(
        GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_registration_request_id',
        'PASS',
        'FAIL'
    ) AS result
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_requests'
  AND index_name = 'PRIMARY';

SELECT
    'B_request_items_table' AS check_name,
    IF(COUNT(*) = 6, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_request_items'
  AND column_name IN (
      'student_registration_request_item_id',
      'student_registration_request_id',
      'course_offering_id',
      'student_course_registration_id',
      'created_at',
      'updated_at'
  );

SELECT
    'B2_request_items_column_types' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS mismatches
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
   OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%');

SELECT
    'B3_request_items_engine_innodb' AS check_name,
    IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result
FROM information_schema.tables
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_request_items'
  AND engine = 'InnoDB'
  AND table_type = 'BASE TABLE';

SELECT
    'B4_request_items_primary_key' AS check_name,
    IF(
        GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_registration_request_item_id',
        'PASS',
        'FAIL'
    ) AS result
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_request_items'
  AND index_name = 'PRIMARY';

SELECT
    'C_request_events_table' AS check_name,
    IF(COUNT(*) = 9, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_request_events'
  AND column_name IN (
      'student_registration_request_event_id',
      'student_registration_request_id',
      'event_type',
      'actor_user_id',
      'from_status',
      'to_status',
      'submission_version',
      'notes',
      'created_at'
  );

SELECT
    'C2_request_events_column_types' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS mismatches
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
   OR (required.data_type = 'int' AND LOWER(c.column_type) LIKE '%unsigned%');

SELECT
    'C3_request_events_engine_innodb' AS check_name,
    IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result
FROM information_schema.tables
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_request_events'
  AND engine = 'InnoDB'
  AND table_type = 'BASE TABLE';

SELECT
    'C4_request_events_primary_key' AS check_name,
    IF(
        GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_registration_request_event_id',
        'PASS',
        'FAIL'
    ) AS result
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_request_events'
  AND index_name = 'PRIMARY';

SELECT
    'D_request_term_unique' AS check_name,
    IF(
        COUNT(*) = 1
        AND MIN(columns) = 'student_id,academic_year_id,semester_id',
        'PASS',
        'FAIL'
    ) AS result,
    MIN(columns) AS columns
FROM (
    SELECT
        index_name,
        GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
    FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'student_registration_requests'
      AND non_unique = 0
      AND index_name <> 'PRIMARY'
    GROUP BY index_name
) idx
WHERE columns = 'student_id,academic_year_id,semester_id';

SELECT
    'E_request_item_unique' AS check_name,
    IF(
        COUNT(*) = 1
        AND MIN(columns) = 'student_registration_request_id,course_offering_id',
        'PASS',
        'FAIL'
    ) AS result,
    MIN(columns) AS columns
FROM (
    SELECT
        index_name,
        GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
    FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'student_registration_request_items'
      AND non_unique = 0
      AND index_name <> 'PRIMARY'
    GROUP BY index_name
) idx
WHERE columns = 'student_registration_request_id,course_offering_id';

SELECT
    'F_request_foreign_keys' AS check_name,
    IF(COUNT(*) = 4, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM information_schema.key_column_usage
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_requests'
  AND (
      (column_name = 'student_id' AND referenced_table_name = 'students' AND referenced_column_name = 'student_id')
      OR (column_name = 'academic_year_id' AND referenced_table_name = 'academic_years' AND referenced_column_name = 'academic_year_id')
      OR (column_name = 'semester_id' AND referenced_table_name = 'semesters' AND referenced_column_name = 'semester_id')
      OR (column_name = 'advisor_user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id')
  );

SELECT
    'G_request_item_foreign_keys' AS check_name,
    IF(COUNT(*) = 3, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM information_schema.key_column_usage
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_request_items'
  AND (
      (column_name = 'student_registration_request_id' AND referenced_table_name = 'student_registration_requests' AND referenced_column_name = 'student_registration_request_id')
      OR (column_name = 'course_offering_id' AND referenced_table_name = 'course_offerings' AND referenced_column_name = 'course_offering_id')
      OR (column_name = 'student_course_registration_id' AND referenced_table_name = 'student_course_registrations' AND referenced_column_name = 'student_course_registration_id')
  );

SELECT
    'H_request_event_foreign_keys' AS check_name,
    IF(COUNT(*) = 2, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM information_schema.key_column_usage
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_request_events'
  AND (
      (column_name = 'student_registration_request_id' AND referenced_table_name = 'student_registration_requests' AND referenced_column_name = 'student_registration_request_id')
      OR (column_name = 'actor_user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id')
  );

SELECT
    'I_registration_request_permissions' AS check_name,
    IF(COUNT(*) = 2, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`permissions` p
INNER JOIN `alrowad_uni_rust`.`system_modules` sm
    ON sm.module_id = p.module_id
WHERE p.permission_code IN ('registration_requests.view', 'registration_requests.review')
  AND p.is_active = 1
  AND sm.module_code = 'registration'
  AND sm.is_active = 1;

SELECT
    'J_academic_advisor_permissions' AS check_name,
    IF(COUNT(*) = 3, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`role_permissions` rp
INNER JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
INNER JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'academic_advisor'
  AND r.is_active = 1
  AND p.is_active = 1
  AND p.permission_code IN (
      'registration.view',
      'registration_requests.view',
      'registration_requests.review'
  );

SELECT
    'K_temporary_dean_review_permissions' AS check_name,
    IF(COUNT(*) = 2, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`role_permissions` rp
INNER JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
INNER JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'dean'
  AND r.is_active = 1
  AND p.is_active = 1
  AND p.permission_code IN (
      'registration_requests.view',
      'registration_requests.review'
  );

SELECT
    'L_student_does_not_have_registration_manage' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`role_permissions` rp
INNER JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
INNER JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'student'
  AND p.permission_code = 'registration.manage';

SELECT
    'M_academic_advisor_role_not_duplicated' AS check_name,
    IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'academic_advisor';

SELECT
    'N_no_new_permission_granted_to_student' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`role_permissions` rp
INNER JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
INNER JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'student'
  AND p.permission_code IN (
      'registration_requests.view',
      'registration_requests.review',
      'registration.manage'
  );

SELECT
    'O_permissions_description_writable' AS check_name,
    IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'permissions'
  AND column_name = 'description';

SELECT
    'OVERALL' AS check_name,
    IF(
        (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = 'alrowad_uni_rust'
           AND table_name = 'student_registration_requests'
           AND column_name IN (
               'student_registration_request_id','student_id','academic_year_id','semester_id',
               'status','submission_version','student_notes','advisor_user_id','advisor_notes',
               'first_submitted_at','last_submitted_at','reviewed_at','approved_at',
               'registered_hours_before_approval','request_hours_at_approval',
               'projected_hours_at_approval','max_allowed_hours_at_approval',
               'remaining_hours_after_approval','created_at','updated_at'
           )) = 20
        AND NOT EXISTS (
            SELECT 1
            FROM (
                SELECT 'student_registration_request_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
                UNION ALL SELECT 'registered_hours_before_approval', 'int', 'YES'
                UNION ALL SELECT 'request_hours_at_approval', 'int', 'YES'
                UNION ALL SELECT 'projected_hours_at_approval', 'int', 'YES'
                UNION ALL SELECT 'max_allowed_hours_at_approval', 'int', 'YES'
                UNION ALL SELECT 'remaining_hours_after_approval', 'int', 'YES'
            ) required
            LEFT JOIN information_schema.columns c
                ON c.table_schema = 'alrowad_uni_rust'
               AND c.table_name = 'student_registration_requests'
               AND c.column_name = required.column_name
            WHERE c.column_name IS NULL
               OR LOWER(c.data_type) <> required.data_type
               OR c.is_nullable <> required.is_nullable
        )
        AND EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_requests'
              AND engine = 'InnoDB'
        )
        AND EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_request_items'
              AND engine = 'InnoDB'
        )
        AND EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_request_events'
              AND engine = 'InnoDB'
        )
        AND (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = 'alrowad_uni_rust'
               AND table_name = 'student_registration_request_items'
               AND column_name IN (
                   'student_registration_request_item_id','student_registration_request_id',
                   'course_offering_id','student_course_registration_id','created_at','updated_at'
               )) = 6
        AND (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = 'alrowad_uni_rust'
               AND table_name = 'student_registration_request_events'
               AND column_name IN (
                   'student_registration_request_event_id','student_registration_request_id',
                   'event_type','actor_user_id','from_status','to_status','submission_version','notes','created_at'
               )) = 9
        AND (
            SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_requests'
              AND index_name = 'PRIMARY'
        ) = 'student_registration_request_id'
        AND EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_requests'
              AND non_unique = 0
              AND index_name <> 'PRIMARY'
            GROUP BY index_name
            HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,academic_year_id,semester_id'
        )
        AND EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_request_items'
              AND non_unique = 0
              AND index_name <> 'PRIMARY'
            GROUP BY index_name
            HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_registration_request_id,course_offering_id'
        )
        AND (SELECT COUNT(*) FROM information_schema.key_column_usage
             WHERE table_schema = 'alrowad_uni_rust'
               AND table_name = 'student_registration_requests'
               AND (
                   (column_name = 'student_id' AND referenced_table_name = 'students')
                   OR (column_name = 'academic_year_id' AND referenced_table_name = 'academic_years')
                   OR (column_name = 'semester_id' AND referenced_table_name = 'semesters')
                   OR (column_name = 'advisor_user_id' AND referenced_table_name = 'users')
               )) = 4
        AND (SELECT COUNT(*) FROM information_schema.key_column_usage
             WHERE table_schema = 'alrowad_uni_rust'
               AND table_name = 'student_registration_request_items'
               AND (
                   (column_name = 'student_registration_request_id' AND referenced_table_name = 'student_registration_requests')
                   OR (column_name = 'course_offering_id' AND referenced_table_name = 'course_offerings')
                   OR (column_name = 'student_course_registration_id' AND referenced_table_name = 'student_course_registrations')
               )) = 3
        AND (SELECT COUNT(*) FROM information_schema.key_column_usage
             WHERE table_schema = 'alrowad_uni_rust'
               AND table_name = 'student_registration_request_events'
               AND (
                   (column_name = 'student_registration_request_id' AND referenced_table_name = 'student_registration_requests')
                   OR (column_name = 'actor_user_id' AND referenced_table_name = 'users')
               )) = 2
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p
             INNER JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
             WHERE p.permission_code IN ('registration_requests.view','registration_requests.review')
               AND p.is_active = 1
               AND sm.module_code = 'registration') = 2
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
             INNER JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
             INNER JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
             WHERE r.role_code = 'academic_advisor' AND r.is_active = 1 AND p.is_active = 1
               AND p.permission_code IN ('registration.view','registration_requests.view','registration_requests.review')) = 3
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
             INNER JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
             INNER JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
             WHERE r.role_code = 'dean' AND r.is_active = 1 AND p.is_active = 1
               AND p.permission_code IN ('registration_requests.view','registration_requests.review')) = 2
        AND NOT EXISTS (
            SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp
            INNER JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
            INNER JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
            WHERE r.role_code = 'student' AND p.permission_code = 'registration.manage'
        )
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'academic_advisor') = 1
        AND EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'permissions'
              AND column_name = 'description'
        ),
        'PASS',
        'FAIL'
    ) AS result;
