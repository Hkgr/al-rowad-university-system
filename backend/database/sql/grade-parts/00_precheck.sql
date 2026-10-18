-- Read-only precheck. Review every row before running 01_apply.sql.
SELECT 'database_version' AS check_name, VERSION() AS observed_value,
       CASE WHEN VERSION() REGEXP '^8\\.' OR (VERSION() LIKE '%MariaDB%' AND VERSION() REGEXP '^(10|11)\\.') THEN 'PASS' ELSE 'REVIEW' END AS result;

SELECT required.object_name, required.column_name,
       COALESCE(c.COLUMN_TYPE, 'MISSING') AS observed_type,
       CASE WHEN c.COLUMN_NAME IS NULL THEN 'FAIL' ELSE 'PASS' END AS result
FROM (
  SELECT 'users' object_name, 'user_id' column_name UNION ALL
  SELECT 'course_offerings', 'course_offering_id' UNION ALL
  SELECT 'grade_approvals', 'grade_approval_id' UNION ALL
  SELECT 'grade_approvals', 'course_offering_id' UNION ALL
  SELECT 'grade_approvals', 'approval_status_id' UNION ALL
  SELECT 'approval_statuses', 'approval_status_id' UNION ALL
  SELECT 'approval_statuses', 'status_code' UNION ALL
  SELECT 'grade_components', 'course_offering_id' UNION ALL
  SELECT 'grade_components', 'component_type' UNION ALL
  SELECT 'grade_components', 'is_required'
) required
LEFT JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = DATABASE()
 AND c.TABLE_NAME = required.object_name AND c.COLUMN_NAME = required.column_name;

SELECT 'foreign_key_type_compatibility' AS check_name,
       u.COLUMN_TYPE AS users_key_type, co.COLUMN_TYPE AS offering_key_type,
       CASE WHEN u.DATA_TYPE = 'int' AND co.DATA_TYPE = 'int'
                  AND u.COLUMN_TYPE = co.COLUMN_TYPE THEN 'PASS' ELSE 'FAIL' END AS result
FROM information_schema.COLUMNS u
JOIN information_schema.COLUMNS co ON co.TABLE_SCHEMA = u.TABLE_SCHEMA
WHERE u.TABLE_SCHEMA = DATABASE() AND u.TABLE_NAME = 'users' AND u.COLUMN_NAME = 'user_id'
  AND co.TABLE_NAME = 'course_offerings' AND co.COLUMN_NAME = 'course_offering_id';

SELECT 'new_tables_absent_or_complete' AS check_name,
       SUM(TABLE_NAME = 'grade_part_approvals') AS approvals_table,
       SUM(TABLE_NAME = 'grade_part_approval_events') AS events_table,
       CASE SUM(TABLE_NAME IN ('grade_part_approvals','grade_part_approval_events'))
            WHEN 0 THEN 'PASS' WHEN 2 THEN 'REVIEW' ELSE 'FAIL' END AS result
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('grade_part_approvals','grade_part_approval_events');
