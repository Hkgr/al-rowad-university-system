-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE. SET user variables only.
-- BLOCK only when the unique index cannot safely be applied.
-- Legacy NULL academic_program_id / department_id is reported, not a blocker.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @missing_required_columns := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'course_offerings' AS table_name, 'course_offering_id' AS column_name
            UNION ALL SELECT 'course_offerings', 'course_id'
            UNION ALL SELECT 'course_offerings', 'academic_program_id'
            UNION ALL SELECT 'course_offerings', 'academic_year_id'
            UNION ALL SELECT 'course_offerings', 'semester_id'
            UNION ALL SELECT 'course_offerings', 'department_id'
            UNION ALL SELECT 'course_offerings', 'status'
            UNION ALL SELECT 'academic_programs', 'academic_program_id'
            UNION ALL SELECT 'academic_programs', 'department_id'
            UNION ALL SELECT 'departments', 'department_id'
            UNION ALL SELECT 'departments', 'college_id'
            UNION ALL SELECT 'colleges', 'college_id'
            UNION ALL SELECT 'program_courses', 'program_course_id'
            UNION ALL SELECT 'program_courses', 'academic_program_id'
            UNION ALL SELECT 'program_courses', 'course_id'
            UNION ALL SELECT 'program_courses', 'is_active'
            UNION ALL SELECT 'student_course_registrations', 'student_course_registration_id'
            UNION ALL SELECT 'student_course_registrations', 'course_offering_id'
            UNION ALL SELECT 'attendance_sessions', 'attendance_session_id'
            UNION ALL SELECT 'attendance_sessions', 'course_offering_id'
            UNION ALL SELECT 'student_course_results', 'student_course_result_id'
            UNION ALL SELECT 'student_course_results', 'student_course_registration_id'
        ) required_columns
        LEFT JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = required_columns.table_name
           AND existing.column_name = required_columns.column_name
        WHERE existing.column_name IS NULL
    ),
    1
);

SET @duplicate_identity_groups := IF(
    @db_ready = 1 AND @missing_required_columns = 0,
    (
        SELECT COUNT(*)
        FROM (
            SELECT `course_id`, `academic_program_id`, `academic_year_id`, `semester_id`
            FROM `alrowad_uni_rust`.`course_offerings`
            WHERE `academic_program_id` IS NOT NULL
            GROUP BY `course_id`, `academic_program_id`, `academic_year_id`, `semester_id`
            HAVING COUNT(*) > 1
        ) duplicates
    ),
    0
);

SET @index_exists := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offerings'
          AND index_name = 'uq_course_offering_program_term'
    ),
    0
);

SET @index_column_mismatch := IF(
    @index_exists = 0,
    0,
    IF(
        (
            SELECT COUNT(*)
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'course_offerings'
              AND index_name = 'uq_course_offering_program_term'
              AND (
                    (seq_in_index = 1 AND column_name = 'course_id')
                 OR (seq_in_index = 2 AND column_name = 'academic_program_id')
                 OR (seq_in_index = 3 AND column_name = 'academic_year_id')
                 OR (seq_in_index = 4 AND column_name = 'semester_id')
              )
              AND non_unique = 0
        ) = 4
        AND (
            SELECT COUNT(*)
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'course_offerings'
              AND index_name = 'uq_course_offering_program_term'
        ) = 4,
        0,
        1
    )
);

SET @blocked := IF(
    @db_ready = 0 OR @missing_required_columns > 0 OR @duplicate_identity_groups > 0 OR @index_column_mismatch = 1,
    1,
    0
);

SELECT 'required_course_offering_columns' AS report_section, column_name, column_type, is_nullable, column_key
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offerings'
  AND column_name IN (
      'course_offering_id', 'course_id', 'academic_year_id', 'semester_id',
      'department_id', 'academic_program_id', 'faculty_member_id',
      'capacity', 'available_seats', 'status'
  )
ORDER BY ordinal_position;

SELECT 'current_indexes' AS report_section, index_name, non_unique, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offerings'
ORDER BY index_name, seq_in_index;

SELECT 'canonical_duplicate_groups' AS report_section,
       @duplicate_identity_groups AS duplicate_group_count,
       IF(@duplicate_identity_groups = 0, 'NONE', 'PRESENT') AS result;

SELECT 'canonical_duplicate_rows' AS report_section,
       `course_id`, `academic_program_id`, `academic_year_id`, `semester_id`, COUNT(*) AS row_count
