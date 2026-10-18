-- READ ONLY. Independently verifies the deployed Phase-6 contract.
SET @phase6_owner := 'owned:supplementary-exam-materialization-phase6';
SET @phase6_permission := 'supplementary_exams.results.materialize';
SET @phase6_noop := 0;

SET @verify_dependencies := (
    SELECT COUNT(*) = 30
    FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust' AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
      AND table_name IN (
          'supplementary_exam_periods', 'supplementary_exam_period_events',
          'supplementary_exam_offerings', 'supplementary_exam_offering_sources',
          'supplementary_exam_registrations', 'supplementary_exam_grade_results',
          'supplementary_exam_grade_submissions', 'supplementary_exam_grade_events',
          'student_course_registrations',
          'student_course_results', 'course_offerings', 'grade_approvals',
          'approval_statuses', 'grade_components', 'student_grade_components',
          'grading_policies', 'registration_statuses', 'result_statuses',
          'students', 'users', 'roles', 'permissions', 'role_permissions',
          'user_roles', 'system_modules', 'user_access_scopes',
          'academic_programs', 'departments', 'colleges', 'organizational_units'
      )
) AND (
    SELECT COUNT(*) = 11
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_course_results'
      AND column_name IN (
          'student_course_result_id', 'student_course_registration_id',
          'theoretical_total', 'practical_total', 'coursework_total', 'final_mark',
          'result_status_id', 'is_deprived', 'calculated_at',
          'calculated_by_user_id', 'updated_at'
      )
) AND (
    SELECT COUNT(*) = 1
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods'
      AND column_name = 'status' AND data_type = 'varchar'
      AND character_maximum_length >= 20 AND is_nullable = 'NO'
);

SET @result_announced_at_exists := (
    SELECT COUNT(*) = 1
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'student_course_results'
      AND column_name = 'result_announced_at'
);
SET @result_announced_at_compatible := NOT @result_announced_at_exists OR (
    SELECT COUNT(*) = 1
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'student_course_results'
      AND column_name = 'result_announced_at'
      AND data_type IN ('datetime', 'timestamp')
      AND datetime_precision = 0
      AND LOWER(COALESCE(extra, '')) NOT LIKE '%on update%'
);
SET @result_announced_at_classification := IF(
    NOT @result_announced_at_exists,
    'ABSENT_OPTIONAL',
    IF(@result_announced_at_compatible, 'PRESENT_COMPATIBLE', 'CONFLICT')
);

