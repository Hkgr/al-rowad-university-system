-- Manual, idempotent Phase-6 apply. This file recomputes every guard itself.
SET @phase6_owner := 'owned:supplementary-exam-materialization-phase6';
SET @phase6_permission := 'supplementary_exams.results.materialize';
SET @phase6_noop := 0;

SET @dependency_tables_ready := (
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
);
SET @canonical_result_ready := (
    SELECT COUNT(*) = 12
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_course_results'
      AND column_name IN (
          'student_course_result_id', 'student_course_registration_id',
          'theoretical_total', 'practical_total', 'coursework_total', 'final_mark',
          'result_status_id', 'is_deprived', 'calculated_at',
          'result_announced_at', 'calculated_by_user_id', 'updated_at'
      )
);
SET @period_status_ready := (
    SELECT COUNT(*) = 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods'
      AND column_name = 'status' AND data_type = 'varchar'
      AND character_maximum_length >= 20 AND is_nullable = 'NO'
);
SET @signed_parent_ids_ready := (
    SELECT COUNT(*) = 13
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND data_type = 'int'
      AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO'
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
SET @parent_primary_keys_ready := (
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
SET @application_columns_ready := (
    SELECT COUNT(*) = 139
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
          (table_name = 'student_course_results' AND column_name IN ('student_course_result_id', 'student_course_registration_id', 'theoretical_total', 'practical_total', 'coursework_total', 'final_mark', 'result_status_id', 'is_deprived', 'calculated_at', 'result_announced_at', 'calculated_by_user_id', 'updated_at')) OR
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

SET @drift_timestamps_ready := (
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

SET @rbac_dependencies_ready := 0;
SET @sql := IF(
    @dependency_tables_ready,
    'SELECT ((SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = ''exam_officer'' AND is_active = 1) = 1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = ''exams'' AND is_active = 1) = 1) INTO @rbac_dependencies_ready',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_apply_rbac FROM @sql;
EXECUTE phase6_apply_rbac;
DEALLOCATE PREPARE phase6_apply_rbac;

SET @mat_exists := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materializations'
);
SET @mat_columns_ready := (
    SELECT COUNT(*) = 48
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
                   THEN data_type = 'int' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'YES' AND column_default IS NULL AND extra = ''
               WHEN column_name IN ('before_is_deprived', 'after_is_deprived')
                   THEN data_type = 'tinyint' AND column_type NOT LIKE '%unsigned%' AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''
               WHEN column_name IN (
                   'source_theoretical_mark', 'before_theoretical_total', 'before_practical_total',
                   'before_coursework_total', 'before_final_mark', 'after_theoretical_total',
                   'after_practical_total', 'after_coursework_total', 'after_final_mark'
               ) THEN data_type = 'decimal' AND numeric_precision = 5 AND numeric_scale = 2 AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''
               WHEN column_name = 'practical_components_snapshot'
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
                   THEN data_type = 'datetime' AND is_nullable = 'YES' AND column_default IS NULL AND extra = ''
               ELSE 0
           END
       ) = 48
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materializations'
);
SET @mat_primary_ready := (
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
SET @mat_json_ready := (
    SELECT COUNT(*) = 1
    FROM information_schema.columns c
    WHERE c.table_schema = 'alrowad_uni_rust'
      AND c.table_name = 'supplementary_exam_materializations'
      AND c.column_name = 'practical_components_snapshot'
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
                    AND LOWER(cc.check_clause) LIKE '%practical_components_snapshot%'
              )
          )
      )
);
SET @mat_indexes_ready := (
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
SET @mat_fks_ready := (
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
SET @mat_compatible := @mat_exists
    AND (SELECT COUNT(*) = 1 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materializations' AND table_type = 'BASE TABLE' AND engine = 'InnoDB')
    AND @mat_columns_ready AND @mat_primary_ready AND @mat_json_ready
    AND @mat_indexes_ready AND @mat_fks_ready;
SET @mat_classification := IF(NOT @mat_exists, 'ABSENT', IF(@mat_compatible, 'COMPATIBLE', 'CONFLICT'));

SET @event_exists := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materialization_events'
);
SET @event_columns_ready := (
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
SET @event_primary_ready := (
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
SET @event_indexes_ready := (
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
SET @event_fks_ready := (
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
SET @event_compatible := @event_exists
    AND (SELECT COUNT(*) = 1 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materialization_events' AND table_type = 'BASE TABLE' AND engine = 'InnoDB')
    AND @event_columns_ready AND @event_primary_ready AND @event_indexes_ready AND @event_fks_ready;
SET @event_classification := IF(NOT @event_exists, 'ABSENT', IF(@event_compatible, 'COMPATIBLE', 'CONFLICT'));

SET @permission_exists := 0;
SET @permission_compatible := 0;
SET @mapping_exists := 0;
SET @sql := IF(
    @dependency_tables_ready,
    'SELECT (COUNT(*) = 1), (COUNT(*) = 1 AND SUM(p.is_active = 1 AND m.module_code = ''exams'' AND m.is_active = 1 AND ((p.description = ''owned:supplementary-exam-materialization-phase6'' AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp0 WHERE rp0.permission_id = p.permission_id) = 0) OR ((SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp1 WHERE rp1.permission_id = p.permission_id) = 1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp2 JOIN `alrowad_uni_rust`.`roles` r2 ON r2.role_id = rp2.role_id WHERE rp2.permission_id = p.permission_id AND r2.role_code = ''exam_officer'' AND r2.is_active = 1) = 1))) = 1), (COUNT(*) = 1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp3 JOIN `alrowad_uni_rust`.`roles` r3 ON r3.role_id = rp3.role_id WHERE rp3.permission_id = (SELECT permission_id FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''supplementary_exams.results.materialize'' LIMIT 1) AND r3.role_code = ''exam_officer'' AND r3.is_active = 1) = 1) INTO @permission_exists, @permission_compatible, @mapping_exists FROM `alrowad_uni_rust`.`permissions` p LEFT JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id WHERE p.permission_code = ''supplementary_exams.results.materialize''',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_apply_permission_guard FROM @sql;
EXECUTE phase6_apply_permission_guard;
DEALLOCATE PREPARE phase6_apply_permission_guard;
SET @permission_classification := IF(NOT @permission_exists, 'ABSENT', IF(@permission_compatible, 'COMPATIBLE', 'CONFLICT'));

SET @dependencies_ready := @dependency_tables_ready AND @canonical_result_ready
    AND @period_status_ready AND @signed_parent_ids_ready AND @parent_primary_keys_ready
    AND @application_columns_ready AND @drift_timestamps_ready
    AND @rbac_dependencies_ready;
SET @apply_ready := @dependencies_ready
    AND @mat_classification <> 'CONFLICT'
    AND @event_classification <> 'CONFLICT'
    AND @permission_classification <> 'CONFLICT';
SET @initial_changes_needed := (NOT @mat_exists) + (NOT @event_exists) + (NOT @permission_exists) + (NOT @mapping_exists);

SET @sql := IF(
    @apply_ready AND NOT @mat_exists,
    'CREATE TABLE `alrowad_uni_rust`.`supplementary_exam_materializations` (
        `supplementary_exam_materialization_id` INT NOT NULL AUTO_INCREMENT,
        `supplementary_exam_registration_id` INT NOT NULL,
        `supplementary_exam_offering_id` INT NOT NULL,
        `supplementary_exam_grade_result_id` INT NOT NULL,
        `supplementary_exam_grade_event_id` INT NOT NULL,
        `supplementary_exam_grade_submission_id` INT NOT NULL,
        `source_submission_version` INT NOT NULL,
        `student_course_registration_id` INT NOT NULL,
        `student_course_result_id` INT NOT NULL,
        `student_id` INT NOT NULL,
        `grading_policy_id` INT NOT NULL,
        `grade_approval_id` INT NOT NULL,
        `preserved_registration_status_id` INT NOT NULL,
        `source_theoretical_mark` DECIMAL(5,2) NOT NULL,
        `practical_components_snapshot` LONGTEXT NOT NULL,
        `source_registration_updated_at` DATETIME NOT NULL,
        `source_result_published_at` DATETIME NOT NULL,
        `source_submission_published_at` DATETIME NOT NULL,
        `source_result_updated_at` DATETIME NOT NULL,
        `source_submission_updated_at` DATETIME NOT NULL,
        `grade_approval_updated_at` DATETIME NOT NULL,
        `before_theoretical_total` DECIMAL(5,2) NOT NULL,
        `before_practical_total` DECIMAL(5,2) NOT NULL,
        `before_coursework_total` DECIMAL(5,2) NOT NULL,
        `before_final_mark` DECIMAL(5,2) NOT NULL,
        `before_result_status_id` INT NOT NULL,
        `before_registration_result_status_id` INT NULL,
        `before_is_deprived` TINYINT(1) NOT NULL,
        `before_calculated_at` DATETIME NULL,
        `before_result_announced_at` DATETIME NULL,
        `before_calculated_by_user_id` INT NULL,
        `before_result_updated_at` DATETIME NOT NULL,
        `before_registration_updated_at` DATETIME NOT NULL,
        `after_theoretical_total` DECIMAL(5,2) NOT NULL,
        `after_practical_total` DECIMAL(5,2) NOT NULL,
        `after_coursework_total` DECIMAL(5,2) NOT NULL,
        `after_final_mark` DECIMAL(5,2) NOT NULL,
        `after_result_status_id` INT NOT NULL,
        `after_registration_result_status_id` INT NOT NULL,
        `after_is_deprived` TINYINT(1) NOT NULL,
        `after_calculated_at` DATETIME NOT NULL,
        `after_result_announced_at` DATETIME NULL,
        `after_calculated_by_user_id` INT NOT NULL,
        `after_result_updated_at` DATETIME NOT NULL,
        `after_registration_updated_at` DATETIME NOT NULL,
        `materialized_by_user_id` INT NOT NULL,
        `materialized_at` DATETIME NOT NULL,
        `created_at` DATETIME NOT NULL,
        CONSTRAINT `sem6_practical_snapshot_json` CHECK (JSON_VALID(`practical_components_snapshot`)),
        PRIMARY KEY (`supplementary_exam_materialization_id`),
        UNIQUE KEY `sem6_registration_uq` (`supplementary_exam_registration_id`),
        UNIQUE KEY `sem6_grade_result_uq` (`supplementary_exam_grade_result_id`),
        UNIQUE KEY `sem6_grade_event_uq` (`supplementary_exam_grade_event_id`),
        UNIQUE KEY `sem6_target_registration_uq` (`student_course_registration_id`),
        UNIQUE KEY `sem6_target_result_uq` (`student_course_result_id`),
        UNIQUE KEY `sem6_source_version_uq` (`supplementary_exam_grade_submission_id`, `source_submission_version`, `supplementary_exam_registration_id`),
        KEY `sem6_offering_time_ix` (`supplementary_exam_offering_id`, `materialized_at`),
        KEY `sem6_student_ix` (`student_id`),
        KEY `sem6_policy_ix` (`grading_policy_id`),
        KEY `sem6_approval_ix` (`grade_approval_id`),
        KEY `sem6_registration_status_ix` (`preserved_registration_status_id`),
        KEY `sem6_actor_ix` (`materialized_by_user_id`),
        CONSTRAINT `sem6_registration_fk` FOREIGN KEY (`supplementary_exam_registration_id`) REFERENCES `alrowad_uni_rust`.`supplementary_exam_registrations` (`supplementary_exam_registration_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_offering_fk` FOREIGN KEY (`supplementary_exam_offering_id`) REFERENCES `alrowad_uni_rust`.`supplementary_exam_offerings` (`supplementary_exam_offering_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_grade_result_fk` FOREIGN KEY (`supplementary_exam_grade_result_id`) REFERENCES `alrowad_uni_rust`.`supplementary_exam_grade_results` (`supplementary_exam_grade_result_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_grade_event_fk` FOREIGN KEY (`supplementary_exam_grade_event_id`) REFERENCES `alrowad_uni_rust`.`supplementary_exam_grade_events` (`supplementary_exam_grade_event_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_submission_fk` FOREIGN KEY (`supplementary_exam_grade_submission_id`) REFERENCES `alrowad_uni_rust`.`supplementary_exam_grade_submissions` (`supplementary_exam_grade_submission_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_target_registration_fk` FOREIGN KEY (`student_course_registration_id`) REFERENCES `alrowad_uni_rust`.`student_course_registrations` (`student_course_registration_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_target_result_fk` FOREIGN KEY (`student_course_result_id`) REFERENCES `alrowad_uni_rust`.`student_course_results` (`student_course_result_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_student_fk` FOREIGN KEY (`student_id`) REFERENCES `alrowad_uni_rust`.`students` (`student_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_policy_fk` FOREIGN KEY (`grading_policy_id`) REFERENCES `alrowad_uni_rust`.`grading_policies` (`grading_policy_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_approval_fk` FOREIGN KEY (`grade_approval_id`) REFERENCES `alrowad_uni_rust`.`grade_approvals` (`grade_approval_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_registration_status_fk` FOREIGN KEY (`preserved_registration_status_id`) REFERENCES `alrowad_uni_rust`.`registration_statuses` (`registration_status_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_before_status_fk` FOREIGN KEY (`before_result_status_id`) REFERENCES `alrowad_uni_rust`.`result_statuses` (`result_status_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_before_reg_status_fk` FOREIGN KEY (`before_registration_result_status_id`) REFERENCES `alrowad_uni_rust`.`result_statuses` (`result_status_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_before_actor_fk` FOREIGN KEY (`before_calculated_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_after_status_fk` FOREIGN KEY (`after_result_status_id`) REFERENCES `alrowad_uni_rust`.`result_statuses` (`result_status_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_after_reg_status_fk` FOREIGN KEY (`after_registration_result_status_id`) REFERENCES `alrowad_uni_rust`.`result_statuses` (`result_status_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_after_actor_fk` FOREIGN KEY (`after_calculated_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_actor_fk` FOREIGN KEY (`materialized_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT=''owned:supplementary-exam-materialization-phase6''',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_create_materializations FROM @sql;
EXECUTE phase6_create_materializations;
DEALLOCATE PREPARE phase6_create_materializations;

SET @post_mat_compatible := IF(
    @mat_exists,
    @mat_compatible,
    @apply_ready
    AND (
        SELECT COUNT(*) = 1 FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_materializations'
          AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
          AND table_comment = 'owned:supplementary-exam-materialization-phase6'
    )
    AND (
        SELECT COUNT(*) = 48 FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materializations'
    )
    AND (
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
    )
    AND (
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
    )
    AND (
        SELECT COUNT(*) = 18 FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materializations'
          AND referenced_table_name IS NOT NULL
    )
    AND EXISTS (
        SELECT 1
        FROM information_schema.table_constraints tc
        JOIN information_schema.check_constraints cc
          ON cc.constraint_schema = tc.constraint_schema
         AND cc.constraint_name = tc.constraint_name
        WHERE tc.table_schema = 'alrowad_uni_rust'
          AND tc.table_name = 'supplementary_exam_materializations'
          AND tc.constraint_type = 'CHECK'
          AND LOWER(cc.check_clause) LIKE '%json_valid%'
          AND LOWER(cc.check_clause) LIKE '%practical_components_snapshot%'
    )
);

SET @sql := IF(
    @apply_ready AND @post_mat_compatible AND NOT @event_exists,
    'CREATE TABLE `alrowad_uni_rust`.`supplementary_exam_materialization_events` (
        `supplementary_exam_materialization_event_id` INT NOT NULL AUTO_INCREMENT,
        `supplementary_exam_materialization_id` INT NOT NULL,
        `supplementary_exam_offering_id` INT NOT NULL,
        `supplementary_exam_registration_id` INT NOT NULL,
        `event_type` VARCHAR(64) NOT NULL,
        `source_submission_version` INT NOT NULL,
        `actor_user_id` INT NOT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`supplementary_exam_materialization_event_id`),
        UNIQUE KEY `sem6_event_materialization_uq` (`supplementary_exam_materialization_id`),
        KEY `sem6_event_offering_ix` (`supplementary_exam_offering_id`),
        KEY `sem6_event_registration_ix` (`supplementary_exam_registration_id`),
        KEY `sem6_event_actor_ix` (`actor_user_id`),
        KEY `sem6_event_history_ix` (`event_type`, `created_at`),
        CONSTRAINT `sem6_event_parent_fk` FOREIGN KEY (`supplementary_exam_materialization_id`) REFERENCES `alrowad_uni_rust`.`supplementary_exam_materializations` (`supplementary_exam_materialization_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_event_offering_fk` FOREIGN KEY (`supplementary_exam_offering_id`) REFERENCES `alrowad_uni_rust`.`supplementary_exam_offerings` (`supplementary_exam_offering_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_event_registration_fk` FOREIGN KEY (`supplementary_exam_registration_id`) REFERENCES `alrowad_uni_rust`.`supplementary_exam_registrations` (`supplementary_exam_registration_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
        CONSTRAINT `sem6_event_actor_fk` FOREIGN KEY (`actor_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT=''owned:supplementary-exam-materialization-phase6''',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_create_events FROM @sql;
EXECUTE phase6_create_events;
DEALLOCATE PREPARE phase6_create_events;

SET @post_event_compatible := IF(
    @event_exists,
    @event_compatible,
    @apply_ready AND @post_mat_compatible
    AND (
        SELECT COUNT(*) = 1 FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'supplementary_exam_materialization_events'
          AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
          AND table_comment = 'owned:supplementary-exam-materialization-phase6'
    )
    AND (
        SELECT COUNT(*) = 8 FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materialization_events'
    )
    AND (
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
    )
    AND (
        SELECT COUNT(DISTINCT CONCAT(non_unique, ':', index_columns)) = 6
        FROM (
            SELECT non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS index_columns
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materialization_events'
            GROUP BY index_name, non_unique
        ) indexes_found
        WHERE (non_unique = 0 AND index_columns IN ('supplementary_exam_materialization_event_id', 'supplementary_exam_materialization_id'))
           OR (non_unique = 1 AND index_columns IN ('supplementary_exam_offering_id', 'supplementary_exam_registration_id', 'actor_user_id', 'event_type,created_at'))
    )
    AND (
        SELECT COUNT(*) = 4 FROM information_schema.key_column_usage
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_materialization_events'
          AND referenced_table_name IS NOT NULL
    )
);

START TRANSACTION;
SET @sql := IF(
    @apply_ready AND @post_mat_compatible AND @post_event_compatible,
    'INSERT INTO `alrowad_uni_rust`.`permissions` (`permission_code`, `permission_name`, `description`, `module_id`, `is_active`, `created_at`, `updated_at`) SELECT ''supplementary_exams.results.materialize'', ''Materialize supplementary official results'', ''owned:supplementary-exam-materialization-phase6'', m.module_id, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM `alrowad_uni_rust`.`system_modules` m WHERE m.module_code = ''exams'' AND m.is_active = 1 AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` p WHERE p.permission_code = ''supplementary_exams.results.materialize'')',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_insert_permission FROM @sql;
EXECUTE phase6_insert_permission;
DEALLOCATE PREPARE phase6_insert_permission;

SET @sql := IF(
    @apply_ready AND @post_mat_compatible AND @post_event_compatible,
    'INSERT INTO `alrowad_uni_rust`.`role_permissions` (`role_id`, `permission_id`, `granted_at`) SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = ''supplementary_exams.results.materialize'' WHERE r.role_code = ''exam_officer'' AND r.is_active = 1 AND p.is_active = 1 AND (p.description = ''owned:supplementary-exam-materialization-phase6'' OR EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing JOIN `alrowad_uni_rust`.`roles` existing_role ON existing_role.role_id = existing.role_id WHERE existing.permission_id = p.permission_id AND existing_role.role_code = ''exam_officer'')) AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id)',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_insert_mapping FROM @sql;
EXECUTE phase6_insert_mapping;
DEALLOCATE PREPARE phase6_insert_mapping;
COMMIT;

SET @rbac_post_ready := 0;
SET @sql := IF(
    @dependency_tables_ready,
    'SELECT (COUNT(*) = 1 AND COALESCE(SUM(p.is_active = 1 AND m.module_code = ''exams'' AND m.is_active = 1), 0) = 1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id WHERE rp.permission_id = (SELECT permission_id FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''supplementary_exams.results.materialize'' LIMIT 1) AND r.role_code = ''exam_officer'' AND r.is_active = 1) = 1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp2 WHERE rp2.permission_id = (SELECT permission_id FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = ''supplementary_exams.results.materialize'' LIMIT 1)) = 1) INTO @rbac_post_ready FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id WHERE p.permission_code = ''supplementary_exams.results.materialize''',
    'SET @phase6_noop := @phase6_noop'
);
PREPARE phase6_apply_post_rbac FROM @sql;
EXECUTE phase6_apply_post_rbac;
DEALLOCATE PREPARE phase6_apply_post_rbac;

SET @apply_success := @apply_ready AND @post_mat_compatible AND @post_event_compatible AND @rbac_post_ready;

-- This is the only visible operator report. Its last row is the decision.
SELECT report_section, result
FROM (
    SELECT 10 AS sort_order, 'DEPENDENCIES' AS report_section,
        IF(@dependencies_ready, 'PASS', 'FAIL') AS result
    UNION ALL SELECT 20, 'supplementary_exam_materializations',
        IF(@post_mat_compatible, 'COMPATIBLE', IF(@mat_exists, 'CONFLICT', 'ABSENT')) AS result
    UNION ALL SELECT 30, 'supplementary_exam_materialization_events',
        IF(@post_event_compatible, 'COMPATIBLE', IF(@event_exists, 'CONFLICT', 'ABSENT'))
    UNION ALL SELECT 40, @phase6_permission, IF(@rbac_post_ready, 'COMPATIBLE', 'CONFLICT')
    UNION ALL SELECT 99, 'OVERALL',
        IF(NOT @apply_success, 'BLOCKED', IF(@initial_changes_needed > 0, 'APPLIED', 'ALREADY_APPLIED'))
) report
ORDER BY sort_order;