FROM `alrowad_uni_rust`.`course_offerings`
WHERE `academic_program_id` IS NOT NULL
GROUP BY `course_id`, `academic_program_id`, `academic_year_id`, `semester_id`
HAVING COUNT(*) > 1;

SELECT 'department_mismatch_offerings' AS report_section, COUNT(*) AS row_count
FROM `alrowad_uni_rust`.`course_offerings` offerings
JOIN `alrowad_uni_rust`.`academic_programs` programs
  ON programs.`academic_program_id` = offerings.`academic_program_id`
WHERE offerings.`department_id` IS NOT NULL
  AND programs.`department_id` IS NOT NULL
  AND offerings.`department_id` <> programs.`department_id`;

SELECT 'null_academic_program_id' AS report_section, COUNT(*) AS row_count
FROM `alrowad_uni_rust`.`course_offerings`
WHERE `academic_program_id` IS NULL;

SELECT 'null_department_id' AS report_section, COUNT(*) AS row_count
FROM `alrowad_uni_rust`.`course_offerings`
WHERE `department_id` IS NULL;

SELECT 'program_missing_department_or_college' AS report_section, COUNT(*) AS row_count
FROM `alrowad_uni_rust`.`course_offerings` offerings
LEFT JOIN `alrowad_uni_rust`.`academic_programs` programs
  ON programs.`academic_program_id` = offerings.`academic_program_id`
LEFT JOIN `alrowad_uni_rust`.`departments` departments
  ON departments.`department_id` = programs.`department_id`
LEFT JOIN `alrowad_uni_rust`.`colleges` colleges
  ON colleges.`college_id` = departments.`college_id`
WHERE offerings.`academic_program_id` IS NOT NULL
  AND (programs.`academic_program_id` IS NULL
    OR programs.`department_id` IS NULL
    OR departments.`department_id` IS NULL
    OR departments.`college_id` IS NULL
    OR colleges.`college_id` IS NULL);

SELECT 'not_in_active_program_course' AS report_section, COUNT(*) AS row_count
FROM `alrowad_uni_rust`.`course_offerings` offerings
WHERE offerings.`academic_program_id` IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`program_courses` program_courses
      WHERE program_courses.`academic_program_id` = offerings.`academic_program_id`
        AND program_courses.`course_id` = offerings.`course_id`
        AND program_courses.`is_active` = 1
  );

SELECT 'target_unique_index' AS report_section,
       IF(@index_exists > 0, 'EXISTS', 'MISSING') AS index_presence,
       IF(@index_column_mismatch = 1, 'COLUMN_MISMATCH', 'OK') AS index_shape;

SELECT 'legacy_incomplete_dependent_counts' AS report_section,
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings`
         WHERE `academic_program_id` IS NULL OR `department_id` IS NULL) AS incomplete_offerings,
       (SELECT COUNT(*)
          FROM `alrowad_uni_rust`.`student_course_registrations` registrations
          JOIN `alrowad_uni_rust`.`course_offerings` offerings
            ON offerings.`course_offering_id` = registrations.`course_offering_id`
         WHERE offerings.`academic_program_id` IS NULL OR offerings.`department_id` IS NULL) AS registrations,
       (SELECT COUNT(*)
          FROM `alrowad_uni_rust`.`attendance_sessions` sessions
          JOIN `alrowad_uni_rust`.`course_offerings` offerings
            ON offerings.`course_offering_id` = sessions.`course_offering_id`
         WHERE offerings.`academic_program_id` IS NULL OR offerings.`department_id` IS NULL) AS attendance_sessions,
       (SELECT COUNT(*)
          FROM `alrowad_uni_rust`.`student_course_results` results
          JOIN `alrowad_uni_rust`.`student_course_registrations` registrations
            ON registrations.`student_course_registration_id` = results.`student_course_registration_id`
          JOIN `alrowad_uni_rust`.`course_offerings` offerings
            ON offerings.`course_offering_id` = registrations.`course_offering_id`
         WHERE offerings.`academic_program_id` IS NULL OR offerings.`department_id` IS NULL) AS course_results;

SELECT 'blocker_flags' AS report_section,
       @db_ready AS db_ready,
       @missing_required_columns AS missing_required_columns,
       @duplicate_identity_groups AS duplicate_identity_groups,
       @index_column_mismatch AS index_column_mismatch;

SELECT 'OVERALL' AS report_section,
       IF(@blocked = 0, 'READY', 'BLOCKED') AS result,
       @blocked AS blocker_count;
