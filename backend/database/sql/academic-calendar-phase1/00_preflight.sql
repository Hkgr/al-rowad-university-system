-- READ ONLY. Continue only when the final OVERALL row returns READY.
-- Academic Calendar Phase 1 foundation for MariaDB 10.11.
-- Fully qualified objects; no selected-schema dependency.
-- One guarded EXECUTE IMMEDIATE is used so missing core tables return BLOCKED
-- without runtime errors. It is the final statement and returns one grid.

SET @ac1_owner := '[academic-calendar-phase1]';

SET @ac1_db_ready := (
    SELECT COUNT(*) = 1
    FROM information_schema.schemata
    WHERE schema_name = 'alrowad_uni_rust'
);

SET @ac1_required_tables := (
    SELECT COUNT(*) = 4
    FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_type = 'BASE TABLE'
      AND table_name IN ('academic_years', 'semesters', 'users', 'course_offerings')
);

SET @ac1_required_columns := (
    SELECT COUNT(*) = 19
    FROM information_schema.columns
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

SET @ac1_signed_integer_keys := (
    SELECT COUNT(*) = 7
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND data_type = 'int'
      AND column_type NOT LIKE '%unsigned%'
      AND (
        (table_name = 'academic_years' AND column_name = 'academic_year_id')
        OR (table_name = 'semesters' AND column_name = 'semester_id')
        OR (table_name = 'users' AND column_name = 'user_id')
        OR (table_name = 'course_offerings' AND column_name IN (
            'course_offering_id', 'academic_year_id', 'semester_id', 'course_id'
        ))
      )
);

SET @ac1_required_primary_keys := (
    SELECT COUNT(*) = 4
    FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND index_name = 'PRIMARY'
      AND seq_in_index = 1
      AND (
        (table_name = 'academic_years' AND column_name = 'academic_year_id')
        OR (table_name = 'semesters' AND column_name = 'semester_id')
        OR (table_name = 'users' AND column_name = 'user_id')
        OR (table_name = 'course_offerings' AND column_name = 'course_offering_id')
      )
);

SET @ac1_offering_foreign_keys := (
    SELECT COUNT(*) = 2
    FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'course_offerings'
      AND (
        (column_name = 'academic_year_id'
         AND referenced_table_name = 'academic_years'
         AND referenced_column_name = 'academic_year_id')
        OR (column_name = 'semester_id'
            AND referenced_table_name = 'semesters'
            AND referenced_column_name = 'semester_id')
      )
);

SET @ac1_core_ready := @ac1_db_ready
    AND @ac1_required_tables
    AND @ac1_required_columns
    AND @ac1_signed_integer_keys
    AND @ac1_required_primary_keys
    AND @ac1_offering_foreign_keys;

-- Additive academic_years lifecycle objects.
SET @ac1_status_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_lifecycle_status'
);
SET @ac1_status_compatible := (
    SELECT COUNT(*) = 1
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_lifecycle_status'
      AND data_type = 'varchar'
      AND character_maximum_length >= 16
      AND is_nullable = 'NO'
      AND column_comment LIKE '%[academic-calendar-phase1]%'
);
SET @ac1_status_repairable := (
    SELECT COUNT(*) = 1
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_lifecycle_status'
      AND data_type = 'varchar'
      AND character_maximum_length >= 16
      AND is_nullable = 'YES'
      AND column_comment LIKE '%[academic-calendar-phase1]%'
);
SET @ac1_status_state := CASE
    WHEN @ac1_status_exists = 0 THEN 'ABSENT'
    WHEN @ac1_status_compatible THEN 'COMPATIBLE'
    WHEN @ac1_status_repairable THEN 'REPAIRABLE'
    ELSE 'CONFLICT'
END;

