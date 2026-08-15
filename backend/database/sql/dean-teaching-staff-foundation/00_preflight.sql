-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.

-- ---------------------------------------------------------------------------
-- Structure (required columns only)
-- ---------------------------------------------------------------------------
SELECT
    'required_structure' AS report_section,
    required.table_name,
    required.column_name,
    COALESCE(c.column_type, 'MISSING') AS observed_type,
    IF(c.column_name IS NULL, 'BLOCKED', 'READY') AS result
FROM (
    SELECT 'colleges' AS table_name, 'college_id' AS column_name
    UNION ALL SELECT 'colleges', 'organizational_unit_id'
    UNION ALL SELECT 'colleges', 'college_code'
    UNION ALL SELECT 'colleges', 'college_name'
    UNION ALL SELECT 'colleges', 'is_active'
    UNION ALL SELECT 'organizational_units', 'organizational_unit_id'
    UNION ALL SELECT 'organizational_units', 'unit_code'
    UNION ALL SELECT 'organizational_units', 'unit_name'
    UNION ALL SELECT 'organizational_units', 'unit_type_id'
    UNION ALL SELECT 'organizational_units', 'parent_unit_id'
    UNION ALL SELECT 'organizational_units', 'description'
    UNION ALL SELECT 'organizational_units', 'is_active'
    UNION ALL SELECT 'organizational_units', 'created_at'
    UNION ALL SELECT 'organizational_units', 'updated_at'
    UNION ALL SELECT 'organizational_unit_types', 'unit_type_id'
    UNION ALL SELECT 'organizational_unit_types', 'type_code'
    UNION ALL SELECT 'organizational_unit_types', 'is_active'
    UNION ALL SELECT 'departments', 'department_id'
    UNION ALL SELECT 'departments', 'college_id'
    UNION ALL SELECT 'departments', 'organizational_unit_id'
    UNION ALL SELECT 'academic_programs', 'academic_program_id'
    UNION ALL SELECT 'academic_programs', 'department_id'
    UNION ALL SELECT 'employees', 'employee_id'
    UNION ALL SELECT 'employees', 'employee_number'
    UNION ALL SELECT 'employees', 'first_name'
    UNION ALL SELECT 'employees', 'last_name'
    UNION ALL SELECT 'employees', 'phone_number'
    UNION ALL SELECT 'employees', 'email'
    UNION ALL SELECT 'employees', 'hire_date'
    UNION ALL SELECT 'employees', 'employee_status_id'
    UNION ALL SELECT 'employees', 'organizational_unit_id'
    UNION ALL SELECT 'employee_statuses', 'employee_status_id'
    UNION ALL SELECT 'employee_statuses', 'status_code'
    UNION ALL SELECT 'employee_statuses', 'is_active'
    UNION ALL SELECT 'employee_unit_assignments', 'assignment_id'
    UNION ALL SELECT 'employee_unit_assignments', 'employee_id'
    UNION ALL SELECT 'employee_unit_assignments', 'organizational_unit_id'
    UNION ALL SELECT 'employee_unit_assignments', 'start_date'
    UNION ALL SELECT 'employee_unit_assignments', 'end_date'
    UNION ALL SELECT 'employee_unit_assignments', 'assignment_notes'
    UNION ALL SELECT 'employee_unit_assignments', 'is_active'
    UNION ALL SELECT 'employee_positions', 'employee_position_id'
    UNION ALL SELECT 'employee_positions', 'employee_id'
    UNION ALL SELECT 'employee_positions', 'position_id'
    UNION ALL SELECT 'employee_positions', 'organizational_unit_id'
    UNION ALL SELECT 'employee_positions', 'start_date'
    UNION ALL SELECT 'employee_positions', 'is_active'
    UNION ALL SELECT 'positions', 'position_id'
    UNION ALL SELECT 'positions', 'position_code'
    UNION ALL SELECT 'positions', 'is_active'
    UNION ALL SELECT 'faculty_members', 'faculty_member_id'
    UNION ALL SELECT 'faculty_members', 'employee_id'
    UNION ALL SELECT 'faculty_members', 'academic_rank'
    UNION ALL SELECT 'faculty_members', 'specialization'
    UNION ALL SELECT 'faculty_members', 'office_location'
    UNION ALL SELECT 'faculty_members', 'is_active'
    UNION ALL SELECT 'courses', 'course_id'
    UNION ALL SELECT 'courses', 'course_code'
    UNION ALL SELECT 'courses', 'course_name'
    UNION ALL SELECT 'courses', 'theoretical_hours'
    UNION ALL SELECT 'courses', 'practical_hours'
    UNION ALL SELECT 'course_instructors', 'course_instructor_id'
    UNION ALL SELECT 'course_instructors', 'course_id'
    UNION ALL SELECT 'course_instructors', 'faculty_member_id'
    UNION ALL SELECT 'course_instructors', 'is_active'
    UNION ALL SELECT 'course_offerings', 'course_offering_id'
    UNION ALL SELECT 'course_offerings', 'course_id'
    UNION ALL SELECT 'course_offerings', 'department_id'
    UNION ALL SELECT 'course_offerings', 'academic_program_id'
    UNION ALL SELECT 'course_offerings', 'faculty_member_id'
    UNION ALL SELECT 'course_offering_instructors', 'course_offering_instructor_id'
    UNION ALL SELECT 'course_offering_instructors', 'course_offering_id'
    UNION ALL SELECT 'course_offering_instructors', 'faculty_member_id'
    UNION ALL SELECT 'course_offering_instructors', 'instructor_role'
    UNION ALL SELECT 'course_offering_instructors', 'is_primary'
    UNION ALL SELECT 'course_offering_instructors', 'is_active'
    UNION ALL SELECT 'course_offering_instructors', 'created_at'
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
    UNION ALL SELECT 'role_permissions', 'role_id'
    UNION ALL SELECT 'role_permissions', 'permission_id'
) required
LEFT JOIN information_schema.columns c
    ON c.table_schema = 'alrowad_uni_rust'
   AND c.table_name = required.table_name
   AND c.column_name = required.column_name
