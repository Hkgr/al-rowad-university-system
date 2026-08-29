-- Ministry Placement Phase 4 student-enrollment preflight.
-- Read only. Run after the Phase 1, Phase 2, and Phase 3 preflights.

SET @mp4_database_exists := (
  SELECT COUNT(*) = 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'
);

SET @mp4_required_tables := (
  SELECT COUNT(*) = 20
  FROM information_schema.tables
  WHERE table_schema = 'alrowad_uni_rust' AND table_name IN (
    'ministry_placement_records', 'ministry_placement_batches', 'applicants',
    'admission_applications', 'academic_programs', 'departments', 'colleges',
    'academic_years', 'students', 'academic_levels', 'student_statuses', 'users',
    'user_activity_logs', 'permissions', 'roles', 'role_permissions', 'user_roles',
    'user_access_scopes', 'organizational_units', 'account_statuses'
  )
);

SET @mp4_ministry_columns := (
  SELECT COUNT(*) = 8
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND (
    (table_name = 'ministry_placement_records' AND column_name = 'placement_record_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'ministry_placement_records' AND column_name IN ('batch_id', 'matched_academic_program_id', 'applicant_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%') OR
    (table_name = 'ministry_placement_records' AND column_name = 'national_civil_id' AND data_type = 'varchar') OR
    (table_name = 'ministry_placement_records' AND column_name = 'processing_status' AND data_type = 'varchar' AND is_nullable = 'NO' AND TRIM(BOTH '''' FROM COALESCE(column_default, '')) = 'imported') OR
    (table_name = 'ministry_placement_batches' AND column_name IN ('batch_id', 'academic_year_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO')
  )
);

SET @mp4_applicant_columns := (
  SELECT COUNT(*) = 12
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'applicants' AND (
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

SET @mp4_application_columns := (
  SELECT COUNT(*) = 9
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'admission_applications' AND (
    (column_name = 'admission_application_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name IN ('applicant_id', 'academic_program_id', 'academic_year_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'application_date' AND data_type = 'date' AND is_nullable = 'NO') OR
    (column_name = 'decision_status' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO' AND TRIM(BOTH '''' FROM COALESCE(column_default, '')) = 'pending') OR
    (column_name = 'decision_date' AND data_type = 'date' AND is_nullable = 'YES') OR
    (column_name = 'decided_by_user_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (column_name = 'notes' AND data_type IN ('text', 'mediumtext', 'longtext') AND is_nullable = 'YES')
  )
);

SET @mp4_student_columns := (
  SELECT COUNT(*) = 18
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'students' AND (
    (column_name = 'student_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'student_number' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO') OR
    (column_name = 'admission_application_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (column_name IN ('first_name', 'last_name') AND data_type = 'varchar' AND character_maximum_length >= 100 AND is_nullable = 'NO') OR
    (column_name IN ('father_name', 'mother_name', 'nationality') AND data_type = 'varchar' AND is_nullable = 'YES') OR
    (column_name = 'date_of_birth' AND data_type = 'date' AND is_nullable = 'YES') OR
    (column_name = 'gender' AND data_type = 'varchar' AND character_maximum_length >= 20 AND is_nullable = 'YES') OR
    (column_name = 'phone_number' AND data_type = 'varchar' AND character_maximum_length >= 30 AND is_nullable = 'YES') OR
    (column_name = 'email' AND data_type = 'varchar' AND character_maximum_length >= 150 AND is_nullable = 'YES') OR
    (column_name = 'address' AND data_type = 'varchar' AND character_maximum_length >= 255 AND is_nullable = 'YES') OR
    (column_name IN ('academic_program_id', 'current_academic_level_id', 'student_status_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'enrollment_date' AND data_type = 'date' AND is_nullable = 'NO') OR
    (column_name = 'deleted_at' AND data_type = 'timestamp' AND is_nullable = 'YES')
  )
);

SET @mp4_reference_columns := (
  SELECT COUNT(*) = 9
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND (
    (table_name = 'academic_levels' AND column_name = 'academic_level_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'academic_levels' AND column_name = 'level_code' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO') OR
    (table_name = 'academic_levels' AND column_name = 'level_name' AND data_type = 'varchar' AND character_maximum_length >= 100 AND is_nullable = 'NO') OR
    (table_name = 'academic_levels' AND column_name = 'level_order' AND data_type = 'int' AND is_nullable = 'NO') OR
    (table_name = 'academic_levels' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'student_statuses' AND column_name = 'student_status_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'student_statuses' AND column_name = 'status_code' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO') OR
    (table_name = 'student_statuses' AND column_name = 'status_name' AND data_type = 'varchar' AND character_maximum_length >= 100 AND is_nullable = 'NO') OR
    (table_name = 'student_statuses' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO')
  )
);

SET @mp4_hierarchy_columns := (
  SELECT COUNT(*) = 8
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND (
    (table_name = 'academic_programs' AND column_name IN ('academic_program_id', 'department_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%') OR
    (table_name = 'academic_programs' AND column_name = 'is_active' AND data_type = 'tinyint') OR
    (table_name = 'departments' AND column_name IN ('department_id', 'college_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%') OR
    (table_name = 'departments' AND column_name = 'is_active' AND data_type = 'tinyint') OR
    (table_name = 'colleges' AND column_name = 'college_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%') OR
    (table_name = 'colleges' AND column_name = 'is_active' AND data_type = 'tinyint')
  )
);

SET @mp4_audit_columns := (
  SELECT COUNT(*) = 6
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'user_activity_logs' AND (
    (column_name = 'activity_log_id' AND data_type = 'bigint' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'user_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (column_name = 'module_code' AND data_type = 'varchar' AND character_maximum_length >= 80 AND is_nullable = 'YES') OR
    (column_name = 'action_code' AND data_type = 'varchar' AND character_maximum_length >= 120 AND is_nullable = 'YES') OR
    (column_name = 'description' AND data_type IN ('text', 'mediumtext', 'longtext') AND is_nullable = 'YES') OR
    (column_name = 'created_at' AND data_type = 'timestamp' AND is_nullable = 'NO')
  )
);

SET @mp4_authorization_columns := (
  SELECT COUNT(*) = 23
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND (
    (table_name = 'users' AND column_name IN ('user_id', 'account_status_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'account_statuses' AND column_name = 'account_status_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'account_statuses' AND column_name = 'status_code' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO') OR
    (table_name = 'account_statuses' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'roles' AND column_name = 'role_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'roles' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'user_roles' AND column_name IN ('user_id', 'role_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_roles' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'role_permissions' AND column_name IN ('role_id', 'permission_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'permissions' AND column_name = 'permission_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'permissions' AND column_name = 'permission_code' AND data_type = 'varchar' AND character_maximum_length >= 120 AND is_nullable = 'NO') OR
    (table_name = 'permissions' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'user_access_scopes' AND column_name = 'user_access_scope_id' AND data_type IN ('int', 'bigint') AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_access_scopes' AND column_name IN ('user_id', 'scope_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_access_scopes' AND column_name = 'scope_type' AND data_type IN ('enum', 'varchar') AND is_nullable = 'NO') OR
    (table_name = 'user_access_scopes' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'organizational_units' AND column_name = 'organizational_unit_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'organizational_units' AND column_name = 'unit_code' AND data_type = 'varchar' AND character_maximum_length >= 50) OR
    (table_name = 'organizational_units' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO')
  )
);

SET @mp4_primary_keys := (
  SELECT COUNT(*) = 7
  FROM information_schema.statistics
  WHERE table_schema = 'alrowad_uni_rust' AND index_name = 'PRIMARY' AND seq_in_index = 1 AND (
    (table_name = 'ministry_placement_records' AND column_name = 'placement_record_id') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'batch_id') OR
    (table_name = 'applicants' AND column_name = 'applicant_id') OR
    (table_name = 'admission_applications' AND column_name = 'admission_application_id') OR
    (table_name = 'students' AND column_name = 'student_id') OR
    (table_name = 'academic_levels' AND column_name = 'academic_level_id') OR
    (table_name = 'student_statuses' AND column_name = 'student_status_id')
  )
);

SET @mp4_student_uniqueness := (
  SELECT COUNT(DISTINCT single_column_indexes.column_name) = 3
  FROM (
    SELECT idx_col.column_name
    FROM information_schema.statistics idx_col
    INNER JOIN (
      SELECT index_name
      FROM information_schema.statistics
      WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'students' AND non_unique = 0
      GROUP BY index_name
      HAVING COUNT(*) = 1
    ) unique_single_column ON unique_single_column.index_name = idx_col.index_name
    WHERE idx_col.table_schema = 'alrowad_uni_rust' AND idx_col.table_name = 'students'
      AND idx_col.non_unique = 0
      AND idx_col.column_name IN ('student_number', 'admission_application_id', 'email')
  ) single_column_indexes
);

SET @mp4_required_foreign_keys := (
  SELECT COUNT(DISTINCT CONCAT(table_name, ':', column_name, ':', referenced_table_name, ':', referenced_column_name)) = 13
  FROM information_schema.key_column_usage
  WHERE table_schema = 'alrowad_uni_rust' AND referenced_table_name IS NOT NULL AND (
    (table_name = 'ministry_placement_records' AND column_name = 'batch_id' AND referenced_table_name = 'ministry_placement_batches' AND referenced_column_name = 'batch_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'matched_academic_program_id' AND referenced_table_name = 'academic_programs' AND referenced_column_name = 'academic_program_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'applicant_id' AND referenced_table_name = 'applicants' AND referenced_column_name = 'applicant_id') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'academic_year_id' AND referenced_table_name = 'academic_years' AND referenced_column_name = 'academic_year_id') OR
    (table_name = 'admission_applications' AND column_name = 'applicant_id' AND referenced_table_name = 'applicants' AND referenced_column_name = 'applicant_id') OR
    (table_name = 'admission_applications' AND column_name = 'academic_program_id' AND referenced_table_name = 'academic_programs' AND referenced_column_name = 'academic_program_id') OR
    (table_name = 'admission_applications' AND column_name = 'academic_year_id' AND referenced_table_name = 'academic_years' AND referenced_column_name = 'academic_year_id') OR
    (table_name = 'admission_applications' AND column_name = 'decided_by_user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id') OR
    (table_name = 'user_activity_logs' AND column_name = 'user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id') OR
    (table_name = 'students' AND column_name = 'admission_application_id' AND referenced_table_name = 'admission_applications' AND referenced_column_name = 'admission_application_id') OR
    (table_name = 'students' AND column_name = 'academic_program_id' AND referenced_table_name = 'academic_programs' AND referenced_column_name = 'academic_program_id') OR
    (table_name = 'students' AND column_name = 'current_academic_level_id' AND referenced_table_name = 'academic_levels' AND referenced_column_name = 'academic_level_id') OR
    (table_name = 'students' AND column_name = 'student_status_id' AND referenced_table_name = 'student_statuses' AND referenced_column_name = 'student_status_id')
  )
);

SET @mp4_authorization_keys := (
  SELECT COUNT(*) = 8
  FROM information_schema.statistics
  WHERE table_schema = 'alrowad_uni_rust' AND index_name = 'PRIMARY' AND seq_in_index = 1 AND (
    (table_name = 'users' AND column_name = 'user_id') OR
    (table_name = 'account_statuses' AND column_name = 'account_status_id') OR
    (table_name = 'roles' AND column_name = 'role_id') OR
    (table_name = 'user_roles' AND column_name = 'user_role_id') OR
    (table_name = 'role_permissions' AND column_name = 'role_permission_id') OR
    (table_name = 'permissions' AND column_name = 'permission_id') OR
    (table_name = 'user_access_scopes' AND column_name = 'user_access_scope_id') OR
    (table_name = 'organizational_units' AND column_name = 'organizational_unit_id')
  )
);

SET @mp4_authorization_foreign_keys := (
  SELECT COUNT(DISTINCT CONCAT(table_name, ':', column_name, ':', referenced_table_name, ':', referenced_column_name)) = 6
  FROM information_schema.key_column_usage
  WHERE table_schema = 'alrowad_uni_rust' AND referenced_table_name IS NOT NULL AND (
    (table_name = 'users' AND column_name = 'account_status_id' AND referenced_table_name = 'account_statuses' AND referenced_column_name = 'account_status_id') OR
    (table_name = 'user_roles' AND column_name = 'user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id') OR
    (table_name = 'user_roles' AND column_name = 'role_id' AND referenced_table_name = 'roles' AND referenced_column_name = 'role_id') OR
    (table_name = 'role_permissions' AND column_name = 'role_id' AND referenced_table_name = 'roles' AND referenced_column_name = 'role_id') OR
    (table_name = 'role_permissions' AND column_name = 'permission_id' AND referenced_table_name = 'permissions' AND referenced_column_name = 'permission_id') OR
    (table_name = 'user_access_scopes' AND column_name = 'user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id')
  )
);

SET @mp4_active_student_status := (
  SELECT COUNT(*) = 1 FROM `alrowad_uni_rust`.`student_statuses`
  WHERE status_code = 'active' AND is_active = 1
);
SET @mp4_active_academic_levels := (
  SELECT COUNT(*) >= 1 FROM `alrowad_uni_rust`.`academic_levels` WHERE is_active = 1
);
SET @mp4_active_account_status := (
  SELECT COUNT(*) = 1 FROM `alrowad_uni_rust`.`account_statuses`
  WHERE status_code = 'active' AND is_active = 1
);
SET @mp4_active_permissions := (
  SELECT COUNT(*) = 2 FROM `alrowad_uni_rust`.`permissions`
  WHERE permission_code IN ('admissions.view', 'admissions.manage') AND is_active = 1
);
SET @mp4_active_pres_root := (
  SELECT COUNT(*) >= 1 FROM `alrowad_uni_rust`.`organizational_units`
  WHERE unit_code = 'PRES' AND is_active = 1
);

SET @mp4_ready := @mp4_database_exists AND @mp4_required_tables
  AND @mp4_ministry_columns AND @mp4_applicant_columns AND @mp4_application_columns
  AND @mp4_student_columns AND @mp4_reference_columns AND @mp4_hierarchy_columns
  AND @mp4_audit_columns AND @mp4_authorization_columns
  AND @mp4_primary_keys AND @mp4_student_uniqueness AND @mp4_required_foreign_keys
  AND @mp4_authorization_keys AND @mp4_authorization_foreign_keys
  AND @mp4_active_student_status AND @mp4_active_academic_levels
  AND @mp4_active_account_status AND @mp4_active_permissions AND @mp4_active_pres_root;

SELECT
  'STUDENT_ENROLLMENT_DATA_READINESS' AS report_section,
  records.batch_id,
  COUNT(*) AS total_records,
  SUM(records.processing_status = 'applicant_created' AND apps.application_count = 1 AND apps.decision_status = 'pending' AND students.student_id IS NULL) AS pending_ready_candidates,
  SUM(records.processing_status = 'enrolled' AND students.student_id IS NOT NULL AND apps.decision_status = 'accepted') AS already_enrolled_records,
  SUM(apps.decision_status = 'rejected') AS rejected_applications,
  SUM(apps.decision_status = 'accepted' AND students.student_id IS NULL) AS accepted_without_student,
  SUM(students.student_id IS NOT NULL AND apps.decision_status <> 'accepted') AS student_with_nonaccepted_application,
  SUM(records.processing_status = 'enrolled' AND students.student_id IS NULL) AS ministry_enrolled_without_student,
  SUM(students.student_id IS NOT NULL AND students.academic_program_id <> records.matched_academic_program_id) AS student_program_mismatch,
  SUM(COALESCE(identities.identity_count, 0) > 1) AS identity_conflict_candidates
FROM `alrowad_uni_rust`.`ministry_placement_records` records
INNER JOIN `alrowad_uni_rust`.`ministry_placement_batches` batches ON batches.batch_id = records.batch_id
LEFT JOIN (
  SELECT applicant_id, academic_program_id, academic_year_id, COUNT(*) AS application_count,
         MAX(admission_application_id) AS admission_application_id,
         MAX(decision_status) AS decision_status
  FROM `alrowad_uni_rust`.`admission_applications`
  GROUP BY applicant_id, academic_program_id, academic_year_id
) apps ON apps.applicant_id = records.applicant_id
  AND apps.academic_program_id = records.matched_academic_program_id
  AND apps.academic_year_id = batches.academic_year_id
LEFT JOIN `alrowad_uni_rust`.`students` students ON students.admission_application_id = apps.admission_application_id
LEFT JOIN (
  SELECT national_civil_id, COUNT(*) AS identity_count
  FROM `alrowad_uni_rust`.`ministry_placement_records`
  WHERE national_civil_id IS NOT NULL AND TRIM(national_civil_id) <> ''
  GROUP BY national_civil_id
) identities ON identities.national_civil_id = records.national_civil_id
GROUP BY records.batch_id WITH ROLLUP;

SELECT 'DATABASE_AND_TABLES' AS check_name, IF(@mp4_database_exists AND @mp4_required_tables, 'PASS', 'FAIL') AS result
UNION ALL SELECT 'REQUIRED_COLUMNS', IF(@mp4_ministry_columns AND @mp4_applicant_columns AND @mp4_application_columns AND @mp4_student_columns AND @mp4_reference_columns AND @mp4_hierarchy_columns, 'PASS', 'FAIL')
UNION ALL SELECT 'STUDENT_UNIQUENESS', IF(@mp4_student_uniqueness, 'PASS', 'FAIL')
UNION ALL SELECT 'KEYS_AND_RELATIONSHIPS', IF(@mp4_primary_keys AND @mp4_required_foreign_keys, 'PASS', 'FAIL')
UNION ALL SELECT 'STUDENT_REFERENCE_DATA', IF(@mp4_active_student_status AND @mp4_active_academic_levels, 'PASS', 'FAIL')
UNION ALL SELECT 'AUTHORIZATION_AND_AUDIT', IF(@mp4_audit_columns AND @mp4_authorization_columns AND @mp4_authorization_keys AND @mp4_authorization_foreign_keys AND @mp4_active_account_status AND @mp4_active_permissions AND @mp4_active_pres_root, 'PASS', 'FAIL')
UNION ALL SELECT 'OVERALL', IF(@mp4_ready, 'READY', 'BLOCKED');