SET @ac1_slot_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_active_slot'
);
SET @ac1_slot_compatible := (
    SELECT COUNT(*) = 1
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_active_slot'
      AND data_type = 'tinyint'
      AND is_nullable = 'YES'
      AND is_generated = 'ALWAYS'
      AND (UPPER(extra) LIKE '%PERSISTENT%' OR UPPER(extra) LIKE '%STORED%')
      AND generation_expression LIKE '%calendar_lifecycle_status%'
      AND column_comment LIKE '%[academic-calendar-phase1]%'
);
SET @ac1_slot_state := CASE
    WHEN @ac1_slot_exists = 0 THEN 'ABSENT'
    WHEN @ac1_slot_compatible THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @ac1_lifecycle_check_exists := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND constraint_name = 'chk_ay_calendar_lifecycle_status'
      AND constraint_type = 'CHECK'
);
SET @ac1_active_unique_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND index_name = 'uq_ay_calendar_active_slot'
      AND non_unique = 0
      AND seq_in_index = 1
      AND column_name = 'calendar_active_slot'
);
SET @ac1_extension_conflict := @ac1_status_state = 'CONFLICT'
    OR @ac1_slot_state = 'CONFLICT'
    OR (@ac1_status_exists = 0 AND (@ac1_slot_exists > 0 OR @ac1_lifecycle_check_exists > 0 OR @ac1_active_unique_exists > 0))
    OR (@ac1_lifecycle_check_exists > 1)
    OR (@ac1_active_unique_exists > 1);

