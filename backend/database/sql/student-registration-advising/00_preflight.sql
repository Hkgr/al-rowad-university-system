-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not create a new academic_advisor role. Reuse the existing one.

SELECT
    'database_alrowad_uni_rust' AS check_name,
    IF(
        EXISTS (
            SELECT 1
            FROM information_schema.schemata
            WHERE schema_name = 'alrowad_uni_rust'
        ),
        'READY',
        'BLOCKED'
    ) AS result;

SELECT
    'academic_advisor_role_exactly_once_active' AS check_name,
    IF(COUNT(*) = 1, 'READY', 'BLOCKED') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'academic_advisor'
  AND is_active = 1;

SELECT
    'academic_advisor_role_not_duplicated' AS check_name,
    IF(COUNT(*) = 1, 'READY', 'BLOCKED') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'academic_advisor';

SELECT
    'dean_role_active' AS check_name,
    IF(COUNT(*) = 1, 'READY', 'BLOCKED') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'dean'
  AND is_active = 1;

SELECT
    'student_role_active' AS check_name,
    IF(COUNT(*) >= 1, 'READY', 'BLOCKED') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'student'
  AND is_active = 1;

SELECT
    'registration_module_active' AS check_name,
    IF(COUNT(*) = 1, 'READY', 'BLOCKED') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`system_modules`
WHERE module_code = 'registration'
  AND is_active = 1;

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
    'permission_code_unique_compatible' AS check_name,
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
        'READY',
        'BLOCKED'
    ) AS result;

SELECT
    'role_permissions_unique_compatible' AS check_name,
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
        'READY',
        'BLOCKED'
    ) AS result;

SELECT
    'new_or_compatible_student_registration_requests' AS check_name,
    CASE
        WHEN NOT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_requests'
        ) THEN 'READY'
        WHEN (
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
                UNION ALL SELECT 'created_at'
                UNION ALL SELECT 'updated_at'
            ) required
            LEFT JOIN information_schema.columns c
                ON c.table_schema = 'alrowad_uni_rust'
               AND c.table_name = 'student_registration_requests'
               AND c.column_name = required.column_name
            WHERE c.column_name IS NULL
        ) = 0 THEN 'READY'
        ELSE 'BLOCKED'
    END AS result;

SELECT
    'new_or_compatible_student_registration_request_items' AS check_name,
    CASE
        WHEN NOT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_request_items'
        ) THEN 'READY'
        WHEN (
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
        ) = 0 THEN 'READY'
        ELSE 'BLOCKED'
    END AS result;

SELECT
    'new_or_compatible_student_registration_request_events' AS check_name,
    CASE
        WHEN NOT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'student_registration_request_events'
        ) THEN 'READY'
        WHEN (
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
        ) = 0 THEN 'READY'
        ELSE 'BLOCKED'
    END AS result;

SELECT
    'OVERALL' AS check_name,
    IF(
        EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust')
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'academic_advisor') = 1
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'academic_advisor' AND is_active = 1) = 1
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'dean' AND is_active = 1) = 1
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'student' AND is_active = 1) >= 1
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'registration' AND is_active = 1) = 1
        AND NOT EXISTS (
            SELECT 1
            FROM (
                SELECT 'students' AS table_name, 'student_id' AS column_name
                UNION ALL SELECT 'academic_years', 'academic_year_id'
                UNION ALL SELECT 'semesters', 'semester_id'
                UNION ALL SELECT 'users', 'user_id'
                UNION ALL SELECT 'course_offerings', 'course_offering_id'
                UNION ALL SELECT 'student_course_registrations', 'student_course_registration_id'
                UNION ALL SELECT 'roles', 'role_code'
                UNION ALL SELECT 'system_modules', 'module_code'
                UNION ALL SELECT 'permissions', 'permission_code'
                UNION ALL SELECT 'role_permissions', 'permission_id'
            ) required
            LEFT JOIN information_schema.columns c
                ON c.table_schema = 'alrowad_uni_rust'
               AND c.table_name = required.table_name
               AND c.column_name = required.column_name
            WHERE c.column_name IS NULL
        )
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
        'READY',
        'BLOCKED'
    ) AS result;
