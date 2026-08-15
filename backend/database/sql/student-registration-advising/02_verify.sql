-- READ ONLY. Require OVERALL = PASS after 01_apply.sql.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.

SELECT
    'A_requests_table' AS check_name,
    IF(COUNT(*) = 15, 'PASS', 'FAIL') AS result,
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
      'created_at',
      'updated_at'
  );

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
FROM information_schema.referential_constraints
WHERE constraint_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_requests'
  AND constraint_name IN (
      'fk_srr_student',
      'fk_srr_year',
      'fk_srr_semester',
      'fk_srr_advisor_user'
  );

SELECT
    'G_request_item_foreign_keys' AS check_name,
    IF(COUNT(*) = 3, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM information_schema.referential_constraints
WHERE constraint_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_request_items'
  AND constraint_name IN (
      'fk_srri_request',
      'fk_srri_offering',
      'fk_srri_registration'
  );

SELECT
    'H_request_event_foreign_keys' AS check_name,
    IF(COUNT(*) = 2, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM information_schema.referential_constraints
WHERE constraint_schema = 'alrowad_uni_rust'
  AND table_name = 'student_registration_request_events'
  AND constraint_name IN (
      'fk_srre_request',
      'fk_srre_actor'
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
    'OVERALL' AS check_name,
    IF(
        (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = 'alrowad_uni_rust'
           AND table_name = 'student_registration_requests'
           AND column_name IN (
               'student_registration_request_id','student_id','academic_year_id','semester_id',
               'status','submission_version','student_notes','advisor_user_id','advisor_notes',
               'first_submitted_at','last_submitted_at','reviewed_at','approved_at','created_at','updated_at'
           )) = 15
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
        AND (SELECT COUNT(*) FROM information_schema.referential_constraints
             WHERE constraint_schema = 'alrowad_uni_rust'
               AND table_name = 'student_registration_requests'
               AND constraint_name IN ('fk_srr_student','fk_srr_year','fk_srr_semester','fk_srr_advisor_user')) = 4
        AND (SELECT COUNT(*) FROM information_schema.referential_constraints
             WHERE constraint_schema = 'alrowad_uni_rust'
               AND table_name = 'student_registration_request_items'
               AND constraint_name IN ('fk_srri_request','fk_srri_offering','fk_srri_registration')) = 3
        AND (SELECT COUNT(*) FROM information_schema.referential_constraints
             WHERE constraint_schema = 'alrowad_uni_rust'
               AND table_name = 'student_registration_request_events'
               AND constraint_name IN ('fk_srre_request','fk_srre_actor')) = 2
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
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'academic_advisor') = 1,
        'PASS',
        'FAIL'
    ) AS result;