SET @verify_application_columns := (
    SELECT COUNT(*) = 138
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND (
          (table_name = 'supplementary_exam_periods' AND column_name IN ('supplementary_exam_period_id', 'status', 'updated_at')) OR
          (table_name = 'supplementary_exam_period_events' AND column_name IN ('supplementary_exam_period_event_id', 'supplementary_exam_period_id', 'event_type', 'from_status', 'to_status', 'actor_user_id', 'notes', 'created_at')) OR
          (table_name = 'supplementary_exam_offerings' AND column_name IN ('supplementary_exam_offering_id', 'supplementary_exam_period_id', 'academic_program_id', 'course_id')) OR
          (table_name = 'supplementary_exam_offering_sources' AND column_name IN ('supplementary_exam_offering_source_id', 'supplementary_exam_offering_id', 'course_offering_id')) OR
          (table_name = 'supplementary_exam_registrations' AND column_name IN ('supplementary_exam_registration_id', 'supplementary_exam_offering_id', 'student_course_registration_id', 'student_id', 'status', 'current_slot', 'eligibility_reason', 'updated_at')) OR
          (table_name = 'supplementary_exam_grade_results' AND column_name IN ('supplementary_exam_grade_result_id', 'supplementary_exam_registration_id', 'supplementary_exam_offering_id', 'student_course_registration_id', 'student_id', 'theoretical_mark', 'status', 'submission_version', 'published_at', 'updated_at')) OR
          (table_name = 'supplementary_exam_grade_submissions' AND column_name IN ('supplementary_exam_grade_submission_id', 'supplementary_exam_offering_id', 'submission_version', 'status', 'published_at', 'updated_at')) OR
          (table_name = 'supplementary_exam_grade_events' AND column_name IN ('supplementary_exam_grade_event_id', 'supplementary_exam_grade_result_id', 'supplementary_exam_grade_submission_id', 'event_type', 'from_status', 'to_status', 'submission_version', 'theoretical_mark', 'actor_user_id', 'notes', 'created_at')) OR
          (table_name = 'student_course_registrations' AND column_name IN ('student_course_registration_id', 'student_id', 'course_offering_id', 'registration_status_id', 'result_status_id', 'updated_at')) OR
          (table_name = 'student_course_results' AND column_name IN ('student_course_result_id', 'student_course_registration_id', 'theoretical_total', 'practical_total', 'coursework_total', 'final_mark', 'result_status_id', 'is_deprived', 'calculated_at', 'calculated_by_user_id', 'updated_at')) OR
          (table_name = 'course_offerings' AND column_name IN ('course_offering_id', 'course_id', 'academic_program_id')) OR
          (table_name = 'grade_approvals' AND column_name IN ('grade_approval_id', 'course_offering_id', 'approval_status_id', 'updated_at')) OR
          (table_name = 'approval_statuses' AND column_name IN ('approval_status_id', 'status_code', 'is_active')) OR
          (table_name = 'grade_components' AND column_name IN ('grade_component_id', 'course_offering_id', 'component_type', 'max_mark', 'is_required', 'updated_at')) OR
          (table_name = 'student_grade_components' AND column_name IN ('student_grade_component_id', 'student_course_registration_id', 'grade_component_id', 'mark', 'grade_status', 'updated_at')) OR
          (table_name = 'grading_policies' AND column_name IN ('grading_policy_id', 'theoretical_max_mark', 'practical_max_mark', 'minimum_theoretical_mark', 'minimum_practical_mark', 'minimum_final_mark', 'is_default', 'is_active')) OR
          (table_name = 'registration_statuses' AND column_name IN ('registration_status_id', 'status_code')) OR
          (table_name = 'result_statuses' AND column_name IN ('result_status_id', 'status_code', 'is_active')) OR
          (table_name = 'students' AND column_name = 'student_id') OR
          (table_name = 'users' AND column_name = 'user_id') OR
          (table_name = 'roles' AND column_name IN ('role_id', 'role_code', 'is_active')) OR
          (table_name = 'permissions' AND column_name IN ('permission_id', 'module_id', 'permission_code', 'permission_name', 'description', 'is_active', 'created_at', 'updated_at')) OR
          (table_name = 'role_permissions' AND column_name IN ('role_id', 'permission_id', 'granted_at')) OR
          (table_name = 'user_roles' AND column_name IN ('user_id', 'role_id', 'is_active')) OR
          (table_name = 'system_modules' AND column_name IN ('module_id', 'module_code', 'is_active')) OR
          (table_name = 'user_access_scopes' AND column_name IN ('user_id', 'scope_type', 'scope_id', 'is_active')) OR
          (table_name = 'academic_programs' AND column_name IN ('academic_program_id', 'department_id')) OR
          (table_name = 'departments' AND column_name IN ('department_id', 'college_id')) OR
          (table_name = 'colleges' AND column_name = 'college_id') OR
          (table_name = 'organizational_units' AND column_name IN ('organizational_unit_id', 'unit_code'))
      )
);
SET @verify_drift_timestamps := (
    SELECT COUNT(*) = 11
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND data_type IN ('datetime', 'timestamp')
      AND (
          (table_name = 'supplementary_exam_registrations' AND column_name = 'updated_at') OR
          (table_name = 'supplementary_exam_grade_results' AND column_name IN ('published_at', 'updated_at')) OR
          (table_name = 'supplementary_exam_grade_submissions' AND column_name IN ('published_at', 'updated_at')) OR
          (table_name = 'supplementary_exam_grade_events' AND column_name = 'created_at') OR
          (table_name = 'student_course_registrations' AND column_name = 'updated_at') OR
          (table_name = 'student_course_results' AND column_name = 'updated_at') OR
          (table_name = 'grade_approvals' AND column_name = 'updated_at') OR
          (table_name = 'grade_components' AND column_name = 'updated_at') OR
          (table_name = 'student_grade_components' AND column_name = 'updated_at')
      )
);
SET @verify_signed_parent_ids := (
    SELECT COUNT(*) = 13
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND data_type = 'int'
      AND column_type NOT LIKE '%unsigned%'
      AND is_nullable = 'NO'
      AND (
          (table_name = 'supplementary_exam_registrations' AND column_name = 'supplementary_exam_registration_id') OR
          (table_name = 'supplementary_exam_offerings' AND column_name = 'supplementary_exam_offering_id') OR
          (table_name = 'supplementary_exam_grade_results' AND column_name = 'supplementary_exam_grade_result_id') OR
          (table_name = 'supplementary_exam_grade_submissions' AND column_name = 'supplementary_exam_grade_submission_id') OR
          (table_name = 'supplementary_exam_grade_events' AND column_name = 'supplementary_exam_grade_event_id') OR
          (table_name = 'student_course_registrations' AND column_name = 'student_course_registration_id') OR
          (table_name = 'student_course_results' AND column_name = 'student_course_result_id') OR
          (table_name = 'students' AND column_name = 'student_id') OR
          (table_name = 'grading_policies' AND column_name = 'grading_policy_id') OR
          (table_name = 'grade_approvals' AND column_name = 'grade_approval_id') OR
          (table_name = 'registration_statuses' AND column_name = 'registration_status_id') OR
          (table_name = 'result_statuses' AND column_name = 'result_status_id') OR
          (table_name = 'users' AND column_name = 'user_id')
      )
);
SET @verify_parent_primary_keys := (
    SELECT COUNT(*) = 13
    FROM (
        SELECT table_name, non_unique,
            GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS index_columns
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND index_name = 'PRIMARY'
          AND table_name IN (
              'supplementary_exam_registrations', 'supplementary_exam_offerings',
              'supplementary_exam_grade_results', 'supplementary_exam_grade_submissions',
              'supplementary_exam_grade_events',
              'student_course_registrations', 'student_course_results', 'students',
              'grading_policies', 'grade_approvals', 'registration_statuses',
              'result_statuses', 'users'
          )
        GROUP BY table_name, index_name, non_unique
    ) parent_keys
    WHERE non_unique = 0
      AND (
          (table_name = 'supplementary_exam_registrations' AND index_columns = 'supplementary_exam_registration_id') OR
          (table_name = 'supplementary_exam_offerings' AND index_columns = 'supplementary_exam_offering_id') OR
          (table_name = 'supplementary_exam_grade_results' AND index_columns = 'supplementary_exam_grade_result_id') OR
          (table_name = 'supplementary_exam_grade_submissions' AND index_columns = 'supplementary_exam_grade_submission_id') OR
          (table_name = 'supplementary_exam_grade_events' AND index_columns = 'supplementary_exam_grade_event_id') OR
          (table_name = 'student_course_registrations' AND index_columns = 'student_course_registration_id') OR
          (table_name = 'student_course_results' AND index_columns = 'student_course_result_id') OR
          (table_name = 'students' AND index_columns = 'student_id') OR
          (table_name = 'grading_policies' AND index_columns = 'grading_policy_id') OR
          (table_name = 'grade_approvals' AND index_columns = 'grade_approval_id') OR
          (table_name = 'registration_statuses' AND index_columns = 'registration_status_id') OR
          (table_name = 'result_statuses' AND index_columns = 'result_status_id') OR
          (table_name = 'users' AND index_columns = 'user_id')
      )
);
SET @verify_dependencies := @verify_dependencies AND @result_announced_at_compatible
    AND @verify_application_columns
    AND @verify_drift_timestamps AND @verify_signed_parent_ids
    AND @verify_parent_primary_keys;

