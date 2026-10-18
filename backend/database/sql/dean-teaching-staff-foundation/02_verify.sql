-- READ ONLY. Require OVERALL = PASS after 01_apply.sql.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.

SELECT
    'A_fmf_college_organizational_unit' AS check_name,
    IF(
        COUNT(*) = 1
        AND MIN(c.organizational_unit_id) IS NOT NULL,
        'PASS',
        'FAIL'
    ) AS result,
    MIN(c.college_id) AS college_id,
    MIN(c.organizational_unit_id) AS organizational_unit_id
FROM `alrowad_uni_rust`.`colleges` c
WHERE c.college_code = 'FMF';

SELECT
    'B_fmf_unit_is_active_college_under_811' AS check_name,
    IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
    MIN(u.organizational_unit_id) AS organizational_unit_id,
    MIN(t.type_code) AS type_code,
    MIN(p.unit_code) AS parent_unit_code
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
  AND p.unit_code = '811';

SELECT
    'C_instructor_role_column' AS check_name,
    IF(
        LOWER(column_type) LIKE '%theoretical%'
        AND LOWER(column_type) LIKE '%practical%'
        AND LOWER(column_type) NOT LIKE '%lead%'
        AND LOWER(column_type) NOT LIKE '%assistant%',
        'PASS',
        'FAIL'
    ) AS result,
    column_type
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offering_instructors'
  AND column_name = 'instructor_role';

SELECT
    'D_no_legacy_instructor_roles' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`course_offering_instructors`
WHERE instructor_role NOT IN ('theoretical', 'practical')
   OR TRIM(COALESCE(instructor_role, '')) = '';

SELECT
    'E_old_unique_index_gone' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offering_instructors'
  AND index_name = 'uq_course_offering_instructor';

SELECT
    'F_new_unique_index' AS check_name,
    IF(
        COUNT(*) = 1
        AND MIN(columns) = 'course_offering_id,instructor_role',
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
      AND table_name = 'course_offering_instructors'
      AND index_name = 'uq_course_offering_role'
      AND non_unique = 0
    GROUP BY index_name
) idx;

SELECT
    'G_no_duplicate_theoretical_slot' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM (
    SELECT course_offering_id
    FROM `alrowad_uni_rust`.`course_offering_instructors`
    WHERE instructor_role = 'theoretical'
    GROUP BY course_offering_id
    HAVING COUNT(*) > 1
) duplicates;

SELECT
    'H_no_duplicate_practical_slot' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM (
    SELECT course_offering_id
    FROM `alrowad_uni_rust`.`course_offering_instructors`
    WHERE instructor_role = 'practical'
    GROUP BY course_offering_id
    HAVING COUNT(*) > 1
) duplicates;

SELECT
    'I_no_theoretical_on_zero_theoretical_hours' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`course_offering_instructors` coi
INNER JOIN `alrowad_uni_rust`.`course_offerings` co
    ON co.course_offering_id = coi.course_offering_id
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
WHERE coi.instructor_role = 'theoretical'
  AND IFNULL(c.theoretical_hours, 0) = 0;

SELECT
    'J_no_practical_on_zero_practical_hours' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`course_offering_instructors` coi
INNER JOIN `alrowad_uni_rust`.`course_offerings` co
    ON co.course_offering_id = coi.course_offering_id
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
WHERE coi.instructor_role = 'practical'
  AND IFNULL(c.practical_hours, 0) = 0;

SELECT
    'K_same_faculty_both_roles_structurally_allowed' AS check_name,
    IF(
        EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'course_offering_instructors'
              AND index_name = 'uq_course_offering_role'
              AND non_unique = 0
        )
        AND NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'course_offering_instructors'
              AND index_name = 'uq_course_offering_instructor'
        )
        AND NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics s
            WHERE s.table_schema = 'alrowad_uni_rust'
              AND s.table_name = 'course_offering_instructors'
              AND s.non_unique = 0
              AND s.index_name <> 'PRIMARY'
            GROUP BY s.index_name
            HAVING GROUP_CONCAT(s.column_name ORDER BY s.seq_in_index)
                = 'course_offering_id,faculty_member_id'
        ),
        'PASS',
        'FAIL'
    ) AS result;

SELECT
    'L_is_primary_semantics' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`course_offering_instructors` coi
INNER JOIN `alrowad_uni_rust`.`course_offerings` co
    ON co.course_offering_id = coi.course_offering_id
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
WHERE coi.is_primary <> CASE
        WHEN coi.instructor_role = 'theoretical' THEN 1
        WHEN coi.instructor_role = 'practical' AND IFNULL(c.theoretical_hours, 0) = 0 THEN 1
        ELSE 0
    END;

