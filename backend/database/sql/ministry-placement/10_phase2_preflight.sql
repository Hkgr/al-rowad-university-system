-- Ministry Placement Phase 2 compatibility preflight.
-- Read only. Phase 1 objects are an operational prerequisite.

SET @mp2_schema := 'alrowad_uni_rust';

SELECT COUNT(*) = 1 INTO @mp2_database_exists
FROM information_schema.schemata
WHERE schema_name = @mp2_schema;

SELECT COUNT(*) = 16 INTO @mp2_required_tables
FROM information_schema.tables
WHERE table_schema = @mp2_schema
  AND table_type = 'BASE TABLE'
  AND table_name IN (
    'academic_years', 'users', 'account_statuses', 'roles', 'user_roles',
    'role_permissions', 'permissions', 'user_access_scopes', 'organizational_units',
    'user_activity_logs', 'applicants', 'academic_programs', 'departments', 'colleges',
    'ministry_placement_batches', 'ministry_placement_records'
  );

SELECT COUNT(*) = 6 INTO @mp2_record_columns
FROM information_schema.columns
WHERE table_schema = @mp2_schema
  AND table_name = 'ministry_placement_records'
  AND (
    (column_name = 'placement_record_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'batch_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'accepted_preference_text' AND data_type = 'varchar' AND character_maximum_length >= 500 AND is_nullable = 'YES') OR
    (column_name = 'matched_academic_program_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (column_name = 'applicant_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (column_name = 'processing_status' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO')
  );

SELECT COUNT(*) = 6 INTO @mp2_program_columns
FROM information_schema.columns
WHERE table_schema = @mp2_schema
  AND table_name = 'academic_programs'
  AND (
    (column_name = 'academic_program_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'department_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'program_code' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO') OR
    (column_name = 'program_name' AND data_type = 'varchar' AND character_maximum_length >= 200 AND is_nullable = 'NO') OR
    (column_name = 'degree_level' AND data_type = 'varchar' AND character_maximum_length >= 80 AND is_nullable = 'NO') OR
    (column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO')
  );

SELECT COUNT(*) = 5 INTO @mp2_department_columns
FROM information_schema.columns
WHERE table_schema = @mp2_schema
  AND table_name = 'departments'
  AND (
    (column_name = 'department_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'college_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'department_code' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO') OR
    (column_name = 'department_name' AND data_type = 'varchar' AND character_maximum_length >= 200 AND is_nullable = 'NO') OR
    (column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO')
  );

SELECT COUNT(*) = 4 INTO @mp2_college_columns
FROM information_schema.columns
WHERE table_schema = @mp2_schema
  AND table_name = 'colleges'
  AND (
    (column_name = 'college_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'college_code' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO') OR
    (column_name = 'college_name' AND data_type = 'varchar' AND character_maximum_length >= 200 AND is_nullable = 'NO') OR
    (column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO')
  );

SELECT COUNT(*) = 18 INTO @mp2_authorization_columns
FROM information_schema.columns
WHERE table_schema = @mp2_schema
  AND (
    (table_name = 'permissions' AND column_name = 'permission_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'permissions' AND column_name = 'permission_code' AND data_type = 'varchar' AND character_maximum_length >= 120 AND is_nullable = 'NO') OR
    (table_name = 'permissions' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'roles' AND column_name = 'role_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'roles' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'user_roles' AND column_name IN ('user_id', 'role_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_roles' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'role_permissions' AND column_name IN ('role_id', 'permission_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_access_scopes' AND column_name = 'user_access_scope_id' AND data_type IN ('int', 'bigint') AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_access_scopes' AND column_name IN ('user_id', 'scope_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_access_scopes' AND column_name = 'scope_type' AND is_nullable = 'NO' AND ((data_type = 'varchar' AND character_maximum_length >= 20) OR (data_type = 'enum' AND column_type LIKE '%''university''%'))) OR
    (table_name = 'user_access_scopes' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'organizational_units' AND column_name = 'organizational_unit_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'organizational_units' AND column_name = 'unit_code' AND data_type = 'varchar' AND character_maximum_length >= 50) OR
    (table_name = 'organizational_units' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO')
  );

SELECT COUNT(*) = 5 INTO @mp2_account_columns
FROM information_schema.columns
WHERE table_schema = @mp2_schema
  AND (
    (table_name = 'users' AND column_name = 'user_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'users' AND column_name = 'account_status_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'account_statuses' AND column_name = 'account_status_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'account_statuses' AND column_name = 'status_code' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO') OR
    (table_name = 'account_statuses' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO')
  );

SELECT COUNT(*) = 1 INTO @mp2_active_account_status
FROM `alrowad_uni_rust`.`account_statuses`
WHERE status_code = 'active'
  AND is_active = 1;

SELECT COUNT(*) = 6 INTO @mp2_activity_columns
FROM information_schema.columns
WHERE table_schema = @mp2_schema
  AND table_name = 'user_activity_logs'
  AND (
    (column_name = 'activity_log_id' AND data_type = 'bigint' AND is_nullable = 'NO') OR
    (column_name = 'user_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'module_code' AND data_type = 'varchar' AND character_maximum_length >= 80) OR
    (column_name = 'action_code' AND data_type = 'varchar' AND character_maximum_length >= 120) OR
    (column_name = 'description' AND data_type IN ('text', 'mediumtext', 'longtext')) OR
    (column_name = 'created_at' AND data_type = 'timestamp' AND is_nullable = 'NO')
  );

SELECT COUNT(*) = 7 INTO @mp2_required_primary_keys
FROM information_schema.key_column_usage
WHERE constraint_schema = @mp2_schema
  AND constraint_name = 'PRIMARY'
  AND (
    (table_name = 'ministry_placement_batches' AND column_name = 'batch_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'placement_record_id') OR
    (table_name = 'academic_programs' AND column_name = 'academic_program_id') OR
    (table_name = 'departments' AND column_name = 'department_id') OR
    (table_name = 'colleges' AND column_name = 'college_id') OR
    (table_name = 'user_activity_logs' AND column_name = 'activity_log_id') OR
    (table_name = 'user_access_scopes' AND column_name = 'user_access_scope_id')
  );

SELECT COUNT(*) = 12 INTO @mp2_required_foreign_keys
FROM information_schema.key_column_usage
WHERE constraint_schema = @mp2_schema
  AND referenced_table_name IS NOT NULL
  AND (
    (table_name = 'ministry_placement_records' AND column_name = 'batch_id' AND referenced_table_name = 'ministry_placement_batches' AND referenced_column_name = 'batch_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'matched_academic_program_id' AND referenced_table_name = 'academic_programs' AND referenced_column_name = 'academic_program_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'applicant_id' AND referenced_table_name = 'applicants' AND referenced_column_name = 'applicant_id') OR
    (table_name = 'academic_programs' AND column_name = 'department_id' AND referenced_table_name = 'departments' AND referenced_column_name = 'department_id') OR
    (table_name = 'departments' AND column_name = 'college_id' AND referenced_table_name = 'colleges' AND referenced_column_name = 'college_id') OR
    (table_name = 'user_activity_logs' AND column_name = 'user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id') OR
    (table_name = 'user_access_scopes' AND column_name = 'user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id') OR
    (table_name = 'users' AND column_name = 'account_status_id' AND referenced_table_name = 'account_statuses' AND referenced_column_name = 'account_status_id') OR
    (table_name = 'user_roles' AND column_name = 'user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id') OR
    (table_name = 'user_roles' AND column_name = 'role_id' AND referenced_table_name = 'roles' AND referenced_column_name = 'role_id') OR
    (table_name = 'role_permissions' AND column_name = 'role_id' AND referenced_table_name = 'roles' AND referenced_column_name = 'role_id') OR
    (table_name = 'role_permissions' AND column_name = 'permission_id' AND referenced_table_name = 'permissions' AND referenced_column_name = 'permission_id')
  );

SELECT COUNT(*) >= 1 INTO @mp2_matched_program_index
FROM information_schema.statistics
WHERE table_schema = @mp2_schema
  AND table_name = 'ministry_placement_records'
  AND column_name = 'matched_academic_program_id'
  AND seq_in_index = 1;

SELECT COUNT(*) = 2 INTO @mp2_active_permissions
FROM (
  SELECT permission_code
  FROM `alrowad_uni_rust`.`permissions`
  WHERE permission_code IN ('admissions.view', 'admissions.manage')
  GROUP BY permission_code
  HAVING COUNT(*) = 1 AND MAX(is_active = 1) = 1
) required_permissions;

SET @mp2_ready := @mp2_database_exists
  AND @mp2_required_tables
  AND @mp2_record_columns
  AND @mp2_program_columns
  AND @mp2_department_columns
  AND @mp2_college_columns
  AND @mp2_authorization_columns
  AND @mp2_account_columns
  AND @mp2_active_account_status
  AND @mp2_activity_columns
  AND @mp2_required_primary_keys
  AND @mp2_required_foreign_keys
  AND @mp2_matched_program_index
  AND @mp2_active_permissions;

SELECT
  'MATCHING_DATA_READINESS' AS report_section,
  records.batch_id,
  COUNT(*) AS total_records,
  SUM(records.applicant_id IS NULL AND records.processing_status = 'imported' AND records.matched_academic_program_id IS NULL) AS unmatched_records,
  SUM(records.processing_status = 'program_matched' AND records.matched_academic_program_id IS NOT NULL) AS already_matched_records,
  SUM(records.applicant_id IS NOT NULL OR records.processing_status NOT IN ('imported', 'program_matched')) AS locked_or_later_stage_records,
  SUM(TRIM(COALESCE(records.accepted_preference_text, '')) = '') AS missing_preference_records,
  SUM(records.processing_status NOT IN ('imported', 'program_matched', 'applicant_created', 'documents_pending', 'accepted', 'enrolled', 'rejected')) AS unexpected_status_records,
  SUM(records.matched_academic_program_id IS NOT NULL AND (
    programs.academic_program_id IS NULL OR programs.is_active <> 1 OR
    departments.department_id IS NULL OR departments.is_active <> 1 OR
    colleges.college_id IS NULL OR colleges.is_active <> 1
  )) AS inactive_match_records
FROM `alrowad_uni_rust`.`ministry_placement_records` records
LEFT JOIN `alrowad_uni_rust`.`academic_programs` programs
  ON programs.academic_program_id = records.matched_academic_program_id
LEFT JOIN `alrowad_uni_rust`.`departments` departments
  ON departments.department_id = programs.department_id
LEFT JOIN `alrowad_uni_rust`.`colleges` colleges
  ON colleges.college_id = departments.college_id
GROUP BY records.batch_id WITH ROLLUP;

SELECT 'DATABASE_AND_TABLES' AS check_name, IF(@mp2_database_exists AND @mp2_required_tables, 'PASS', 'FAIL') AS result
UNION ALL
SELECT 'PHASE1_RECORD_CONTRACT', IF(@mp2_record_columns, 'PASS', 'FAIL')
UNION ALL
SELECT 'ACTIVE_ACADEMIC_STRUCTURE', IF(@mp2_program_columns AND @mp2_department_columns AND @mp2_college_columns, 'PASS', 'FAIL')
UNION ALL
SELECT 'AUTHORIZATION_AND_AUDIT', IF(@mp2_authorization_columns AND @mp2_account_columns AND @mp2_active_account_status AND @mp2_activity_columns AND @mp2_active_permissions, 'PASS', 'FAIL')
UNION ALL
SELECT 'KEYS_AND_RELATIONSHIPS', IF(@mp2_required_primary_keys AND @mp2_required_foreign_keys AND @mp2_matched_program_index, 'PASS', 'FAIL')
UNION ALL
SELECT 'OVERALL', IF(@mp2_ready, 'READY', 'BLOCKED');