ORDER BY required.table_name, required.column_name;

SELECT
    'course_offering_instructors_indexes' AS report_section,
    index_name,
    non_unique,
    GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offering_instructors'
GROUP BY index_name, non_unique
ORDER BY index_name;

SELECT
    'instructor_role_column' AS report_section,
    column_type,
    is_nullable,
    column_default
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offering_instructors'
  AND column_name = 'instructor_role';

-- ---------------------------------------------------------------------------
-- Organizational report
-- ---------------------------------------------------------------------------
SELECT
    'fmf_college' AS report_section,
    college_id,
    college_code,
    college_name,
    organizational_unit_id,
    is_active
FROM `alrowad_uni_rust`.`colleges`
WHERE college_code = 'FMF';

SELECT
    'colleges_container_811' AS report_section,
    u.organizational_unit_id,
    u.unit_code,
    u.unit_name,
    u.is_active,
    u.unit_type_id,
    t.type_code,
    t.type_name,
    u.parent_unit_id,
    p.unit_code AS parent_unit_code,
    p.unit_name AS parent_unit_name,
    p.is_active AS parent_is_active
FROM `alrowad_uni_rust`.`organizational_units` u
LEFT JOIN `alrowad_uni_rust`.`organizational_unit_types` t
    ON t.unit_type_id = u.unit_type_id
LEFT JOIN `alrowad_uni_rust`.`organizational_units` p
    ON p.organizational_unit_id = u.parent_unit_id
WHERE u.unit_code = '811';

SELECT
    'university_education_81' AS report_section,
    organizational_unit_id,
    unit_code,
    unit_name,
    is_active,
    parent_unit_id
FROM `alrowad_uni_rust`.`organizational_units`
WHERE unit_code = '81';

SELECT
    'college_unit_type' AS report_section,
    unit_type_id,
    type_code,
    type_name,
    is_active
FROM `alrowad_uni_rust`.`organizational_unit_types`
WHERE type_code = 'college';

SELECT
    'unit_code_FMF' AS report_section,
    u.organizational_unit_id,
    u.unit_code,
    u.unit_name,
    u.is_active,
    u.unit_type_id,
    t.type_code,
    u.parent_unit_id,
    p.unit_code AS parent_unit_code,
    CASE
        WHEN t.type_code = 'college'
         AND p.unit_code = '811'
         AND u.is_active = 1 THEN 'reusable'
        WHEN u.organizational_unit_id IS NOT NULL THEN 'conflicting'
        ELSE 'absent'
    END AS classification
FROM `alrowad_uni_rust`.`organizational_units` u
LEFT JOIN `alrowad_uni_rust`.`organizational_unit_types` t
    ON t.unit_type_id = u.unit_type_id
LEFT JOIN `alrowad_uni_rust`.`organizational_units` p
    ON p.organizational_unit_id = u.parent_unit_id
WHERE u.unit_code = 'FMF';

SELECT
    'exact_name_college_units' AS report_section,
    u.organizational_unit_id,
    u.unit_code,
    u.unit_name,
    u.is_active,
    t.type_code,
    p.unit_code AS parent_unit_code,
    CASE
        WHEN t.type_code = 'college'
         AND p.unit_code = '811'
         AND u.is_active = 1 THEN 'reusable'
        ELSE 'conflicting'
    END AS classification
FROM `alrowad_uni_rust`.`organizational_units` u
LEFT JOIN `alrowad_uni_rust`.`organizational_unit_types` t
    ON t.unit_type_id = u.unit_type_id
LEFT JOIN `alrowad_uni_rust`.`organizational_units` p
    ON p.organizational_unit_id = u.parent_unit_id
WHERE u.unit_name = 'كلية العلوم الإدارية والمالية';

SELECT
    'fmf_organizational_plan' AS report_section,
    CASE
        WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') <> 1
            THEN 'conflicting'
        WHEN EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`colleges` c
            INNER JOIN `alrowad_uni_rust`.`organizational_units` u
                ON u.organizational_unit_id = c.organizational_unit_id
            INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                ON t.unit_type_id = u.unit_type_id
            INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                ON p.organizational_unit_id = u.parent_unit_id
            WHERE c.college_code = 'FMF'
              AND u.is_active = 1
              AND t.type_code = 'college'
              AND p.unit_code = '811'
        ) THEN 'preserve'
        WHEN (SELECT organizational_unit_id FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') IS NOT NULL
            THEN 'conflicting'
        WHEN (
            SELECT COUNT(*)
            FROM `alrowad_uni_rust`.`organizational_units` u
            INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                ON t.unit_type_id = u.unit_type_id
            INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                ON p.organizational_unit_id = u.parent_unit_id
            WHERE u.unit_code = 'FMF'
              AND u.is_active = 1
              AND t.type_code = 'college'
              AND p.unit_code = '811'
        ) = 1 THEN 'reuse'
        WHEN EXISTS (
            SELECT 1 FROM `alrowad_uni_rust`.`organizational_units` WHERE unit_code = 'FMF'
        ) THEN 'conflicting'
        WHEN (
            SELECT COUNT(*)
            FROM `alrowad_uni_rust`.`organizational_units` u
            INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                ON t.unit_type_id = u.unit_type_id
            INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                ON p.organizational_unit_id = u.parent_unit_id
            WHERE u.unit_name = 'كلية العلوم الإدارية والمالية'
              AND u.is_active = 1
              AND t.type_code = 'college'
              AND p.unit_code = '811'
        ) = 1 THEN 'reuse'
        WHEN EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`organizational_units`
            WHERE unit_name = 'كلية العلوم الإدارية والمالية'
        ) THEN 'conflicting'
        ELSE 'needs_creation'
    END AS plan;

