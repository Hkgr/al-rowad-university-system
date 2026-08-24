-- READ ONLY. Accept the repair only when the final visible row is OVERALL | PASS.

SET @acr_database_ok := (SELECT COUNT(*) = 1 FROM information_schema.schemata WHERE schema_name='alrowad_uni_rust');
SET @acr_required_tables := (
    SELECT COUNT(*) = 11 FROM information_schema.tables
    WHERE table_schema='alrowad_uni_rust' AND table_type='BASE TABLE'
      AND table_name IN ('academic_years','semesters','users','academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events','system_modules','permissions','roles','role_permissions')
);
SET @acr_calendar_tables := (
    SELECT COUNT(*) = 4 FROM information_schema.tables
    WHERE table_schema='alrowad_uni_rust' AND table_type='BASE TABLE' AND engine='InnoDB'
      AND table_collation='utf8mb4_unicode_ci'
      AND table_comment LIKE '%[academic-calendar-phase1]%'
      AND table_name IN ('academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events')
);
SET @acr_data_columns := (
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
SET @acr_expected_columns := (
    SELECT COUNT(*) = 42 FROM information_schema.columns
    WHERE table_schema='alrowad_uni_rust' AND (
      (table_name='academic_calendar_event_types' AND column_name IN ('academic_calendar_event_type_id','event_type_code','name_ar','name_en','event_type_kind','default_is_enforcement','is_active','created_at','updated_at'))
      OR (table_name='academic_calendar_events' AND column_name IN ('academic_calendar_event_id','academic_year_id','semester_id','academic_calendar_event_type_id','created_by_user_id','created_at','cancelled_by_user_id','cancelled_at','cancellation_reason'))
      OR (table_name='academic_calendar_event_versions' AND column_name IN ('academic_calendar_event_version_id','academic_calendar_event_id','version_number','replaces_version_id','title','public_notes','starts_at','ends_at','is_enforcement','change_reason','created_by_user_id','created_at','publication_status','published_by_user_id','published_at','superseded_at','published_event_slot'))
      OR (table_name='academic_calendar_year_lifecycle_events' AND column_name IN ('academic_calendar_year_lifecycle_event_id','academic_year_id','from_status','to_status','actor_user_id','reason','occurred_at'))
    )
);
SET @acr_exact_column_total := (
    SELECT COUNT(*) = 42 FROM information_schema.columns
    WHERE table_schema='alrowad_uni_rust'
      AND table_name IN ('academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events')
);
SET @acr_context_ownership := (
    SELECT
      (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_events' AND column_name IN ('semester_id','academic_calendar_event_type_id')) = 2
      AND
      (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_versions' AND column_name IN ('semester_id','academic_calendar_event_type_id')) = 0
);
SET @acr_signed_key_contract := (
    SELECT COUNT(*) = 18 FROM information_schema.columns
    WHERE table_schema='alrowad_uni_rust' AND data_type='int'
      AND LOWER(column_type) NOT LIKE '%unsigned%'
      AND (
        (table_name='academic_years' AND column_name='academic_year_id')
        OR (table_name='semesters' AND column_name='semester_id')
        OR (table_name='users' AND column_name='user_id')
        OR (table_name='academic_calendar_event_types' AND column_name='academic_calendar_event_type_id')
        OR (table_name='academic_calendar_events' AND column_name IN ('academic_calendar_event_id','academic_year_id','semester_id','academic_calendar_event_type_id','created_by_user_id','cancelled_by_user_id'))
        OR (table_name='academic_calendar_event_versions' AND column_name IN ('academic_calendar_event_version_id','academic_calendar_event_id','replaces_version_id','created_by_user_id','published_by_user_id'))
        OR (table_name='academic_calendar_year_lifecycle_events' AND column_name IN ('academic_calendar_year_lifecycle_event_id','academic_year_id','actor_user_id'))
      )
);
SET @acr_state_contract := (
    SELECT COUNT(*) = 5 FROM information_schema.columns
    WHERE table_schema='alrowad_uni_rust' AND data_type='varchar' AND character_maximum_length=16
      AND (
        (table_name='academic_years' AND column_name='calendar_lifecycle_status'
          AND is_nullable='NO' AND LOWER(TRIM(BOTH '''' FROM CAST(column_default AS CHAR)))='draft'
          AND column_comment='[academic-calendar-phase1] draft|active|closed')
        OR (table_name='academic_calendar_event_types' AND column_name='event_type_kind'
          AND is_nullable='NO')
        OR (table_name='academic_calendar_event_versions' AND column_name='publication_status'
          AND is_nullable='NO' AND LOWER(TRIM(BOTH '''' FROM CAST(column_default AS CHAR)))='draft')
        OR (table_name='academic_calendar_year_lifecycle_events' AND column_name='from_status'
          AND is_nullable='YES')
        OR (table_name='academic_calendar_year_lifecycle_events' AND column_name='to_status'
          AND is_nullable='NO')
      )
);
SET @acr_required_checks := (
    SELECT COUNT(*) = 12 FROM information_schema.table_constraints
    WHERE table_schema='alrowad_uni_rust' AND constraint_type='CHECK'
      AND ((table_name='academic_years' AND constraint_name='chk_ay_calendar_lifecycle_status')
        OR (table_name='academic_calendar_event_types' AND constraint_name IN ('chk_acet_kind','chk_acet_flags'))
        OR (table_name='academic_calendar_events' AND constraint_name='chk_ace_cancellation')
        OR (table_name='academic_calendar_event_versions' AND constraint_name IN ('chk_acev_version_number','chk_acev_window','chk_acev_enforcement','chk_acev_change_reason','chk_acev_publication'))
        OR (table_name='academic_calendar_year_lifecycle_events' AND constraint_name IN ('chk_acyle_from_status','chk_acyle_to_status','chk_acyle_reason')))
);
SET @acr_required_indexes := (
    SELECT COUNT(*) = 10 FROM information_schema.statistics
    WHERE table_schema='alrowad_uni_rust' AND seq_in_index=1 AND (
      (table_name='academic_calendar_event_types' AND index_name='idx_acet_kind_active' AND column_name='event_type_kind')
      OR (table_name='academic_calendar_events' AND index_name='idx_ace_year_semester' AND column_name='academic_year_id')
      OR (table_name='academic_calendar_events' AND index_name='idx_ace_event_type' AND column_name='academic_calendar_event_type_id')
      OR (table_name='academic_calendar_events' AND index_name='idx_ace_cancelled_at' AND column_name='cancelled_at')
      OR (table_name='academic_calendar_event_versions' AND index_name='idx_acev_event_status' AND column_name='academic_calendar_event_id')
      OR (table_name='academic_calendar_event_versions' AND index_name='idx_acev_publication_window' AND column_name='publication_status')
      OR (table_name='academic_calendar_event_versions' AND index_name='idx_acev_replaces' AND column_name='replaces_version_id')
      OR (table_name='academic_calendar_year_lifecycle_events' AND index_name='idx_acyle_year_occurred' AND column_name='academic_year_id')
      OR (table_name='academic_calendar_year_lifecycle_events' AND index_name='idx_acyle_status_occurred' AND column_name='to_status')
      OR (table_name='academic_calendar_year_lifecycle_events' AND index_name='idx_acyle_actor' AND column_name='actor_user_id')
    )
);
SET @acr_source_indexes_absent := (
    SELECT COUNT(*) = 0 FROM information_schema.statistics
    WHERE table_schema='alrowad_uni_rust' AND index_name IN ('idx_ace_year','idx_acev_semester','idx_acev_event_type','idx_acyle_to_status')
      AND table_name IN ('academic_calendar_events','academic_calendar_event_versions','academic_calendar_year_lifecycle_events')
);
SET @acr_required_foreign_keys := (
    SELECT COUNT(*) = 11 FROM information_schema.key_column_usage k
    JOIN information_schema.referential_constraints r
      ON r.constraint_schema=k.constraint_schema AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name
    WHERE k.table_schema='alrowad_uni_rust' AND r.delete_rule IN ('RESTRICT','NO ACTION') AND r.update_rule IN ('RESTRICT','NO ACTION')
      AND (
        (k.table_name='academic_calendar_events' AND k.constraint_name='fk_ace_year' AND k.column_name='academic_year_id' AND k.referenced_table_name='academic_years' AND k.referenced_column_name='academic_year_id')
        OR (k.table_name='academic_calendar_events' AND k.constraint_name='fk_ace_semester' AND k.column_name='semester_id' AND k.referenced_table_name='semesters' AND k.referenced_column_name='semester_id')
        OR (k.table_name='academic_calendar_events' AND k.constraint_name='fk_ace_event_type' AND k.column_name='academic_calendar_event_type_id' AND k.referenced_table_name='academic_calendar_event_types' AND k.referenced_column_name='academic_calendar_event_type_id')
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
SET @acr_generated_slots := (
    SELECT COUNT(*) = 2 FROM information_schema.columns
    WHERE table_schema='alrowad_uni_rust' AND is_generated='ALWAYS'
      AND (UPPER(extra) LIKE '%PERSISTENT%' OR UPPER(extra) LIKE '%STORED%')
      AND ((table_name='academic_years' AND column_name='calendar_active_slot' AND generation_expression LIKE '%calendar_lifecycle_status%')
        OR (table_name='academic_calendar_event_versions' AND column_name='published_event_slot' AND generation_expression LIKE '%publication_status%' AND generation_expression LIKE '%academic_calendar_event_id%'))
);
SET @acr_generated_unique_indexes := (
    SELECT COUNT(*) = 2 FROM information_schema.statistics
    WHERE table_schema='alrowad_uni_rust' AND non_unique=0 AND seq_in_index=1
      AND ((table_name='academic_years' AND index_name='uq_ay_calendar_active_slot' AND column_name='calendar_active_slot')
        OR (table_name='academic_calendar_event_versions' AND index_name='uq_acev_published_event_slot' AND column_name='published_event_slot'))
);

SET @acr_data_sql := IF(@acr_required_tables AND @acr_data_columns,
 'SELECT
    (SELECT COUNT(DISTINCT event_type_code) FROM `alrowad_uni_rust`.`academic_calendar_event_types` WHERE event_type_code IN (''admission_registration'',''course_registration'',''withdrawal'',''study_period'',''exam_preparation'',''practical_exams'',''theoretical_exams'',''grade_appeals'',''supplementary_exams'',''university_break'',''preparation_period'',''holiday'',''general_event'')),
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years` WHERE is_current=1),
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years` WHERE is_current=1 AND is_active=1 AND calendar_lifecycle_status=''active''),
    (SELECT COUNT(DISTINCT semester_code) FROM `alrowad_uni_rust`.`semesters` WHERE semester_code IN (''first'',''second'',''summer'')),
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_events` e LEFT JOIN `alrowad_uni_rust`.`academic_years` y ON y.academic_year_id=e.academic_year_id LEFT JOIN `alrowad_uni_rust`.`semesters` s ON s.semester_id=e.semester_id LEFT JOIN `alrowad_uni_rust`.`academic_calendar_event_types` t ON t.academic_calendar_event_type_id=e.academic_calendar_event_type_id WHERE y.academic_year_id IS NULL OR (e.semester_id IS NOT NULL AND s.semester_id IS NULL) OR t.academic_calendar_event_type_id IS NULL),
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id=p.module_id WHERE p.permission_code=''academic_calendar.manage'' AND p.is_active=1 AND m.module_code=''vice_presidency''),
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id=rp.role_id WHERE p.permission_code=''academic_calendar.manage'' AND r.role_code=''vice_president_scientific'' AND r.is_active=1),
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id=rp.role_id WHERE p.permission_code=''academic_calendar.manage'' AND r.role_code<>''vice_president_scientific'')
  INTO @acr_seed_codes,@acr_current_count,@acr_current_active,@acr_semester_codes,@acr_orphan_context,@acr_permission_ok,@acr_mapping_ok,@acr_other_mappings',
 'SELECT 0,0,0,0,1,0,0,1 INTO @acr_seed_codes,@acr_current_count,@acr_current_active,@acr_semester_codes,@acr_orphan_context,@acr_permission_ok,@acr_mapping_ok,@acr_other_mappings');
PREPARE acr_verify_data FROM @acr_data_sql; EXECUTE acr_verify_data; DEALLOCATE PREPARE acr_verify_data;

SET @acr_structure_pass := @acr_database_ok AND @acr_required_tables AND @acr_data_columns AND @acr_calendar_tables
    AND @acr_expected_columns AND @acr_exact_column_total AND @acr_context_ownership
    AND @acr_signed_key_contract AND @acr_state_contract AND @acr_required_checks AND @acr_required_indexes
    AND @acr_source_indexes_absent AND @acr_required_foreign_keys
    AND @acr_generated_slots AND @acr_generated_unique_indexes;
SET @acr_data_pass := @acr_seed_codes=13 AND @acr_current_count=1 AND @acr_current_active=1
    AND @acr_semester_codes=3 AND @acr_orphan_context=0
    AND @acr_permission_ok=1 AND @acr_mapping_ok=1 AND @acr_other_mappings=0;

SELECT 'STRUCTURE' AS report_section, IF(@acr_structure_pass,'PASS','FAIL') AS result
UNION ALL SELECT 'CONTEXT_OWNERSHIP', IF(@acr_context_ownership,'PASS','FAIL')
UNION ALL SELECT 'REFERENCE_DATA', IF(@acr_data_pass,'PASS','FAIL')
UNION ALL SELECT 'PHASE2_PERMISSION', IF(@acr_permission_ok=1 AND @acr_mapping_ok=1 AND @acr_other_mappings=0,'PASS','FAIL')
UNION ALL SELECT 'OVERALL', IF(@acr_structure_pass AND @acr_data_pass,'PASS','FAIL');
