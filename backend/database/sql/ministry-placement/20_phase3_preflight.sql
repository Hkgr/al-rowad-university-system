-- Ministry Placement Phase 3 applicant-conversion preflight.
-- Read only. Run after 00_preflight.sql and 10_phase2_preflight.sql.

SET @mp3_database_exists := (
  SELECT COUNT(*) = 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'
);

SET @mp3_required_tables := (
  SELECT COUNT(*) = 17
  FROM information_schema.tables
  WHERE table_schema = 'alrowad_uni_rust'
    AND table_name IN (
      'ministry_placement_records', 'ministry_placement_batches', 'applicants',
      'admission_applications', 'academic_programs', 'departments', 'colleges',
      'academic_years', 'users', 'user_activity_logs', 'permissions', 'roles',
      'role_permissions', 'user_roles', 'user_access_scopes',
      'organizational_units', 'account_statuses'
    )
);

SET @mp3_record_columns := (
  SELECT COUNT(*) = 6
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'ministry_placement_records'
    AND (
      (column_name = 'placement_record_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
      (column_name = 'batch_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
      (column_name = 'national_civil_id' AND data_type = 'varchar') OR
      (column_name = 'matched_academic_program_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
      (column_name = 'applicant_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
      (column_name = 'processing_status' AND data_type = 'varchar' AND is_nullable = 'NO')
    )
);

SET @mp3_batch_columns := (
  SELECT COUNT(*) = 2
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'ministry_placement_batches'
    AND (
      (column_name = 'batch_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
      (column_name = 'academic_year_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO')
    )
);

SET @mp3_applicant_columns := (
  SELECT COUNT(*) = 12
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'applicants'
    AND (
      (column_name = 'applicant_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
      (column_name = 'applicant_number' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO') OR
      (column_name IN ('first_name', 'last_name') AND data_type = 'varchar' AND character_maximum_length >= 100 AND is_nullable = 'NO') OR
      (column_name IN ('father_name', 'mother_name', 'nationality') AND data_type = 'varchar' AND is_nullable = 'YES') OR
      (column_name = 'date_of_birth' AND data_type = 'date' AND is_nullable = 'YES') OR
      (column_name = 'gender' AND data_type = 'varchar' AND character_maximum_length >= 20 AND is_nullable = 'YES') OR
      (column_name = 'phone_number' AND data_type = 'varchar' AND character_maximum_length >= 30 AND is_nullable = 'YES') OR
      (column_name = 'email' AND data_type = 'varchar' AND character_maximum_length >= 150 AND is_nullable = 'YES') OR
      (column_name = 'address' AND data_type = 'varchar' AND character_maximum_length >= 255 AND is_nullable = 'YES')
    )
);

SET @mp3_application_columns := (
  SELECT COUNT(*) = 9
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'admission_applications'
    AND (
      (column_name = 'admission_application_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
      (column_name IN ('applicant_id', 'academic_program_id', 'academic_year_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
      (column_name = 'application_date' AND data_type = 'date' AND is_nullable = 'NO') OR
      (column_name = 'decision_status' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO' AND column_default = 'pending') OR
      (column_name = 'decision_date' AND data_type = 'date' AND is_nullable = 'YES') OR
      (column_name = 'decided_by_user_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
      (column_name = 'notes' AND data_type IN ('text', 'mediumtext', 'longtext') AND is_nullable = 'YES')
    )
);

SET @mp3_hierarchy_columns := (
  SELECT COUNT(*) = 9
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND (
    (table_name = 'academic_programs' AND column_name IN ('academic_program_id', 'department_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%') OR
    (table_name = 'academic_programs' AND column_name = 'is_active') OR
    (table_name = 'departments' AND column_name IN ('department_id', 'college_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%') OR
    (table_name = 'departments' AND column_name = 'is_active') OR
    (table_name = 'colleges' AND column_name = 'college_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%') OR
    (table_name = 'colleges' AND column_name = 'is_active') OR
    (table_name = 'academic_years' AND column_name = 'academic_year_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%')
  )
);

SET @mp3_audit_columns := (
  SELECT COUNT(*) = 5
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'user_activity_logs'
    AND column_name IN ('activity_log_id', 'user_id', 'module_code', 'action_code', 'description')
);

SET @mp3_primary_keys := (
  SELECT COUNT(*) = 4
  FROM information_schema.statistics
  WHERE table_schema = 'alrowad_uni_rust' AND index_name = 'PRIMARY' AND seq_in_index = 1
    AND ((table_name = 'ministry_placement_records' AND column_name = 'placement_record_id') OR
         (table_name = 'ministry_placement_batches' AND column_name = 'batch_id') OR
         (table_name = 'applicants' AND column_name = 'applicant_id') OR
         (table_name = 'admission_applications' AND column_name = 'admission_application_id'))
);

SET @mp3_applicant_number_unique := (
  SELECT COUNT(*) >= 1
  FROM information_schema.statistics
  WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'applicants'
    AND column_name = 'applicant_number' AND non_unique = 0 AND seq_in_index = 1
);

SET @mp3_required_indexes := (
  SELECT COUNT(DISTINCT CONCAT(table_name, ':', column_name)) = 8
  FROM information_schema.statistics
  WHERE table_schema = 'alrowad_uni_rust' AND seq_in_index = 1 AND (
    (table_name = 'ministry_placement_records' AND column_name IN ('batch_id', 'matched_academic_program_id', 'applicant_id')) OR
    (table_name = 'ministry_placement_batches' AND column_name = 'academic_year_id') OR
    (table_name = 'admission_applications' AND column_name IN ('applicant_id', 'academic_program_id', 'academic_year_id', 'decided_by_user_id'))
  )
);

SET @mp3_required_foreign_keys := (
  SELECT COUNT(DISTINCT CONCAT(table_name, ':', column_name, ':', referenced_table_name, ':', referenced_column_name)) = 8
  FROM information_schema.key_column_usage
  WHERE table_schema = 'alrowad_uni_rust' AND referenced_table_name IS NOT NULL AND (
    (table_name = 'ministry_placement_records' AND column_name = 'batch_id' AND referenced_table_name = 'ministry_placement_batches' AND referenced_column_name = 'batch_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'matched_academic_program_id' AND referenced_table_name = 'academic_programs' AND referenced_column_name = 'academic_program_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'applicant_id' AND referenced_table_name = 'applicants' AND referenced_column_name = 'applicant_id') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'academic_year_id' AND referenced_table_name = 'academic_years' AND referenced_column_name = 'academic_year_id') OR
    (table_name = 'admission_applications' AND column_name = 'applicant_id' AND referenced_table_name = 'applicants' AND referenced_column_name = 'applicant_id') OR
    (table_name = 'admission_applications' AND column_name = 'academic_program_id' AND referenced_table_name = 'academic_programs' AND referenced_column_name = 'academic_program_id') OR
    (table_name = 'admission_applications' AND column_name = 'academic_year_id' AND referenced_table_name = 'academic_years' AND referenced_column_name = 'academic_year_id') OR
    (table_name = 'admission_applications' AND column_name = 'decided_by_user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id')
  )
);

SET @mp3_active_permissions := (
  SELECT COUNT(*) = 2 FROM `alrowad_uni_rust`.`permissions`
  WHERE permission_code IN ('admissions.view', 'admissions.manage') AND is_active = 1
);

SET @mp3_ready := @mp3_database_exists AND @mp3_required_tables AND @mp3_record_columns
  AND @mp3_batch_columns AND @mp3_applicant_columns AND @mp3_application_columns
  AND @mp3_hierarchy_columns AND @mp3_audit_columns AND @mp3_primary_keys
  AND @mp3_applicant_number_unique AND @mp3_required_indexes
  AND @mp3_required_foreign_keys AND @mp3_active_permissions;

SELECT
  'CONVERSION_DATA_READINESS' AS report_section,
  records.batch_id,
  COUNT(*) AS total_records,
  SUM(records.processing_status = 'program_matched' AND records.matched_academic_program_id IS NOT NULL) AS program_matched_records,
  SUM(records.applicant_id IS NOT NULL) AS applicant_linked_records,
  SUM(records.processing_status = 'program_matched' AND records.matched_academic_program_id IS NOT NULL AND records.applicant_id IS NULL) AS potential_convertible_records,
  SUM((records.processing_status = 'applicant_created') <> (records.applicant_id IS NOT NULL)) AS partial_state_records,
  SUM(COALESCE(app_counts.application_count, 0) > 1) AS multiple_application_records,
  SUM(records.matched_academic_program_id IS NOT NULL AND (
    programs.academic_program_id IS NULL OR programs.is_active <> 1 OR
    departments.department_id IS NULL OR departments.is_active <> 1 OR
    colleges.college_id IS NULL OR colleges.is_active <> 1
  )) AS program_inactive_records,
  SUM(COALESCE(duplicates.identity_count, 0) > 1) AS exact_duplicate_national_id_records
FROM `alrowad_uni_rust`.`ministry_placement_records` records
INNER JOIN `alrowad_uni_rust`.`ministry_placement_batches` batches ON batches.batch_id = records.batch_id
LEFT JOIN `alrowad_uni_rust`.`academic_programs` programs ON programs.academic_program_id = records.matched_academic_program_id
LEFT JOIN `alrowad_uni_rust`.`departments` departments ON departments.department_id = programs.department_id
LEFT JOIN `alrowad_uni_rust`.`colleges` colleges ON colleges.college_id = departments.college_id
LEFT JOIN (
  SELECT applicant_id, academic_program_id, academic_year_id, COUNT(*) AS application_count
  FROM `alrowad_uni_rust`.`admission_applications`
  GROUP BY applicant_id, academic_program_id, academic_year_id
) app_counts ON app_counts.applicant_id = records.applicant_id
  AND app_counts.academic_program_id = records.matched_academic_program_id
  AND app_counts.academic_year_id = batches.academic_year_id
LEFT JOIN (
  -- Informational exact SQL equality only. PHP duplicateKey remains authoritative
  -- for Unicode whitespace and Arabic/Eastern-Arabic digit equivalence.
  SELECT national_civil_id, COUNT(*) AS identity_count
  FROM `alrowad_uni_rust`.`ministry_placement_records`
  WHERE national_civil_id IS NOT NULL AND TRIM(national_civil_id) <> ''
  GROUP BY national_civil_id
) duplicates ON duplicates.national_civil_id = records.national_civil_id
GROUP BY records.batch_id WITH ROLLUP;

SELECT 'DATABASE_AND_TABLES' AS check_name, IF(@mp3_database_exists AND @mp3_required_tables, 'PASS', 'FAIL') AS result
UNION ALL SELECT 'REQUIRED_COLUMNS', IF(@mp3_record_columns AND @mp3_batch_columns AND @mp3_applicant_columns AND @mp3_application_columns AND @mp3_hierarchy_columns, 'PASS', 'FAIL')
UNION ALL SELECT 'KEYS_INDEXES_AND_RELATIONSHIPS', IF(@mp3_primary_keys AND @mp3_applicant_number_unique AND @mp3_required_indexes AND @mp3_required_foreign_keys, 'PASS', 'FAIL')
UNION ALL SELECT 'AUTHORIZATION_AND_AUDIT', IF(@mp3_active_permissions AND @mp3_audit_columns, 'PASS', 'FAIL')
UNION ALL SELECT 'OVERALL', IF(@mp3_ready, 'READY', 'BLOCKED');