-- Calendar tables are adoptable only when they carry the Phase 1 ownership
-- marker and satisfy their complete structural contract.
SET @ac1_types_any := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_types'
);
SET @ac1_types_contract := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_types'
      AND table_type = 'BASE TABLE'
      AND engine = 'InnoDB'
      AND table_comment LIKE '%[academic-calendar-phase1]%'
) AND (
    SELECT COUNT(*) = 9 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_types'
      AND column_name IN (
        'academic_calendar_event_type_id', 'event_type_code', 'name_ar', 'name_en',
        'event_type_kind', 'default_is_enforcement', 'is_active', 'created_at', 'updated_at'
      )
) AND (
    SELECT COUNT(*) = 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_types'
      AND column_name = 'academic_calendar_event_type_id'
      AND data_type = 'int' AND column_type NOT LIKE '%unsigned%'
      AND extra LIKE '%auto_increment%'
) AND (
    SELECT COUNT(*) = 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_types'
      AND index_name = 'PRIMARY' AND column_name = 'academic_calendar_event_type_id'
) AND (
    SELECT COUNT(*) = 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_types'
      AND index_name = 'uq_acet_code' AND non_unique = 0
      AND column_name = 'event_type_code'
) AND (
    SELECT COUNT(*) = 2 FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_types'
      AND constraint_type = 'CHECK'
      AND constraint_name IN ('chk_acet_kind', 'chk_acet_flags')
);
SET @ac1_types_state := CASE
    WHEN @ac1_types_any = 0 THEN 'ABSENT'
    WHEN @ac1_types_contract THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @ac1_events_any := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_events'
);
SET @ac1_events_contract := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_events'
      AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
      AND table_comment LIKE '%[academic-calendar-phase1]%'
) AND (
    SELECT COUNT(*) = 9 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_events'
      AND column_name IN (
        'academic_calendar_event_id', 'academic_year_id', 'semester_id',
        'academic_calendar_event_type_id', 'created_by_user_id', 'created_at',
        'cancelled_by_user_id', 'cancelled_at', 'cancellation_reason'
      )
) AND (
    SELECT COUNT(*) = 6 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_events'
      AND column_name IN (
        'academic_calendar_event_id', 'academic_year_id', 'semester_id',
        'academic_calendar_event_type_id', 'created_by_user_id', 'cancelled_by_user_id'
      )
      AND data_type = 'int' AND column_type NOT LIKE '%unsigned%'
) AND (
    SELECT COUNT(*) = 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_events'
      AND index_name = 'PRIMARY' AND column_name = 'academic_calendar_event_id'
) AND (
    SELECT COUNT(*) = 5
    FROM information_schema.key_column_usage k
    JOIN information_schema.referential_constraints r
      ON r.constraint_schema = k.constraint_schema
     AND r.table_name = k.table_name
     AND r.constraint_name = k.constraint_name
    WHERE k.table_schema = 'alrowad_uni_rust'
      AND k.table_name = 'academic_calendar_events'
      AND r.delete_rule IN ('RESTRICT', 'NO ACTION')
      AND (
        (k.column_name = 'academic_year_id' AND k.referenced_table_name = 'academic_years' AND k.referenced_column_name = 'academic_year_id')
        OR (k.column_name = 'semester_id' AND k.referenced_table_name = 'semesters' AND k.referenced_column_name = 'semester_id')
        OR (k.column_name = 'academic_calendar_event_type_id' AND k.referenced_table_name = 'academic_calendar_event_types' AND k.referenced_column_name = 'academic_calendar_event_type_id')
        OR (k.column_name = 'created_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id')
        OR (k.column_name = 'cancelled_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id')
      )
) AND (
    SELECT COUNT(*) = 1 FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_events'
      AND constraint_name = 'chk_ace_cancellation'
      AND constraint_type = 'CHECK'
);
SET @ac1_events_state := CASE
    WHEN @ac1_events_any = 0 THEN 'ABSENT'
    WHEN @ac1_events_contract THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @ac1_versions_any := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
);
SET @ac1_versions_contract := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
      AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
      AND table_comment LIKE '%[academic-calendar-phase1]%'
) AND (
    SELECT COUNT(*) = 17 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
      AND column_name IN (
        'academic_calendar_event_version_id', 'academic_calendar_event_id',
        'version_number', 'replaces_version_id', 'title', 'public_notes',
        'starts_at', 'ends_at', 'is_enforcement', 'change_reason',
        'created_by_user_id', 'created_at', 'publication_status',
        'published_by_user_id', 'published_at', 'superseded_at',
        'published_event_slot'
      )
) AND (
    SELECT COUNT(*) = 6 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
      AND column_name IN (
        'academic_calendar_event_version_id', 'academic_calendar_event_id',
        'version_number', 'replaces_version_id', 'created_by_user_id',
        'published_by_user_id'
      )
      AND data_type = 'int' AND column_type NOT LIKE '%unsigned%'
) AND (
    SELECT COUNT(*) = 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
      AND column_name = 'published_event_slot'
      AND data_type = 'int' AND column_type NOT LIKE '%unsigned%'
      AND is_generated = 'ALWAYS'
      AND (UPPER(extra) LIKE '%PERSISTENT%' OR UPPER(extra) LIKE '%STORED%')
      AND generation_expression LIKE '%publication_status%'
      AND generation_expression LIKE '%academic_calendar_event_id%'
) AND (
    SELECT COUNT(*) = 1
    FROM (
      SELECT index_name
      FROM information_schema.statistics
      WHERE table_schema = 'alrowad_uni_rust'
        AND table_name = 'academic_calendar_event_versions'
        AND index_name = 'uq_acev_event_version' AND non_unique = 0
      GROUP BY index_name
      HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'academic_calendar_event_id,version_number'
    ) ac1_revision_unique
) AND (
    SELECT COUNT(*) = 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
      AND index_name = 'uq_acev_published_event_slot' AND non_unique = 0
      AND column_name = 'published_event_slot'
) AND (
    SELECT COUNT(*) = 4
    FROM information_schema.key_column_usage k
    JOIN information_schema.referential_constraints r
      ON r.constraint_schema = k.constraint_schema
     AND r.table_name = k.table_name
     AND r.constraint_name = k.constraint_name
    WHERE k.table_schema = 'alrowad_uni_rust'
      AND k.table_name = 'academic_calendar_event_versions'
      AND r.delete_rule IN ('RESTRICT', 'NO ACTION')
      AND (
        (k.column_name = 'academic_calendar_event_id' AND k.referenced_table_name = 'academic_calendar_events' AND k.referenced_column_name = 'academic_calendar_event_id')
        OR (k.column_name = 'replaces_version_id' AND k.referenced_table_name = 'academic_calendar_event_versions' AND k.referenced_column_name = 'academic_calendar_event_version_id')
        OR (k.column_name = 'created_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id')
        OR (k.column_name = 'published_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id')
      )
) AND (
    SELECT COUNT(*) = 5 FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
      AND constraint_type = 'CHECK'
      AND constraint_name IN (
        'chk_acev_version_number', 'chk_acev_window', 'chk_acev_enforcement',
        'chk_acev_change_reason', 'chk_acev_publication'
      )
);
SET @ac1_versions_state := CASE
    WHEN @ac1_versions_any = 0 THEN 'ABSENT'
    WHEN @ac1_versions_contract THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @ac1_year_events_any := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_year_lifecycle_events'
);
SET @ac1_year_events_contract := (
    SELECT COUNT(*) = 1 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_year_lifecycle_events'
      AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
      AND table_comment LIKE '%[academic-calendar-phase1]%'
) AND (
    SELECT COUNT(*) = 7 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_year_lifecycle_events'
      AND column_name IN (
        'academic_calendar_year_lifecycle_event_id', 'academic_year_id',
        'from_status', 'to_status', 'actor_user_id', 'reason', 'occurred_at'
      )
) AND (
    SELECT COUNT(*) = 3 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_year_lifecycle_events'
      AND column_name IN (
        'academic_calendar_year_lifecycle_event_id', 'academic_year_id', 'actor_user_id'
      )
      AND data_type = 'int' AND column_type NOT LIKE '%unsigned%'
) AND (
    SELECT COUNT(*) = 1 FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_year_lifecycle_events'
      AND index_name = 'PRIMARY'
      AND column_name = 'academic_calendar_year_lifecycle_event_id'
) AND (
    SELECT COUNT(*) = 2
    FROM information_schema.key_column_usage k
    JOIN information_schema.referential_constraints r
      ON r.constraint_schema = k.constraint_schema
     AND r.table_name = k.table_name
     AND r.constraint_name = k.constraint_name
    WHERE k.table_schema = 'alrowad_uni_rust'
      AND k.table_name = 'academic_calendar_year_lifecycle_events'
      AND r.delete_rule IN ('RESTRICT', 'NO ACTION')
      AND (
        (k.column_name = 'academic_year_id' AND k.referenced_table_name = 'academic_years' AND k.referenced_column_name = 'academic_year_id')
        OR (k.column_name = 'actor_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id')
      )
) AND (
    SELECT COUNT(*) = 3 FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_year_lifecycle_events'
      AND constraint_type = 'CHECK'
      AND constraint_name IN ('chk_acyle_from_status', 'chk_acyle_to_status', 'chk_acyle_reason')
);
SET @ac1_year_events_state := CASE
    WHEN @ac1_year_events_any = 0 THEN 'ABSENT'
    WHEN @ac1_year_events_contract THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @ac1_unexpected_calendar_objects := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name LIKE 'academic#_calendar#_%' ESCAPE '#'
      AND table_name NOT IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
);

