-- READ ONLY. Academic Calendar deployed-schema compatibility repair.
-- Continue only when the final visible row is OVERALL | READY.

SET @acr_database_ok := (
    SELECT COUNT(*) = 1
    FROM information_schema.schemata
    WHERE schema_name = 'alrowad_uni_rust'
);

SET @acr_required_tables_ok := (
    SELECT COUNT(*) = 11
    FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_type = 'BASE TABLE'
      AND table_name IN (
        'academic_years', 'semesters', 'users',
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events',
        'system_modules', 'permissions', 'roles', 'role_permissions'
      )
);

SET @acr_calendar_tables_ok := (
    SELECT COUNT(*) = 4
    FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_type = 'BASE TABLE'
      AND engine = 'InnoDB'
      AND table_name IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
);
SET @acr_data_columns_ok := (
    SELECT COUNT(*) = 17 FROM information_schema.columns
    WHERE table_schema='alrowad_uni_rust' AND (
      (table_name='academic_years' AND column_name IN ('academic_year_id','is_current','is_active','calendar_lifecycle_status'))
      OR (table_name='semesters' AND column_name='semester_code')
      OR (table_name='academic_calendar_event_types' AND column_name='event_type_code')
      OR (table_name='system_modules' AND column_name IN ('module_id','module_code'))
      OR (table_name='permissions' AND column_name IN ('permission_id','module_id','permission_code','is_active'))
      OR (table_name='roles' AND column_name IN ('role_id','role_code','is_active'))
      OR (table_name='role_permissions' AND column_name IN ('role_id','permission_id'))
    )
);

-- Forty columns are common to the deployed and merged contracts. The other
-- four are the two context columns in either their old or intended location.
SET @acr_common_columns := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND (
        (table_name = 'academic_calendar_event_types' AND column_name IN (
          'academic_calendar_event_type_id', 'event_type_code', 'name_ar', 'name_en',
          'event_type_kind', 'default_is_enforcement', 'is_active', 'created_at', 'updated_at'
        ))
        OR (table_name = 'academic_calendar_events' AND column_name IN (
          'academic_calendar_event_id', 'academic_year_id', 'created_by_user_id', 'created_at',
          'cancelled_by_user_id', 'cancelled_at', 'cancellation_reason'
        ))
        OR (table_name = 'academic_calendar_event_versions' AND column_name IN (
          'academic_calendar_event_version_id', 'academic_calendar_event_id',
          'version_number', 'replaces_version_id', 'title', 'public_notes',
          'starts_at', 'ends_at', 'is_enforcement', 'change_reason',
          'created_by_user_id', 'created_at', 'publication_status',
          'published_by_user_id', 'published_at', 'superseded_at', 'published_event_slot'
        ))
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND column_name IN (
          'academic_calendar_year_lifecycle_event_id', 'academic_year_id',
          'from_status', 'to_status', 'actor_user_id', 'reason', 'occurred_at'
        ))
      )
);

SET @acr_calendar_column_total := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
);

SET @acr_unknown_columns := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
      AND NOT (
        (table_name = 'academic_calendar_event_types' AND column_name IN (
          'academic_calendar_event_type_id', 'event_type_code', 'name_ar', 'name_en',
          'event_type_kind', 'default_is_enforcement', 'is_active', 'created_at', 'updated_at'
        ))
        OR (table_name = 'academic_calendar_events' AND column_name IN (
          'academic_calendar_event_id', 'academic_year_id', 'semester_id',
          'academic_calendar_event_type_id', 'created_by_user_id', 'created_at',
          'cancelled_by_user_id', 'cancelled_at', 'cancellation_reason'
        ))
        OR (table_name = 'academic_calendar_event_versions' AND column_name IN (
          'academic_calendar_event_version_id', 'academic_calendar_event_id',
          'version_number', 'replaces_version_id', 'semester_id',
          'academic_calendar_event_type_id', 'title', 'public_notes',
          'starts_at', 'ends_at', 'is_enforcement', 'change_reason',
          'created_by_user_id', 'created_at', 'publication_status',
          'published_by_user_id', 'published_at', 'superseded_at', 'published_event_slot'
        ))
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND column_name IN (
          'academic_calendar_year_lifecycle_event_id', 'academic_year_id',
          'from_status', 'to_status', 'actor_user_id', 'reason', 'occurred_at'
        ))
      )
);