SELECT
    'M_legacy_pointer_matches_primary_slot' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`course_offerings` co
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
LEFT JOIN `alrowad_uni_rust`.`course_offering_instructors` primary_slot
    ON primary_slot.course_offering_id = co.course_offering_id
   AND primary_slot.is_active = 1
   AND primary_slot.instructor_role = CASE
        WHEN c.theoretical_hours > 0 THEN 'theoretical'
        WHEN c.practical_hours > 0 THEN 'practical'
        ELSE NULL
    END
WHERE NOT (co.faculty_member_id <=> primary_slot.faculty_member_id);

SELECT
    'N_active_offering_instructors_have_course_instructors' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`course_offering_instructors` coi
INNER JOIN `alrowad_uni_rust`.`course_offerings` co
    ON co.course_offering_id = coi.course_offering_id
WHERE coi.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`course_instructors` ci
      WHERE ci.course_id = co.course_id
        AND ci.faculty_member_id = coi.faculty_member_id
        AND ci.is_active = 1
  );

SELECT
    'O_active_faculty_have_college_membership' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM (
    SELECT DISTINCT
        fm.employee_id,
        col.organizational_unit_id
    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
    INNER JOIN `alrowad_uni_rust`.`faculty_members` fm
        ON fm.faculty_member_id = coi.faculty_member_id
    INNER JOIN `alrowad_uni_rust`.`course_offerings` co
        ON co.course_offering_id = coi.course_offering_id
    LEFT JOIN `alrowad_uni_rust`.`departments` d
        ON d.department_id = co.department_id
    LEFT JOIN `alrowad_uni_rust`.`academic_programs` ap
        ON ap.academic_program_id = co.academic_program_id
    LEFT JOIN `alrowad_uni_rust`.`departments` pd
        ON pd.department_id = ap.department_id
    INNER JOIN `alrowad_uni_rust`.`colleges` col
        ON col.college_id = COALESCE(d.college_id, pd.college_id)
    WHERE coi.is_active = 1
      AND col.organizational_unit_id IS NOT NULL
      AND (d.college_id IS NULL OR pd.college_id IS NULL OR d.college_id = pd.college_id)
) expected
WHERE NOT EXISTS (
    SELECT 1
    FROM `alrowad_uni_rust`.`employee_unit_assignments` eua
    WHERE eua.employee_id = expected.employee_id
      AND eua.organizational_unit_id = expected.organizational_unit_id
      AND eua.is_active = 1
);

SELECT
    'P_active_college_teaching_staff_have_instructor_position' AS check_name,
    IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM (
    SELECT DISTINCT
        fm.employee_id,
        col.organizational_unit_id
    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
    INNER JOIN `alrowad_uni_rust`.`faculty_members` fm
        ON fm.faculty_member_id = coi.faculty_member_id
    INNER JOIN `alrowad_uni_rust`.`course_offerings` co
        ON co.course_offering_id = coi.course_offering_id
    LEFT JOIN `alrowad_uni_rust`.`departments` d
        ON d.department_id = co.department_id
    LEFT JOIN `alrowad_uni_rust`.`academic_programs` ap
        ON ap.academic_program_id = co.academic_program_id
    LEFT JOIN `alrowad_uni_rust`.`departments` pd
        ON pd.department_id = ap.department_id
    INNER JOIN `alrowad_uni_rust`.`colleges` col
        ON col.college_id = COALESCE(d.college_id, pd.college_id)
    WHERE coi.is_active = 1
      AND col.organizational_unit_id IS NOT NULL
      AND (d.college_id IS NULL OR pd.college_id IS NULL OR d.college_id = pd.college_id)
) expected
WHERE NOT EXISTS (
    SELECT 1
    FROM `alrowad_uni_rust`.`employee_positions` ep
    INNER JOIN `alrowad_uni_rust`.`positions` p
        ON p.position_id = ep.position_id
    WHERE ep.employee_id = expected.employee_id
      AND ep.organizational_unit_id = expected.organizational_unit_id
      AND ep.is_active = 1
      AND p.position_code = 'INSTRUCTOR'
);

SELECT
    'Q_teaching_staff_view_exactly_once' AS check_name,
    IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`permissions`
WHERE permission_code = 'teaching_staff.view';

SELECT
    'R_teaching_staff_manage_exactly_once' AS check_name,
    IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`permissions`
WHERE permission_code = 'teaching_staff.manage';