SET @verify_mat_table := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materializations'
      AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
);
SET @verify_mat_columns := (
    SELECT COUNT(*) = 50
       AND SUM(
           CASE
               WHEN column_name = 'supplementary_exam_materialization_id'
                   THEN data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO' AND column_default IS NULL AND LOWER(extra) = 'auto_increment'
               WHEN column_name IN (
                   'supplementary_exam_registration_id', 'supplementary_exam_offering_id',
                   'supplementary_exam_grade_result_id', 'supplementary_exam_grade_event_id',
                   'supplementary_exam_grade_submission_id',
                   'source_submission_version', 'student_course_registration_id',
                   'student_course_result_id', 'student_id', 'grading_policy_id',
                   'grade_approval_id', 'preserved_registration_status_id',
                   'before_result_status_id', 'after_result_status_id',
                   'after_registration_result_status_id', 'after_calculated_by_user_id',
                   'materialized_by_user_id'
               ) THEN data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''
               WHEN column_name IN ('before_registration_result_status_id', 'before_calculated_by_user_id')
                   THEN data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES' AND (
                       column_default IS NULL
                       OR UPPER(
                           TRIM(
                               BOTH ''''
                               FROM CAST(column_default AS CHAR)
                           )
                       ) = 'NULL'
                   ) AND extra = ''
               WHEN column_name IN ('before_is_deprived', 'after_is_deprived')
                   THEN data_type = 'tinyint' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''
               WHEN column_name IN (
                   'source_theoretical_mark', 'before_theoretical_total', 'before_practical_total',
                   'before_coursework_total', 'before_final_mark', 'after_theoretical_total',
                   'after_practical_total', 'after_coursework_total', 'after_final_mark'
               ) THEN data_type = 'decimal' AND numeric_precision = 5 AND numeric_scale = 2 AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''
                WHEN column_name IN (
                    'practical_components_snapshot',
                    'before_theoretical_components_snapshot',
                    'after_theoretical_components_snapshot'
                )
                    THEN data_type IN ('longtext', 'json') AND is_nullable = 'NO' AND column_default IS NULL
               WHEN column_name IN (
                   'source_result_published_at', 'source_submission_published_at',
                   'source_registration_updated_at', 'grade_approval_updated_at',
                   'source_result_updated_at', 'source_submission_updated_at',
                   'before_result_updated_at', 'before_registration_updated_at',
                   'after_calculated_at', 'after_result_updated_at',
                   'after_registration_updated_at', 'materialized_at', 'created_at'
               ) THEN data_type = 'datetime' AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''
               WHEN column_name IN ('before_calculated_at', 'before_result_announced_at', 'after_result_announced_at')
                   THEN data_type = 'datetime' AND is_nullable = 'YES' AND (
                       column_default IS NULL
                       OR UPPER(
                           TRIM(
                               BOTH ''''
                               FROM CAST(column_default AS CHAR)
                           )
                       ) = 'NULL'
                   ) AND extra = ''
               ELSE 0
           END
       ) = 50
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materializations'
);
SET @verify_mat_primary := (
    SELECT COUNT(*) = 1
    FROM (
        SELECT non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS index_columns
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_materializations'
          AND index_name = 'PRIMARY'
        GROUP BY index_name, non_unique
    ) primary_found
    WHERE non_unique = 0 AND index_columns = 'supplementary_exam_materialization_id'
);
SET @verify_mat_json := (
    SELECT COUNT(*) = 3
    FROM information_schema.columns c
    WHERE c.table_schema = 'alrowad_uni_rust'
      AND c.table_name = 'supplementary_exam_materializations'
      AND c.column_name IN (
          'practical_components_snapshot',
          'before_theoretical_components_snapshot',
          'after_theoretical_components_snapshot'
      )
      AND (
          c.data_type = 'json'
          OR (
              c.data_type = 'longtext'
              AND EXISTS (
                  SELECT 1
                  FROM information_schema.table_constraints tc
                  JOIN information_schema.check_constraints cc
                    ON cc.constraint_schema = tc.constraint_schema
                   AND cc.constraint_name = tc.constraint_name
                  WHERE tc.table_schema = c.table_schema
                    AND tc.table_name = c.table_name
                    AND tc.constraint_type = 'CHECK'
                    AND LOWER(cc.check_clause) LIKE '%json_valid%'
                    AND LOWER(cc.check_clause) LIKE CONCAT('%', LOWER(c.column_name), '%')
              )
          )
      )
);
SET @verify_mat_indexes := (
    SELECT COUNT(DISTINCT CONCAT(non_unique, ':', index_columns)) = 13
    FROM (
        SELECT non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS index_columns
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materializations'
        GROUP BY index_name, non_unique
    ) indexes_found
    WHERE (non_unique = 0 AND index_columns IN (
        'supplementary_exam_materialization_id', 'supplementary_exam_registration_id',
        'supplementary_exam_grade_result_id', 'supplementary_exam_grade_event_id',
        'student_course_registration_id',
        'student_course_result_id',
        'supplementary_exam_grade_submission_id,source_submission_version,supplementary_exam_registration_id'
    )) OR (non_unique = 1 AND index_columns IN (
        'supplementary_exam_offering_id,materialized_at', 'student_id',
        'grading_policy_id', 'grade_approval_id', 'preserved_registration_status_id',
        'materialized_by_user_id'
    ))
) AND (
    SELECT COUNT(*) = 0
    FROM (
        SELECT non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS index_columns
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materializations'
        GROUP BY index_name, non_unique
    ) unique_indexes_found
    WHERE non_unique = 0 AND index_columns NOT IN (
        'supplementary_exam_materialization_id', 'supplementary_exam_registration_id',
        'supplementary_exam_grade_result_id', 'supplementary_exam_grade_event_id',
        'student_course_registration_id', 'student_course_result_id',
        'supplementary_exam_grade_submission_id,source_submission_version,supplementary_exam_registration_id'
    )
);
SET @verify_mat_fks := (
    SELECT COUNT(*) = 18
    FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materializations'
      AND referenced_table_schema = 'alrowad_uni_rust'
      AND (
          (column_name = 'supplementary_exam_registration_id' AND referenced_table_name = 'supplementary_exam_registrations' AND referenced_column_name = 'supplementary_exam_registration_id') OR
          (column_name = 'supplementary_exam_offering_id' AND referenced_table_name = 'supplementary_exam_offerings' AND referenced_column_name = 'supplementary_exam_offering_id') OR
          (column_name = 'supplementary_exam_grade_result_id' AND referenced_table_name = 'supplementary_exam_grade_results' AND referenced_column_name = 'supplementary_exam_grade_result_id') OR
          (column_name = 'supplementary_exam_grade_event_id' AND referenced_table_name = 'supplementary_exam_grade_events' AND referenced_column_name = 'supplementary_exam_grade_event_id') OR
          (column_name = 'supplementary_exam_grade_submission_id' AND referenced_table_name = 'supplementary_exam_grade_submissions' AND referenced_column_name = 'supplementary_exam_grade_submission_id') OR
          (column_name = 'student_course_registration_id' AND referenced_table_name = 'student_course_registrations' AND referenced_column_name = 'student_course_registration_id') OR
          (column_name = 'student_course_result_id' AND referenced_table_name = 'student_course_results' AND referenced_column_name = 'student_course_result_id') OR
          (column_name = 'student_id' AND referenced_table_name = 'students' AND referenced_column_name = 'student_id') OR
          (column_name = 'grading_policy_id' AND referenced_table_name = 'grading_policies' AND referenced_column_name = 'grading_policy_id') OR
          (column_name = 'grade_approval_id' AND referenced_table_name = 'grade_approvals' AND referenced_column_name = 'grade_approval_id') OR
          (column_name = 'preserved_registration_status_id' AND referenced_table_name = 'registration_statuses' AND referenced_column_name = 'registration_status_id') OR
          (column_name IN ('before_result_status_id', 'before_registration_result_status_id', 'after_result_status_id', 'after_registration_result_status_id') AND referenced_table_name = 'result_statuses' AND referenced_column_name = 'result_status_id') OR
          (column_name IN ('before_calculated_by_user_id', 'after_calculated_by_user_id', 'materialized_by_user_id') AND referenced_table_name = 'users' AND referenced_column_name = 'user_id')
      )
) AND (
    SELECT COUNT(*) = 18
    FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materializations'
      AND referenced_table_name IS NOT NULL
) AND (
    SELECT COUNT(*) = 18
    FROM information_schema.referential_constraints
    WHERE constraint_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materializations'
      AND update_rule IN ('RESTRICT', 'NO ACTION')
      AND delete_rule IN ('RESTRICT', 'NO ACTION')
);