SELECT
    'dean_role' AS report_section,
    role_id,
    role_code,
    is_active
FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'dean';

SELECT
    'instructor_position' AS report_section,
    position_id,
    position_code,
    position_title,
    is_active
FROM `alrowad_uni_rust`.`positions`
WHERE position_code = 'INSTRUCTOR';

SELECT
    'hr_module' AS report_section,
    module_id,
    module_code,
    module_name,
    is_active
FROM `alrowad_uni_rust`.`system_modules`
WHERE module_code = 'hr';

SELECT
    'teaching_staff_permissions' AS report_section,
    p.permission_id,
    p.permission_code,
    p.permission_name,
    p.is_active,
    sm.module_code,
    CASE
        WHEN sm.module_code = 'hr' THEN 'compatible'
        ELSE 'incompatible'
    END AS ownership
FROM `alrowad_uni_rust`.`permissions` p
LEFT JOIN `alrowad_uni_rust`.`system_modules` sm
    ON sm.module_id = p.module_id
WHERE p.permission_code IN ('teaching_staff.view', 'teaching_staff.manage');

SELECT
    'faculty_employees_existing_legacy_unit' AS report_section,
    e.employee_id,
    e.employee_number,
    e.organizational_unit_id,
    u.unit_code,
    u.unit_name
FROM `alrowad_uni_rust`.`faculty_members` fm
INNER JOIN `alrowad_uni_rust`.`employees` e
    ON e.employee_id = fm.employee_id
LEFT JOIN `alrowad_uni_rust`.`organizational_units` u
    ON u.organizational_unit_id = e.organizational_unit_id
WHERE e.organizational_unit_id IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Teaching data report
-- ---------------------------------------------------------------------------
SELECT
    'course_offering_instructors' AS report_section,
    coi.course_offering_instructor_id,
    coi.course_offering_id,
    c.course_id,
    c.course_code,
    c.course_name,
    c.theoretical_hours,
    c.practical_hours,
    coi.faculty_member_id,
    coi.instructor_role AS legacy_role,
    coi.is_primary,
    coi.is_active,
    co.faculty_member_id AS offering_faculty_member_id,
    d.college_id AS department_college_id,
    pd.college_id AS program_college_id,
    CASE
        WHEN d.college_id IS NOT NULL
         AND pd.college_id IS NOT NULL
         AND d.college_id <> pd.college_id THEN NULL
        ELSE COALESCE(d.college_id, pd.college_id)
    END AS resolved_college_id
FROM `alrowad_uni_rust`.`course_offering_instructors` coi
INNER JOIN `alrowad_uni_rust`.`course_offerings` co
    ON co.course_offering_id = coi.course_offering_id
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
LEFT JOIN `alrowad_uni_rust`.`departments` d
    ON d.department_id = co.department_id
LEFT JOIN `alrowad_uni_rust`.`academic_programs` ap
    ON ap.academic_program_id = co.academic_program_id
LEFT JOIN `alrowad_uni_rust`.`departments` pd
    ON pd.department_id = ap.department_id
ORDER BY coi.course_offering_instructor_id;

SELECT
    'distinct_instructor_roles' AS report_section,
    instructor_role,
    COUNT(*) AS row_count
FROM `alrowad_uni_rust`.`course_offering_instructors`
GROUP BY instructor_role
ORDER BY instructor_role;

SELECT
    'legacy_offering_pointers_without_slot' AS report_section,
    co.course_offering_id,
    co.faculty_member_id,
    c.course_id,
    c.course_code,
    c.theoretical_hours,
    c.practical_hours,
    CASE
        WHEN c.theoretical_hours > 0 AND IFNULL(c.practical_hours, 0) = 0 THEN 'theoretical'
        WHEN IFNULL(c.theoretical_hours, 0) = 0 AND c.practical_hours > 0 THEN 'practical'
        ELSE 'ambiguous'
    END AS proposed_role
FROM `alrowad_uni_rust`.`course_offerings` co
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
WHERE co.faculty_member_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`course_offering_instructors` coi
      WHERE coi.course_offering_id = co.course_offering_id
        AND coi.faculty_member_id = co.faculty_member_id
  );

