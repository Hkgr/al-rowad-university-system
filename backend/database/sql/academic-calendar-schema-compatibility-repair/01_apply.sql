-- Academic Calendar deployed-schema compatibility repair.
-- Guarded, rerunnable DDL for MariaDB 10.11. No application data is changed.

SET @acr_database_ok := (
    SELECT COUNT(*) = 1 FROM information_schema.schemata
    WHERE schema_name = 'alrowad_uni_rust'
);
SET @acr_required_tables_ok := (
    SELECT COUNT(*) = 11 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust' AND table_type = 'BASE TABLE'
      AND table_name IN (
        'academic_years', 'semesters', 'users',
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events',
        'system_modules', 'permissions', 'roles', 'role_permissions'
      )
);
SET @acr_calendar_tables_ok := (
    SELECT COUNT(*) = 4 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust' AND table_type = 'BASE TABLE'
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
SET @acr_common_columns := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND (
      (table_name = 'academic_calendar_event_types' AND column_name IN (
        'academic_calendar_event_type_id', 'event_type_code', 'name_ar', 'name_en',
        'event_type_kind', 'default_is_enforcement', 'is_active', 'created_at', 'updated_at'
      )) OR
      (table_name = 'academic_calendar_events' AND column_name IN (
        'academic_calendar_event_id', 'academic_year_id', 'created_by_user_id', 'created_at',
        'cancelled_by_user_id', 'cancelled_at', 'cancellation_reason'
      )) OR
      (table_name = 'academic_calendar_event_versions' AND column_name IN (
        'academic_calendar_event_version_id', 'academic_calendar_event_id',
        'version_number', 'replaces_version_id', 'title', 'public_notes',
        'starts_at', 'ends_at', 'is_enforcement', 'change_reason',
        'created_by_user_id', 'created_at', 'publication_status',
        'published_by_user_id', 'published_at', 'superseded_at', 'published_event_slot'
      )) OR
      (table_name = 'academic_calendar_year_lifecycle_events' AND column_name IN (
        'academic_calendar_year_lifecycle_event_id', 'academic_year_id',
        'from_status', 'to_status', 'actor_user_id', 'reason', 'occurred_at'
      ))
    )
);
SET @acr_unknown_columns := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
      AND NOT (
        (table_name='academic_calendar_event_types' AND column_name IN ('academic_calendar_event_type_id','event_type_code','name_ar','name_en','event_type_kind','default_is_enforcement','is_active','created_at','updated_at'))
        OR (table_name='academic_calendar_events' AND column_name IN ('academic_calendar_event_id','academic_year_id','semester_id','academic_calendar_event_type_id','created_by_user_id','created_at','cancelled_by_user_id','cancelled_at','cancellation_reason'))
        OR (table_name='academic_calendar_event_versions' AND column_name IN ('academic_calendar_event_version_id','academic_calendar_event_id','version_number','replaces_version_id','semester_id','academic_calendar_event_type_id','title','public_notes','starts_at','ends_at','is_enforcement','change_reason','created_by_user_id','created_at','publication_status','published_by_user_id','published_at','superseded_at','published_event_slot'))
        OR (table_name='academic_calendar_year_lifecycle_events' AND column_name IN ('academic_calendar_year_lifecycle_event_id','academic_year_id','from_status','to_status','actor_user_id','reason','occurred_at'))
      )
);
SET @acr_calendar_column_total := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name IN ('academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events'));
SET @acr_context_column_total := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name IN ('academic_calendar_events','academic_calendar_event_versions') AND column_name IN ('semester_id','academic_calendar_event_type_id'));
SET @acr_common_signed_keys := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='int' AND LOWER(column_type) NOT LIKE '%unsigned%' AND ((table_name='academic_years' AND column_name='academic_year_id') OR (table_name='semesters' AND column_name='semester_id') OR (table_name='users' AND column_name='user_id') OR (table_name='academic_calendar_event_types' AND column_name='academic_calendar_event_type_id') OR (table_name='academic_calendar_events' AND column_name IN ('academic_calendar_event_id','academic_year_id','created_by_user_id','cancelled_by_user_id')) OR (table_name='academic_calendar_event_versions' AND column_name IN ('academic_calendar_event_version_id','academic_calendar_event_id','replaces_version_id','created_by_user_id','published_by_user_id')) OR (table_name='academic_calendar_year_lifecycle_events' AND column_name IN ('academic_calendar_year_lifecycle_event_id','academic_year_id','actor_user_id'))));
SET @acr_context_signed_keys := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='int' AND LOWER(column_type) NOT LIKE '%unsigned%' AND table_name IN ('academic_calendar_events','academic_calendar_event_versions') AND column_name IN ('semester_id','academic_calendar_event_type_id'));
SET @acr_generated_slots := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND is_generated='ALWAYS' AND (UPPER(extra) LIKE '%PERSISTENT%' OR UPPER(extra) LIKE '%STORED%') AND ((table_name='academic_years' AND column_name='calendar_active_slot' AND generation_expression LIKE '%calendar_lifecycle_status%') OR (table_name='academic_calendar_event_versions' AND column_name='published_event_slot' AND generation_expression LIKE '%publication_status%' AND generation_expression LIKE '%academic_calendar_event_id%')));
SET @acr_generated_unique_indexes := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND non_unique=0 AND seq_in_index=1 AND ((table_name='academic_years' AND index_name='uq_ay_calendar_active_slot' AND column_name='calendar_active_slot') OR (table_name='academic_calendar_event_versions' AND index_name='uq_acev_published_event_slot' AND column_name='published_event_slot')));
SET @acr_known_state_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type IN ('enum','varchar') AND ((table_name='academic_years' AND column_name='calendar_lifecycle_status') OR (table_name='academic_calendar_event_types' AND column_name='event_type_kind') OR (table_name='academic_calendar_event_versions' AND column_name='publication_status') OR (table_name='academic_calendar_year_lifecycle_events' AND column_name IN ('from_status','to_status'))));
SET @acr_unknown_checks := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND constraint_type='CHECK' AND table_name IN ('academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events') AND NOT ((table_name='academic_calendar_event_types' AND constraint_name IN ('chk_acet_kind','chk_acet_flags')) OR (table_name='academic_calendar_events' AND constraint_name='chk_ace_cancellation') OR (table_name='academic_calendar_event_versions' AND constraint_name IN ('chk_acev_version_number','chk_acev_window','chk_acev_enforcement','chk_acev_change_reason','chk_acev_publication')) OR (table_name='academic_calendar_year_lifecycle_events' AND constraint_name IN ('chk_acyle_from_status','chk_acyle_to_status','chk_acyle_reason'))));
SET @acr_noncontext_fks := (
    SELECT COUNT(*) FROM information_schema.key_column_usage k
    JOIN information_schema.referential_constraints r
      ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name
    WHERE k.table_schema='alrowad_uni_rust' AND k.referenced_table_name IS NOT NULL
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
SET @acr_context_fk_total := (SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema='alrowad_uni_rust' AND referenced_table_name IS NOT NULL AND ((table_name='academic_calendar_events' AND constraint_name IN ('fk_ace_semester','fk_ace_event_type')) OR (table_name='academic_calendar_event_versions' AND constraint_name IN ('fk_acev_semester','fk_acev_event_type'))));
SET @acr_compatible_context_fks := (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name WHERE k.table_schema='alrowad_uni_rust' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION') AND ((k.table_name='academic_calendar_events' AND k.constraint_name='fk_ace_semester' AND k.column_name='semester_id' AND k.referenced_table_name='semesters' AND k.referenced_column_name='semester_id') OR (k.table_name='academic_calendar_events' AND k.constraint_name='fk_ace_event_type' AND k.column_name='academic_calendar_event_type_id' AND k.referenced_table_name='academic_calendar_event_types' AND k.referenced_column_name='academic_calendar_event_type_id') OR (k.table_name='academic_calendar_event_versions' AND k.constraint_name='fk_acev_semester' AND k.column_name='semester_id' AND k.referenced_table_name='semesters' AND k.referenced_column_name='semester_id') OR (k.table_name='academic_calendar_event_versions' AND k.constraint_name='fk_acev_event_type' AND k.column_name='academic_calendar_event_type_id' AND k.referenced_table_name='academic_calendar_event_types' AND k.referenced_column_name='academic_calendar_event_type_id')));
SET @acr_unknown_calendar_fks := (SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema='alrowad_uni_rust' AND referenced_table_name IS NOT NULL AND table_name IN ('academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events') AND constraint_name NOT IN ('fk_ace_year','fk_ace_semester','fk_ace_event_type','fk_ace_created_by','fk_ace_cancelled_by','fk_acev_event','fk_acev_replaces','fk_acev_semester','fk_acev_event_type','fk_acev_created_by','fk_acev_published_by','fk_acyle_year','fk_acyle_actor'));
SET @acr_unknown_indexes := (SELECT COUNT(DISTINCT table_name,index_name) FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name IN ('academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events') AND NOT ((table_name='academic_calendar_event_types' AND index_name IN ('PRIMARY','uq_acet_code','idx_acet_kind_active')) OR (table_name='academic_calendar_events' AND index_name IN ('PRIMARY','idx_ace_year','idx_ace_year_semester','idx_ace_event_type','idx_ace_cancelled_at','idx_ace_created_by','idx_ace_cancelled_by')) OR (table_name='academic_calendar_event_versions' AND index_name IN ('PRIMARY','uq_acev_event_version','uq_acev_published_event_slot','idx_acev_event_status','idx_acev_semester','idx_acev_event_type','idx_acev_publication_window','idx_acev_replaces','idx_acev_created_by','idx_acev_published_by')) OR (table_name='academic_calendar_year_lifecycle_events' AND index_name IN ('PRIMARY','idx_acyle_year_occurred','idx_acyle_to_status','idx_acyle_status_occurred','idx_acyle_actor'))));
SET @acr_known_common_index_names := (SELECT COUNT(DISTINCT table_name,index_name) FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND ((table_name='academic_calendar_event_types' AND index_name='idx_acet_kind_active') OR (table_name='academic_calendar_events' AND index_name='idx_ace_cancelled_at') OR (table_name='academic_calendar_event_versions' AND index_name IN ('idx_acev_event_status','idx_acev_publication_window','idx_acev_replaces')) OR (table_name='academic_calendar_year_lifecycle_events' AND index_name IN ('idx_acyle_year_occurred','idx_acyle_actor'))));
SET @acr_compatible_common_indexes := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND seq_in_index=1 AND ((table_name='academic_calendar_event_types' AND index_name='idx_acet_kind_active' AND column_name='event_type_kind') OR (table_name='academic_calendar_events' AND index_name='idx_ace_cancelled_at' AND column_name='cancelled_at') OR (table_name='academic_calendar_event_versions' AND index_name='idx_acev_event_status' AND column_name='academic_calendar_event_id') OR (table_name='academic_calendar_event_versions' AND index_name='idx_acev_publication_window' AND column_name='publication_status') OR (table_name='academic_calendar_event_versions' AND index_name='idx_acev_replaces' AND column_name='replaces_version_id') OR (table_name='academic_calendar_year_lifecycle_events' AND index_name='idx_acyle_year_occurred' AND column_name='academic_year_id') OR (table_name='academic_calendar_year_lifecycle_events' AND index_name='idx_acyle_actor' AND column_name='actor_user_id')));
SET @acr_known_migration_index_names := (SELECT COUNT(DISTINCT table_name,index_name) FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND ((table_name='academic_calendar_events' AND index_name IN ('idx_ace_year','idx_ace_year_semester','idx_ace_event_type')) OR (table_name='academic_calendar_event_versions' AND index_name IN ('idx_acev_semester','idx_acev_event_type')) OR (table_name='academic_calendar_year_lifecycle_events' AND index_name IN ('idx_acyle_to_status','idx_acyle_status_occurred'))));
SET @acr_compatible_migration_indexes := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND seq_in_index=1 AND ((table_name='academic_calendar_events' AND index_name='idx_ace_year' AND column_name='academic_year_id') OR (table_name='academic_calendar_events' AND index_name='idx_ace_year_semester' AND column_name='academic_year_id') OR (table_name='academic_calendar_events' AND index_name='idx_ace_event_type' AND column_name='academic_calendar_event_type_id') OR (table_name='academic_calendar_event_versions' AND index_name='idx_acev_semester' AND column_name='semester_id') OR (table_name='academic_calendar_event_versions' AND index_name='idx_acev_event_type' AND column_name='academic_calendar_event_type_id') OR (table_name='academic_calendar_year_lifecycle_events' AND index_name='idx_acyle_to_status' AND column_name='to_status') OR (table_name='academic_calendar_year_lifecycle_events' AND index_name='idx_acyle_status_occurred' AND column_name='to_status')));
SET @acr_data_sql := IF(
    @acr_required_tables_ok AND @acr_data_columns_ok,
    'SELECT
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events`),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions`),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years`
        WHERE calendar_lifecycle_status NOT IN (''draft'', ''active'', ''closed'')),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_types`
        WHERE event_type_kind NOT IN (''system'', ''general'')
           OR default_is_enforcement NOT IN (0,1) OR is_active NOT IN (0,1)),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events`
        WHERE (from_status IS NOT NULL AND from_status NOT IN (''draft'', ''active'', ''closed''))
           OR to_status NOT IN (''draft'', ''active'', ''closed'')
           OR (from_status IS NOT NULL AND from_status = to_status)
           OR NULLIF(TRIM(reason), '''') IS NULL),
       (SELECT COUNT(DISTINCT event_type_code) FROM `alrowad_uni_rust`.`academic_calendar_event_types`
        WHERE event_type_code IN (
          ''admission_registration'', ''course_registration'', ''withdrawal'',
          ''study_period'', ''exam_preparation'', ''practical_exams'',
          ''theoretical_exams'', ''grade_appeals'', ''supplementary_exams'',
          ''university_break'', ''preparation_period'', ''holiday'', ''general_event''
        )),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years` WHERE is_current = 1),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years`
        WHERE is_current = 1 AND is_active = 1 AND calendar_lifecycle_status = ''active''),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id
        WHERE p.permission_code = ''academic_calendar.manage'' AND p.is_active = 1
          AND m.module_code = ''vice_presidency''),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id=rp.role_id WHERE p.permission_code=''academic_calendar.manage'' AND r.role_code=''vice_president_scientific'' AND r.is_active=1),
       (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id=rp.role_id WHERE p.permission_code=''academic_calendar.manage'' AND r.role_code<>''vice_president_scientific''),
       (SELECT COUNT(DISTINCT semester_code) FROM `alrowad_uni_rust`.`semesters` WHERE semester_code IN (''first'',''second'',''summer''))
     INTO @acr_event_rows, @acr_version_rows, @acr_bad_year_states,
          @acr_bad_type_values, @acr_bad_lifecycle_history, @acr_seed_codes,
          @acr_current_count, @acr_current_active, @acr_permission_ok,
          @acr_mapping_ok, @acr_other_mappings, @acr_semester_codes',
    'SELECT 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 1, 0
     INTO @acr_event_rows, @acr_version_rows, @acr_bad_year_states,
          @acr_bad_type_values, @acr_bad_lifecycle_history, @acr_seed_codes,
          @acr_current_count, @acr_current_active, @acr_permission_ok,
          @acr_mapping_ok, @acr_other_mappings, @acr_semester_codes'
);
PREPARE acr_apply_data FROM @acr_data_sql;
EXECUTE acr_apply_data;
DEALLOCATE PREPARE acr_apply_data;

SET @acr_apply_allowed := @acr_database_ok AND @acr_required_tables_ok AND @acr_data_columns_ok
    AND @acr_calendar_tables_ok AND @acr_common_columns = 40
    AND @acr_unknown_columns = 0 AND @acr_calendar_column_total BETWEEN 40 AND 44
    AND @acr_common_signed_keys = 16 AND @acr_context_signed_keys = @acr_context_column_total
    AND @acr_generated_slots = 2 AND @acr_generated_unique_indexes = 2
    AND @acr_known_state_columns = 5 AND @acr_unknown_checks = 0
    AND @acr_noncontext_fks = 9 AND @acr_unknown_calendar_fks = 0
    AND @acr_compatible_context_fks = @acr_context_fk_total
    AND @acr_unknown_indexes = 0
    AND @acr_known_common_index_names = @acr_compatible_common_indexes
    AND @acr_known_migration_index_names = @acr_compatible_migration_indexes
    AND @acr_event_rows = 0 AND @acr_version_rows = 0
    AND @acr_bad_year_states = 0 AND @acr_bad_type_values = 0
    AND @acr_bad_lifecycle_history = 0 AND @acr_seed_codes = 13
    AND @acr_current_count = 1 AND @acr_current_active = 1
    AND @acr_permission_ok = 1 AND @acr_mapping_ok = 1
    AND @acr_other_mappings = 0 AND @acr_semester_codes = 3;

-- Remove context ownership from revisions before adding it to logical events.
-- Each destructive step repeats a fresh zero-row guard; the initial snapshot is not sufficient.
SET @acr_sql := IF(@acr_apply_allowed
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events`) = 0
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions`) = 0
    AND EXISTS(
    SELECT 1 FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_event_versions'
      AND constraint_name = 'fk_acev_semester' AND referenced_table_name IS NOT NULL
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` DROP FOREIGN KEY `fk_acev_semester`',
   'SELECT ''SKIPPED_DROP_VERSION_SEMESTER_FK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events`) = 0
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions`) = 0
    AND EXISTS(
    SELECT 1 FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_event_versions'
      AND constraint_name = 'fk_acev_event_type' AND referenced_table_name IS NOT NULL
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` DROP FOREIGN KEY `fk_acev_event_type`',
   'SELECT ''SKIPPED_DROP_VERSION_TYPE_FK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events`) = 0
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions`) = 0
    AND EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_event_versions'
      AND index_name = 'idx_acev_semester'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` DROP INDEX `idx_acev_semester`',
   'SELECT ''SKIPPED_DROP_VERSION_SEMESTER_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events`) = 0
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions`) = 0
    AND EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_event_versions'
      AND index_name = 'idx_acev_event_type'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` DROP INDEX `idx_acev_event_type`',
   'SELECT ''SKIPPED_DROP_VERSION_TYPE_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events`) = 0
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions`) = 0
    AND EXISTS(
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_event_versions'
      AND column_name = 'semester_id'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` DROP COLUMN `semester_id`',
   'SELECT ''SKIPPED_DROP_VERSION_SEMESTER'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events`) = 0
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions`) = 0
    AND EXISTS(
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_event_versions'
      AND column_name = 'academic_calendar_event_type_id'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` DROP COLUMN `academic_calendar_event_type_id`',
   'SELECT ''SKIPPED_DROP_VERSION_TYPE'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_events'
      AND column_name = 'semester_id'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` ADD COLUMN `semester_id` INT NULL AFTER `academic_year_id`',
   'SELECT ''SKIPPED_ADD_EVENT_SEMESTER'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_events'
      AND column_name = 'academic_calendar_event_type_id'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` ADD COLUMN `academic_calendar_event_type_id` INT NOT NULL AFTER `semester_id`',
   'SELECT ''SKIPPED_ADD_EVENT_TYPE'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

-- Restore merged lookup indexes.
SET @acr_sql := IF(@acr_apply_allowed AND EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_events'
      AND index_name = 'idx_ace_year'
), IF(EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_events'
      AND index_name = 'idx_ace_year_semester'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` DROP INDEX `idx_ace_year`',
   'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` ADD KEY `idx_ace_year_semester` (`academic_year_id`, `semester_id`), DROP INDEX `idx_ace_year`'),
   'SELECT ''SKIPPED_DROP_SOURCE_YEAR_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_events'
      AND index_name = 'idx_ace_year_semester'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` ADD KEY `idx_ace_year_semester` (`academic_year_id`, `semester_id`)',
   'SELECT ''SKIPPED_ADD_EVENT_YEAR_SEMESTER_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_events'
      AND index_name = 'idx_ace_event_type'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` ADD KEY `idx_ace_event_type` (`academic_calendar_event_type_id`)',
   'SELECT ''SKIPPED_ADD_EVENT_TYPE_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_year_lifecycle_events'
      AND index_name = 'idx_acyle_to_status'
), IF(EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_year_lifecycle_events'
      AND index_name = 'idx_acyle_status_occurred'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` DROP INDEX `idx_acyle_to_status`',
   'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` ADD KEY `idx_acyle_status_occurred` (`to_status`, `occurred_at`), DROP INDEX `idx_acyle_to_status`'),
   'SELECT ''SKIPPED_DROP_SOURCE_LIFECYCLE_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_year_lifecycle_events'
      AND index_name = 'idx_acyle_status_occurred'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` ADD KEY `idx_acyle_status_occurred` (`to_status`, `occurred_at`)',
   'SELECT ''SKIPPED_ADD_LIFECYCLE_STATUS_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_event_types'
      AND index_name = 'idx_acet_kind_active'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_types` ADD KEY `idx_acet_kind_active` (`event_type_kind`, `is_active`)',
   'SELECT ''SKIPPED_ADD_EVENT_TYPE_KIND_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_events'
      AND index_name = 'idx_ace_cancelled_at'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` ADD KEY `idx_ace_cancelled_at` (`cancelled_at`)',
   'SELECT ''SKIPPED_ADD_EVENT_CANCELLED_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_event_versions'
      AND index_name = 'idx_acev_event_status'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` ADD KEY `idx_acev_event_status` (`academic_calendar_event_id`, `publication_status`)',
   'SELECT ''SKIPPED_ADD_VERSION_EVENT_STATUS_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_event_versions'
      AND index_name = 'idx_acev_publication_window'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` ADD KEY `idx_acev_publication_window` (`publication_status`, `starts_at`, `ends_at`)',
   'SELECT ''SKIPPED_ADD_VERSION_PUBLICATION_WINDOW_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_event_versions'
      AND index_name = 'idx_acev_replaces'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` ADD KEY `idx_acev_replaces` (`replaces_version_id`)',
   'SELECT ''SKIPPED_ADD_VERSION_REPLACES_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_year_lifecycle_events'
      AND index_name = 'idx_acyle_year_occurred'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` ADD KEY `idx_acyle_year_occurred` (`academic_year_id`, `occurred_at`)',
   'SELECT ''SKIPPED_ADD_LIFECYCLE_YEAR_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_year_lifecycle_events'
      AND index_name = 'idx_acyle_actor'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` ADD KEY `idx_acyle_actor` (`actor_user_id`)',
   'SELECT ''SKIPPED_ADD_LIFECYCLE_ACTOR_INDEX'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

-- Restore context foreign keys on the logical event.
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_events'
      AND constraint_name = 'fk_ace_semester' AND referenced_table_name IS NOT NULL
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` ADD CONSTRAINT `fk_ace_semester` FOREIGN KEY (`semester_id`) REFERENCES `alrowad_uni_rust`.`semesters` (`semester_id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
   'SELECT ''SKIPPED_ADD_EVENT_SEMESTER_FK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'academic_calendar_events'
      AND constraint_name = 'fk_ace_event_type' AND referenced_table_name IS NOT NULL
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` ADD CONSTRAINT `fk_ace_event_type` FOREIGN KEY (`academic_calendar_event_type_id`) REFERENCES `alrowad_uni_rust`.`academic_calendar_event_types` (`academic_calendar_event_type_id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
   'SELECT ''SKIPPED_ADD_EVENT_TYPE_FK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

-- Normalize enum state columns to the merged VARCHAR representation.
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years' AND column_name = 'calendar_lifecycle_status'
      AND data_type = 'varchar' AND character_maximum_length = 16 AND is_nullable = 'NO'
      AND LOWER(TRIM(BOTH '''' FROM CAST(column_default AS CHAR))) = 'draft'
      AND column_comment = '[academic-calendar-phase1] draft|active|closed'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_years` MODIFY COLUMN `calendar_lifecycle_status` VARCHAR(16) NOT NULL DEFAULT ''draft'' COMMENT ''[academic-calendar-phase1] draft|active|closed''',
   'SELECT ''SKIPPED_NORMALIZE_YEAR_STATE'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_types' AND column_name = 'event_type_kind'
      AND data_type = 'varchar' AND character_maximum_length = 16 AND is_nullable = 'NO'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_types` MODIFY COLUMN `event_type_kind` VARCHAR(16) NOT NULL',
   'SELECT ''SKIPPED_NORMALIZE_EVENT_KIND'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions' AND column_name = 'publication_status'
      AND data_type = 'varchar' AND character_maximum_length = 16 AND is_nullable = 'NO'
      AND LOWER(TRIM(BOTH '''' FROM CAST(column_default AS CHAR))) = 'draft'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` MODIFY COLUMN `publication_status` VARCHAR(16) NOT NULL DEFAULT ''draft''',
   'SELECT ''SKIPPED_NORMALIZE_PUBLICATION_STATE'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_year_lifecycle_events' AND column_name = 'from_status'
      AND data_type = 'varchar' AND character_maximum_length = 16 AND is_nullable = 'YES'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` MODIFY COLUMN `from_status` VARCHAR(16) NULL',
   'SELECT ''SKIPPED_NORMALIZE_FROM_STATE'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(
    SELECT 1 FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_year_lifecycle_events' AND column_name = 'to_status'
      AND data_type = 'varchar' AND character_maximum_length = 16 AND is_nullable = 'NO'
), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` MODIFY COLUMN `to_status` VARCHAR(16) NOT NULL',
   'SELECT ''SKIPPED_NORMALIZE_TO_STATE'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

-- Add the twelve named checks from the merged Phase 1 contract.
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_years' AND constraint_name='chk_ay_calendar_lifecycle_status'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_years` ADD CONSTRAINT `chk_ay_calendar_lifecycle_status` CHECK (`calendar_lifecycle_status` IN (''draft'', ''active'', ''closed''))', 'SELECT ''SKIPPED_YEAR_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_types' AND constraint_name='chk_acet_kind'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_types` ADD CONSTRAINT `chk_acet_kind` CHECK (`event_type_kind` IN (''system'', ''general''))', 'SELECT ''SKIPPED_TYPE_KIND_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_types' AND constraint_name='chk_acet_flags'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_types` ADD CONSTRAINT `chk_acet_flags` CHECK (`default_is_enforcement` IN (0,1) AND `is_active` IN (0,1))', 'SELECT ''SKIPPED_TYPE_FLAGS_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_events' AND constraint_name='chk_ace_cancellation'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` ADD CONSTRAINT `chk_ace_cancellation` CHECK ((`cancelled_by_user_id` IS NULL AND `cancelled_at` IS NULL AND `cancellation_reason` IS NULL) OR (`cancelled_by_user_id` IS NOT NULL AND `cancelled_at` IS NOT NULL AND NULLIF(TRIM(`cancellation_reason`), '''') IS NOT NULL))', 'SELECT ''SKIPPED_CANCELLATION_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_versions' AND constraint_name='chk_acev_version_number'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` ADD CONSTRAINT `chk_acev_version_number` CHECK (`version_number` >= 1)', 'SELECT ''SKIPPED_VERSION_NUMBER_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_versions' AND constraint_name='chk_acev_window'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` ADD CONSTRAINT `chk_acev_window` CHECK (`ends_at` >= `starts_at`)', 'SELECT ''SKIPPED_WINDOW_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_versions' AND constraint_name='chk_acev_enforcement'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` ADD CONSTRAINT `chk_acev_enforcement` CHECK (`is_enforcement` IN (0,1))', 'SELECT ''SKIPPED_ENFORCEMENT_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_versions' AND constraint_name='chk_acev_change_reason'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` ADD CONSTRAINT `chk_acev_change_reason` CHECK (NULLIF(TRIM(`change_reason`), '''') IS NOT NULL)', 'SELECT ''SKIPPED_CHANGE_REASON_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_versions' AND constraint_name='chk_acev_publication'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` ADD CONSTRAINT `chk_acev_publication` CHECK ((`publication_status` = ''draft'' AND `published_by_user_id` IS NULL AND `published_at` IS NULL AND `superseded_at` IS NULL) OR (`publication_status` = ''published'' AND `published_by_user_id` IS NOT NULL AND `published_at` IS NOT NULL AND `superseded_at` IS NULL) OR (`publication_status` = ''superseded'' AND `published_by_user_id` IS NOT NULL AND `published_at` IS NOT NULL AND `superseded_at` IS NOT NULL))', 'SELECT ''SKIPPED_PUBLICATION_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_year_lifecycle_events' AND constraint_name='chk_acyle_from_status'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` ADD CONSTRAINT `chk_acyle_from_status` CHECK (`from_status` IS NULL OR `from_status` IN (''draft'', ''active'', ''closed''))', 'SELECT ''SKIPPED_FROM_STATUS_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_year_lifecycle_events' AND constraint_name='chk_acyle_to_status'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` ADD CONSTRAINT `chk_acyle_to_status` CHECK (`to_status` IN (''draft'', ''active'', ''closed'') AND (`from_status` IS NULL OR `from_status` <> `to_status`))', 'SELECT ''SKIPPED_TO_STATUS_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_year_lifecycle_events' AND constraint_name='chk_acyle_reason'),
 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` ADD CONSTRAINT `chk_acyle_reason` CHECK (NULLIF(TRIM(`reason`), '''') IS NOT NULL)', 'SELECT ''SKIPPED_LIFECYCLE_REASON_CHECK'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

-- Restore merged ownership comments and calendar table collation.
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_types' AND table_collation='utf8mb4_unicode_ci' AND table_comment='[academic-calendar-phase1] stable event vocabulary'), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_types` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, COMMENT=''[academic-calendar-phase1] stable event vocabulary''', 'SELECT ''SKIPPED_TYPES_COMMENT'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_events' AND table_collation='utf8mb4_unicode_ci' AND table_comment='[academic-calendar-phase1] logical university calendar events'), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_events` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, COMMENT=''[academic-calendar-phase1] logical university calendar events''', 'SELECT ''SKIPPED_EVENTS_COMMENT'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_versions' AND table_collation='utf8mb4_unicode_ci' AND table_comment='[academic-calendar-phase1] immutable event content revisions'), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, COMMENT=''[academic-calendar-phase1] immutable event content revisions''', 'SELECT ''SKIPPED_VERSIONS_COMMENT'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;
SET @acr_sql := IF(@acr_apply_allowed AND NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_year_lifecycle_events' AND table_collation='utf8mb4_unicode_ci' AND table_comment='[academic-calendar-phase1] append-only academic year lifecycle history'), 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, COMMENT=''[academic-calendar-phase1] append-only academic year lifecycle history''', 'SELECT ''SKIPPED_LIFECYCLE_COMMENT'' AS apply_step');
PREPARE acr_step FROM @acr_sql; EXECUTE acr_step; DEALLOCATE PREPARE acr_step;

SET @acr_post_event_context := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_events' AND column_name IN ('semester_id','academic_calendar_event_type_id'));
SET @acr_post_version_context := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_versions' AND column_name IN ('semester_id','academic_calendar_event_type_id'));
SET @acr_post_checks := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND constraint_type='CHECK' AND constraint_name IN ('chk_ay_calendar_lifecycle_status','chk_acet_kind','chk_acet_flags','chk_ace_cancellation','chk_acev_version_number','chk_acev_window','chk_acev_enforcement','chk_acev_change_reason','chk_acev_publication','chk_acyle_from_status','chk_acyle_to_status','chk_acyle_reason'));
SET @acr_post_varchars := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='varchar' AND ((table_name='academic_years' AND column_name='calendar_lifecycle_status') OR (table_name='academic_calendar_event_types' AND column_name='event_type_kind') OR (table_name='academic_calendar_event_versions' AND column_name='publication_status') OR (table_name='academic_calendar_year_lifecycle_events' AND column_name IN ('from_status','to_status'))));
SET @acr_post_context_fks := (SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_events' AND referenced_table_name IS NOT NULL AND constraint_name IN ('fk_ace_semester','fk_ace_event_type'));
SET @acr_post_target_indexes := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND seq_in_index=1 AND ((table_name='academic_calendar_event_types' AND index_name='idx_acet_kind_active' AND column_name='event_type_kind') OR (table_name='academic_calendar_events' AND index_name='idx_ace_year_semester' AND column_name='academic_year_id') OR (table_name='academic_calendar_events' AND index_name='idx_ace_event_type' AND column_name='academic_calendar_event_type_id') OR (table_name='academic_calendar_events' AND index_name='idx_ace_cancelled_at' AND column_name='cancelled_at') OR (table_name='academic_calendar_event_versions' AND index_name='idx_acev_event_status' AND column_name='academic_calendar_event_id') OR (table_name='academic_calendar_event_versions' AND index_name='idx_acev_publication_window' AND column_name='publication_status') OR (table_name='academic_calendar_event_versions' AND index_name='idx_acev_replaces' AND column_name='replaces_version_id') OR (table_name='academic_calendar_year_lifecycle_events' AND index_name='idx_acyle_year_occurred' AND column_name='academic_year_id') OR (table_name='academic_calendar_year_lifecycle_events' AND index_name='idx_acyle_status_occurred' AND column_name='to_status') OR (table_name='academic_calendar_year_lifecycle_events' AND index_name='idx_acyle_actor' AND column_name='actor_user_id')));
SET @acr_post_source_indexes := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND index_name IN ('idx_ace_year','idx_acev_semester','idx_acev_event_type','idx_acyle_to_status') AND table_name IN ('academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events'));
SET @acr_post_comments := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN ('academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events') AND table_comment LIKE '%[academic-calendar-phase1]%');

SELECT 'OVERALL' AS report_section,
       IF(@acr_apply_allowed
          AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events`) = 0
          AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions`) = 0
          AND @acr_post_event_context = 2
          AND @acr_post_version_context = 0 AND @acr_post_checks = 12
          AND @acr_post_varchars = 5 AND @acr_post_context_fks = 2
          AND @acr_post_target_indexes = 10 AND @acr_post_source_indexes = 0
          AND @acr_post_comments = 4, 'APPLIED', 'BLOCKED') AS result;
