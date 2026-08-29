-- Ministry Placement Phase 5 final reconciliation and production-readiness report.
-- Read only. PHP duplicateKey() remains authoritative for canonical identity reconciliation.

SET @mp5_database_exists := (
  SELECT COUNT(*) = 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'
);

SET @mp5_required_tables := (
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

SET @mp5_ministry_columns := (
  SELECT COUNT(*) = 40
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND (
    (table_name = 'ministry_placement_batches' AND column_name = 'batch_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO' AND extra LIKE '%auto_increment%') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'batch_name' AND data_type = 'varchar' AND character_maximum_length >= 255 AND is_nullable = 'NO') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'source_file_name' AND data_type = 'varchar' AND character_maximum_length >= 255 AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'academic_year_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'import_date' AND data_type = 'date' AND is_nullable = 'NO') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'imported_by_user_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'notes' AND data_type IN ('text', 'mediumtext', 'longtext') AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_batches' AND column_name IN ('created_at', 'updated_at') AND data_type = 'timestamp' AND is_nullable = 'NO' AND column_default IS NOT NULL) OR
    (table_name = 'ministry_placement_records' AND column_name = 'placement_record_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO' AND extra LIKE '%auto_increment%') OR
    (table_name = 'ministry_placement_records' AND column_name = 'batch_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'ministry_placement_records' AND column_name = 'row_number' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name = 'national_civil_id' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name = 'subscription_number' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name IN ('first_name', 'last_name') AND data_type = 'varchar' AND character_maximum_length >= 100 AND is_nullable = 'NO') OR
    (table_name = 'ministry_placement_records' AND column_name IN ('father_name', 'mother_name', 'nationality', 'certificate_source_country', 'directorate', 'track', 'registration_type') AND data_type = 'varchar' AND character_maximum_length >= 100 AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name = 'date_of_birth' AND data_type = 'date' AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name = 'gender' AND data_type = 'varchar' AND character_maximum_length >= 20 AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name = 'phone_number' AND data_type = 'varchar' AND character_maximum_length >= 30 AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name = 'email' AND data_type = 'varchar' AND character_maximum_length >= 150 AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name IN ('certificate_type', 'placement_round_name') AND data_type = 'varchar' AND character_maximum_length >= 255 AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name = 'certificate_grant_year' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name IN ('total_score', 'max_total_score') AND data_type = 'decimal' AND numeric_precision = 6 AND numeric_scale = 3 AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name = 'accepted_preference_text' AND data_type = 'varchar' AND character_maximum_length >= 500 AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name IN ('matched_academic_program_id', 'applicant_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES') OR
    (table_name = 'ministry_placement_records' AND column_name IN ('is_faculty_member_child', 'has_academic_sequence') AND data_type = 'tinyint' AND is_nullable = 'NO' AND column_default IN ('0', 0)) OR
    (table_name = 'ministry_placement_records' AND column_name = 'processing_status' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO' AND TRIM(BOTH '''' FROM COALESCE(column_default, '')) = 'imported') OR
    (table_name = 'ministry_placement_records' AND column_name IN ('created_at', 'updated_at') AND data_type = 'timestamp' AND is_nullable = 'NO' AND column_default IS NOT NULL)
  )
);

SET @mp5_applicant_columns := (
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

SET @mp5_application_columns := (
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

SET @mp5_student_columns := (
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

SET @mp5_reference_columns := (
  SELECT COUNT(*) = 18
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND (
    (table_name = 'academic_years' AND column_name = 'academic_year_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'academic_programs' AND column_name IN ('academic_program_id', 'department_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'academic_programs' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'departments' AND column_name IN ('department_id', 'college_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'departments' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'colleges' AND column_name = 'college_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'colleges' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
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

SET @mp5_audit_auth_columns := (
  SELECT COUNT(*) = 31
  FROM information_schema.columns
  WHERE table_schema = 'alrowad_uni_rust' AND (
    (table_name = 'user_activity_logs' AND column_name = 'activity_log_id' AND data_type = 'bigint' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_activity_logs' AND column_name = 'user_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_activity_logs' AND column_name = 'module_code' AND data_type = 'varchar' AND character_maximum_length >= 80 AND is_nullable = 'YES') OR
    (table_name = 'user_activity_logs' AND column_name = 'action_code' AND data_type = 'varchar' AND character_maximum_length >= 120 AND is_nullable = 'YES') OR
    (table_name = 'user_activity_logs' AND column_name = 'description' AND data_type IN ('text', 'mediumtext', 'longtext') AND is_nullable = 'YES') OR
    (table_name = 'user_activity_logs' AND column_name = 'created_at' AND data_type = 'timestamp' AND is_nullable = 'NO') OR
    (table_name = 'users' AND column_name IN ('user_id', 'account_status_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'account_statuses' AND column_name = 'account_status_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'account_statuses' AND column_name = 'status_code' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO') OR
    (table_name = 'account_statuses' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'roles' AND column_name = 'role_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'roles' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'permissions' AND column_name = 'permission_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'permissions' AND column_name = 'permission_code' AND data_type = 'varchar' AND character_maximum_length >= 120 AND is_nullable = 'NO') OR
    (table_name = 'permissions' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'role_permissions' AND column_name IN ('role_permission_id', 'role_id', 'permission_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_roles' AND column_name IN ('user_role_id', 'user_id', 'role_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_roles' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'user_access_scopes' AND column_name = 'user_access_scope_id' AND data_type IN ('int', 'bigint') AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_access_scopes' AND column_name IN ('user_id', 'scope_id') AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'user_access_scopes' AND column_name = 'scope_type' AND data_type IN ('varchar', 'enum') AND is_nullable = 'NO') OR
    (table_name = 'user_access_scopes' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO') OR
    (table_name = 'organizational_units' AND column_name = 'organizational_unit_id' AND data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO') OR
    (table_name = 'organizational_units' AND column_name = 'unit_code' AND data_type = 'varchar' AND character_maximum_length >= 50 AND is_nullable = 'NO') OR
    (table_name = 'organizational_units' AND column_name = 'is_active' AND data_type = 'tinyint' AND is_nullable = 'NO')
  )
);

SET @mp5_primary_keys := (
  SELECT COUNT(*) = 20
  FROM information_schema.statistics pk_index
  WHERE pk_index.table_schema = 'alrowad_uni_rust' AND pk_index.index_name = 'PRIMARY' AND pk_index.seq_in_index = 1
    AND (
      (pk_index.table_name = 'ministry_placement_records' AND pk_index.column_name = 'placement_record_id') OR
      (pk_index.table_name = 'ministry_placement_batches' AND pk_index.column_name = 'batch_id') OR
      (pk_index.table_name = 'applicants' AND pk_index.column_name = 'applicant_id') OR
      (pk_index.table_name = 'admission_applications' AND pk_index.column_name = 'admission_application_id') OR
      (pk_index.table_name = 'students' AND pk_index.column_name = 'student_id') OR
      (pk_index.table_name = 'academic_programs' AND pk_index.column_name = 'academic_program_id') OR
      (pk_index.table_name = 'departments' AND pk_index.column_name = 'department_id') OR
      (pk_index.table_name = 'colleges' AND pk_index.column_name = 'college_id') OR
      (pk_index.table_name = 'academic_levels' AND pk_index.column_name = 'academic_level_id') OR
      (pk_index.table_name = 'student_statuses' AND pk_index.column_name = 'student_status_id') OR
      (pk_index.table_name = 'users' AND pk_index.column_name = 'user_id') OR
      (pk_index.table_name = 'permissions' AND pk_index.column_name = 'permission_id') OR
      (pk_index.table_name = 'roles' AND pk_index.column_name = 'role_id') OR
      (pk_index.table_name = 'role_permissions' AND pk_index.column_name = 'role_permission_id') OR
      (pk_index.table_name = 'user_roles' AND pk_index.column_name = 'user_role_id') OR
      (pk_index.table_name = 'user_access_scopes' AND pk_index.column_name = 'user_access_scope_id') OR
      (pk_index.table_name = 'organizational_units' AND pk_index.column_name = 'organizational_unit_id') OR
      (pk_index.table_name = 'account_statuses' AND pk_index.column_name = 'account_status_id') OR
      (pk_index.table_name = 'academic_years' AND pk_index.column_name = 'academic_year_id') OR
      (pk_index.table_name = 'user_activity_logs' AND pk_index.column_name = 'activity_log_id')
    )
    AND (SELECT COUNT(*) FROM information_schema.statistics primary_part
         WHERE primary_part.table_schema = pk_index.table_schema
           AND primary_part.table_name = pk_index.table_name
           AND primary_part.index_name = 'PRIMARY') = 1
);

SET @mp5_student_uniqueness := (
  SELECT COUNT(DISTINCT unique_columns.column_name) = 3
  FROM (
    SELECT idx_col.column_name
    FROM information_schema.statistics idx_col
    INNER JOIN (
      SELECT index_name
      FROM information_schema.statistics
      WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'students' AND non_unique = 0
      GROUP BY index_name
      HAVING COUNT(*) = 1
    ) single_indexes ON single_indexes.index_name = idx_col.index_name
    WHERE idx_col.table_schema = 'alrowad_uni_rust' AND idx_col.table_name = 'students'
      AND idx_col.column_name IN ('student_number', 'admission_application_id', 'email')
  ) unique_columns
);

SET @mp5_ministry_identity_uniqueness := (
  SELECT COUNT(*) = 1
  FROM (
    SELECT index_name
    FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'ministry_placement_records' AND non_unique = 0
    GROUP BY index_name
    HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'national_civil_id,batch_id'
  ) identity_unique_indexes
);

SET @mp5_applicant_number_uniqueness := (
  SELECT COUNT(*) >= 1
  FROM (
    SELECT index_name
    FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'applicants' AND non_unique = 0
    GROUP BY index_name
    HAVING COUNT(*) = 1 AND MAX(column_name) = 'applicant_number'
  ) applicant_number_unique_indexes
);

SET @mp5_required_foreign_keys := (
  SELECT COUNT(DISTINCT CONCAT(table_name, ':', column_name, ':', referenced_table_name, ':', referenced_column_name)) = 22
  FROM information_schema.key_column_usage
  WHERE table_schema = 'alrowad_uni_rust' AND referenced_table_name IS NOT NULL AND (
    (table_name = 'ministry_placement_records' AND column_name = 'batch_id' AND referenced_table_name = 'ministry_placement_batches' AND referenced_column_name = 'batch_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'matched_academic_program_id' AND referenced_table_name = 'academic_programs' AND referenced_column_name = 'academic_program_id') OR
    (table_name = 'ministry_placement_records' AND column_name = 'applicant_id' AND referenced_table_name = 'applicants' AND referenced_column_name = 'applicant_id') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'academic_year_id' AND referenced_table_name = 'academic_years' AND referenced_column_name = 'academic_year_id') OR
    (table_name = 'ministry_placement_batches' AND column_name = 'imported_by_user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id') OR
    (table_name = 'admission_applications' AND column_name = 'applicant_id' AND referenced_table_name = 'applicants' AND referenced_column_name = 'applicant_id') OR
    (table_name = 'admission_applications' AND column_name = 'academic_program_id' AND referenced_table_name = 'academic_programs' AND referenced_column_name = 'academic_program_id') OR
    (table_name = 'admission_applications' AND column_name = 'academic_year_id' AND referenced_table_name = 'academic_years' AND referenced_column_name = 'academic_year_id') OR
    (table_name = 'admission_applications' AND column_name = 'decided_by_user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id') OR
    (table_name = 'students' AND column_name = 'admission_application_id' AND referenced_table_name = 'admission_applications' AND referenced_column_name = 'admission_application_id') OR
    (table_name = 'students' AND column_name = 'academic_program_id' AND referenced_table_name = 'academic_programs' AND referenced_column_name = 'academic_program_id') OR
    (table_name = 'students' AND column_name = 'current_academic_level_id' AND referenced_table_name = 'academic_levels' AND referenced_column_name = 'academic_level_id') OR
    (table_name = 'students' AND column_name = 'student_status_id' AND referenced_table_name = 'student_statuses' AND referenced_column_name = 'student_status_id') OR
    (table_name = 'academic_programs' AND column_name = 'department_id' AND referenced_table_name = 'departments' AND referenced_column_name = 'department_id') OR
    (table_name = 'departments' AND column_name = 'college_id' AND referenced_table_name = 'colleges' AND referenced_column_name = 'college_id') OR
    (table_name = 'user_activity_logs' AND column_name = 'user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id') OR
    (table_name = 'user_roles' AND column_name = 'user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id') OR
    (table_name = 'user_roles' AND column_name = 'role_id' AND referenced_table_name = 'roles' AND referenced_column_name = 'role_id') OR
    (table_name = 'role_permissions' AND column_name = 'role_id' AND referenced_table_name = 'roles' AND referenced_column_name = 'role_id') OR
    (table_name = 'role_permissions' AND column_name = 'permission_id' AND referenced_table_name = 'permissions' AND referenced_column_name = 'permission_id') OR
    (table_name = 'users' AND column_name = 'account_status_id' AND referenced_table_name = 'account_statuses' AND referenced_column_name = 'account_status_id') OR
    (table_name = 'user_access_scopes' AND column_name = 'user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id')
  )
);

SET @mp5_required_indexes := (
  SELECT COUNT(DISTINCT CONCAT(table_name, ':', column_name)) = 22
  FROM information_schema.statistics
  WHERE table_schema = 'alrowad_uni_rust' AND seq_in_index = 1 AND (
    (table_name = 'ministry_placement_records' AND column_name IN ('batch_id', 'matched_academic_program_id', 'applicant_id')) OR
    (table_name = 'ministry_placement_batches' AND column_name IN ('academic_year_id', 'imported_by_user_id')) OR
    (table_name = 'admission_applications' AND column_name IN ('applicant_id', 'academic_program_id', 'academic_year_id', 'decided_by_user_id')) OR
    (table_name = 'students' AND column_name IN ('admission_application_id', 'academic_program_id', 'current_academic_level_id', 'student_status_id')) OR
    (table_name = 'academic_programs' AND column_name = 'department_id') OR
    (table_name = 'departments' AND column_name = 'college_id') OR
    (table_name = 'user_activity_logs' AND column_name = 'user_id') OR
    (table_name = 'users' AND column_name = 'account_status_id') OR
    (table_name = 'user_roles' AND column_name IN ('user_id', 'role_id')) OR
    (table_name = 'role_permissions' AND column_name IN ('role_id', 'permission_id')) OR
    (table_name = 'user_access_scopes' AND column_name = 'user_id')
  )
);

SET @mp5_reference_data := (
  SELECT
    (SELECT COUNT(*) >= 1 FROM `alrowad_uni_rust`.`academic_levels` WHERE is_active = 1)
    AND (SELECT COUNT(*) = 1 FROM `alrowad_uni_rust`.`student_statuses` WHERE status_code = 'active' AND is_active = 1)
);
SET @mp5_authorization := (
  SELECT
    (SELECT COUNT(*) = 2 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN ('admissions.view', 'admissions.manage') AND is_active = 1)
    AND (SELECT COUNT(*) = 1 FROM `alrowad_uni_rust`.`account_statuses` WHERE status_code = 'active' AND is_active = 1)
    AND (SELECT COUNT(*) >= 1 FROM (
      SELECT users.user_id
      FROM `alrowad_uni_rust`.`users` users
      INNER JOIN `alrowad_uni_rust`.`account_statuses` account_statuses ON account_statuses.account_status_id = users.account_status_id AND account_statuses.status_code = 'active' AND account_statuses.is_active = 1
      INNER JOIN `alrowad_uni_rust`.`user_roles` user_roles ON user_roles.user_id = users.user_id AND user_roles.is_active = 1
      INNER JOIN `alrowad_uni_rust`.`roles` roles ON roles.role_id = user_roles.role_id AND roles.is_active = 1
      INNER JOIN `alrowad_uni_rust`.`role_permissions` role_permissions ON role_permissions.role_id = roles.role_id
      INNER JOIN `alrowad_uni_rust`.`permissions` permissions ON permissions.permission_id = role_permissions.permission_id AND permissions.is_active = 1
      INNER JOIN `alrowad_uni_rust`.`user_access_scopes` scopes ON scopes.user_id = users.user_id AND scopes.scope_type = 'university' AND scopes.is_active = 1
      INNER JOIN `alrowad_uni_rust`.`organizational_units` units ON units.organizational_unit_id = scopes.scope_id AND units.unit_code = 'PRES' AND units.is_active = 1
      WHERE permissions.permission_code IN ('admissions.view', 'admissions.manage')
      GROUP BY users.user_id
      HAVING COUNT(DISTINCT permissions.permission_code) = 2
    ) ready_operators)
);

SET @mp5_multiple_application_contexts := (
  SELECT COUNT(*) FROM (
    SELECT applications.applicant_id, applications.academic_program_id, applications.academic_year_id
    FROM `alrowad_uni_rust`.`admission_applications` applications
    INNER JOIN `alrowad_uni_rust`.`ministry_placement_records` records ON records.applicant_id = applications.applicant_id
    INNER JOIN `alrowad_uni_rust`.`ministry_placement_batches` batches ON batches.batch_id = records.batch_id
      AND records.matched_academic_program_id = applications.academic_program_id
      AND batches.academic_year_id = applications.academic_year_id
    GROUP BY applications.applicant_id, applications.academic_program_id, applications.academic_year_id
    HAVING COUNT(DISTINCT applications.admission_application_id) > 1
  ) duplicate_contexts
);

SET @mp5_accepted_without_student := (
  SELECT COUNT(DISTINCT applications.admission_application_id)
  FROM `alrowad_uni_rust`.`ministry_placement_records` records
  INNER JOIN `alrowad_uni_rust`.`ministry_placement_batches` batches ON batches.batch_id = records.batch_id
  INNER JOIN `alrowad_uni_rust`.`admission_applications` applications ON applications.applicant_id = records.applicant_id
    AND applications.academic_program_id = records.matched_academic_program_id
    AND applications.academic_year_id = batches.academic_year_id
  LEFT JOIN `alrowad_uni_rust`.`students` students ON students.admission_application_id = applications.admission_application_id
  WHERE applications.decision_status = 'accepted' AND students.student_id IS NULL
);

SET @mp5_student_with_nonaccepted := (
  SELECT COUNT(DISTINCT students.student_id)
  FROM `alrowad_uni_rust`.`ministry_placement_records` records
  INNER JOIN `alrowad_uni_rust`.`ministry_placement_batches` batches ON batches.batch_id = records.batch_id
  INNER JOIN `alrowad_uni_rust`.`admission_applications` applications ON applications.applicant_id = records.applicant_id
    AND applications.academic_program_id = records.matched_academic_program_id
    AND applications.academic_year_id = batches.academic_year_id
  INNER JOIN `alrowad_uni_rust`.`students` students ON students.admission_application_id = applications.admission_application_id
  WHERE applications.decision_status <> 'accepted'
);

SET @mp5_student_program_mismatch := (
  SELECT COUNT(DISTINCT students.student_id)
  FROM `alrowad_uni_rust`.`students` students
  INNER JOIN `alrowad_uni_rust`.`admission_applications` applications ON applications.admission_application_id = students.admission_application_id
  INNER JOIN `alrowad_uni_rust`.`ministry_placement_records` records ON records.applicant_id = applications.applicant_id
  INNER JOIN `alrowad_uni_rust`.`ministry_placement_batches` batches ON batches.batch_id = records.batch_id
    AND records.matched_academic_program_id = applications.academic_program_id
    AND batches.academic_year_id = applications.academic_year_id
  WHERE students.academic_program_id <> applications.academic_program_id
);

SET @mp5_enrolled_without_student := (
  SELECT COUNT(*)
  FROM `alrowad_uni_rust`.`ministry_placement_records` records
  INNER JOIN `alrowad_uni_rust`.`ministry_placement_batches` batches ON batches.batch_id = records.batch_id
  WHERE records.processing_status = 'enrolled' AND NOT EXISTS (
    SELECT 1 FROM `alrowad_uni_rust`.`admission_applications` applications
    INNER JOIN `alrowad_uni_rust`.`students` students ON students.admission_application_id = applications.admission_application_id AND students.deleted_at IS NULL
    WHERE applications.applicant_id = records.applicant_id
      AND applications.academic_program_id = records.matched_academic_program_id
      AND applications.academic_year_id = batches.academic_year_id
      AND applications.decision_status = 'accepted'
  )
);

SET @mp5_missing_expected_application := (
  SELECT COUNT(*)
  FROM `alrowad_uni_rust`.`ministry_placement_records` records
  INNER JOIN `alrowad_uni_rust`.`ministry_placement_batches` batches ON batches.batch_id = records.batch_id
  WHERE records.processing_status IN ('applicant_created', 'documents_pending', 'accepted', 'enrolled', 'rejected') AND NOT EXISTS (
    SELECT 1 FROM `alrowad_uni_rust`.`admission_applications` applications
    WHERE applications.applicant_id = records.applicant_id
      AND applications.academic_program_id = records.matched_academic_program_id
      AND applications.academic_year_id = batches.academic_year_id
  )
);

SET @mp5_invalid_provenance := (
  SELECT COUNT(DISTINCT applications.admission_application_id)
  FROM `alrowad_uni_rust`.`admission_applications` applications
  INNER JOIN `alrowad_uni_rust`.`ministry_placement_records` records ON records.applicant_id = applications.applicant_id
  INNER JOIN `alrowad_uni_rust`.`ministry_placement_batches` batches ON batches.batch_id = records.batch_id
    AND records.matched_academic_program_id = applications.academic_program_id
    AND batches.academic_year_id = applications.academic_year_id
  WHERE (applications.decision_status = 'pending' AND (applications.decision_date IS NOT NULL OR applications.decided_by_user_id IS NOT NULL))
     OR (applications.decision_status IN ('accepted', 'rejected') AND (applications.decision_date IS NULL OR applications.decided_by_user_id IS NULL))
);

SET @mp5_unknown_decisions := (
  SELECT COUNT(DISTINCT applications.admission_application_id)
  FROM `alrowad_uni_rust`.`admission_applications` applications
  INNER JOIN `alrowad_uni_rust`.`ministry_placement_records` records ON records.applicant_id = applications.applicant_id
  INNER JOIN `alrowad_uni_rust`.`ministry_placement_batches` batches ON batches.batch_id = records.batch_id
    AND records.matched_academic_program_id = applications.academic_program_id
    AND batches.academic_year_id = applications.academic_year_id
  WHERE applications.decision_status NOT IN ('pending', 'accepted', 'rejected')
);

SET @mp5_unknown_ministry_statuses := (
  SELECT COUNT(*) FROM `alrowad_uni_rust`.`ministry_placement_records`
  WHERE processing_status NOT IN ('imported', 'program_matched', 'applicant_created', 'documents_pending', 'accepted', 'enrolled', 'rejected')
);

SET @mp5_noncanonical_accepted_status := (
  SELECT COUNT(*) FROM `alrowad_uni_rust`.`ministry_placement_records`
  WHERE processing_status = 'accepted'
);

SET @mp5_relational_blockers := @mp5_multiple_application_contexts + @mp5_accepted_without_student
  + @mp5_student_with_nonaccepted + @mp5_student_program_mismatch + @mp5_enrolled_without_student
  + @mp5_missing_expected_application + @mp5_invalid_provenance + @mp5_unknown_decisions
  + @mp5_unknown_ministry_statuses + @mp5_noncanonical_accepted_status;

SET @mp5_structure_ready := @mp5_database_exists AND @mp5_required_tables
  AND @mp5_ministry_columns AND @mp5_applicant_columns AND @mp5_application_columns
  AND @mp5_student_columns AND @mp5_reference_columns AND @mp5_audit_auth_columns
  AND @mp5_primary_keys AND @mp5_student_uniqueness AND @mp5_ministry_identity_uniqueness
  AND @mp5_applicant_number_uniqueness AND @mp5_required_foreign_keys AND @mp5_required_indexes
  AND @mp5_reference_data AND @mp5_authorization;
SET @mp5_ready := @mp5_structure_ready AND @mp5_relational_blockers = 0;

SELECT 'MULTIPLE_APPLICATION_CONTEXTS' AS report_section,
       applications.applicant_id, applications.academic_program_id, applications.academic_year_id,
       COUNT(DISTINCT applications.admission_application_id) AS conflicting_count
FROM `alrowad_uni_rust`.`admission_applications` applications
INNER JOIN `alrowad_uni_rust`.`ministry_placement_records` records ON records.applicant_id = applications.applicant_id
INNER JOIN `alrowad_uni_rust`.`ministry_placement_batches` batches ON batches.batch_id = records.batch_id
  AND records.matched_academic_program_id = applications.academic_program_id
  AND batches.academic_year_id = applications.academic_year_id
GROUP BY applications.applicant_id, applications.academic_program_id, applications.academic_year_id
HAVING COUNT(DISTINCT applications.admission_application_id) > 1;

SELECT 'RELATIONAL_ANOMALY_TOTALS' AS report_section, 'ACCEPTED_WITHOUT_STUDENT' AS anomaly, @mp5_accepted_without_student AS total
UNION ALL SELECT 'RELATIONAL_ANOMALY_TOTALS', 'STUDENT_WITH_NONACCEPTED_APPLICATION', @mp5_student_with_nonaccepted
UNION ALL SELECT 'RELATIONAL_ANOMALY_TOTALS', 'STUDENT_PROGRAM_MISMATCH', @mp5_student_program_mismatch
UNION ALL SELECT 'RELATIONAL_ANOMALY_TOTALS', 'MINISTRY_ENROLLED_WITHOUT_STUDENT', @mp5_enrolled_without_student
UNION ALL SELECT 'RELATIONAL_ANOMALY_TOTALS', 'MISSING_EXPECTED_APPLICATION', @mp5_missing_expected_application
UNION ALL SELECT 'RELATIONAL_ANOMALY_TOTALS', 'INVALID_DECISION_PROVENANCE', @mp5_invalid_provenance
UNION ALL SELECT 'RELATIONAL_ANOMALY_TOTALS', 'UNKNOWN_DECISION_STATUS', @mp5_unknown_decisions
UNION ALL SELECT 'RELATIONAL_ANOMALY_TOTALS', 'UNKNOWN_MINISTRY_STATUS', @mp5_unknown_ministry_statuses
UNION ALL SELECT 'RELATIONAL_ANOMALY_TOTALS', 'NONCANONICAL_ACCEPTED_MINISTRY_STATUS', @mp5_noncanonical_accepted_status;

SELECT 'EXACT_IDENTITY_DUPLICATES_INFORMATIONAL_NON_AUTHORITATIVE' AS report_section,
       COUNT(*) AS exact_duplicate_group_count,
       COALESCE(SUM(exact_duplicate_count), 0) AS records_in_exact_duplicate_groups
FROM (
  SELECT COUNT(*) AS exact_duplicate_count
  FROM `alrowad_uni_rust`.`ministry_placement_records`
  WHERE national_civil_id IS NOT NULL AND TRIM(national_civil_id) <> ''
  GROUP BY national_civil_id
  HAVING COUNT(*) > 1
) exact_identity_groups;

SELECT 'IDENTITY_AUTHORITY_NOTE' AS report_section,
       'SQL exact equality is informational only; MinistryPlacementNormalizer::duplicateKey() and the application production_gate are authoritative.' AS result;

SELECT 'DATABASE_AND_TABLES' AS check_name, IF(@mp5_database_exists AND @mp5_required_tables, 'PASS', 'FAIL') AS result
UNION ALL SELECT 'REQUIRED_COLUMNS', IF(@mp5_ministry_columns AND @mp5_applicant_columns AND @mp5_application_columns AND @mp5_student_columns AND @mp5_reference_columns AND @mp5_audit_auth_columns, 'PASS', 'FAIL')
UNION ALL SELECT 'KEYS_INDEXES_AND_RELATIONSHIPS', IF(@mp5_primary_keys AND @mp5_required_indexes AND @mp5_required_foreign_keys, 'PASS', 'FAIL')
UNION ALL SELECT 'UNIQUENESS', IF(@mp5_student_uniqueness AND @mp5_ministry_identity_uniqueness AND @mp5_applicant_number_uniqueness, 'PASS', 'FAIL')
UNION ALL SELECT 'REFERENCE_DATA', IF(@mp5_reference_data, 'PASS', 'FAIL')
UNION ALL SELECT 'AUTHORIZATION_AND_AUDIT', IF(@mp5_authorization AND @mp5_audit_auth_columns, 'PASS', 'FAIL')
UNION ALL SELECT 'RELATIONAL_DATA_GATE', IF(@mp5_relational_blockers = 0, 'PASS', 'FAIL')
UNION ALL SELECT 'APPLICATION_GATE_REQUIRED', 'GET /api/v1/ministry-placement-reconciliation must also report production_gate=READY'
UNION ALL SELECT 'OVERALL', IF(@mp5_ready, 'READY', 'BLOCKED');