SET @acr_event_context_columns := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_events'
      AND column_name IN ('semester_id', 'academic_calendar_event_type_id')
);
SET @acr_version_context_columns := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
      AND column_name IN ('semester_id', 'academic_calendar_event_type_id')
);

SET @acr_common_signed_keys := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema='alrowad_uni_rust' AND data_type='int'
      AND LOWER(column_type) NOT LIKE '%unsigned%'
      AND (
        (table_name='academic_years' AND column_name='academic_year_id')
        OR (table_name='semesters' AND column_name='semester_id')
        OR (table_name='users' AND column_name='user_id')
        OR (table_name='academic_calendar_event_types' AND column_name='academic_calendar_event_type_id')
        OR (table_name='academic_calendar_events' AND column_name IN ('academic_calendar_event_id','academic_year_id','created_by_user_id','cancelled_by_user_id'))
        OR (table_name='academic_calendar_event_versions' AND column_name IN ('academic_calendar_event_version_id','academic_calendar_event_id','replaces_version_id','created_by_user_id','published_by_user_id'))
        OR (table_name='academic_calendar_year_lifecycle_events' AND column_name IN ('academic_calendar_year_lifecycle_event_id','academic_year_id','actor_user_id'))
      )
);
SET @acr_context_signed_keys := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema='alrowad_uni_rust' AND data_type='int'
      AND LOWER(column_type) NOT LIKE '%unsigned%'
      AND table_name IN ('academic_calendar_events','academic_calendar_event_versions')
      AND column_name IN ('semester_id','academic_calendar_event_type_id')
);
SET @acr_generated_slots := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema='alrowad_uni_rust' AND is_generated='ALWAYS'
      AND (UPPER(extra) LIKE '%PERSISTENT%' OR UPPER(extra) LIKE '%STORED%')
      AND (
        (table_name='academic_years' AND column_name='calendar_active_slot'
          AND generation_expression LIKE '%calendar_lifecycle_status%')
        OR (table_name='academic_calendar_event_versions' AND column_name='published_event_slot'
          AND generation_expression LIKE '%publication_status%'
          AND generation_expression LIKE '%academic_calendar_event_id%')
      )
);
SET @acr_generated_unique_indexes := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema='alrowad_uni_rust' AND non_unique=0 AND seq_in_index=1
      AND ((table_name='academic_years' AND index_name='uq_ay_calendar_active_slot' AND column_name='calendar_active_slot')
        OR (table_name='academic_calendar_event_versions' AND index_name='uq_acev_published_event_slot' AND column_name='published_event_slot'))
);

SET @acr_state_enum_columns := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND data_type = 'enum'
      AND (
        (table_name = 'academic_years' AND column_name = 'calendar_lifecycle_status')
        OR (table_name = 'academic_calendar_event_types' AND column_name = 'event_type_kind')
        OR (table_name = 'academic_calendar_event_versions' AND column_name = 'publication_status')
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND column_name IN ('from_status', 'to_status'))
      )
);
SET @acr_state_varchar_columns := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND data_type = 'varchar'
      AND character_maximum_length >= 16
      AND (
        (table_name = 'academic_years' AND column_name = 'calendar_lifecycle_status')
        OR (table_name = 'academic_calendar_event_types' AND column_name = 'event_type_kind')
        OR (table_name = 'academic_calendar_event_versions' AND column_name = 'publication_status')
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND column_name IN ('from_status', 'to_status'))
      )
);

