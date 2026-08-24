-- READ ONLY. Academic Calendar Phase 1 verification.
-- Accept the deployment only when the final OVERALL row returns PASS.

SET @ac1_db_ready := (
    SELECT COUNT(*) = 1 FROM information_schema.schemata
    WHERE schema_name = 'alrowad_uni_rust'
);
SET @ac1_core_tables := (
    SELECT COUNT(*) = 4 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_type = 'BASE TABLE'
      AND table_name IN ('academic_years', 'semesters', 'users', 'course_offerings')
);
SET @ac1_core_columns := (
    SELECT COUNT(*) = 19 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND (
        (table_name = 'academic_years' AND column_name IN (
          'academic_year_id', 'year_name', 'start_date', 'end_date',
          'is_current', 'is_active', 'created_at', 'updated_at'
        ))
        OR (table_name = 'semesters' AND column_name IN (
          'semester_id', 'semester_code', 'semester_name', 'semester_order', 'is_active'
        ))
        OR (table_name = 'users' AND column_name = 'user_id')
        OR (table_name = 'course_offerings' AND column_name IN (
          'course_offering_id', 'academic_year_id', 'semester_id', 'course_id', 'status'
        ))
      )
);
SET @ac1_core_signed_keys := (
    SELECT COUNT(*) = 7 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND data_type = 'int' AND column_type NOT LIKE '%unsigned%'
      AND (
        (table_name = 'academic_years' AND column_name = 'academic_year_id')
        OR (table_name = 'semesters' AND column_name = 'semester_id')
        OR (table_name = 'users' AND column_name = 'user_id')
        OR (table_name = 'course_offerings' AND column_name IN (
          'course_offering_id', 'academic_year_id', 'semester_id', 'course_id'
        ))
      )
);
SET @ac1_core_offering_fks := (
    SELECT COUNT(*) = 2 FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'course_offerings'
      AND (
        (column_name = 'academic_year_id' AND referenced_table_name = 'academic_years' AND referenced_column_name = 'academic_year_id')
        OR (column_name = 'semester_id' AND referenced_table_name = 'semesters' AND referenced_column_name = 'semester_id')
      )
);
SET @ac1_calendar_tables := (
    SELECT COUNT(*) = 4 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_type = 'BASE TABLE'
      AND engine = 'InnoDB'
      AND table_comment LIKE '%[academic-calendar-phase1]%'
      AND table_name IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
);
SET @ac1_year_extension := (
    SELECT COUNT(*) = 2 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name IN ('calendar_lifecycle_status', 'calendar_active_slot')
      AND column_comment LIKE '%[academic-calendar-phase1]%'
);
SET @ac1_lifecycle_status_column := (
    SELECT COUNT(*) = 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_lifecycle_status'
      AND data_type = 'varchar'
      AND character_maximum_length >= 16
      AND is_nullable = 'NO'
      AND LOWER(TRIM(BOTH '''' FROM CAST(column_default AS CHAR))) = 'draft'
      AND column_comment LIKE '%[academic-calendar-phase1]%'
);

SET @ac1_expected_columns := (
    SELECT COUNT(*) = 42 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND (
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
          'version_number', 'replaces_version_id', 'title', 'public_notes',
          'starts_at', 'ends_at', 'is_enforcement', 'change_reason',
          'created_by_user_id', 'created_at', 'publication_status',
          'published_by_user_id', 'published_at', 'superseded_at',
          'published_event_slot'
        ))
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND column_name IN (
          'academic_calendar_year_lifecycle_event_id', 'academic_year_id',
          'from_status', 'to_status', 'actor_user_id', 'reason', 'occurred_at'
        ))
      )
);

SET @ac1_signed_calendar_integers := (
    SELECT COUNT(*) = 17 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND data_type = 'int'
      AND column_type NOT LIKE '%unsigned%'
      AND (
        (table_name = 'academic_calendar_event_types'
         AND column_name = 'academic_calendar_event_type_id')
        OR (table_name = 'academic_calendar_events' AND column_name IN (
          'academic_calendar_event_id', 'academic_year_id', 'semester_id',
          'academic_calendar_event_type_id', 'created_by_user_id', 'cancelled_by_user_id'
        ))
        OR (table_name = 'academic_calendar_event_versions' AND column_name IN (
          'academic_calendar_event_version_id', 'academic_calendar_event_id',
          'version_number', 'replaces_version_id', 'created_by_user_id',
          'published_by_user_id', 'published_event_slot'
        ))
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND column_name IN (
          'academic_calendar_year_lifecycle_event_id', 'academic_year_id', 'actor_user_id'
        ))
      )
);

SET @ac1_primary_keys := (
    SELECT COUNT(*) = 4 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND index_name = 'PRIMARY' AND seq_in_index = 1
      AND (
        (table_name = 'academic_calendar_event_types' AND column_name = 'academic_calendar_event_type_id')
        OR (table_name = 'academic_calendar_events' AND column_name = 'academic_calendar_event_id')
        OR (table_name = 'academic_calendar_event_versions' AND column_name = 'academic_calendar_event_version_id')
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND column_name = 'academic_calendar_year_lifecycle_event_id')
      )
);

SET @ac1_datetime_columns := (
    SELECT COUNT(*) = 8 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND data_type IN ('datetime', 'timestamp')
      AND (
        (table_name = 'academic_calendar_events' AND column_name IN ('created_at', 'cancelled_at'))
        OR (table_name = 'academic_calendar_event_versions' AND column_name IN (
          'starts_at', 'ends_at', 'created_at', 'published_at', 'superseded_at'
        ))
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND column_name = 'occurred_at')
      )
);

SET @ac1_required_uniques := (
    SELECT COUNT(*) = 4 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND non_unique = 0
      AND seq_in_index = 1
      AND (
        (table_name = 'academic_years' AND index_name = 'uq_ay_calendar_active_slot' AND column_name = 'calendar_active_slot')
        OR (table_name = 'academic_calendar_event_types' AND index_name = 'uq_acet_code' AND column_name = 'event_type_code')
        OR (table_name = 'academic_calendar_event_versions' AND index_name = 'uq_acev_event_version' AND column_name = 'academic_calendar_event_id')
        OR (table_name = 'academic_calendar_event_versions' AND index_name = 'uq_acev_published_event_slot' AND column_name = 'published_event_slot')
      )
);
SET @ac1_revision_unique_exact := (
    SELECT COUNT(*) = 1
    FROM (
      SELECT index_name
      FROM information_schema.statistics
      WHERE table_schema = 'alrowad_uni_rust'
        AND table_name = 'academic_calendar_event_versions'
        AND index_name = 'uq_acev_event_version'
        AND non_unique = 0
      GROUP BY index_name
      HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'academic_calendar_event_id,version_number'
    ) revision_unique
);

SET @ac1_lookup_indexes := (
    SELECT COUNT(*) = 10 FROM information_schema.statistics
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

SET @ac1_required_foreign_keys := (
    SELECT COUNT(*) = 11
    FROM information_schema.key_column_usage k
    JOIN information_schema.referential_constraints r
      ON r.constraint_schema = k.constraint_schema
     AND r.table_name = k.table_name
     AND r.constraint_name = k.constraint_name
    WHERE k.table_schema = 'alrowad_uni_rust'
      AND r.delete_rule IN ('RESTRICT', 'NO ACTION')
      AND r.update_rule IN ('RESTRICT', 'NO ACTION')
      AND (
        (k.table_name = 'academic_calendar_events' AND k.column_name = 'academic_year_id' AND k.referenced_table_name = 'academic_years' AND k.referenced_column_name = 'academic_year_id')
        OR (k.table_name = 'academic_calendar_events' AND k.column_name = 'semester_id' AND k.referenced_table_name = 'semesters' AND k.referenced_column_name = 'semester_id')
        OR (k.table_name = 'academic_calendar_events' AND k.column_name = 'academic_calendar_event_type_id' AND k.referenced_table_name = 'academic_calendar_event_types' AND k.referenced_column_name = 'academic_calendar_event_type_id')
        OR (k.table_name = 'academic_calendar_events' AND k.column_name = 'created_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id')
        OR (k.table_name = 'academic_calendar_events' AND k.column_name = 'cancelled_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id')
        OR (k.table_name = 'academic_calendar_event_versions' AND k.column_name = 'academic_calendar_event_id' AND k.referenced_table_name = 'academic_calendar_events' AND k.referenced_column_name = 'academic_calendar_event_id')
        OR (k.table_name = 'academic_calendar_event_versions' AND k.column_name = 'replaces_version_id' AND k.referenced_table_name = 'academic_calendar_event_versions' AND k.referenced_column_name = 'academic_calendar_event_version_id')
        OR (k.table_name = 'academic_calendar_event_versions' AND k.column_name = 'created_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id')
        OR (k.table_name = 'academic_calendar_event_versions' AND k.column_name = 'published_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id')
        OR (k.table_name = 'academic_calendar_year_lifecycle_events' AND k.column_name = 'academic_year_id' AND k.referenced_table_name = 'academic_years' AND k.referenced_column_name = 'academic_year_id')
        OR (k.table_name = 'academic_calendar_year_lifecycle_events' AND k.column_name = 'actor_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id')
      )
);

SET @ac1_required_checks := (
    SELECT COUNT(*) = 12 FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND constraint_type = 'CHECK'
      AND constraint_name IN (
        'chk_ay_calendar_lifecycle_status', 'chk_acet_kind', 'chk_acet_flags',
        'chk_ace_cancellation', 'chk_acev_version_number', 'chk_acev_window',
        'chk_acev_enforcement', 'chk_acev_change_reason', 'chk_acev_publication',
        'chk_acyle_from_status', 'chk_acyle_to_status', 'chk_acyle_reason'
      )
);

SET @ac1_generated_slots := (
    SELECT COUNT(*) = 2 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND is_generated = 'ALWAYS'
      AND (UPPER(extra) LIKE '%PERSISTENT%' OR UPPER(extra) LIKE '%STORED%')
      AND (
        (table_name = 'academic_years'
         AND column_name = 'calendar_active_slot'
         AND generation_expression LIKE '%calendar_lifecycle_status%')
        OR (table_name = 'academic_calendar_event_versions'
            AND column_name = 'published_event_slot'
            AND generation_expression LIKE '%publication_status%'
            AND generation_expression LIKE '%academic_calendar_event_id%')
      )
);

SET @ac1_forbidden_context_unique := (
    SELECT COUNT(*)
    FROM (
      SELECT index_name,
             GROUP_CONCAT(column_name ORDER BY seq_in_index) AS index_columns
      FROM information_schema.statistics
      WHERE table_schema = 'alrowad_uni_rust'
        AND table_name = 'academic_calendar_events'
        AND non_unique = 0
        AND index_name <> 'PRIMARY'
      GROUP BY index_name
    ) event_uniques
    WHERE index_columns LIKE '%academic_year_id%'
       OR index_columns LIKE '%semester_id%'
       OR index_columns LIKE '%academic_calendar_event_type_id%'
);
SET @ac1_calendar_triggers := (
    SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = 'alrowad_uni_rust'
      AND event_object_table IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
);

SET @ac1_semester_nullable := (
    SELECT COUNT(*) = 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_events'
      AND column_name = 'semester_id'
      AND is_nullable = 'YES'
);
SET @ac1_enforcement_explicit := (
    SELECT COUNT(*) = 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
      AND column_name = 'is_enforcement'
      AND data_type = 'tinyint'
      AND is_nullable = 'NO'
      AND column_default IS NULL
);
SET @ac1_cancellation_provenance := (
    SELECT COUNT(*) = 3 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_events'
      AND column_name IN ('cancelled_by_user_id', 'cancelled_at', 'cancellation_reason')
      AND is_nullable = 'YES'
);
SET @ac1_revision_history := (
    SELECT COUNT(*) = 7 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
      AND column_name IN (
        'version_number', 'replaces_version_id', 'change_reason', 'created_by_user_id',
        'publication_status', 'published_by_user_id', 'published_at'
      )
);

SET @ac1_sql := IF(
    @ac1_calendar_tables,
    'SELECT
       COUNT(*) = 13,
       COUNT(DISTINCT event_type_code) = 13,
       COALESCE(SUM(
         (event_type_code = ''admission_registration'' AND event_type_kind = ''system'' AND default_is_enforcement = 1)
         OR (event_type_code = ''course_registration'' AND event_type_kind = ''system'' AND default_is_enforcement = 1)
         OR (event_type_code = ''withdrawal'' AND event_type_kind = ''system'' AND default_is_enforcement = 1)
         OR (event_type_code = ''study_period'' AND event_type_kind = ''system'' AND default_is_enforcement = 0)
         OR (event_type_code = ''exam_preparation'' AND event_type_kind = ''system'' AND default_is_enforcement = 0)
         OR (event_type_code = ''practical_exams'' AND event_type_kind = ''system'' AND default_is_enforcement = 1)
         OR (event_type_code = ''theoretical_exams'' AND event_type_kind = ''system'' AND default_is_enforcement = 1)
         OR (event_type_code = ''grade_appeals'' AND event_type_kind = ''system'' AND default_is_enforcement = 1)
         OR (event_type_code = ''supplementary_exams'' AND event_type_kind = ''system'' AND default_is_enforcement = 1)
         OR (event_type_code = ''university_break'' AND event_type_kind = ''general'' AND default_is_enforcement = 0)
         OR (event_type_code = ''preparation_period'' AND event_type_kind = ''general'' AND default_is_enforcement = 0)
         OR (event_type_code = ''holiday'' AND event_type_kind = ''general'' AND default_is_enforcement = 0)
         OR (event_type_code = ''general_event'' AND event_type_kind = ''general'' AND default_is_enforcement = 0)
       ), 0) = 13
     INTO @ac1_seed_count_ok, @ac1_seed_unique_ok, @ac1_seed_contract_ok
     FROM `alrowad_uni_rust`.`academic_calendar_event_types`
     WHERE event_type_code IN (
       ''admission_registration'', ''course_registration'', ''withdrawal'',
       ''study_period'', ''exam_preparation'', ''practical_exams'',
       ''theoretical_exams'', ''grade_appeals'', ''supplementary_exams'',
       ''university_break'', ''preparation_period'', ''holiday'', ''general_event''
     )',
    'SELECT 0, 0, 0 INTO @ac1_seed_count_ok, @ac1_seed_unique_ok, @ac1_seed_contract_ok'
);
PREPARE ac1_verify_types FROM @ac1_sql;
EXECUTE ac1_verify_types;
DEALLOCATE PREPARE ac1_verify_types;

SET @ac1_sql := IF(
    @ac1_core_tables AND @ac1_year_extension,
    'SELECT
       COUNT(*),
       COALESCE(SUM(is_current = 1), 0) = 1,
       COALESCE(SUM(is_current = 1 AND is_active = 1), 0) = 1,
       COALESCE(SUM(calendar_lifecycle_status = ''active''), 0) = 1,
       COALESCE(SUM(is_current = 1 AND calendar_lifecycle_status = ''active''), 0) = 1,
       COALESCE(SUM(calendar_lifecycle_status NOT IN (''draft'', ''active'', ''closed'')), 0) = 0
     INTO @ac1_year_count, @ac1_one_current, @ac1_current_still_active,
          @ac1_one_lifecycle_active, @ac1_current_matches_lifecycle, @ac1_valid_lifecycle_values
     FROM `alrowad_uni_rust`.`academic_years`',
    'SELECT 0, 0, 0, 0, 0, 0 INTO @ac1_year_count, @ac1_one_current, @ac1_current_still_active,
          @ac1_one_lifecycle_active, @ac1_current_matches_lifecycle, @ac1_valid_lifecycle_values'
);
PREPARE ac1_verify_years FROM @ac1_sql;
EXECUTE ac1_verify_years;
DEALLOCATE PREPARE ac1_verify_years;

SET @ac1_sql := IF(
    @ac1_core_tables,
    'SELECT COUNT(*), COALESCE(SUM(semester_code IN (''first'', ''second'', ''summer'')), 0) = 3
     INTO @ac1_semester_count, @ac1_core_semesters_preserved
     FROM `alrowad_uni_rust`.`semesters`',
    'SELECT 0, 0 INTO @ac1_semester_count, @ac1_core_semesters_preserved'
);
PREPARE ac1_verify_semesters FROM @ac1_sql;
EXECUTE ac1_verify_semesters;
DEALLOCATE PREPARE ac1_verify_semesters;

SET @ac1_sql := IF(
    @ac1_core_tables,
    'SELECT
       COUNT(*),
       COALESCE(SUM(ay.academic_year_id IS NULL), 0) = 0,
       COALESCE(SUM(s.semester_id IS NULL), 0) = 0
     INTO @ac1_offering_count, @ac1_offering_year_links_ok, @ac1_offering_semester_links_ok
     FROM `alrowad_uni_rust`.`course_offerings` co
     LEFT JOIN `alrowad_uni_rust`.`academic_years` ay
       ON ay.academic_year_id = co.academic_year_id
     LEFT JOIN `alrowad_uni_rust`.`semesters` s
       ON s.semester_id = co.semester_id',
    'SELECT 0, 0, 0 INTO @ac1_offering_count, @ac1_offering_year_links_ok, @ac1_offering_semester_links_ok'
);
PREPARE ac1_verify_offerings FROM @ac1_sql;
EXECUTE ac1_verify_offerings;
DEALLOCATE PREPARE ac1_verify_offerings;

SET @ac1_structure_pass := @ac1_db_ready
    AND @ac1_core_tables
    AND @ac1_core_columns
    AND @ac1_core_signed_keys
    AND @ac1_core_offering_fks
    AND @ac1_year_extension
    AND @ac1_lifecycle_status_column
    AND @ac1_calendar_tables
    AND @ac1_expected_columns
    AND @ac1_signed_calendar_integers
    AND @ac1_primary_keys
    AND @ac1_datetime_columns
    AND @ac1_required_uniques
    AND @ac1_revision_unique_exact
    AND @ac1_lookup_indexes
    AND @ac1_required_foreign_keys
    AND @ac1_required_checks
    AND @ac1_generated_slots;

SET @ac1_representation_pass := @ac1_semester_nullable
    AND @ac1_enforcement_explicit
    AND @ac1_cancellation_provenance
    AND @ac1_revision_history
    AND @ac1_forbidden_context_unique = 0
    AND @ac1_calendar_triggers = 0;

SET @ac1_compatibility_pass := @ac1_year_count > 0
    AND @ac1_semester_count > 0
    AND @ac1_core_semesters_preserved
    AND @ac1_one_current
    AND @ac1_current_still_active
    AND @ac1_one_lifecycle_active
    AND @ac1_current_matches_lifecycle
    AND @ac1_valid_lifecycle_values
    AND @ac1_offering_year_links_ok
    AND @ac1_offering_semester_links_ok;

SET @ac1_verify_pass := @ac1_structure_pass
    AND @ac1_representation_pass
    AND @ac1_compatibility_pass
    AND @ac1_seed_count_ok
    AND @ac1_seed_unique_ok
    AND @ac1_seed_contract_ok;

SELECT 'STRUCTURE' AS report_section,
       IF(@ac1_structure_pass, 'PASS', 'FAIL') AS result,
       @ac1_calendar_tables AS expected_tables,
       @ac1_expected_columns AS expected_columns,
       @ac1_required_foreign_keys AS required_foreign_keys,
       @ac1_required_checks AS required_checks;

SELECT 'VOCABULARY' AS report_section,
       IF(@ac1_seed_count_ok AND @ac1_seed_unique_ok AND @ac1_seed_contract_ok, 'PASS', 'FAIL') AS result,
       13 AS required_code_count;

SELECT 'REPRESENTATION' AS report_section,
       IF(@ac1_representation_pass, 'PASS', 'FAIL') AS result,
       @ac1_semester_nullable AS supports_year_wide_and_semester_events,
       @ac1_enforcement_explicit AS enforcement_is_persisted,
       @ac1_revision_history AS supports_revision_history,
       @ac1_cancellation_provenance AS supports_cancellation_history,
       (@ac1_forbidden_context_unique = 0) AS supports_multiple_same_type_windows,
       (@ac1_calendar_triggers = 0) AS no_overlap_triggers;

SELECT 'COMPATIBILITY' AS report_section,
       IF(@ac1_compatibility_pass, 'PASS', 'FAIL') AS result,
       @ac1_year_count AS academic_year_count,
       @ac1_semester_count AS semester_count,
       @ac1_core_semesters_preserved AS first_second_summer_preserved,
       @ac1_offering_count AS course_offering_count,
       @ac1_one_current AS current_year_preserved,
       @ac1_offering_year_links_ok AS offering_year_links_valid,
       @ac1_offering_semester_links_ok AS offering_semester_links_valid;

SELECT 'OVERALL' AS report_section,
       IF(@ac1_verify_pass, 'PASS', 'FAIL') AS result;