SET @verify_event_table := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materialization_events'
      AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
);
SET @verify_event_columns := (
    SELECT COUNT(*) = 8
       AND SUM(
           CASE
               WHEN column_name = 'supplementary_exam_materialization_event_id'
                   THEN data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO' AND column_default IS NULL AND LOWER(extra) = 'auto_increment'
               WHEN column_name IN ('supplementary_exam_materialization_id', 'supplementary_exam_offering_id', 'supplementary_exam_registration_id', 'source_submission_version', 'actor_user_id')
                   THEN data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''
               WHEN column_name = 'event_type'
                   THEN data_type = 'varchar' AND character_maximum_length >= 40 AND is_nullable = 'NO' AND column_default IS NULL
               WHEN column_name = 'created_at'
                   THEN data_type = 'datetime' AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''
               ELSE 0
           END
       ) = 8
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materialization_events'
);
SET @verify_event_primary := (
    SELECT COUNT(*) = 1
    FROM (
        SELECT non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS index_columns
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_materialization_events'
          AND index_name = 'PRIMARY'
        GROUP BY index_name, non_unique
    ) primary_found
    WHERE non_unique = 0 AND index_columns = 'supplementary_exam_materialization_event_id'
);
SET @verify_event_indexes := (
    SELECT COUNT(DISTINCT CONCAT(non_unique, ':', index_columns)) = 6
    FROM (
        SELECT non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS index_columns
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materialization_events'
        GROUP BY index_name, non_unique
    ) indexes_found
    WHERE (non_unique = 0 AND index_columns IN ('supplementary_exam_materialization_event_id', 'supplementary_exam_materialization_id'))
       OR (non_unique = 1 AND index_columns IN ('supplementary_exam_offering_id', 'supplementary_exam_registration_id', 'actor_user_id', 'event_type,created_at'))
) AND (
    SELECT COUNT(*) = 0
    FROM (
        SELECT non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS index_columns
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materialization_events'
        GROUP BY index_name, non_unique
    ) unique_indexes_found
    WHERE non_unique = 0 AND index_columns NOT IN (
        'supplementary_exam_materialization_event_id', 'supplementary_exam_materialization_id'
    )
);
SET @verify_event_fks := (
    SELECT COUNT(*) = 4
    FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materialization_events'
      AND referenced_table_schema = 'alrowad_uni_rust'
      AND (
          (column_name = 'supplementary_exam_materialization_id' AND referenced_table_name = 'supplementary_exam_materializations' AND referenced_column_name = 'supplementary_exam_materialization_id') OR
          (column_name = 'supplementary_exam_offering_id' AND referenced_table_name = 'supplementary_exam_offerings' AND referenced_column_name = 'supplementary_exam_offering_id') OR
          (column_name = 'supplementary_exam_registration_id' AND referenced_table_name = 'supplementary_exam_registrations' AND referenced_column_name = 'supplementary_exam_registration_id') OR
          (column_name = 'actor_user_id' AND referenced_table_name = 'users' AND referenced_column_name = 'user_id')
      )
) AND (
    SELECT COUNT(*) = 4
    FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materialization_events'
      AND referenced_table_name IS NOT NULL
) AND (
    SELECT COUNT(*) = 4
    FROM information_schema.referential_constraints
    WHERE constraint_schema = 'alrowad_uni_rust'
      AND table_name = 'supplementary_exam_materialization_events'
      AND update_rule IN ('RESTRICT', 'NO ACTION')
      AND delete_rule IN ('RESTRICT', 'NO ACTION')
);