SET @acr_known_check_count := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND constraint_type = 'CHECK'
      AND (
        (table_name='academic_years' AND constraint_name='chk_ay_calendar_lifecycle_status')
        OR (table_name='academic_calendar_event_types' AND constraint_name IN ('chk_acet_kind','chk_acet_flags'))
        OR (table_name='academic_calendar_events' AND constraint_name='chk_ace_cancellation')
        OR (table_name='academic_calendar_event_versions' AND constraint_name IN ('chk_acev_version_number','chk_acev_window','chk_acev_enforcement','chk_acev_change_reason','chk_acev_publication'))
        OR (table_name='academic_calendar_year_lifecycle_events' AND constraint_name IN ('chk_acyle_from_status','chk_acyle_to_status','chk_acyle_reason'))
      )
);
SET @acr_unknown_checks := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND constraint_type = 'CHECK'
      AND table_name IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
      AND NOT (
        (table_name='academic_calendar_event_types' AND constraint_name IN ('chk_acet_kind','chk_acet_flags'))
        OR (table_name='academic_calendar_events' AND constraint_name='chk_ace_cancellation')
        OR (table_name='academic_calendar_event_versions' AND constraint_name IN ('chk_acev_version_number','chk_acev_window','chk_acev_enforcement','chk_acev_change_reason','chk_acev_publication'))
        OR (table_name='academic_calendar_year_lifecycle_events' AND constraint_name IN ('chk_acyle_from_status','chk_acyle_to_status','chk_acyle_reason'))
      )
);

SET @acr_unknown_context_fks := (
    SELECT COUNT(*)
    FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust'
      AND referenced_table_name IS NOT NULL
      AND table_name IN ('academic_calendar_events', 'academic_calendar_event_versions')
      AND column_name IN ('semester_id', 'academic_calendar_event_type_id')
      AND constraint_name NOT IN (
        'fk_ace_semester', 'fk_ace_event_type', 'fk_acev_semester', 'fk_acev_event_type'
      )
);

