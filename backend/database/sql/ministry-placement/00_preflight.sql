-- Ministry Placement Phase 1 compatibility preflight.
-- Read only. Run in phpMyAdmin and continue only when the final row is OVERALL | READY.

SET @mp_schema := 'alrowad_uni_rust';

SELECT COUNT(*) = 1 INTO @mp_database_exists
FROM information_schema.schemata
WHERE schema_name = @mp_schema;

SELECT COUNT(*) = 5 INTO @mp_required_tables
FROM information_schema.tables
WHERE table_schema = @mp_schema
  AND table_type = 'BASE TABLE'
  AND table_name IN (
    'academic_years', 'users', 'user_activity_logs',
    'ministry_placement_batches', 'ministry_placement_records'
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
  AND @mp_batch_required_columns
  AND @mp_record_required_columns
  AND @mp_activity_required_columns
  AND @mp_parent_identity_types
  AND @mp_primary_keys
  AND @mp_batch_unique_identifier
  AND @mp_required_foreign_keys;

SELECT 'DATABASE_AND_TABLES' AS check_name, IF(@mp_database_exists AND @mp_required_tables, 'PASS', 'FAIL') AS result
UNION ALL
SELECT 'REQUIRED_COLUMNS', IF(@mp_batch_required_columns AND @mp_record_required_columns AND @mp_activity_required_columns, 'PASS', 'FAIL')
UNION ALL
SELECT 'KEY_TYPES', IF(@mp_parent_identity_types, 'PASS', 'FAIL')
UNION ALL
SELECT 'INDEXES_AND_FOREIGN_KEYS', IF(@mp_primary_keys AND @mp_batch_unique_identifier AND @mp_required_foreign_keys, 'PASS', 'FAIL')
UNION ALL
SELECT 'OVERALL', IF(@mp_ready, 'READY', 'BLOCKED');
