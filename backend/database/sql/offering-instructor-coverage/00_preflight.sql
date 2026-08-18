-- Phase 5 READ ONLY preflight. Do not CREATE/INSERT/UPDATE/DELETE.
-- Fully qualified objects. Do not use DATABASE().
-- Existing open offerings with incomplete coverage are INFORMATIONAL, not a blocker.
-- Continue only when the final OVERALL row is READY.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SELECT 'database' AS report_section,
       IF(@db_ready = 1, 'READY', 'BLOCKED') AS result,
       'alrowad_uni_rust' AS expected_schema;

SELECT 'tables' AS report_section, table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'courses',
      'course_offerings',
      'course_offering_instructors',
      'faculty_members',
      'employees',
      'employee_statuses',
      'teaching_assignment_requests',
      'teaching_assignment_reviews',
      'teaching_assignment_events'
  )
ORDER BY table_name;

SELECT 'course_hour_columns' AS report_section, column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'courses'
  AND column_name IN ('course_id', 'theoretical_hours', 'practical_hours')
ORDER BY ordinal_position;

SELECT 'offering_columns' AS report_section, column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offerings'
  AND column_name IN ('course_offering_id', 'course_id', 'faculty_member_id', 'status')
ORDER BY ordinal_position;

SELECT 'coi_columns' AS report_section, column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offering_instructors'
  AND column_name IN (
      'course_offering_instructor_id',
      'course_offering_id',
      'faculty_member_id',
      'instructor_role',
      'is_active'
  )
ORDER BY ordinal_position;

SELECT 'unique_indexes' AS report_section, table_name, index_name,
       GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns,
       IF(non_unique = 0, 'UNIQUE', 'NON_UNIQUE') AS uniqueness
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND (
      (table_name = 'course_offering_instructors' AND index_name = 'uq_course_offering_role')
      OR (table_name = 'course_offerings' AND index_name = 'uq_course_offering_program_term')
  )
GROUP BY table_name, index_name, non_unique
ORDER BY table_name, index_name;

SET @courses_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'courses' AND table_type = 'BASE TABLE'), 0);
SET @offerings_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offerings' AND table_type = 'BASE TABLE'), 0);
SET @coi_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_instructors' AND table_type = 'BASE TABLE'), 0);
SET @faculty_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'faculty_members' AND table_type = 'BASE TABLE'), 0);
SET @employees_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'employees' AND table_type = 'BASE TABLE'), 0);
SET @statuses_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'employee_statuses' AND table_type = 'BASE TABLE'), 0);
SET @requests_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND table_type = 'BASE TABLE'), 0);
SET @reviews_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_reviews' AND table_type = 'BASE TABLE'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_events' AND table_type = 'BASE TABLE'), 0);

SET @theory_hours_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'courses' AND column_name = 'theoretical_hours'), 0);
SET @practical_hours_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'courses' AND column_name = 'practical_hours'), 0);
SET @offering_status_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offerings' AND column_name = 'status'), 0);
SET @offering_faculty_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offerings' AND column_name = 'faculty_member_id'), 0);
SET @coi_role_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_instructors' AND column_name = 'instructor_role'), 0);
SET @coi_active_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_instructors' AND column_name = 'is_active'), 0);

SET @role_index := IF(@db_ready = 1, (
    SELECT COUNT(*) FROM (
        SELECT index_name
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_instructors'
          AND index_name = 'uq_course_offering_role'
          AND non_unique = 0
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'course_offering_id,instructor_role'
    ) idx
), 0);
SET @identity_index := IF(@db_ready = 1, (
    SELECT COUNT(*) FROM (
        SELECT index_name
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offerings'
          AND index_name = 'uq_course_offering_program_term'
          AND non_unique = 0
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'course_id,academic_program_id,academic_year_id,semester_id'
    ) idx
), 0);
SET @coi_offering_id_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_instructors' AND column_name = 'course_offering_id'), 0);
SET @coi_faculty_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_instructors' AND column_name = 'faculty_member_id'), 0);
SET @faculty_is_active_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'faculty_members' AND column_name = 'is_active'), 0);
SET @faculty_employee_id_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'faculty_members' AND column_name = 'employee_id'), 0);
SET @employee_status_id_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'employees' AND column_name = 'employee_status_id'), 0);
SET @es_status_code_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'employee_statuses' AND column_name = 'status_code'), 0);
SET @es_is_active_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'employee_statuses' AND column_name = 'is_active'), 0);