SET @acr_noncontext_fks := (
    SELECT COUNT(*)
    FROM information_schema.key_column_usage k
    JOIN information_schema.referential_constraints r
      ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name
    WHERE k.table_schema = 'alrowad_uni_rust' AND k.referenced_table_name IS NOT NULL
      AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION')
      AND (
        (k.table_name='academic_calendar_events' AND k.constraint_name='fk_ace_year' AND k.column_name='academic_year_id' AND k.referenced_table_name='academic_years' AND k.referenced_column_name='academic_year_id')
        OR (k.table_name='academic_calendar_events' AND k.constraint_name='fk_ace_created_by' AND k.column_name='created_by_user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='user_id')
        OR (k.table_name='academic_calendar_events' AND k.constraint_name='fk_ace_cancelled_by' AND k.column_name='cancelled_by_user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='user_id')
        OR (k.table_name='academic_calendar_event_versions' AND k.constraint_name='fk_acev_event' AND k.column_name='academic_calendar_event_id' AND k.referenced_table_name='academic_calendar_events' AND k.referenced_column_name='academic_calendar_event_id')
        OR (k.table_name='academic_calendar_event_versions' AND k.constraint_name='fk_acev_replaces' AND k.column_name='replaces_version_id' AND k.referenced_table_name='academic_calendar_event_versions' AND k.referenced_column_name='academic_calendar_event_version_id')
        OR (k.table_name='academic_calendar_event_versions' AND k.constraint_name='fk_acev_created_by' AND k.column_name='created_by_user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='user_id')
        OR (k.table_name='academic_calendar_event_versions' AND k.constraint_name='fk_acev_published_by' AND k.column_name='published_by_user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='user_id')
        OR (k.table_name='academic_calendar_year_lifecycle_events' AND k.constraint_name='fk_acyle_year' AND k.column_name='academic_year_id' AND k.referenced_table_name='academic_years' AND k.referenced_column_name='academic_year_id')
        OR (k.table_name='academic_calendar_year_lifecycle_events' AND k.constraint_name='fk_acyle_actor' AND k.column_name='actor_user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='user_id')
      )
);
SET @acr_unknown_calendar_fks := (
    SELECT COUNT(*)
    FROM information_schema.key_column_usage
    WHERE table_schema='alrowad_uni_rust' AND referenced_table_name IS NOT NULL
      AND table_name IN ('academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events')
      AND constraint_name NOT IN ('fk_ace_year','fk_ace_semester','fk_ace_event_type','fk_ace_created_by','fk_ace_cancelled_by','fk_acev_event','fk_acev_replaces','fk_acev_semester','fk_acev_event_type','fk_acev_created_by','fk_acev_published_by','fk_acyle_year','fk_acyle_actor')
);

SET @acr_unknown_indexes := (
    SELECT COUNT(DISTINCT table_name, index_name)
    FROM information_schema.statistics
    WHERE table_schema='alrowad_uni_rust'
      AND table_name IN ('academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events')
      AND NOT (
        (table_name='academic_calendar_event_types' AND index_name IN ('PRIMARY','uq_acet_code','idx_acet_kind_active'))
        OR (table_name='academic_calendar_events' AND index_name IN ('PRIMARY','idx_ace_year','idx_ace_year_semester','idx_ace_event_type','idx_ace_cancelled_at','idx_ace_created_by','idx_ace_cancelled_by'))
        OR (table_name='academic_calendar_event_versions' AND index_name IN ('PRIMARY','uq_acev_event_version','uq_acev_published_event_slot','idx_acev_event_status','idx_acev_semester','idx_acev_event_type','idx_acev_publication_window','idx_acev_replaces','idx_acev_created_by','idx_acev_published_by'))
        OR (table_name='academic_calendar_year_lifecycle_events' AND index_name IN ('PRIMARY','idx_acyle_year_occurred','idx_acyle_to_status','idx_acyle_status_occurred','idx_acyle_actor'))
      )
);

SET @acr_source_indexes := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND seq_in_index = 1
      AND (
        (table_name = 'academic_calendar_events' AND index_name = 'idx_ace_year' AND column_name = 'academic_year_id')
        OR (table_name = 'academic_calendar_event_versions' AND index_name = 'idx_acev_semester' AND column_name = 'semester_id')
        OR (table_name = 'academic_calendar_event_versions' AND index_name = 'idx_acev_event_type' AND column_name = 'academic_calendar_event_type_id')
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND index_name = 'idx_acyle_to_status' AND column_name = 'to_status')
      )
);
SET @acr_target_indexes := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND seq_in_index = 1
      AND (
        (table_name = 'academic_calendar_event_types' AND index_name = 'idx_acet_kind_active' AND column_name = 'event_type_kind')
        OR (table_name = 'academic_calendar_events' AND index_name = 'idx_ace_year_semester' AND column_name = 'academic_year_id')
        OR (table_name = 'academic_calendar_events' AND index_name = 'idx_ace_event_type' AND column_name = 'academic_calendar_event_type_id')
        OR (table_name = 'academic_calendar_events' AND index_name = 'idx_ace_cancelled_at' AND column_name = 'cancelled_at')
        OR (table_name = 'academic_calendar_event_versions' AND index_name = 'idx_acev_event_status' AND column_name = 'academic_calendar_event_id')
        OR (table_name = 'academic_calendar_event_versions' AND index_name = 'idx_acev_publication_window' AND column_name = 'publication_status')
        OR (table_name = 'academic_calendar_event_versions' AND index_name = 'idx_acev_replaces' AND column_name = 'replaces_version_id')
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND index_name = 'idx_acyle_year_occurred' AND column_name = 'academic_year_id')
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND index_name = 'idx_acyle_status_occurred' AND column_name = 'to_status')
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND index_name = 'idx_acyle_actor' AND column_name = 'actor_user_id')
      )
);

