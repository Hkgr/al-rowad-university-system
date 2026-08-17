-- OPTIONAL MANUAL ROLLBACK: drops only uq_course_offering_program_term.
-- No data is changed. Safe to skip if the index was never applied.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not use stored procedures, DELIMITER, or SIGNAL.

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

SELECT 'ROLLBACK_GUARD' AS report_section,
       IF(@index_exists > 0, 'WILL_DROP_INDEX', 'NOTHING_TO_DROP') AS result,
       @index_exists AS index_exists;

SET @ddl_sql := IF(
    @index_exists > 0,
    'ALTER TABLE `alrowad_uni_rust`.`course_offerings` DROP INDEX `uq_course_offering_program_term`',
    'SELECT ''SKIPPED'' AS rollback_result'
);

PREPARE course_offering_governance_rollback_stmt FROM @ddl_sql;
EXECUTE course_offering_governance_rollback_stmt;
DEALLOCATE PREPARE course_offering_governance_rollback_stmt;