SELECT '1_total_offerings' AS report_section,
       IF(@offerings_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings`), NULL) AS offering_count;
SELECT '2_open_offerings' AS report_section,
       IF(@offerings_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings` WHERE status = 'open'), NULL) AS open_count;
SELECT '3_closed_offerings' AS report_section,
       IF(@offerings_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings` WHERE status = 'closed'), NULL) AS closed_count;
SELECT '4_active_course_offering_instructors' AS report_section,
       IF(@coi_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offering_instructors` WHERE is_active = 1), NULL) AS active_coi_count;

SELECT '5_theoretical_only_offerings' AS report_section,
       IF(@offerings_exist = 1 AND @courses_exist = 1, (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`course_offerings` co
           INNER JOIN `alrowad_uni_rust`.`courses` c ON c.course_id = co.course_id
           WHERE IFNULL(c.theoretical_hours, 0) > 0 AND IFNULL(c.practical_hours, 0) <= 0
       ), NULL) AS theoretical_only_count;
SELECT '6_practical_only_offerings' AS report_section,
       IF(@offerings_exist = 1 AND @courses_exist = 1, (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`course_offerings` co
           INNER JOIN `alrowad_uni_rust`.`courses` c ON c.course_id = co.course_id
           WHERE IFNULL(c.theoretical_hours, 0) <= 0 AND IFNULL(c.practical_hours, 0) > 0
       ), NULL) AS practical_only_count;
SELECT '7_both_component_offerings' AS report_section,
       IF(@offerings_exist = 1 AND @courses_exist = 1, (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`course_offerings` co
           INNER JOIN `alrowad_uni_rust`.`courses` c ON c.course_id = co.course_id
           WHERE IFNULL(c.theoretical_hours, 0) > 0 AND IFNULL(c.practical_hours, 0) > 0
       ), NULL) AS both_components_count;
SELECT 'undefined_component_offerings' AS report_section,
       IF(@offerings_exist = 1 AND @courses_exist = 1, (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`course_offerings` co
           INNER JOIN `alrowad_uni_rust`.`courses` c ON c.course_id = co.course_id
           WHERE IFNULL(c.theoretical_hours, 0) <= 0 AND IFNULL(c.practical_hours, 0) <= 0
       ), NULL) AS undefined_components_count,
       'INFORMATIONAL' AS note;

SELECT
    'coverage_audit' AS report_section,
    SUM(CASE WHEN classified.status = 'closed' AND classified.complete = 1 THEN 1 ELSE 0 END) AS closed_complete_count,
    SUM(CASE WHEN classified.status = 'closed' AND classified.complete = 0 THEN 1 ELSE 0 END) AS closed_incomplete_count,
    SUM(CASE WHEN classified.status = 'open' AND classified.complete = 0 THEN 1 ELSE 0 END) AS open_incomplete_count,
    'open_incomplete is INFORMATIONAL and must not block OVERALL' AS note
