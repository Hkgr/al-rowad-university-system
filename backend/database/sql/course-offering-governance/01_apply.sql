-- Manual and idempotent. Fail-closed: DDL runs only when @apply_ready = 1.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- DDL commits implicitly in MariaDB; do not wrap this file in a transaction.
-- Do not use stored procedures, DELIMITER, or SIGNAL.
-- Independently recomputes the same critical safety conditions as 00_preflight.sql.
-- Does not rewrite data. Adds only uq_course_offering_program_term when missing.

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
            SELECT 'course_offerings' AS table_name, 'course_id' AS column_name
            UNION ALL SELECT 'course_offerings', 'academic_program_id'
            UNION ALL SELECT 'course_offerings', 'academic_year_id'
            UNION ALL SELECT 'course_offerings', 'semester_id'
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
    1
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

SET @apply_ready := IF(
    @db_ready = 1
    AND @missing_required_columns = 0
    AND @duplicate_identity_groups = 0
    AND @index_column_mismatch = 0,
    1,
    0
);

SELECT 'APPLY_GUARD' AS report_section,
       IF(@apply_ready = 1, 'READY', 'BLOCKED') AS result,
       @apply_ready AS apply_ready,
       @missing_required_columns AS missing_required_columns,
       @duplicate_identity_groups AS duplicate_identity_groups,
       @index_exists AS index_exists,
       @index_column_mismatch AS index_column_mismatch;

SET @ddl_sql := IF(
    @apply_ready = 1 AND @index_exists = 0,
    'ALTER TABLE `alrowad_uni_rust`.`course_offerings` ADD UNIQUE INDEX `uq_course_offering_program_term` (`course_id`, `academic_program_id`, `academic_year_id`, `semester_id`)',
    'SELECT ''SKIPPED'' AS apply_result, @apply_ready AS apply_ready, @index_exists AS index_exists'
);

PREPARE course_offering_governance_stmt FROM @ddl_sql;
EXECUTE course_offering_governance_stmt;
DEALLOCATE PREPARE course_offering_governance_stmt;