SET @ac1_objects_sane := @ac1_types_state <> 'CONFLICT'
    AND @ac1_events_state <> 'CONFLICT'
    AND @ac1_versions_state <> 'CONFLICT'
    AND @ac1_year_events_state <> 'CONFLICT'
    AND @ac1_unexpected_calendar_objects = 0
    AND NOT @ac1_extension_conflict;

-- Query lifecycle values only when the additive column is absent or has a
-- compatible Phase 1 shape. A conflicting column is blocked by metadata and
-- is never referenced as if it had the expected type.
SET @ac1_lifecycle_checks_sql := IF(
    @ac1_status_exists = 1 AND @ac1_status_state <> 'CONFLICT',
    'SELECT
       COALESCE(SUM(calendar_lifecycle_status IS NOT NULL
         AND calendar_lifecycle_status NOT IN (''draft'', ''active'', ''closed'')), 0) AS bad_lifecycle_values,
       COALESCE(SUM(calendar_lifecycle_status = ''active''), 0) AS lifecycle_active_count,
       COALESCE(SUM(is_current = 1
         AND calendar_lifecycle_status IS NOT NULL
         AND calendar_lifecycle_status <> ''active''), 0) AS current_lifecycle_mismatch,
       COALESCE(SUM(is_current = 0 AND calendar_lifecycle_status = ''active''), 0) AS noncurrent_lifecycle_active
     FROM `alrowad_uni_rust`.`academic_years`',
    'SELECT 0 AS bad_lifecycle_values,
            0 AS lifecycle_active_count,
            0 AS current_lifecycle_mismatch,
            0 AS noncurrent_lifecycle_active'
);