SELECT
    'S_teaching_staff_permissions_belong_to_hr' AS check_name,
    IF(COUNT(*) = 2, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`permissions` p
INNER JOIN `alrowad_uni_rust`.`system_modules` sm
    ON sm.module_id = p.module_id
WHERE p.permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
  AND sm.module_code = 'hr';

SELECT
    'T_dean_has_teaching_staff_permissions' AS check_name,
    IF(COUNT(*) = 2, 'PASS', 'FAIL') AS result,
    COUNT(*) AS actual
FROM `alrowad_uni_rust`.`role_permissions` rp
INNER JOIN `alrowad_uni_rust`.`roles` r
    ON r.role_id = rp.role_id
INNER JOIN `alrowad_uni_rust`.`permissions` p
    ON p.permission_id = rp.permission_id
WHERE r.role_code = 'dean'
  AND r.is_active = 1
  AND p.permission_code IN ('teaching_staff.view', 'teaching_staff.manage');

SELECT
    'U_dean_hr_manage_not_granted_by_this_foundation' AS check_name,
    IF(
        NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`role_permissions` rp
            INNER JOIN `alrowad_uni_rust`.`roles` r
                ON r.role_id = rp.role_id
            INNER JOIN `alrowad_uni_rust`.`permissions` p
                ON p.permission_id = rp.permission_id
            WHERE r.role_code = 'dean'
              AND p.permission_code = 'hr.manage'
              AND rp.granted_at >= (
                  SELECT MIN(ts.granted_at)
                  FROM `alrowad_uni_rust`.`role_permissions` ts
                  INNER JOIN `alrowad_uni_rust`.`permissions` tp
                      ON tp.permission_id = ts.permission_id
                  WHERE ts.role_id = r.role_id
                    AND tp.permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
              )
        ),
        'PASS',
        'FAIL'
    ) AS result,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`role_permissions` rp
        INNER JOIN `alrowad_uni_rust`.`roles` r
            ON r.role_id = rp.role_id
        INNER JOIN `alrowad_uni_rust`.`permissions` p
            ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'dean'
          AND p.permission_code = 'hr.manage'
    ) AS preexisting_or_current_hr_manage_grants;

SELECT
    'V_dean_courses_manage_not_granted_by_this_foundation' AS check_name,
    IF(
        NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`role_permissions` rp
            INNER JOIN `alrowad_uni_rust`.`roles` r
                ON r.role_id = rp.role_id
            INNER JOIN `alrowad_uni_rust`.`permissions` p
                ON p.permission_id = rp.permission_id
            WHERE r.role_code = 'dean'
              AND p.permission_code = 'courses.manage'
              AND rp.granted_at >= (
                  SELECT MIN(ts.granted_at)
                  FROM `alrowad_uni_rust`.`role_permissions` ts
                  INNER JOIN `alrowad_uni_rust`.`permissions` tp
                      ON tp.permission_id = ts.permission_id
                  WHERE ts.role_id = r.role_id
                    AND tp.permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
              )
        ),
        'PASS',
        'FAIL'
    ) AS result,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`role_permissions` rp
        INNER JOIN `alrowad_uni_rust`.`roles` r
            ON r.role_id = rp.role_id
        INNER JOIN `alrowad_uni_rust`.`permissions` p
            ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'dean'
          AND p.permission_code = 'courses.manage'
    ) AS preexisting_or_current_courses_manage_grants;

SELECT
    'informational_sample_offerings_117_118' AS report_section,
    coi.course_offering_id,
    coi.faculty_member_id,
    coi.instructor_role,
    c.course_id,
    c.theoretical_hours,
    c.practical_hours
FROM `alrowad_uni_rust`.`course_offering_instructors` coi
INNER JOIN `alrowad_uni_rust`.`course_offerings` co
    ON co.course_offering_id = coi.course_offering_id
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
WHERE coi.course_offering_id IN (117, 118)
ORDER BY coi.course_offering_id;

SELECT
    'informational_sample_faculty_3_employee_9' AS report_section,
    fm.faculty_member_id,
    e.employee_id,
    e.employee_number,
    e.organizational_unit_id AS employee_legacy_unit_id,
    eua.organizational_unit_id AS assignment_unit_id,
    eua.is_active AS assignment_is_active,
    p.position_code,
    ep.is_active AS position_is_active,
    col.college_code
FROM `alrowad_uni_rust`.`faculty_members` fm
INNER JOIN `alrowad_uni_rust`.`employees` e
    ON e.employee_id = fm.employee_id
LEFT JOIN `alrowad_uni_rust`.`employee_unit_assignments` eua
    ON eua.employee_id = e.employee_id
   AND eua.is_active = 1
LEFT JOIN `alrowad_uni_rust`.`colleges` col
    ON col.organizational_unit_id = eua.organizational_unit_id
