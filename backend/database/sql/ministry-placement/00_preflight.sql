-- Ministry Placement Phase 1 compatibility preflight.
-- Read only. Run in phpMyAdmin and continue only when the final row is OVERALL | READY.

SET @mp_schema := 'alrowad_uni_rust';

SELECT COUNT(*) = 1 INTO @mp_database_exists
FROM information_schema.schemata
WHERE schema_name = @mp_schema;

SELECT COUNT(*) = 7 INTO @mp_required_tables
FROM information_schema.tables
WHERE table_schema = @mp_schema
  AND table_type = 'BASE TABLE'
  AND table_name IN (
    'academic_years', 'users', 'permissions', 'user_access_scopes', 'user_activity_logs',
    'ministry_placement_batches', 'ministry_placement_records'
  );

SELECT COUNT(*) = 3 INTO @mp_permission_required_columns
FROM information_schema.columns
WHERE table_schema = @mp_schema
  AND table_name = 'permissions'
  AND (
    (column_name = 'permission_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'permission_code' AND data_type = 'varchar' AND character_maximum_length >= 120 AND is_nullable = 'NO') OR
    (column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO')
  );

SET @mp_permissions_query := IF(
  @mp_permission_required_columns,
  'SELECT COUNT(*) = 2 INTO @mp_required_active_permissions FROM (SELECT permission_code FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN (''admissions.view'', ''admissions.manage'') GROUP BY permission_code HAVING COUNT(*) = 1 AND MAX(is_active = 1) = 1) AS required_active_permissions',
  'SELECT 0 INTO @mp_required_active_permissions'
);
PREPARE mp_permissions_statement FROM @mp_permissions_query;
EXECUTE mp_permissions_statement;
DEALLOCATE PREPARE mp_permissions_statement;

SELECT COUNT(*) = 5 INTO @mp_scope_required_columns
FROM information_schema.columns
WHERE table_schema = @mp_schema
  AND table_name = 'user_access_scopes'
  AND (
    (column_name = 'user_access_scope_id' AND data_type IN ('int', 'bigint') AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO' AND extra LIKE '%auto_increment%') OR
    (column_name = 'user_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'scope_type' AND is_nullable = 'NO' AND (
      (data_type = 'varchar' AND character_maximum_length >= 20) OR
      (data_type = 'enum' AND column_type LIKE '%''university''%')
    )) OR
    (column_name = 'scope_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO')
  );

SELECT COUNT(*) = 9 INTO @mp_batch_required_columns
FROM information_schema.columns
WHERE table_schema = @mp_schema
  AND table_name = 'ministry_placement_batches'
  AND (
    (column_name = 'batch_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO' AND extra LIKE '%auto_increment%') OR
    (column_name = 'batch_name' AND data_type = 'varchar' AND character_maximum_length >= 255 AND is_nullable = 'NO') OR
    (column_name = 'source_file_name' AND data_type = 'varchar' AND character_maximum_length >= 255 AND is_nullable = 'YES') OR
    (column_name = 'academic_year_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'import_date' AND data_type = 'date' AND is_nullable = 'NO') OR
    (column_name = 'imported_by_user_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (column_name = 'notes' AND data_type IN ('text', 'mediumtext', 'longtext') AND is_nullable = 'YES') OR
    (column_name = 'created_at' AND data_type = 'timestamp' AND is_nullable = 'NO' AND column_default IS NOT NULL) OR
    (column_name = 'updated_at' AND data_type = 'timestamp' AND is_nullable = 'NO' AND column_default IS NOT NULL)
  );

SELECT COUNT(*) = 31 INTO @mp_record_required_columns
FROM information_schema.columns
WHERE table_schema = @mp_schema
  AND table_name = 'ministry_placement_records'
  AND (
    (column_name = 'placement_record_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO' AND extra LIKE '%auto_increment%') OR
    (column_name = 'batch_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'row_number' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (column_name = 'national_civil_id' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'YES') OR
    (column_name = 'subscription_number' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'YES') OR
    (column_name IN ('first_name', 'last_name') AND data_type = 'varchar' AND character_maximum_length >= 100 AND is_nullable = 'NO') OR
    (column_name IN ('father_name', 'mother_name', 'nationality', 'certificate_source_country', 'directorate', 'track', 'registration_type') AND data_type = 'varchar' AND character_maximum_length >= 100 AND is_nullable = 'YES') OR
    (column_name = 'date_of_birth' AND data_type = 'date' AND is_nullable = 'YES') OR
    (column_name = 'gender' AND data_type = 'varchar' AND character_maximum_length >= 20 AND is_nullable = 'YES') OR
    (column_name = 'phone_number' AND data_type = 'varchar' AND character_maximum_length >= 30 AND is_nullable = 'YES') OR
    (column_name = 'email' AND data_type = 'varchar' AND character_maximum_length >= 150 AND is_nullable = 'YES') OR
    (column_name IN ('certificate_type', 'placement_round_name') AND data_type = 'varchar' AND character_maximum_length >= 255 AND is_nullable = 'YES') OR
    (column_name = 'certificate_grant_year' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (column_name IN ('total_score', 'max_total_score') AND data_type = 'decimal' AND numeric_precision = 6 AND numeric_scale = 3 AND is_nullable = 'YES') OR
    (column_name = 'accepted_preference_text' AND data_type = 'varchar' AND character_maximum_length >= 500 AND is_nullable = 'YES') OR
    (column_name IN ('matched_academic_program_id', 'applicant_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (column_name IN ('is_faculty_member_child', 'has_academic_sequence') AND data_type = 'tinyint' AND is_nullable = 'NO' AND column_default IN ('0', 0)) OR
    (column_name = 'processing_status' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO' AND column_default = 'imported') OR
    (column_name IN ('created_at', 'updated_at') AND data_type = 'timestamp' AND is_nullable = 'NO' AND column_default IS NOT NULL)
  );

SELECT COUNT(*) = 6 INTO @mp_activity_required_columns
FROM information_schema.columns
WHERE table_schema = @mp_schema
  AND table_name = 'user_activity_logs'
  AND (
    (column_name = 'activity_log_id' AND data_type = 'bigint' AND is_nullable = 'NO' AND extra LIKE '%auto_increment%') OR
    (column_name = 'user_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'module_code' AND data_type = 'varchar' AND character_maximum_length >= 80 AND is_nullable = 'YES') OR
    (column_name = 'action_code' AND data_type = 'varchar' AND character_maximum_length >= 120 AND is_nullable = 'YES') OR
    (column_name = 'description' AND data_type IN ('text', 'mediumtext', 'longtext') AND is_nullable = 'YES') OR
    (column_name = 'created_at' AND data_type = 'timestamp' AND is_nullable = 'NO')
  );

SELECT COUNT(*) = 4 INTO @mp_parent_identity_types
FROM information_schema.columns
WHERE table_schema = @mp_schema
  AND (
    (table_name = 'academic_years' AND column_name = 'academic_year_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%') OR
    (table_name = 'users' AND column_name = 'user_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%') OR
    (table_name = 'academic_programs' AND column_name = 'academic_program_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%') OR
    (table_name = 'applicants' AND column_name = 'applicant_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%')
  );

SELECT COUNT(*) = 3 INTO @mp_primary_keys
FROM information_schema.key_column_usage
WHERE constraint_schema = @mp_schema
  AND constraint_name = 'PRIMARY'
  AND (
    (table_name = 'ministry_placement_batches' AND column_name = 'batch_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'placement_record_id') OR
    (table_name = 'user_activity_logs' AND column_name = 'activity_log_id')
  );

SELECT COUNT(*) = 2 INTO @mp_authorization_primary_keys
FROM information_schema.key_column_usage
WHERE constraint_schema = @mp_schema
  AND constraint_name = 'PRIMARY'
  AND (
    (table_name = 'permissions' AND column_name = 'permission_id') OR
    (table_name = 'user_access_scopes' AND column_name = 'user_access_scope_id')
  );

SELECT COUNT(*) = 1 INTO @mp_scope_user_foreign_key
FROM information_schema.key_column_usage
WHERE constraint_schema = @mp_schema
  AND table_name = 'user_access_scopes'
  AND column_name = 'user_id'
  AND referenced_table_name = 'users'
  AND referenced_column_name = 'user_id';

SELECT COUNT(*) = 1 INTO @mp_batch_unique_identifier
FROM (
  SELECT index_name
  FROM information_schema.statistics
  WHERE table_schema = @mp_schema
    AND table_name = 'ministry_placement_records'
    AND non_unique = 0
  GROUP BY index_name
  HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'national_civil_id,batch_id'
) AS required_unique_index;

SELECT COUNT(*) = 6 INTO @mp_required_foreign_keys
FROM information_schema.key_column_usage
WHERE constraint_schema = @mp_schema
  AND referenced_table_name IS NOT NULL
  AND (
    (table_name = 'ministry_placement_batches' AND column_name = 'academic_year_id' AND referenced_table_name = 'academic_years' AND referenced_column_name = 'academic_year_id') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'imported_by_user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'batch_id' AND referenced_table_name = 'ministry_placement_batches' AND referenced_column_name = 'batch_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'matched_academic_program_id' AND referenced_table_name = 'academic_programs' AND referenced_column_name = 'academic_program_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'applicant_id' AND referenced_table_name = 'applicants' AND referenced_column_name = 'applicant_id') OR
    (table_name = 'user_activity_logs' AND column_name = 'user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id')
  );

SET @mp_ready := @mp_database_exists
  AND @mp_required_tables
  AND @mp_permission_required_columns
  AND @mp_required_active_permissions
  AND @mp_scope_required_columns
  AND @mp_batch_required_columns
  AND @mp_record_required_columns
  AND @mp_activity_required_columns
  AND @mp_parent_identity_types
  AND @mp_primary_keys
  AND @mp_authorization_primary_keys
  AND @mp_scope_user_foreign_key
  AND @mp_batch_unique_identifier
  AND @mp_required_foreign_keys;

-- Informational provisioning report only. This does not change @mp_ready:
-- schema compatibility and operator assignment are deliberately separate.
SELECT COUNT(*) = 7 INTO @mp_operator_report_tables
FROM information_schema.tables
WHERE table_schema = @mp_schema
  AND table_type = 'BASE TABLE'
  AND table_name IN (
    'users', 'user_roles', 'roles', 'role_permissions', 'permissions',
    'user_access_scopes', 'organizational_units'
  );

SELECT COUNT(*) = 20 INTO @mp_operator_report_columns
FROM information_schema.columns
WHERE table_schema = @mp_schema
  AND (
    (table_name = 'users' AND column_name IN ('user_id', 'username', 'is_active')) OR
    (table_name = 'user_roles' AND column_name IN ('user_id', 'role_id', 'is_active')) OR
    (table_name = 'roles' AND column_name IN ('role_id', 'is_active')) OR
    (table_name = 'role_permissions' AND column_name IN ('role_id', 'permission_id')) OR
    (table_name = 'permissions' AND column_name IN ('permission_id', 'permission_code', 'is_active')) OR
    (table_name = 'user_access_scopes' AND column_name IN ('user_id', 'scope_type', 'scope_id', 'is_active')) OR
    (table_name = 'organizational_units' AND column_name IN ('organizational_unit_id', 'unit_code', 'is_active'))
  );

SET @mp_operator_readiness_query := IF(
  @mp_operator_report_tables AND @mp_operator_report_columns,
  'SELECT ''OPERATOR_READINESS'' AS report_section, operator.user_id, operator.username, COALESCE(operator.has_view, 0) AS has_admissions_view, COALESCE(operator.has_manage, 0) AS has_admissions_manage, COALESCE(operator.has_valid_pres_scope, 0) AS has_valid_pres_scope, CASE WHEN operator.user_id IS NULL THEN ''NO_ACTIVE_ROLE_MAPPED_OPERATOR'' WHEN operator.has_valid_pres_scope = 1 THEN ''SCOPED'' ELSE ''MISSING_VALID_UNIVERSITY_SCOPE'' END AS provisioning_status FROM (SELECT 1 AS report_row) report LEFT JOIN (SELECT u.user_id, u.username, MAX(CASE WHEN p.permission_code = ''admissions.view'' THEN 1 ELSE 0 END) AS has_view, MAX(CASE WHEN p.permission_code = ''admissions.manage'' THEN 1 ELSE 0 END) AS has_manage, MAX(CASE WHEN ou.organizational_unit_id IS NOT NULL THEN 1 ELSE 0 END) AS has_valid_pres_scope FROM `alrowad_uni_rust`.`users` u JOIN `alrowad_uni_rust`.`user_roles` ur ON ur.user_id = u.user_id AND ur.is_active = 1 JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id AND r.is_active = 1 JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id AND p.is_active = 1 AND p.permission_code IN (''admissions.view'', ''admissions.manage'') LEFT JOIN `alrowad_uni_rust`.`user_access_scopes` uas ON uas.user_id = u.user_id AND uas.scope_type = ''university'' AND uas.is_active = 1 LEFT JOIN `alrowad_uni_rust`.`organizational_units` ou ON ou.organizational_unit_id = uas.scope_id AND ou.unit_code = ''PRES'' AND ou.is_active = 1 WHERE u.is_active = 1 GROUP BY u.user_id, u.username HAVING MAX(CASE WHEN p.permission_code = ''admissions.view'' THEN 1 ELSE 0 END) = 1 OR MAX(CASE WHEN p.permission_code = ''admissions.manage'' THEN 1 ELSE 0 END) = 1) operator ON 1 = 1 ORDER BY operator.user_id',
  'SELECT ''OPERATOR_READINESS'' AS report_section, NULL AS user_id, NULL AS username, 0 AS has_admissions_view, 0 AS has_admissions_manage, 0 AS has_valid_pres_scope, ''UNAVAILABLE_SCHEMA'' AS provisioning_status'
);
PREPARE mp_operator_readiness_statement FROM @mp_operator_readiness_query;
EXECUTE mp_operator_readiness_statement;
DEALLOCATE PREPARE mp_operator_readiness_statement;

SELECT 'DATABASE_AND_TABLES' AS check_name, IF(@mp_database_exists AND @mp_required_tables, 'PASS', 'FAIL') AS result
UNION ALL
SELECT 'RBAC_PERMISSIONS', IF(@mp_permission_required_columns AND @mp_required_active_permissions AND @mp_authorization_primary_keys, 'PASS', 'FAIL')
UNION ALL
SELECT 'ACTUAL_SCOPE_STRUCTURE', IF(@mp_scope_required_columns AND @mp_scope_user_foreign_key AND @mp_authorization_primary_keys, 'PASS', 'FAIL')
UNION ALL
SELECT 'REQUIRED_COLUMNS', IF(@mp_batch_required_columns AND @mp_record_required_columns AND @mp_activity_required_columns, 'PASS', 'FAIL')
UNION ALL
SELECT 'KEY_TYPES', IF(@mp_parent_identity_types, 'PASS', 'FAIL')
UNION ALL
SELECT 'INDEXES_AND_FOREIGN_KEYS', IF(@mp_primary_keys AND @mp_batch_unique_identifier AND @mp_required_foreign_keys, 'PASS', 'FAIL')
UNION ALL
SELECT 'OVERALL', IF(@mp_ready, 'READY', 'BLOCKED');