-- The dynamic statement is only a missing-table guard. Its final execution
-- returns one compact result set, ordered so OVERALL is always the final row.
SET @ac1_report_sql := IF(
    @ac1_core_ready,
    CONCAT(
      'WITH
       year_checks AS (
         SELECT COUNT(*) AS academic_year_count,
                COALESCE(SUM(is_current = 1), 0) AS current_year_count,
                COALESCE(SUM(is_current = 1 AND is_active = 1), 0) AS current_and_active_count,
                COALESCE(SUM(start_date > end_date), 0) AS invalid_year_date_count
         FROM `alrowad_uni_rust`.`academic_years`
       ),
       semester_checks AS (
         SELECT COUNT(*) AS semester_count,
                COALESCE(SUM(semester_code IN (''first'', ''second'', ''summer'')), 0) AS required_semester_code_count
         FROM `alrowad_uni_rust`.`semesters`
       ),
       offering_checks AS (
         SELECT COUNT(*) AS course_offering_count,
                COALESCE(SUM(ay.academic_year_id IS NULL), 0) AS orphan_offering_year_count,
                COALESCE(SUM(s.semester_id IS NULL), 0) AS orphan_offering_semester_count
         FROM `alrowad_uni_rust`.`course_offerings` co
         LEFT JOIN `alrowad_uni_rust`.`academic_years` ay
           ON ay.academic_year_id = co.academic_year_id
         LEFT JOIN `alrowad_uni_rust`.`semesters` s
           ON s.semester_id = co.semester_id
       ),
       lifecycle_checks AS (',
       @ac1_lifecycle_checks_sql,
      '),
       readiness AS (
         SELECT
           (y.academic_year_count > 0
             AND y.current_year_count = 1
             AND y.current_and_active_count = 1
             AND y.invalid_year_date_count = 0) AS current_year_sane,
           (s.semester_count > 0
             AND s.required_semester_code_count = 3
             AND o.orphan_offering_year_count = 0
             AND o.orphan_offering_semester_count = 0) AS links_sane,
           (l.bad_lifecycle_values = 0
             AND l.current_lifecycle_mismatch = 0
             AND l.noncurrent_lifecycle_active = 0
             AND l.lifecycle_active_count <= 1) AS lifecycle_data_sane,
           y.academic_year_count,
           y.current_year_count,
           y.current_and_active_count,
           y.invalid_year_date_count,
           s.semester_count,
           s.required_semester_code_count,
           o.course_offering_count,
           o.orphan_offering_year_count,
           o.orphan_offering_semester_count
         FROM year_checks y
         CROSS JOIN semester_checks s
         CROSS JOIN offering_checks o
         CROSS JOIN lifecycle_checks l
       )
       SELECT report_section, result, details
       FROM (
         SELECT 1 AS report_order,
                ''DATABASE_AND_CORE'' AS report_section,
                ''PASS'' AS result,
                CONCAT(''server_version='', @@version,
                       ''; academic_years='', academic_year_count,
                       ''; semesters='', semester_count,
                       ''; course_offerings='', course_offering_count) AS details
         FROM readiness
         UNION ALL
         SELECT 2,
                ''CURRENT_YEAR_AND_LINKS'',
                IF(current_year_sane AND links_sane, ''PASS'', ''FAIL''),
                CONCAT(''current='', current_year_count,
                       ''; current_and_active='', current_and_active_count,
                       ''; invalid_year_dates='', invalid_year_date_count,
                       ''; required_semester_codes='', required_semester_code_count,
                       ''; orphan_year_links='', orphan_offering_year_count,
                       ''; orphan_semester_links='', orphan_offering_semester_count)
         FROM readiness
         UNION ALL
         SELECT 3,
                ''STRUCTURE_COMPATIBILITY'',
                IF(NOT @ac1_extension_conflict AND lifecycle_data_sane, ''PASS'', ''FAIL''),
                CONCAT(''lifecycle_status='', @ac1_status_state,
                       ''; active_slot='', @ac1_slot_state,
                       ''; lifecycle_check_count='', @ac1_lifecycle_check_exists,
                       ''; active_unique_count='', @ac1_active_unique_exists)
         FROM readiness
         UNION ALL
         SELECT 4,
                ''PHASE1_OBJECTS'',
                IF(@ac1_objects_sane, ''PASS'', ''FAIL''),
                CONCAT(''event_types='', @ac1_types_state,
                       ''; events='', @ac1_events_state,
                       ''; versions='', @ac1_versions_state,
                       ''; lifecycle_events='', @ac1_year_events_state,
                       ''; unexpected_objects='', @ac1_unexpected_calendar_objects)
         FROM readiness
         UNION ALL
         SELECT 5,
                ''OVERALL'',
                IF(current_year_sane
                   AND links_sane
                   AND lifecycle_data_sane
                   AND @ac1_objects_sane,
                   ''READY'', ''BLOCKED''),
                ''Continue to 01_apply.sql only when this row is READY.''
         FROM readiness
       ) report
       ORDER BY report_order'
    ),
    'SELECT report_section, result, details
     FROM (
       SELECT 1 AS report_order,
              ''DATABASE_AND_CORE'' AS report_section,
              ''FAIL'' AS result,
              CONCAT(''database_exists='', @ac1_db_ready,
                     ''; required_core_tables='', @ac1_required_tables,
                     ''; required_core_columns='', @ac1_required_columns,
                     ''; signed_integer_keys='', @ac1_signed_integer_keys,
                     ''; primary_keys='', @ac1_required_primary_keys,
                     ''; offering_foreign_keys='', @ac1_offering_foreign_keys) AS details
       UNION ALL
       SELECT 2, ''CURRENT_YEAR_AND_LINKS'', ''FAIL'',
              ''Not evaluated because core prerequisites are missing or incompatible.''
       UNION ALL
       SELECT 3, ''STRUCTURE_COMPATIBILITY'',
              IF(@ac1_extension_conflict, ''FAIL'', ''PASS''),
              CONCAT(''lifecycle_status='', @ac1_status_state,
                     ''; active_slot='', @ac1_slot_state)
       UNION ALL
       SELECT 4, ''PHASE1_OBJECTS'',
              IF(@ac1_objects_sane, ''PASS'', ''FAIL''),
              CONCAT(''event_types='', @ac1_types_state,
                     ''; events='', @ac1_events_state,
                     ''; versions='', @ac1_versions_state,
                     ''; lifecycle_events='', @ac1_year_events_state,
                     ''; unexpected_objects='', @ac1_unexpected_calendar_objects)
       UNION ALL
       SELECT 5, ''OVERALL'', ''BLOCKED'',
              ''Core prerequisites are missing or incompatible; do not run 01_apply.sql.''
     ) report
     ORDER BY report_order'
);

EXECUTE IMMEDIATE @ac1_report_sql;
