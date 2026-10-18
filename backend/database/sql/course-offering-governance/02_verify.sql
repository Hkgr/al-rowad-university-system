-- READ ONLY. Require OVERALL = PASS after 01_apply.sql.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- No data is changed by this script.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
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

SET @index_shape_ok := IF(
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
        1,
        0
    )
);

SET @duplicate_identity_groups := IF(
    @db_ready = 1,
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
    1
);

SET @offering_row_count := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings`),
    NULL
);

SET @null_program_count := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings` WHERE `academic_program_id` IS NULL),
    NULL
);

SET @null_department_count := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offerings` WHERE `department_id` IS NULL),
    NULL
);

SELECT 'unique_index_exists' AS check_name, IF(@index_exists > 0, 'PASS', 'FAIL') AS result;
SELECT 'unique_index_columns' AS check_name, IF(@index_shape_ok = 1, 'PASS', 'FAIL') AS result,
       'course_id, academic_program_id, academic_year_id, semester_id' AS expected_columns;
SELECT 'unique_index_non_unique' AS check_name, IF(@index_shape_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'no_canonical_duplicates' AS check_name, IF(@duplicate_identity_groups = 0, 'PASS', 'FAIL') AS result,
       @duplicate_identity_groups AS duplicate_group_count;
SELECT 'legacy_null_program_rows' AS check_name, 'INFO' AS result, @null_program_count AS row_count;
SELECT 'legacy_null_department_rows' AS check_name, 'INFO' AS result, @null_department_count AS row_count;
SELECT 'offering_row_count' AS check_name, 'INFO' AS result, @offering_row_count AS row_count;

SELECT 'OVERALL' AS report_section,
       IF(@db_ready = 1 AND @index_shape_ok = 1 AND @duplicate_identity_groups = 0, 'PASS', 'FAIL') AS result;