SET @acr_context_fk_source := (
    SELECT COUNT(*)
    FROM information_schema.key_column_usage k
    JOIN information_schema.referential_constraints r
      ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name
    WHERE k.table_schema = 'alrowad_uni_rust'
      AND k.table_name = 'academic_calendar_event_versions'
      AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION')
      AND (
        (k.constraint_name = 'fk_acev_semester' AND k.column_name = 'semester_id' AND k.referenced_table_name = 'semesters' AND k.referenced_column_name='semester_id')
        OR (k.constraint_name = 'fk_acev_event_type' AND k.column_name = 'academic_calendar_event_type_id' AND k.referenced_table_name = 'academic_calendar_event_types' AND k.referenced_column_name='academic_calendar_event_type_id')
      )
);
SET @acr_context_fk_target := (
    SELECT COUNT(*)
    FROM information_schema.key_column_usage k
    JOIN information_schema.referential_constraints r
      ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name
    WHERE k.table_schema = 'alrowad_uni_rust'
      AND k.table_name = 'academic_calendar_events'
      AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION')
      AND (
        (k.constraint_name = 'fk_ace_semester' AND k.column_name = 'semester_id' AND k.referenced_table_name = 'semesters' AND k.referenced_column_name='semester_id')
        OR (k.constraint_name = 'fk_ace_event_type' AND k.column_name = 'academic_calendar_event_type_id' AND k.referenced_table_name = 'academic_calendar_event_types' AND k.referenced_column_name='academic_calendar_event_type_id')
      )
);
SET @acr_context_fk_total := (
    SELECT COUNT(*) FROM information_schema.key_column_usage
    WHERE table_schema='alrowad_uni_rust' AND referenced_table_name IS NOT NULL
      AND ((table_name='academic_calendar_events' AND constraint_name IN ('fk_ace_semester','fk_ace_event_type'))
        OR (table_name='academic_calendar_event_versions' AND constraint_name IN ('fk_acev_semester','fk_acev_event_type')))
);

SET @acr_table_comments := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
      AND table_comment LIKE '%[academic-calendar-phase1]%'
);

SET @acr_data_sql := IF(
    @acr_required_tables_ok AND @acr_data_columns_ok,
    'SELECT
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events`),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions`),
       (SELECT COUNT(DISTINCT event_type_code) FROM `alrowad_uni_rust`.`academic_calendar_event_types`
        WHERE event_type_code IN (
          ''admission_registration'', ''course_registration'', ''withdrawal'',
          ''study_period'', ''exam_preparation'', ''practical_exams'',
          ''theoretical_exams'', ''grade_appeals'', ''supplementary_exams'',
          ''university_break'', ''preparation_period'', ''holiday'', ''general_event''
        )),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years`
        WHERE is_current = 1 AND is_active = 1 AND calendar_lifecycle_status = ''active''),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years` WHERE is_current = 1),
       (SELECT COUNT(DISTINCT semester_code) FROM `alrowad_uni_rust`.`semesters`
        WHERE semester_code IN (''first'', ''second'', ''summer'')),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id
        WHERE p.permission_code = ''academic_calendar.manage'' AND p.is_active = 1
          AND m.module_code = ''vice_presidency''),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
        WHERE p.permission_code = ''academic_calendar.manage''
          AND r.role_code = ''vice_president_scientific'' AND r.is_active = 1),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
        WHERE p.permission_code = ''academic_calendar.manage''
          AND r.role_code <> ''vice_president_scientific'')
     INTO @acr_event_rows, @acr_version_rows, @acr_seed_codes,
          @acr_current_active, @acr_current_count, @acr_semester_codes,
          @acr_permission_ok, @acr_role_mapping_ok, @acr_other_mappings',
    'SELECT 1, 1, 0, 0, 0, 0, 0, 0, 1
     INTO @acr_event_rows, @acr_version_rows, @acr_seed_codes,
          @acr_current_active, @acr_current_count, @acr_semester_codes,
          @acr_permission_ok, @acr_role_mapping_ok, @acr_other_mappings'
);
PREPARE acr_preflight_data FROM @acr_data_sql;
EXECUTE acr_preflight_data;
DEALLOCATE PREPARE acr_preflight_data;