SET @verify_fk_types := (
    SELECT COUNT(*) = 22
    FROM information_schema.key_column_usage k
    JOIN information_schema.columns local_column
      ON local_column.table_schema = k.table_schema
     AND local_column.table_name = k.table_name
     AND local_column.column_name = k.column_name
    JOIN information_schema.columns parent_column
      ON parent_column.table_schema = k.referenced_table_schema
     AND parent_column.table_name = k.referenced_table_name
     AND parent_column.column_name = k.referenced_column_name
    WHERE k.table_schema = 'alrowad_uni_rust'
      AND k.table_name IN ('supplementary_exam_materializations', 'supplementary_exam_materialization_events')
      AND k.referenced_table_name IS NOT NULL
      AND local_column.data_type = parent_column.data_type
      AND (local_column.column_type LIKE '%unsigned%') = (parent_column.column_type LIKE '%unsigned%')
);

SET @verify_owned_tables := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name IN ('supplementary_exam_materializations', 'supplementary_exam_materialization_events')
      AND table_comment = 'owned:supplementary-exam-materialization-phase6'
);

SET @verify_rbac := 0;
SET @verify_permission_owned := 0;
SET @sql := IF(
    @verify_dependencies,
    'SELECT (COUNT(*) = 1 AND COALESCE(SUM(p.is_active = 1 AND m.module_code = ''exams'' AND m.is_active = 1), 0) = 1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id WHERE rp.permission_id = (SELECT permission_id FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''supplementary_exams.results.materialize'' LIMIT 1) AND r.role_code = ''exam_officer'' AND r.is_active = 1) = 1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp2 WHERE rp2.permission_id = (SELECT permission_id FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''supplementary_exams.results.materialize'' LIMIT 1)) = 1), (COALESCE(SUM(p.description = ''owned:supplementary-exam-materialization-phase6''), 0) = 1) INTO @verify_rbac, @verify_permission_owned FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id WHERE p.permission_code = ''supplementary_exams.results.materialize''',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_verify_rbac FROM @sql;
EXECUTE phase6_verify_rbac;
DEALLOCATE PREPARE phase6_verify_rbac;

SET @verify_mat_compatible := @verify_mat_table AND @verify_mat_columns
    AND @verify_mat_primary AND @verify_mat_json AND @verify_mat_indexes AND @verify_mat_fks;
SET @verify_event_compatible := @verify_event_table AND @verify_event_columns
    AND @verify_event_primary AND @verify_event_indexes AND @verify_event_fks;
SET @verify_mat_exists := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materializations'
);
SET @verify_event_exists := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materialization_events'
);
SET @verify_mat_classification := IF(NOT @verify_mat_exists, 'ABSENT', IF(@verify_mat_compatible, 'COMPATIBLE', 'CONFLICT'));
SET @verify_event_classification := IF(NOT @verify_event_exists, 'ABSENT', IF(@verify_event_compatible, 'COMPATIBLE', 'CONFLICT'));
SET @verify_pass := @verify_dependencies AND @verify_mat_compatible
    AND @verify_event_compatible AND @verify_fk_types AND @verify_rbac;

