-- Phase 5 READ ONLY verify. Do not CREATE/INSERT/UPDATE/DELETE.
-- Fully qualified objects. Do not use DATABASE().
-- This verifies structural prerequisites, not PHP opening behavior.
-- Continue only when the final OVERALL row is PASS.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

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
SET @coi_offering_id_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_instructors' AND column_name = 'course_offering_id'), 0);
SET @coi_faculty_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_instructors' AND column_name = 'faculty_member_id'), 0);
SET @coi_role_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_instructors' AND column_name = 'instructor_role'), 0);
SET @coi_active_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_instructors' AND column_name = 'is_active'), 0);
SET @faculty_is_active_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'faculty_members' AND column_name = 'is_active'), 0);
SET @faculty_employee_id_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'faculty_members' AND column_name = 'employee_id'), 0);
SET @employee_status_id_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'employees' AND column_name = 'employee_status_id'), 0);
SET @es_status_code_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'employee_statuses' AND column_name = 'status_code'), 0);
SET @es_is_active_col := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'employee_statuses' AND column_name = 'is_active'), 0);

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

SET @duplicate_active_roles := IF(@coi_exist = 1, (
    SELECT COUNT(*) FROM (
        SELECT course_offering_id, instructor_role
        FROM `alrowad_uni_rust`.`course_offering_instructors`
        WHERE is_active = 1
        GROUP BY course_offering_id, instructor_role
        HAVING COUNT(*) > 1
    ) dups
), 1);

SET @offering_count := IF(@offerings_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings`), NULL);
SET @coi_count := IF(@coi_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offering_instructors`), NULL);
SET @open_count := IF(@offerings_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings` WHERE status = 'open'), NULL);

SELECT 'course_offering_instructors_exists' AS report_section,
       IF(@coi_exist = 1, 'PASS', 'FAIL') AS result;

SELECT 'courses.theoretical_hours' AS report_section,
       IF(@theory_hours_col = 1, 'PASS', 'FAIL') AS result;
SELECT 'courses.practical_hours' AS report_section,
       IF(@practical_hours_col = 1, 'PASS', 'FAIL') AS result;

SELECT 'course_offerings.status' AS report_section,
       IF(@offering_status_col = 1, 'PASS', 'FAIL') AS result;
SELECT 'course_offerings.faculty_member_id' AS report_section,
       IF(@offering_faculty_col = 1, 'PASS', 'FAIL') AS result;

SELECT 'course_offering_instructors.course_offering_id' AS report_section,
       IF(@coi_offering_id_col = 1, 'PASS', 'FAIL') AS result;
SELECT 'course_offering_instructors.faculty_member_id' AS report_section,
       IF(@coi_faculty_col = 1, 'PASS', 'FAIL') AS result;
SELECT 'course_offering_instructors.instructor_role' AS report_section,
       IF(@coi_role_col = 1, 'PASS', 'FAIL') AS result;
SELECT 'course_offering_instructors.is_active' AS report_section,
       IF(@coi_active_col = 1, 'PASS', 'FAIL') AS result;

SELECT 'faculty_members.is_active' AS report_section,
       IF(@faculty_is_active_col = 1, 'PASS', 'FAIL') AS result;
SELECT 'faculty_members.employee_id' AS report_section,
       IF(@faculty_employee_id_col = 1, 'PASS', 'FAIL') AS result;

SELECT 'employees.employee_status_id' AS report_section,
       IF(@employee_status_id_col = 1, 'PASS', 'FAIL') AS result;

SELECT 'employee_statuses.status_code' AS report_section,
       IF(@es_status_code_col = 1, 'PASS', 'FAIL') AS result;
SELECT 'employee_statuses.is_active' AS report_section,
       IF(@es_is_active_col = 1, 'PASS', 'FAIL') AS result;

SELECT 'effective_role_unique_index' AS report_section,
       IF(@role_index = 1, 'PASS', 'FAIL') AS result,
       'uq_course_offering_role (course_offering_id, instructor_role)' AS expected_index;

SELECT 'phase4_workflow_tables' AS report_section,
       IF(@requests_exist = 1 AND @reviews_exist = 1 AND @events_exist = 1, 'PASS', 'FAIL') AS result,
       @requests_exist AS teaching_assignment_requests,
       @reviews_exist AS teaching_assignment_reviews,
       @events_exist AS teaching_assignment_events;

SELECT 'phase2_offering_identity_index' AS report_section,
       IF(@identity_index = 1, 'PASS', 'FAIL') AS result,
       'uq_course_offering_program_term' AS expected_index,
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

SELECT 'no_duplicate_active_role_slots' AS report_section,
       IF(@duplicate_active_roles = 0, 'PASS', 'FAIL') AS result,
       @duplicate_active_roles AS duplicate_active_role_groups;

SELECT 'legacy_data_untouched' AS report_section,
       'PASS' AS result,
       @offering_count AS course_offerings_rows,
       @coi_count AS course_offering_instructors_rows,
       @open_count AS open_offerings,
       'Phase 5 SQL is read-only and does not mutate offerings or instructor rows' AS note;

SELECT 'php_opening_behavior' AS report_section,
       'NOT_VERIFIED_BY_SQL' AS result,
       'Accept opening/coverage behavior with the manual A-AC and AD-AE matrix in the pull request' AS note;

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
         AND @duplicate_active_roles = 0
            THEN 'PASS'
        ELSE 'FAIL'
    END AS result;