SET @acr_data_safe := @acr_event_rows = 0 AND @acr_version_rows = 0
    AND @acr_seed_codes = 13
    AND @acr_current_active = 1 AND @acr_current_count = 1
    AND @acr_semester_codes = 3
    AND @acr_permission_ok = 1 AND @acr_role_mapping_ok = 1
    AND @acr_other_mappings = 0;

SET @acr_common_safe := @acr_database_ok AND @acr_required_tables_ok AND @acr_data_columns_ok
    AND @acr_calendar_tables_ok AND @acr_common_columns = 40
    AND @acr_unknown_columns = 0 AND @acr_calendar_column_total BETWEEN 40 AND 44
    AND @acr_common_signed_keys = 16
    AND @acr_context_signed_keys = (@acr_event_context_columns + @acr_version_context_columns)
    AND @acr_generated_slots = 2 AND @acr_generated_unique_indexes = 2
    AND @acr_unknown_checks = 0 AND @acr_unknown_context_fks = 0
    AND @acr_noncontext_fks = 9 AND @acr_unknown_calendar_fks = 0
    AND (@acr_context_fk_source + @acr_context_fk_target) = @acr_context_fk_total
    AND @acr_unknown_indexes = 0
    AND (@acr_state_enum_columns + @acr_state_varchar_columns = 5)
    AND @acr_data_safe;

SET @acr_source_fingerprint := @acr_common_safe
    AND @acr_calendar_column_total = 42
    AND @acr_event_context_columns = 0 AND @acr_version_context_columns = 2
    AND @acr_state_enum_columns = 5 AND @acr_known_check_count = 0
    AND @acr_source_indexes = 4 AND @acr_context_fk_source = 2
    AND @acr_context_fk_target = 0;

SET @acr_target_fingerprint := @acr_common_safe
    AND @acr_calendar_column_total = 42
    AND @acr_event_context_columns = 2 AND @acr_version_context_columns = 0
    AND @acr_state_varchar_columns = 5 AND @acr_known_check_count = 12
    AND @acr_source_indexes = 0 AND @acr_target_indexes = 10
    AND @acr_context_fk_source = 0 AND @acr_context_fk_target = 2
    AND @acr_table_comments = 4;

SET @acr_safe_partial := @acr_common_safe
    AND NOT @acr_source_fingerprint AND NOT @acr_target_fingerprint;
SET @acr_ready := @acr_source_fingerprint OR @acr_target_fingerprint OR @acr_safe_partial;

SELECT 'DATABASE_AND_TABLES' AS report_section,
       IF(@acr_database_ok AND @acr_required_tables_ok AND @acr_calendar_tables_ok, 'PASS', 'FAIL') AS result
UNION ALL
SELECT 'REFERENCE_DATA', IF(@acr_data_safe, 'PASS', 'FAIL')
UNION ALL
SELECT 'EMPTY_EVENT_HISTORY', IF(@acr_event_rows = 0 AND @acr_version_rows = 0, 'PASS', 'FAIL')
UNION ALL
SELECT 'STRUCTURE_CLASSIFICATION', CASE
    WHEN @acr_source_fingerprint THEN 'REPAIRABLE_SOURCE'
    WHEN @acr_target_fingerprint THEN 'ALREADY_COMPATIBLE'
    WHEN @acr_safe_partial THEN 'SAFE_PARTIAL'
    ELSE 'CONFLICTING'
END
UNION ALL
SELECT 'OVERALL', IF(@acr_ready, 'READY', 'BLOCKED');