FROM (
    SELECT
        co.status,
        CASE
            WHEN IFNULL(c.theoretical_hours, 0) <= 0 AND IFNULL(c.practical_hours, 0) <= 0 THEN 0
            WHEN (IFNULL(c.theoretical_hours, 0) > 0 AND covered.theoretical_covered = 0)
              OR (IFNULL(c.practical_hours, 0) > 0 AND covered.practical_covered = 0)
                THEN 0
            ELSE 1
        END AS complete
    FROM `alrowad_uni_rust`.`course_offerings` co
    INNER JOIN `alrowad_uni_rust`.`courses` c ON c.course_id = co.course_id
    LEFT JOIN (
        SELECT
            co2.course_offering_id,
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
                    INNER JOIN `alrowad_uni_rust`.`faculty_members` fm ON fm.faculty_member_id = coi.faculty_member_id
                    INNER JOIN `alrowad_uni_rust`.`employees` e ON e.employee_id = fm.employee_id
                    INNER JOIN `alrowad_uni_rust`.`employee_statuses` es ON es.employee_status_id = e.employee_status_id
                    WHERE coi.course_offering_id = co2.course_offering_id
                      AND coi.instructor_role = 'theoretical'
                      AND coi.is_active = 1
                      AND IFNULL(fm.is_active, 0) = 1
                      AND es.status_code = 'active'
                      AND IFNULL(es.is_active, 0) = 1
                ) THEN 1
                WHEN NOT EXISTS (
                    SELECT 1 FROM `alrowad_uni_rust`.`course_offering_instructors` active_coi
                    WHERE active_coi.course_offering_id = co2.course_offering_id
                      AND active_coi.is_active = 1
                )
                 AND co2.faculty_member_id IS NOT NULL
                 AND IFNULL(c2.theoretical_hours, 0) > 0
                 AND EXISTS (
                    SELECT 1
                    FROM `alrowad_uni_rust`.`faculty_members` lfm
                    INNER JOIN `alrowad_uni_rust`.`employees` le ON le.employee_id = lfm.employee_id
                    INNER JOIN `alrowad_uni_rust`.`employee_statuses` les ON les.employee_status_id = le.employee_status_id
                    WHERE lfm.faculty_member_id = co2.faculty_member_id
                      AND IFNULL(lfm.is_active, 0) = 1
                      AND les.status_code = 'active'
                      AND IFNULL(les.is_active, 0) = 1
                 ) THEN 1
                ELSE 0
            END AS theoretical_covered,
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
                    INNER JOIN `alrowad_uni_rust`.`faculty_members` fm ON fm.faculty_member_id = coi.faculty_member_id
                    INNER JOIN `alrowad_uni_rust`.`employees` e ON e.employee_id = fm.employee_id
                    INNER JOIN `alrowad_uni_rust`.`employee_statuses` es ON es.employee_status_id = e.employee_status_id
                    WHERE coi.course_offering_id = co2.course_offering_id
                      AND coi.instructor_role = 'practical'
                      AND coi.is_active = 1
                      AND IFNULL(fm.is_active, 0) = 1
                      AND es.status_code = 'active'
                      AND IFNULL(es.is_active, 0) = 1
                ) THEN 1
                WHEN NOT EXISTS (
                    SELECT 1 FROM `alrowad_uni_rust`.`course_offering_instructors` active_coi
                    WHERE active_coi.course_offering_id = co2.course_offering_id
                      AND active_coi.is_active = 1
                )
                 AND co2.faculty_member_id IS NOT NULL
                 AND IFNULL(c2.theoretical_hours, 0) <= 0
                 AND IFNULL(c2.practical_hours, 0) > 0
                 AND EXISTS (
                    SELECT 1
                    FROM `alrowad_uni_rust`.`faculty_members` lfm
                    INNER JOIN `alrowad_uni_rust`.`employees` le ON le.employee_id = lfm.employee_id
                    INNER JOIN `alrowad_uni_rust`.`employee_statuses` les ON les.employee_status_id = le.employee_status_id
                    WHERE lfm.faculty_member_id = co2.faculty_member_id
                      AND IFNULL(lfm.is_active, 0) = 1
                      AND les.status_code = 'active'
                      AND IFNULL(les.is_active, 0) = 1
                 ) THEN 1
                ELSE 0
            END AS practical_covered
        FROM `alrowad_uni_rust`.`course_offerings` co2
        INNER JOIN `alrowad_uni_rust`.`courses` c2 ON c2.course_id = co2.course_id
    ) covered ON covered.course_offering_id = co.course_offering_id
) classified;

SELECT '8_closed_complete' AS report_section, 'see coverage_audit.closed_complete_count' AS result;
SELECT '9_closed_incomplete' AS report_section, 'see coverage_audit.closed_incomplete_count' AS result;
SELECT '10_open_incomplete' AS report_section, 'INFORMATIONAL' AS result,
       'Existing open offerings with incomplete coverage must not be closed by this phase' AS note;

SELECT '11_legacy_faculty_member_id_only' AS report_section,
       IF(@offerings_exist = 1 AND @coi_exist = 1, (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`course_offerings` co
           WHERE co.faculty_member_id IS NOT NULL
             AND NOT EXISTS (
                 SELECT 1 FROM `alrowad_uni_rust`.`course_offering_instructors` coi
                 WHERE coi.course_offering_id = co.course_offering_id
                   AND coi.is_active = 1
             )
       ), NULL) AS legacy_pointer_only_count;

SELECT '12_duplicate_active_offering_role' AS report_section,
       IF(@coi_exist = 1, (
           SELECT COUNT(*) FROM (
               SELECT course_offering_id, instructor_role
               FROM `alrowad_uni_rust`.`course_offering_instructors`
               WHERE is_active = 1
               GROUP BY course_offering_id, instructor_role
               HAVING COUNT(*) > 1
           ) dups
       ), NULL) AS duplicate_active_role_groups;