LEFT JOIN `alrowad_uni_rust`.`employee_positions` ep
    ON ep.employee_id = e.employee_id
   AND ep.is_active = 1
LEFT JOIN `alrowad_uni_rust`.`positions` p
    ON p.position_id = ep.position_id
   AND p.position_code = 'INSTRUCTOR'
WHERE fm.faculty_member_id = 3
   OR e.employee_id = 9
   OR e.employee_number = 'TEMP-PROF-2026';

SELECT
    'OVERALL' AS check_name,
    IF(
        (SELECT COUNT(*) FROM `alrowad_uni_rust`.`colleges`
         WHERE college_code = 'FMF' AND organizational_unit_id IS NOT NULL) = 1
        AND (
            SELECT COUNT(*)
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
        ) = 1
        AND EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'course_offering_instructors'
              AND column_name = 'instructor_role'
              AND LOWER(column_type) LIKE '%theoretical%'
              AND LOWER(column_type) LIKE '%practical%'
              AND LOWER(column_type) NOT LIKE '%lead%'
              AND LOWER(column_type) NOT LIKE '%assistant%'
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offering_instructors`
            WHERE instructor_role NOT IN ('theoretical', 'practical')
               OR TRIM(COALESCE(instructor_role, '')) = ''
        )
        AND NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'course_offering_instructors'
              AND index_name = 'uq_course_offering_instructor'
        )
        AND EXISTS (
            SELECT 1
            FROM (
                SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
                FROM information_schema.statistics
                WHERE table_schema = 'alrowad_uni_rust'
                  AND table_name = 'course_offering_instructors'
                  AND index_name = 'uq_course_offering_role'
                  AND non_unique = 0
                GROUP BY index_name
            ) idx
            WHERE idx.columns = 'course_offering_id,instructor_role'
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offering_instructors`
            WHERE instructor_role = 'theoretical'
            GROUP BY course_offering_id
            HAVING COUNT(*) > 1
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offering_instructors`
            WHERE instructor_role = 'practical'
            GROUP BY course_offering_id
            HAVING COUNT(*) > 1
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offering_instructors` coi
            INNER JOIN `alrowad_uni_rust`.`course_offerings` co
                ON co.course_offering_id = coi.course_offering_id
            INNER JOIN `alrowad_uni_rust`.`courses` c
                ON c.course_id = co.course_id
            WHERE (coi.instructor_role = 'theoretical' AND IFNULL(c.theoretical_hours, 0) = 0)
               OR (coi.instructor_role = 'practical' AND IFNULL(c.practical_hours, 0) = 0)
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offering_instructors` coi
            INNER JOIN `alrowad_uni_rust`.`course_offerings` co
                ON co.course_offering_id = coi.course_offering_id
            INNER JOIN `alrowad_uni_rust`.`courses` c
                ON c.course_id = co.course_id
            WHERE coi.is_primary <> CASE
                    WHEN coi.instructor_role = 'theoretical' THEN 1
                    WHEN coi.instructor_role = 'practical' AND IFNULL(c.theoretical_hours, 0) = 0 THEN 1
                    ELSE 0
                END
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offerings` co
            INNER JOIN `alrowad_uni_rust`.`courses` c
                ON c.course_id = co.course_id
            LEFT JOIN `alrowad_uni_rust`.`course_offering_instructors` primary_slot
                ON primary_slot.course_offering_id = co.course_offering_id
               AND primary_slot.is_active = 1
               AND primary_slot.instructor_role = CASE
                    WHEN c.theoretical_hours > 0 THEN 'theoretical'
                    WHEN c.practical_hours > 0 THEN 'practical'
                    ELSE NULL
                END
            WHERE NOT (co.faculty_member_id <=> primary_slot.faculty_member_id)
        )
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`course_offering_instructors` coi
            INNER JOIN `alrowad_uni_rust`.`course_offerings` co
                ON co.course_offering_id = coi.course_offering_id
            WHERE coi.is_active = 1
              AND NOT EXISTS (
                  SELECT 1
                  FROM `alrowad_uni_rust`.`course_instructors` ci
                  WHERE ci.course_id = co.course_id
                    AND ci.faculty_member_id = coi.faculty_member_id
                    AND ci.is_active = 1
              )
        )
        AND NOT EXISTS (
            SELECT 1
            FROM (
                SELECT DISTINCT
                    fm.employee_id,
                    col.organizational_unit_id
                FROM `alrowad_uni_rust`.`course_offering_instructors` coi
                INNER JOIN `alrowad_uni_rust`.`faculty_members` fm
                    ON fm.faculty_member_id = coi.faculty_member_id
                INNER JOIN `alrowad_uni_rust`.`course_offerings` co
                    ON co.course_offering_id = coi.course_offering_id
                LEFT JOIN `alrowad_uni_rust`.`departments` d
                    ON d.department_id = co.department_id
                LEFT JOIN `alrowad_uni_rust`.`academic_programs` ap
                    ON ap.academic_program_id = co.academic_program_id
                LEFT JOIN `alrowad_uni_rust`.`departments` pd
                    ON pd.department_id = ap.department_id
                INNER JOIN `alrowad_uni_rust`.`colleges` col
                    ON col.college_id = COALESCE(d.college_id, pd.college_id)
                WHERE coi.is_active = 1
                  AND col.organizational_unit_id IS NOT NULL
                  AND (d.college_id IS NULL OR pd.college_id IS NULL OR d.college_id = pd.college_id)
            ) expected
            WHERE NOT EXISTS (
                SELECT 1
                FROM `alrowad_uni_rust`.`employee_unit_assignments` eua
                WHERE eua.employee_id = expected.employee_id
                  AND eua.organizational_unit_id = expected.organizational_unit_id
                  AND eua.is_active = 1
            )
        )
        AND NOT EXISTS (
            SELECT 1
            FROM (
                SELECT DISTINCT
                    fm.employee_id,
                    col.organizational_unit_id
                FROM `alrowad_uni_rust`.`course_offering_instructors` coi
                INNER JOIN `alrowad_uni_rust`.`faculty_members` fm
                    ON fm.faculty_member_id = coi.faculty_member_id
                INNER JOIN `alrowad_uni_rust`.`course_offerings` co
                    ON co.course_offering_id = coi.course_offering_id
                LEFT JOIN `alrowad_uni_rust`.`departments` d
                    ON d.department_id = co.department_id
                LEFT JOIN `alrowad_uni_rust`.`academic_programs` ap
                    ON ap.academic_program_id = co.academic_program_id
                LEFT JOIN `alrowad_uni_rust`.`departments` pd
                    ON pd.department_id = ap.department_id
                INNER JOIN `alrowad_uni_rust`.`colleges` col
                    ON col.college_id = COALESCE(d.college_id, pd.college_id)
                WHERE coi.is_active = 1
                  AND col.organizational_unit_id IS NOT NULL
                  AND (d.college_id IS NULL OR pd.college_id IS NULL OR d.college_id = pd.college_id)
            ) expected
            WHERE NOT EXISTS (
                SELECT 1
                FROM `alrowad_uni_rust`.`employee_positions` ep
                INNER JOIN `alrowad_uni_rust`.`positions` p
                    ON p.position_id = ep.position_id
                WHERE ep.employee_id = expected.employee_id
                  AND ep.organizational_unit_id = expected.organizational_unit_id
                  AND ep.is_active = 1
                  AND p.position_code = 'INSTRUCTOR'
            )
        )
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions`
             WHERE permission_code = 'teaching_staff.view') = 1
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions`
             WHERE permission_code = 'teaching_staff.manage') = 1
        AND (
            SELECT COUNT(*)
            FROM `alrowad_uni_rust`.`permissions` p
            INNER JOIN `alrowad_uni_rust`.`system_modules` sm
                ON sm.module_id = p.module_id
            WHERE p.permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
              AND sm.module_code = 'hr'
        ) = 2
        AND (
            SELECT COUNT(*)
            FROM `alrowad_uni_rust`.`role_permissions` rp
            INNER JOIN `alrowad_uni_rust`.`roles` r
                ON r.role_id = rp.role_id
            INNER JOIN `alrowad_uni_rust`.`permissions` p
                ON p.permission_id = rp.permission_id
            WHERE r.role_code = 'dean'
              AND r.is_active = 1
              AND p.permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
        ) = 2
        AND NOT EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`role_permissions` rp
            INNER JOIN `alrowad_uni_rust`.`roles` r
                ON r.role_id = rp.role_id
            INNER JOIN `alrowad_uni_rust`.`permissions` p
                ON p.permission_id = rp.permission_id
            WHERE r.role_code = 'dean'
              AND p.permission_code IN ('hr.manage', 'courses.manage')
              AND rp.granted_at >= (
                  SELECT MIN(ts.granted_at)
                  FROM `alrowad_uni_rust`.`role_permissions` ts
                  INNER JOIN `alrowad_uni_rust`.`permissions` tp
                      ON tp.permission_id = ts.permission_id
                  WHERE ts.role_id = r.role_id
                    AND tp.permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
              )
        ),
        'PASS',
        'FAIL'
    ) AS result;