-- ---------------------------------------------------------------------------
-- Blockers
-- ---------------------------------------------------------------------------
SELECT 'BLOCKED_REASON' AS report_section, reason
FROM (
    SELECT 'required structure missing' AS reason
    WHERE EXISTS (
        SELECT 1
        FROM (
            SELECT 'colleges' AS table_name, 'college_id' AS column_name
            UNION ALL SELECT 'colleges', 'organizational_unit_id'
            UNION ALL SELECT 'colleges', 'college_code'
            UNION ALL SELECT 'colleges', 'college_name'
            UNION ALL SELECT 'colleges', 'is_active'
            UNION ALL SELECT 'organizational_units', 'organizational_unit_id'
            UNION ALL SELECT 'organizational_units', 'unit_code'
            UNION ALL SELECT 'organizational_units', 'unit_name'
            UNION ALL SELECT 'organizational_units', 'unit_type_id'
            UNION ALL SELECT 'organizational_units', 'parent_unit_id'
            UNION ALL SELECT 'organizational_units', 'is_active'
            UNION ALL SELECT 'organizational_unit_types', 'unit_type_id'
            UNION ALL SELECT 'organizational_unit_types', 'type_code'
            UNION ALL SELECT 'departments', 'department_id'
            UNION ALL SELECT 'departments', 'college_id'
            UNION ALL SELECT 'academic_programs', 'academic_program_id'
            UNION ALL SELECT 'academic_programs', 'department_id'
            UNION ALL SELECT 'employees', 'employee_id'
            UNION ALL SELECT 'employees', 'hire_date'
            UNION ALL SELECT 'employees', 'employee_status_id'
            UNION ALL SELECT 'employees', 'organizational_unit_id'
            UNION ALL SELECT 'employee_statuses', 'status_code'
            UNION ALL SELECT 'employee_unit_assignments', 'assignment_id'
            UNION ALL SELECT 'employee_unit_assignments', 'employee_id'
            UNION ALL SELECT 'employee_unit_assignments', 'organizational_unit_id'
            UNION ALL SELECT 'employee_unit_assignments', 'start_date'
            UNION ALL SELECT 'employee_unit_assignments', 'is_active'
            UNION ALL SELECT 'employee_positions', 'employee_position_id'
            UNION ALL SELECT 'employee_positions', 'employee_id'
            UNION ALL SELECT 'employee_positions', 'position_id'
            UNION ALL SELECT 'employee_positions', 'organizational_unit_id'
            UNION ALL SELECT 'employee_positions', 'start_date'
            UNION ALL SELECT 'employee_positions', 'is_active'
            UNION ALL SELECT 'positions', 'position_code'
            UNION ALL SELECT 'faculty_members', 'faculty_member_id'
            UNION ALL SELECT 'faculty_members', 'employee_id'
            UNION ALL SELECT 'faculty_members', 'is_active'
            UNION ALL SELECT 'courses', 'course_id'
            UNION ALL SELECT 'courses', 'theoretical_hours'
            UNION ALL SELECT 'courses', 'practical_hours'
            UNION ALL SELECT 'course_instructors', 'course_id'
            UNION ALL SELECT 'course_instructors', 'faculty_member_id'
            UNION ALL SELECT 'course_instructors', 'is_active'
            UNION ALL SELECT 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'course_offerings', 'course_id'
            UNION ALL SELECT 'course_offerings', 'department_id'
            UNION ALL SELECT 'course_offerings', 'academic_program_id'
            UNION ALL SELECT 'course_offerings', 'faculty_member_id'
            UNION ALL SELECT 'course_offering_instructors', 'course_offering_instructor_id'
            UNION ALL SELECT 'course_offering_instructors', 'course_offering_id'
            UNION ALL SELECT 'course_offering_instructors', 'faculty_member_id'
            UNION ALL SELECT 'course_offering_instructors', 'instructor_role'
            UNION ALL SELECT 'course_offering_instructors', 'is_primary'
            UNION ALL SELECT 'course_offering_instructors', 'is_active'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'system_modules', 'module_code'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'permissions', 'module_id'
            UNION ALL SELECT 'role_permissions', 'role_id'
            UNION ALL SELECT 'role_permissions', 'permission_id'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = required.table_name
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
    )
    UNION ALL
    SELECT 'FMF College ambiguous'
    WHERE (SELECT COUNT(*) FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') <> 1
    UNION ALL
    SELECT 'FMF College name does not match كلية العلوم الإدارية والمالية'
    WHERE (SELECT COUNT(*) FROM `alrowad_uni_rust`.`colleges`
           WHERE college_code = 'FMF'
             AND college_name = 'كلية العلوم الإدارية والمالية') <> 1
    UNION ALL
    SELECT '811 College container missing/ambiguous/inactive'
    WHERE (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`organizational_units` u
        INNER JOIN `alrowad_uni_rust`.`organizational_units` p
            ON p.organizational_unit_id = u.parent_unit_id
        WHERE u.unit_code = '811'
          AND u.is_active = 1
          AND p.unit_code = '81'
          AND p.is_active = 1
    ) <> 1
    UNION ALL
    SELECT '811 College container unit type is incompatible with College'
    WHERE EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`organizational_units` u
        INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
            ON t.unit_type_id = u.unit_type_id
        WHERE u.unit_code = '811'
          AND t.type_code IN ('department', 'office', 'lab', 'club', 'committee')
    )
    UNION ALL
    SELECT 'College unit type missing/ambiguous'
    WHERE (SELECT COUNT(*) FROM `alrowad_uni_rust`.`organizational_unit_types`
           WHERE type_code = 'college' AND is_active = 1) <> 1
    UNION ALL
    SELECT 'incompatible existing FMF organizational unit'
    WHERE (SELECT COUNT(*) FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') = 1
      AND (
          (
              (SELECT organizational_unit_id FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM `alrowad_uni_rust`.`colleges` c
                  INNER JOIN `alrowad_uni_rust`.`organizational_units` u
                      ON u.organizational_unit_id = c.organizational_unit_id
                  INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                      ON t.unit_type_id = u.unit_type_id
                  INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                      ON p.organizational_unit_id = u.parent_unit_id
                  WHERE c.college_code = 'FMF'
                    AND u.is_active = 1
                    AND t.type_code = 'college'
                    AND p.unit_code = '811'
              )
          )
          OR (
              (SELECT organizational_unit_id FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') IS NULL
              AND EXISTS (
                  SELECT 1 FROM `alrowad_uni_rust`.`organizational_units` WHERE unit_code = 'FMF'
              )
              AND (
                  SELECT COUNT(*)
                  FROM `alrowad_uni_rust`.`organizational_units` u
                  INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                      ON t.unit_type_id = u.unit_type_id
                  INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                      ON p.organizational_unit_id = u.parent_unit_id
                  WHERE u.unit_code = 'FMF'
                    AND u.is_active = 1
                    AND t.type_code = 'college'
                    AND p.unit_code = '811'
              ) <> 1
          )
          OR (
              (SELECT organizational_unit_id FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM `alrowad_uni_rust`.`organizational_units` WHERE unit_code = 'FMF'
              )
              AND EXISTS (
                  SELECT 1
                  FROM `alrowad_uni_rust`.`organizational_units`
                  WHERE unit_name = 'كلية العلوم الإدارية والمالية'
              )
              AND (
                  SELECT COUNT(*)
                  FROM `alrowad_uni_rust`.`organizational_units` u
                  INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                      ON t.unit_type_id = u.unit_type_id
                  INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                      ON p.organizational_unit_id = u.parent_unit_id
                  WHERE u.unit_name = 'كلية العلوم الإدارية والمالية'
                    AND u.is_active = 1
                    AND t.type_code = 'college'
                    AND p.unit_code = '811'
              ) <> 1
          )
          OR (
              SELECT COUNT(*)
              FROM `alrowad_uni_rust`.`organizational_units` u
              INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                  ON t.unit_type_id = u.unit_type_id
              INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                  ON p.organizational_unit_id = u.parent_unit_id
              WHERE (
                    u.unit_code = 'FMF'
                    OR u.unit_name = 'كلية العلوم الإدارية والمالية'
                )
                AND u.is_active = 1
                AND t.type_code = 'college'
                AND p.unit_code = '811'
          ) > 1
      )
    UNION ALL
    SELECT 'role dean missing/ambiguous'
    WHERE (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles`
           WHERE role_code = 'dean' AND is_active = 1) <> 1
    UNION ALL
    SELECT 'position INSTRUCTOR missing/ambiguous'
    WHERE (SELECT COUNT(*) FROM `alrowad_uni_rust`.`positions`
           WHERE position_code = 'INSTRUCTOR') <> 1
    UNION ALL
    SELECT 'hr module missing/ambiguous'
    WHERE (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules`
           WHERE module_code = 'hr' AND is_active = 1) <> 1
    UNION ALL
    SELECT 'existing teaching_staff permission has incompatible ownership'
    WHERE EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`permissions` p
        LEFT JOIN `alrowad_uni_rust`.`system_modules` sm
            ON sm.module_id = p.module_id
        WHERE p.permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
          AND (sm.module_code IS NULL OR sm.module_code <> 'hr')
    )
    UNION ALL
    SELECT 'teaching_staff permission code is duplicated'
    WHERE EXISTS (
        SELECT permission_code
        FROM `alrowad_uni_rust`.`permissions`
        WHERE permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
        GROUP BY permission_code
        HAVING COUNT(*) > 1
    )
    UNION ALL
    SELECT CONCAT(
        'offering department/program resolve to different Colleges: offering ',
        co.course_offering_id
    )
    FROM `alrowad_uni_rust`.`course_offerings` co
    LEFT JOIN `alrowad_uni_rust`.`departments` d
        ON d.department_id = co.department_id
    LEFT JOIN `alrowad_uni_rust`.`academic_programs` ap
        ON ap.academic_program_id = co.academic_program_id
    LEFT JOIN `alrowad_uni_rust`.`departments` pd
        ON pd.department_id = ap.department_id
    WHERE d.college_id IS NOT NULL
      AND pd.college_id IS NOT NULL
      AND d.college_id <> pd.college_id
    UNION ALL
    SELECT CONCAT(
        'unknown legacy instructor_role on assignment ',
        coi.course_offering_instructor_id,
        ': ',
        COALESCE(coi.instructor_role, 'NULL')
    )
    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
    WHERE LOWER(TRIM(COALESCE(coi.instructor_role, ''))) NOT IN (
        '', 'lead', 'theoretical', 'practical'
    )
    UNION ALL
    SELECT CONCAT(
        'legacy Assistant row: assignment ',
        course_offering_instructor_id
    )
    FROM `alrowad_uni_rust`.`course_offering_instructors`
    WHERE LOWER(TRIM(COALESCE(instructor_role, ''))) = 'assistant'
    UNION ALL
    SELECT CONCAT(
        'ambiguous legacy role on dual-component course: assignment ',
        coi.course_offering_instructor_id
    )
    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
    INNER JOIN `alrowad_uni_rust`.`course_offerings` co
        ON co.course_offering_id = coi.course_offering_id
    INNER JOIN `alrowad_uni_rust`.`courses` c
        ON c.course_id = co.course_id
    WHERE LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
      AND c.theoretical_hours > 0
      AND c.practical_hours > 0
    UNION ALL
    SELECT CONCAT(
        'legacy empty/Lead assignment is not a safe primary slot: assignment ',
        coi.course_offering_instructor_id
    )
    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
    INNER JOIN `alrowad_uni_rust`.`course_offerings` co
        ON co.course_offering_id = coi.course_offering_id
    INNER JOIN `alrowad_uni_rust`.`courses` c
        ON c.course_id = co.course_id
    WHERE LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
      AND NOT (c.theoretical_hours > 0 AND IFNULL(c.practical_hours, 0) = 0)
      AND NOT (IFNULL(c.theoretical_hours, 0) = 0 AND c.practical_hours > 0)
    UNION ALL
    SELECT CONCAT(
        'legacy empty/Lead assignment is not primary: assignment ',
        coi.course_offering_instructor_id
    )
    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
    WHERE LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
      AND IFNULL(coi.is_primary, 0) <> 1
    UNION ALL
    SELECT CONCAT(
        'conflict between safe legacy assignment and course_offerings.faculty_member_id: assignment ',
        coi.course_offering_instructor_id
    )
    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
    INNER JOIN `alrowad_uni_rust`.`course_offerings` co
        ON co.course_offering_id = coi.course_offering_id
    WHERE LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
      AND co.faculty_member_id IS NOT NULL
      AND co.faculty_member_id <> coi.faculty_member_id
    UNION ALL
    SELECT CONCAT(
        'assignment references a Course with neither theoretical nor practical hours: assignment ',
        coi.course_offering_instructor_id
    )
    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
    INNER JOIN `alrowad_uni_rust`.`course_offerings` co
        ON co.course_offering_id = coi.course_offering_id
    INNER JOIN `alrowad_uni_rust`.`courses` c
        ON c.course_id = co.course_id
    WHERE IFNULL(c.theoretical_hours, 0) = 0
      AND IFNULL(c.practical_hours, 0) = 0
    UNION ALL
    SELECT CONCAT(
        'ambiguous dual-component legacy offering pointer: offering ',
        co.course_offering_id
    )
    FROM `alrowad_uni_rust`.`course_offerings` co
    INNER JOIN `alrowad_uni_rust`.`courses` c
        ON c.course_id = co.course_id
    WHERE co.faculty_member_id IS NOT NULL
      AND c.theoretical_hours > 0
      AND c.practical_hours > 0
      AND NOT EXISTS (
          SELECT 1
          FROM `alrowad_uni_rust`.`course_offering_instructors` coi
          WHERE coi.course_offering_id = co.course_offering_id
            AND coi.faculty_member_id = co.faculty_member_id
      )
    UNION ALL
    SELECT CONCAT(
        'multiple existing rows would normalize into the same offering+role: offering ',
        classified.course_offering_id,
        ' role ',
        classified.target_role
    )
    FROM (
        SELECT
            coi.course_offering_id,
            CASE
                WHEN LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('theoretical', 'practical')
                    THEN LOWER(TRIM(coi.instructor_role))
                WHEN LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
                 AND c.theoretical_hours > 0
                 AND IFNULL(c.practical_hours, 0) = 0
                    THEN 'theoretical'
                WHEN LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
                 AND IFNULL(c.theoretical_hours, 0) = 0
                 AND c.practical_hours > 0
                    THEN 'practical'
                ELSE CONCAT('unsafe:', COALESCE(coi.instructor_role, ''))
            END AS target_role
        FROM `alrowad_uni_rust`.`course_offering_instructors` coi
        INNER JOIN `alrowad_uni_rust`.`course_offerings` co
            ON co.course_offering_id = coi.course_offering_id
        INNER JOIN `alrowad_uni_rust`.`courses` c
            ON c.course_id = co.course_id
    ) classified
    GROUP BY classified.course_offering_id, classified.target_role
    HAVING COUNT(*) > 1
    UNION ALL
    SELECT CONCAT(
        'competing ambiguous assignment for offering ',
        coi.course_offering_id
    )
    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
    INNER JOIN `alrowad_uni_rust`.`course_offerings` co
        ON co.course_offering_id = coi.course_offering_id
    INNER JOIN `alrowad_uni_rust`.`courses` c
        ON c.course_id = co.course_id
    WHERE LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
      AND (
          SELECT COUNT(*)
          FROM `alrowad_uni_rust`.`course_offering_instructors` other
          WHERE other.course_offering_id = coi.course_offering_id
            AND LOWER(TRIM(COALESCE(other.instructor_role, ''))) IN ('', 'lead', 'theoretical', 'practical')
      ) > 1
      AND NOT (
          (c.theoretical_hours > 0 AND IFNULL(c.practical_hours, 0) = 0)
          OR (IFNULL(c.theoretical_hours, 0) = 0 AND c.practical_hours > 0)
      )
) blockers;

SELECT
    'OVERALL' AS report_section,
    IF(
        NOT EXISTS (
            SELECT 1
            FROM (
                SELECT 'colleges' AS table_name, 'college_id' AS column_name
                UNION ALL SELECT 'colleges', 'organizational_unit_id'
                UNION ALL SELECT 'colleges', 'college_code'
                UNION ALL SELECT 'colleges', 'college_name'
                UNION ALL SELECT 'colleges', 'is_active'
                UNION ALL SELECT 'organizational_units', 'organizational_unit_id'
                UNION ALL SELECT 'organizational_units', 'unit_code'
                UNION ALL SELECT 'organizational_units', 'unit_name'
                UNION ALL SELECT 'organizational_units', 'unit_type_id'
                UNION ALL SELECT 'organizational_units', 'parent_unit_id'
                UNION ALL SELECT 'organizational_units', 'is_active'
                UNION ALL SELECT 'organizational_unit_types', 'unit_type_id'
                UNION ALL SELECT 'organizational_unit_types', 'type_code'
                UNION ALL SELECT 'departments', 'department_id'
                UNION ALL SELECT 'departments', 'college_id'
                UNION ALL SELECT 'academic_programs', 'academic_program_id'
                UNION ALL SELECT 'academic_programs', 'department_id'
                UNION ALL SELECT 'employees', 'employee_id'
                UNION ALL SELECT 'employees', 'hire_date'
                UNION ALL SELECT 'employees', 'employee_status_id'
                UNION ALL SELECT 'employees', 'organizational_unit_id'
                UNION ALL SELECT 'employee_statuses', 'status_code'
                UNION ALL SELECT 'employee_unit_assignments', 'assignment_id'
                UNION ALL SELECT 'employee_unit_assignments', 'employee_id'
                UNION ALL SELECT 'employee_unit_assignments', 'organizational_unit_id'
                UNION ALL SELECT 'employee_unit_assignments', 'start_date'
                UNION ALL SELECT 'employee_unit_assignments', 'is_active'
                UNION ALL SELECT 'employee_positions', 'employee_position_id'
                UNION ALL SELECT 'employee_positions', 'employee_id'
                UNION ALL SELECT 'employee_positions', 'position_id'
                UNION ALL SELECT 'employee_positions', 'organizational_unit_id'
                UNION ALL SELECT 'employee_positions', 'start_date'
                UNION ALL SELECT 'employee_positions', 'is_active'
                UNION ALL SELECT 'positions', 'position_code'
                UNION ALL SELECT 'faculty_members', 'faculty_member_id'
                UNION ALL SELECT 'faculty_members', 'employee_id'
                UNION ALL SELECT 'faculty_members', 'is_active'
                UNION ALL SELECT 'courses', 'course_id'
                UNION ALL SELECT 'courses', 'theoretical_hours'
                UNION ALL SELECT 'courses', 'practical_hours'
                UNION ALL SELECT 'course_instructors', 'course_id'
                UNION ALL SELECT 'course_instructors', 'faculty_member_id'
                UNION ALL SELECT 'course_instructors', 'is_active'
                UNION ALL SELECT 'course_offerings', 'course_offering_id'
                UNION ALL SELECT 'course_offerings', 'course_id'
                UNION ALL SELECT 'course_offerings', 'department_id'
                UNION ALL SELECT 'course_offerings', 'academic_program_id'
                UNION ALL SELECT 'course_offerings', 'faculty_member_id'
                UNION ALL SELECT 'course_offering_instructors', 'course_offering_instructor_id'
                UNION ALL SELECT 'course_offering_instructors', 'course_offering_id'
                UNION ALL SELECT 'course_offering_instructors', 'faculty_member_id'
                UNION ALL SELECT 'course_offering_instructors', 'instructor_role'
                UNION ALL SELECT 'course_offering_instructors', 'is_primary'
                UNION ALL SELECT 'course_offering_instructors', 'is_active'
                UNION ALL SELECT 'roles', 'role_code'
                UNION ALL SELECT 'system_modules', 'module_code'
                UNION ALL SELECT 'permissions', 'permission_code'
                UNION ALL SELECT 'permissions', 'module_id'
                UNION ALL SELECT 'role_permissions', 'role_id'
                UNION ALL SELECT 'role_permissions', 'permission_id'
            ) required
            LEFT JOIN information_schema.columns c
                ON c.table_schema = 'alrowad_uni_rust'
               AND c.table_name = required.table_name
               AND c.column_name = required.column_name
            WHERE c.column_name IS NULL
        )
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') = 1
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`colleges`
             WHERE college_code = 'FMF'
               AND college_name = 'كلية العلوم الإدارية والمالية') = 1
        AND (
            SELECT COUNT(*)
            FROM `alrowad_uni_rust`.`organizational_units` u
            INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                ON p.organizational_unit_id = u.parent_unit_id
            WHERE u.unit_code = '811'
              AND u.is_active = 1
              AND p.unit_code = '81'
              AND p.is_active = 1
        ) = 1
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`organizational_units` u
            INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                ON t.unit_type_id = u.unit_type_id
            WHERE u.unit_code = '811'
              AND t.type_code IN ('department', 'office', 'lab', 'club', 'committee')
        )
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`organizational_unit_types`
             WHERE type_code = 'college' AND is_active = 1) = 1
        AND (
            EXISTS (
                SELECT 1
                FROM `alrowad_uni_rust`.`colleges` c
                INNER JOIN `alrowad_uni_rust`.`organizational_units` u
                    ON u.organizational_unit_id = c.organizational_unit_id
                INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                    ON t.unit_type_id = u.unit_type_id
                INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                    ON p.organizational_unit_id = u.parent_unit_id
                WHERE c.college_code = 'FMF'
                  AND u.is_active = 1
                  AND t.type_code = 'college'
                  AND p.unit_code = '811'
            )
            OR (
                (SELECT organizational_unit_id FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') IS NULL
                AND (
                    (
                        SELECT COUNT(*)
                        FROM `alrowad_uni_rust`.`organizational_units` u
                        INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                            ON t.unit_type_id = u.unit_type_id
                        INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                            ON p.organizational_unit_id = u.parent_unit_id
                        WHERE u.unit_code = 'FMF'
                          AND u.is_active = 1
                          AND t.type_code = 'college'
                          AND p.unit_code = '811'
                    ) = 1
                    OR (
                        NOT EXISTS (
                            SELECT 1 FROM `alrowad_uni_rust`.`organizational_units` WHERE unit_code = 'FMF'
                        )
                        AND (
                            SELECT COUNT(*)
                            FROM `alrowad_uni_rust`.`organizational_units` u
                            INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                                ON t.unit_type_id = u.unit_type_id
                            INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                                ON p.organizational_unit_id = u.parent_unit_id
                            WHERE u.unit_name = 'كلية العلوم الإدارية والمالية'
                              AND u.is_active = 1
                              AND t.type_code = 'college'
                              AND p.unit_code = '811'
                        ) = 1
                    )
                    OR (
                        NOT EXISTS (
                            SELECT 1 FROM `alrowad_uni_rust`.`organizational_units` WHERE unit_code = 'FMF'
                        )
                        AND NOT EXISTS (
                            SELECT 1
                            FROM `alrowad_uni_rust`.`organizational_units`
                            WHERE unit_name = 'كلية العلوم الإدارية والمالية'
                        )
                    )
                )
                AND (
                    SELECT COUNT(*)
                    FROM `alrowad_uni_rust`.`organizational_units` u
                    INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                        ON t.unit_type_id = u.unit_type_id
                    INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                        ON p.organizational_unit_id = u.parent_unit_id
                    WHERE (
                          u.unit_code = 'FMF'
                          OR u.unit_name = 'كلية العلوم الإدارية والمالية'
                      )
                      AND u.is_active = 1
                      AND t.type_code = 'college'
                      AND p.unit_code = '811'
                ) <= 1
            )
        )
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles`
             WHERE role_code = 'dean' AND is_active = 1) = 1
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`positions`
             WHERE position_code = 'INSTRUCTOR') = 1
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules`
             WHERE module_code = 'hr' AND is_active = 1) = 1
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`permissions` p
            LEFT JOIN `alrowad_uni_rust`.`system_modules` sm
                ON sm.module_id = p.module_id
            WHERE p.permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
              AND (sm.module_code IS NULL OR sm.module_code <> 'hr')
        )
        AND NOT EXISTS (
            SELECT permission_code
            FROM `alrowad_uni_rust`.`permissions`
            WHERE permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
            GROUP BY permission_code
            HAVING COUNT(*) > 1
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offerings` co
            LEFT JOIN `alrowad_uni_rust`.`departments` d
                ON d.department_id = co.department_id
            LEFT JOIN `alrowad_uni_rust`.`academic_programs` ap
                ON ap.academic_program_id = co.academic_program_id
            LEFT JOIN `alrowad_uni_rust`.`departments` pd
                ON pd.department_id = ap.department_id
            WHERE d.college_id IS NOT NULL
              AND pd.college_id IS NOT NULL
              AND d.college_id <> pd.college_id
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offering_instructors` coi
            WHERE LOWER(TRIM(COALESCE(coi.instructor_role, ''))) NOT IN (
                '', 'lead', 'theoretical', 'practical'
            )
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offering_instructors` coi
            INNER JOIN `alrowad_uni_rust`.`course_offerings` co
                ON co.course_offering_id = coi.course_offering_id
            INNER JOIN `alrowad_uni_rust`.`courses` c
                ON c.course_id = co.course_id
            WHERE LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
              AND (
                  IFNULL(coi.is_primary, 0) <> 1
                  OR (c.theoretical_hours > 0 AND c.practical_hours > 0)
                  OR (IFNULL(c.theoretical_hours, 0) = 0 AND IFNULL(c.practical_hours, 0) = 0)
                  OR (co.faculty_member_id IS NOT NULL AND co.faculty_member_id <> coi.faculty_member_id)
              )
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offering_instructors` coi
            INNER JOIN `alrowad_uni_rust`.`course_offerings` co
                ON co.course_offering_id = coi.course_offering_id
            INNER JOIN `alrowad_uni_rust`.`courses` c
                ON c.course_id = co.course_id
            WHERE IFNULL(c.theoretical_hours, 0) = 0
              AND IFNULL(c.practical_hours, 0) = 0
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offerings` co
            INNER JOIN `alrowad_uni_rust`.`courses` c
                ON c.course_id = co.course_id
            WHERE co.faculty_member_id IS NOT NULL
              AND c.theoretical_hours > 0
              AND c.practical_hours > 0
              AND NOT EXISTS (
                  SELECT 1
                  FROM `alrowad_uni_rust`.`course_offering_instructors` coi
                  WHERE coi.course_offering_id = co.course_offering_id
                    AND coi.faculty_member_id = co.faculty_member_id
              )
        )
        AND NOT EXISTS (
            SELECT 1
            FROM (
                SELECT
                    coi.course_offering_id,
                    CASE
                        WHEN LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('theoretical', 'practical')
                            THEN LOWER(TRIM(coi.instructor_role))
                        WHEN LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
                         AND c.theoretical_hours > 0
                         AND IFNULL(c.practical_hours, 0) = 0
                            THEN 'theoretical'
                        WHEN LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
                         AND IFNULL(c.theoretical_hours, 0) = 0
                         AND c.practical_hours > 0
                            THEN 'practical'
                        ELSE CONCAT('unsafe:', COALESCE(coi.instructor_role, ''))
                    END AS target_role
                FROM `alrowad_uni_rust`.`course_offering_instructors` coi
                INNER JOIN `alrowad_uni_rust`.`course_offerings` co
                    ON co.course_offering_id = coi.course_offering_id
                INNER JOIN `alrowad_uni_rust`.`courses` c
                    ON c.course_id = co.course_id
            ) classified
            GROUP BY classified.course_offering_id, classified.target_role
            HAVING COUNT(*) > 1
        ),
        'READY',
        'BLOCKED'
    ) AS result;