SELECT '13_invalid_inactive_effective_instructors' AS report_section,
       IF(@coi_exist = 1 AND @faculty_exist = 1, (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`course_offering_instructors` coi
           INNER JOIN `alrowad_uni_rust`.`faculty_members` fm ON fm.faculty_member_id = coi.faculty_member_id
           LEFT JOIN `alrowad_uni_rust`.`employees` e ON e.employee_id = fm.employee_id
           LEFT JOIN `alrowad_uni_rust`.`employee_statuses` es ON es.employee_status_id = e.employee_status_id
           WHERE coi.is_active = 1
             AND (
                 IFNULL(fm.is_active, 0) <> 1
                 OR e.employee_id IS NULL
                 OR es.status_code <> 'active'
                 OR IFNULL(es.is_active, 0) <> 1
             )
       ), NULL) AS invalid_effective_count,
       'INFORMATIONAL: opening fails closed for these roles; do not delete rows' AS note;

SELECT '14_phase4_tables' AS report_section,
       IF(@requests_exist = 1 AND @reviews_exist = 1 AND @events_exist = 1, 'PRESENT', 'MISSING') AS result,
       @requests_exist AS teaching_assignment_requests,
       @reviews_exist AS teaching_assignment_reviews,
       @events_exist AS teaching_assignment_events;

SELECT '15_uq_course_offering_role' AS report_section,
       IF(@role_index = 1, 'PRESENT', 'MISSING') AS result,
       'course_offering_id,instructor_role' AS expected_columns;

SELECT '16_uq_course_offering_program_term' AS report_section,
       IF(@identity_index = 1, 'PRESENT', 'MISSING') AS result,
       'course_id,academic_program_id,academic_year_id,semester_id' AS expected_columns,
       IFNULL((
           SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')
           FROM information_schema.statistics
           WHERE table_schema = 'alrowad_uni_rust'
             AND table_name = 'course_offerings'
             AND index_name = 'uq_course_offering_program_term'
       ), '') AS actual_columns,
       IFNULL((
           SELECT IF(MAX(non_unique) = 0, 'UNIQUE', 'NON_UNIQUE')
           FROM information_schema.statistics
           WHERE table_schema = 'alrowad_uni_rust'
             AND table_name = 'course_offerings'
             AND index_name = 'uq_course_offering_program_term'
       ), 'ABSENT') AS uniqueness;

SELECT 'structural_prerequisites' AS report_section,
       CASE
           WHEN @db_ready = 1
            AND @courses_exist = 1
            AND @offerings_exist = 1
            AND @coi_exist = 1
            AND @faculty_exist = 1
            AND @employees_exist = 1
            AND @statuses_exist = 1
            AND @requests_exist = 1
            AND @reviews_exist = 1
            AND @events_exist = 1
            AND @theory_hours_col = 1
            AND @practical_hours_col = 1
            AND @offering_status_col = 1
            AND @offering_faculty_col = 1
            AND @coi_offering_id_col = 1
            AND @coi_faculty_col = 1
            AND @coi_role_col = 1
            AND @coi_active_col = 1
            AND @faculty_is_active_col = 1
            AND @faculty_employee_id_col = 1
            AND @employee_status_id_col = 1
            AND @es_status_code_col = 1
            AND @es_is_active_col = 1
            AND @role_index = 1
            AND @identity_index = 1
               THEN 'READY'
           ELSE 'BLOCKED'
       END AS result;

SELECT
    'OVERALL' AS report_section,
    CASE
        WHEN @db_ready = 1
         AND @courses_exist = 1
         AND @offerings_exist = 1
         AND @coi_exist = 1
         AND @faculty_exist = 1
         AND @employees_exist = 1
         AND @statuses_exist = 1
         AND @requests_exist = 1
         AND @reviews_exist = 1
         AND @events_exist = 1
         AND @theory_hours_col = 1
         AND @practical_hours_col = 1
         AND @offering_status_col = 1
         AND @offering_faculty_col = 1
         AND @coi_offering_id_col = 1
         AND @coi_faculty_col = 1
         AND @coi_role_col = 1
         AND @coi_active_col = 1
         AND @faculty_is_active_col = 1
         AND @faculty_employee_id_col = 1
         AND @employee_status_id_col = 1
         AND @es_status_code_col = 1
         AND @es_is_active_col = 1
         AND @role_index = 1
         AND @identity_index = 1
            THEN 'READY'
        ELSE 'BLOCKED'
    END AS result;