-- This is the only visible operator report. Extra harmless FK-created indexes do not fail it.
SELECT report_section, result
FROM (
    SELECT 10 AS sort_order, 'DEPENDENCIES' AS report_section, IF(@verify_dependencies, 'PASS', 'FAIL') AS result
    UNION ALL SELECT 12, 'OPTIONAL_RESULT_ANNOUNCED_AT', @result_announced_at_classification
    UNION ALL SELECT 15, 'supplementary_exam_materializations', @verify_mat_classification
    UNION ALL SELECT 20, 'MATERIALIZATION_COLUMNS', IF(@verify_mat_columns, 'PASS', 'FAIL')
    UNION ALL SELECT 25, 'MATERIALIZATION_PRIMARY_AND_JSON', IF(@verify_mat_primary AND @verify_mat_json, 'PASS', 'FAIL')
    UNION ALL SELECT 30, 'MATERIALIZATION_REQUIRED_INDEXES', IF(@verify_mat_indexes, 'PASS', 'FAIL')
    UNION ALL SELECT 40, 'MATERIALIZATION_FOREIGN_KEYS', IF(@verify_mat_fks AND @verify_fk_types, 'PASS', 'FAIL')
    UNION ALL SELECT 45, 'supplementary_exam_materialization_events', @verify_event_classification
    UNION ALL SELECT 50, 'EVENT_COLUMNS', IF(@verify_event_columns, 'PASS', 'FAIL')
    UNION ALL SELECT 55, 'EVENT_PRIMARY_KEY', IF(@verify_event_primary, 'PASS', 'FAIL')
    UNION ALL SELECT 60, 'EVENT_REQUIRED_INDEXES', IF(@verify_event_indexes, 'PASS', 'FAIL')
    UNION ALL SELECT 70, 'EVENT_FOREIGN_KEYS', IF(@verify_event_fks AND @verify_fk_types, 'PASS', 'FAIL')
    UNION ALL SELECT 80, 'TABLE_OWNERSHIP', IF(@verify_owned_tables = 2, 'OWNED', 'ADOPTED')
    UNION ALL SELECT 90, @phase6_permission, IF(@verify_rbac, IF(@verify_permission_owned, 'OWNED', 'ADOPTED'), 'FAIL')
    UNION ALL SELECT 99, 'OVERALL', IF(@verify_pass, 'PASS', 'FAIL')
) report
ORDER BY sort_order;
