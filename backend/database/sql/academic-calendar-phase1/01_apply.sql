-- Academic Calendar Phase 1 foundation.
-- Run only after 00_preflight.sql returns OVERALL = READY.
-- Guarded and rerunnable; every application object is fully qualified.

SET @ac1_owner := '[academic-calendar-phase1]';

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
SET @ac1_signed_keys := (
    SELECT COUNT(*) = 7 FROM information_schema.columns
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
SET @ac1_core_foreign_keys := (
    SELECT COUNT(*) = 2 FROM information_schema.key_column_usage
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'course_offerings'
      AND (
        (column_name = 'academic_year_id' AND referenced_table_name = 'academic_years' AND referenced_column_name = 'academic_year_id')
        OR (column_name = 'semester_id' AND referenced_table_name = 'semesters' AND referenced_column_name = 'semester_id')
      )
);
SET @ac1_core_ready := @ac1_db_ready AND @ac1_core_tables AND @ac1_core_columns
    AND @ac1_signed_keys AND @ac1_core_foreign_keys;

SET @ac1_sql := IF(
    @ac1_core_ready,
    'SELECT
        COALESCE(SUM(is_current = 1), 0),
        COALESCE(SUM(is_current = 1 AND is_active = 1), 0),
        COALESCE(SUM(start_date > end_date), 0)
     INTO @ac1_current_count, @ac1_current_active_count, @ac1_bad_year_dates
     FROM `alrowad_uni_rust`.`academic_years`',
    'SELECT 0, 0, 1 INTO @ac1_current_count, @ac1_current_active_count, @ac1_bad_year_dates'
);
PREPARE ac1_apply_year_guard FROM @ac1_sql;
EXECUTE ac1_apply_year_guard;
DEALLOCATE PREPARE ac1_apply_year_guard;

SET @ac1_sql := IF(
    @ac1_core_ready,
    'SELECT
        COALESCE(SUM(ay.academic_year_id IS NULL), 0),
        COALESCE(SUM(s.semester_id IS NULL), 0)
     INTO @ac1_orphan_years, @ac1_orphan_semesters
     FROM `alrowad_uni_rust`.`course_offerings` co
     LEFT JOIN `alrowad_uni_rust`.`academic_years` ay
       ON ay.academic_year_id = co.academic_year_id
     LEFT JOIN `alrowad_uni_rust`.`semesters` s
       ON s.semester_id = co.semester_id',
    'SELECT 1, 1 INTO @ac1_orphan_years, @ac1_orphan_semesters'
);
PREPARE ac1_apply_link_guard FROM @ac1_sql;
EXECUTE ac1_apply_link_guard;
DEALLOCATE PREPARE ac1_apply_link_guard;

SET @ac1_sql := IF(
    @ac1_core_ready,
    'SELECT COALESCE(SUM(semester_code IN (''first'', ''second'', ''summer'')), 0) = 3
     INTO @ac1_core_semesters_ready
     FROM `alrowad_uni_rust`.`semesters`',
    'SELECT 0 INTO @ac1_core_semesters_ready'
);
PREPARE ac1_apply_semester_guard FROM @ac1_sql;
EXECUTE ac1_apply_semester_guard;
DEALLOCATE PREPARE ac1_apply_semester_guard;

SET @ac1_status_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_lifecycle_status'
);
SET @ac1_status_owned_compatible := (
    SELECT COUNT(*) = 1 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_lifecycle_status'
      AND data_type = 'varchar'
      AND character_maximum_length >= 16
      AND is_nullable IN ('YES', 'NO')
      AND column_comment LIKE '%[academic-calendar-phase1]%'
);
SET @ac1_slot_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_active_slot'
);
SET @ac1_slot_owned_compatible := (
    SELECT COUNT(*) = 1 FROM information_schema.columns
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
SET @ac1_check_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND constraint_name = 'chk_ay_calendar_lifecycle_status'
      AND constraint_type = 'CHECK'
);
SET @ac1_unique_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND index_name = 'uq_ay_calendar_active_slot'
      AND non_unique = 0
      AND seq_in_index = 1
      AND column_name = 'calendar_active_slot'
);
SET @ac1_extension_safe := (@ac1_status_exists = 0 OR @ac1_status_owned_compatible)
    AND (@ac1_slot_exists = 0 OR @ac1_slot_owned_compatible)
    AND @ac1_check_exists <= 1
    AND @ac1_unique_exists <= 1
    AND NOT (@ac1_status_exists = 0 AND (@ac1_slot_exists > 0 OR @ac1_check_exists > 0 OR @ac1_unique_exists > 0));

SET @ac1_sql := IF(
    @ac1_core_ready AND @ac1_status_exists = 1,
    'SELECT
       COALESCE(SUM(calendar_lifecycle_status IS NOT NULL
         AND calendar_lifecycle_status NOT IN (''draft'', ''active'', ''closed'')), 0) = 0,
       COALESCE(SUM(calendar_lifecycle_status = ''active''), 0) <= 1,
       COALESCE(SUM(is_current = 1
         AND calendar_lifecycle_status IS NOT NULL
         AND calendar_lifecycle_status <> ''active''), 0) = 0,
       COALESCE(SUM(is_current = 0 AND calendar_lifecycle_status = ''active''), 0) = 0
     INTO @ac1_existing_status_values_ok, @ac1_existing_active_count_ok,
          @ac1_existing_current_match_ok, @ac1_existing_noncurrent_ok
     FROM `alrowad_uni_rust`.`academic_years`',
    'SELECT 1, 1, 1, 1 INTO @ac1_existing_status_values_ok, @ac1_existing_active_count_ok,
          @ac1_existing_current_match_ok, @ac1_existing_noncurrent_ok'
);
PREPARE ac1_existing_status_guard FROM @ac1_sql;
EXECUTE ac1_existing_status_guard;
DEALLOCATE PREPARE ac1_existing_status_guard;
SET @ac1_existing_status_data_safe := @ac1_existing_status_values_ok
    AND @ac1_existing_active_count_ok
    AND @ac1_existing_current_match_ok
    AND @ac1_existing_noncurrent_ok;

-- Existing Phase 1 tables must be absent or provably owned and structurally
-- compatible. Unknown same-named objects are never rewritten.
SET @ac1_types_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_types'
);
SET @ac1_types_safe := @ac1_types_exists = 0 OR (
    (SELECT COUNT(*) = 1 FROM information_schema.tables
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_event_types'
       AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
       AND table_comment LIKE '%[academic-calendar-phase1]%')
    AND
    (SELECT COUNT(*) = 9 FROM information_schema.columns
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_event_types'
       AND column_name IN (
         'academic_calendar_event_type_id', 'event_type_code', 'name_ar', 'name_en',
         'event_type_kind', 'default_is_enforcement', 'is_active', 'created_at', 'updated_at'
       ))
    AND
    (SELECT COUNT(*) = 1 FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_event_types'
       AND index_name = 'uq_acet_code' AND non_unique = 0
       AND column_name = 'event_type_code')
);

SET @ac1_events_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_events'
);
SET @ac1_events_safe := @ac1_events_exists = 0 OR (
    (SELECT COUNT(*) = 1 FROM information_schema.tables
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_events'
       AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
       AND table_comment LIKE '%[academic-calendar-phase1]%')
    AND
    (SELECT COUNT(*) = 9 FROM information_schema.columns
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_events'
       AND column_name IN (
         'academic_calendar_event_id', 'academic_year_id', 'semester_id',
         'academic_calendar_event_type_id', 'created_by_user_id', 'created_at',
         'cancelled_by_user_id', 'cancelled_at', 'cancellation_reason'
       ))
    AND
    (SELECT COUNT(*) = 5 FROM information_schema.key_column_usage
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_events'
       AND referenced_table_name IS NOT NULL)
);

SET @ac1_versions_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_event_versions'
);
SET @ac1_versions_safe := @ac1_versions_exists = 0 OR (
    (SELECT COUNT(*) = 1 FROM information_schema.tables
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_event_versions'
       AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
       AND table_comment LIKE '%[academic-calendar-phase1]%')
    AND
    (SELECT COUNT(*) = 17 FROM information_schema.columns
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_event_versions'
       AND column_name IN (
         'academic_calendar_event_version_id', 'academic_calendar_event_id',
         'version_number', 'replaces_version_id', 'title', 'public_notes',
         'starts_at', 'ends_at', 'is_enforcement', 'change_reason',
         'created_by_user_id', 'created_at', 'publication_status',
         'published_by_user_id', 'published_at', 'superseded_at',
         'published_event_slot'
       ))
    AND
    (SELECT COUNT(*) = 1 FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_event_versions'
       AND index_name = 'uq_acev_published_event_slot'
       AND non_unique = 0 AND column_name = 'published_event_slot')
);

SET @ac1_year_events_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_calendar_year_lifecycle_events'
);
SET @ac1_year_events_safe := @ac1_year_events_exists = 0 OR (
    (SELECT COUNT(*) = 1 FROM information_schema.tables
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_year_lifecycle_events'
       AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
       AND table_comment LIKE '%[academic-calendar-phase1]%')
    AND
    (SELECT COUNT(*) = 7 FROM information_schema.columns
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_year_lifecycle_events'
       AND column_name IN (
         'academic_calendar_year_lifecycle_event_id', 'academic_year_id',
         'from_status', 'to_status', 'actor_user_id', 'reason', 'occurred_at'
       ))
    AND
    (SELECT COUNT(*) = 2 FROM information_schema.key_column_usage
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'academic_calendar_year_lifecycle_events'
       AND referenced_table_name IS NOT NULL)
);
SET @ac1_unexpected_calendar_objects := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name LIKE 'academic#_calendar#_%' ESCAPE '#'
      AND table_name NOT IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
);

SET @ac1_apply_ready := @ac1_core_ready
    AND @ac1_current_count = 1
    AND @ac1_current_active_count = 1
    AND @ac1_bad_year_dates = 0
    AND @ac1_orphan_years = 0
    AND @ac1_orphan_semesters = 0
    AND @ac1_core_semesters_ready
    AND @ac1_extension_safe
    AND @ac1_existing_status_data_safe
    AND @ac1_types_safe
    AND @ac1_events_safe
    AND @ac1_versions_safe
    AND @ac1_year_events_safe
    AND @ac1_unexpected_calendar_objects = 0;

SET @ac1_sql := IF(
    @ac1_types_exists = 1 AND @ac1_types_safe,
    'SELECT COUNT(*) = 13 INTO @ac1_initial_seed_ready
     FROM `alrowad_uni_rust`.`academic_calendar_event_types`
     WHERE event_type_code IN (
       ''admission_registration'', ''course_registration'', ''withdrawal'',
       ''study_period'', ''exam_preparation'', ''practical_exams'',
       ''theoretical_exams'', ''grade_appeals'', ''supplementary_exams'',
       ''university_break'', ''preparation_period'', ''holiday'', ''general_event''
     )',
    'SELECT 0 INTO @ac1_initial_seed_ready'
);
PREPARE ac1_initial_seed_state FROM @ac1_sql;
EXECUTE ac1_initial_seed_state;
DEALLOCATE PREPARE ac1_initial_seed_state;

SET @ac1_initial_complete := @ac1_apply_ready
    AND @ac1_status_owned_compatible
    AND (SELECT is_nullable = 'NO' FROM information_schema.columns
         WHERE table_schema = 'alrowad_uni_rust'
           AND table_name = 'academic_years'
           AND column_name = 'calendar_lifecycle_status')
    AND @ac1_slot_owned_compatible
    AND @ac1_check_exists = 1
    AND @ac1_unique_exists = 1
    AND @ac1_types_exists = 1
    AND @ac1_events_exists = 1
    AND @ac1_versions_exists = 1
    AND @ac1_year_events_exists = 1
    AND @ac1_initial_seed_ready;

SET @ac1_sql := IF(
    @ac1_apply_ready AND @ac1_status_exists = 0,
    'ALTER TABLE `alrowad_uni_rust`.`academic_years`
       ADD COLUMN `calendar_lifecycle_status` VARCHAR(16) NULL DEFAULT NULL
       COMMENT ''[academic-calendar-phase1] draft|active|closed'' AFTER `is_active`',
    'SELECT ''SKIPPED_ADD_LIFECYCLE_STATUS'' AS apply_step'
);
PREPARE ac1_add_status FROM @ac1_sql;
EXECUTE ac1_add_status;
DEALLOCATE PREPARE ac1_add_status;

-- Fill only NULL Phase 1 values. Explicit updated_at self-assignment prevents
-- the existing ON UPDATE timestamp from changing during this backfill.
SET @ac1_sql := IF(
    @ac1_apply_ready,
    'UPDATE `alrowad_uni_rust`.`academic_years` ay
     JOIN (
       SELECT start_date AS current_start_date
       FROM `alrowad_uni_rust`.`academic_years`
       WHERE is_current = 1
       LIMIT 1
     ) current_year
     SET ay.calendar_lifecycle_status = CASE
           WHEN ay.is_current = 1 THEN ''active''
           WHEN ay.end_date < current_year.current_start_date THEN ''closed''
           ELSE ''draft''
         END,
         ay.updated_at = ay.updated_at
     WHERE ay.calendar_lifecycle_status IS NULL',
    'SELECT ''SKIPPED_LIFECYCLE_BACKFILL'' AS apply_step'
);
PREPARE ac1_backfill_status FROM @ac1_sql;
EXECUTE ac1_backfill_status;
DEALLOCATE PREPARE ac1_backfill_status;

SET @ac1_status_nullable := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_lifecycle_status'
      AND is_nullable = 'YES'
      AND column_comment LIKE '%[academic-calendar-phase1]%'
);
SET @ac1_sql := IF(
    @ac1_apply_ready AND @ac1_status_nullable = 1,
    'ALTER TABLE `alrowad_uni_rust`.`academic_years`
       MODIFY COLUMN `calendar_lifecycle_status` VARCHAR(16) NOT NULL DEFAULT ''draft''
       COMMENT ''[academic-calendar-phase1] draft|active|closed''',
    'SELECT ''SKIPPED_FINALIZE_LIFECYCLE_STATUS'' AS apply_step'
);
PREPARE ac1_finalize_status FROM @ac1_sql;
EXECUTE ac1_finalize_status;
DEALLOCATE PREPARE ac1_finalize_status;

SET @ac1_check_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND constraint_name = 'chk_ay_calendar_lifecycle_status'
      AND constraint_type = 'CHECK'
);
SET @ac1_sql := IF(
    @ac1_apply_ready AND @ac1_check_exists = 0,
    'ALTER TABLE `alrowad_uni_rust`.`academic_years`
       ADD CONSTRAINT `chk_ay_calendar_lifecycle_status`
       CHECK (`calendar_lifecycle_status` IN (''draft'', ''active'', ''closed''))',
    'SELECT ''SKIPPED_ADD_LIFECYCLE_CHECK'' AS apply_step'
);
PREPARE ac1_add_status_check FROM @ac1_sql;
EXECUTE ac1_add_status_check;
DEALLOCATE PREPARE ac1_add_status_check;

SET @ac1_slot_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name = 'calendar_active_slot'
);
SET @ac1_sql := IF(
    @ac1_apply_ready AND @ac1_slot_exists = 0,
    'ALTER TABLE `alrowad_uni_rust`.`academic_years`
       ADD COLUMN `calendar_active_slot` TINYINT
       GENERATED ALWAYS AS (
         CASE WHEN `calendar_lifecycle_status` = ''active'' THEN 1 ELSE NULL END
       ) STORED COMMENT ''[academic-calendar-phase1] unique nullable active slot''
       AFTER `calendar_lifecycle_status`',
    'SELECT ''SKIPPED_ADD_ACTIVE_SLOT'' AS apply_step'
);
PREPARE ac1_add_active_slot FROM @ac1_sql;
EXECUTE ac1_add_active_slot;
DEALLOCATE PREPARE ac1_add_active_slot;

SET @ac1_unique_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND index_name = 'uq_ay_calendar_active_slot'
      AND non_unique = 0
      AND column_name = 'calendar_active_slot'
);
SET @ac1_sql := IF(
    @ac1_apply_ready AND @ac1_unique_exists = 0,
    'ALTER TABLE `alrowad_uni_rust`.`academic_years`
       ADD UNIQUE KEY `uq_ay_calendar_active_slot` (`calendar_active_slot`)',
    'SELECT ''SKIPPED_ADD_ACTIVE_UNIQUE'' AS apply_step'
);
PREPARE ac1_add_active_unique FROM @ac1_sql;
EXECUTE ac1_add_active_unique;
DEALLOCATE PREPARE ac1_add_active_unique;

SET @ac1_sql := IF(
    @ac1_apply_ready AND @ac1_types_exists = 0,
    'CREATE TABLE `alrowad_uni_rust`.`academic_calendar_event_types` (
       `academic_calendar_event_type_id` INT NOT NULL AUTO_INCREMENT,
       `event_type_code` VARCHAR(64) NOT NULL,
       `name_ar` VARCHAR(150) NOT NULL,
       `name_en` VARCHAR(150) NOT NULL,
       `event_type_kind` VARCHAR(16) NOT NULL,
       `default_is_enforcement` TINYINT(1) NOT NULL DEFAULT 0,
       `is_active` TINYINT(1) NOT NULL DEFAULT 1,
       `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (`academic_calendar_event_type_id`),
       UNIQUE KEY `uq_acet_code` (`event_type_code`),
       KEY `idx_acet_kind_active` (`event_type_kind`, `is_active`),
       CONSTRAINT `chk_acet_kind` CHECK (`event_type_kind` IN (''system'', ''general'')),
       CONSTRAINT `chk_acet_flags` CHECK (`default_is_enforcement` IN (0,1) AND `is_active` IN (0,1))
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
       COMMENT=''[academic-calendar-phase1] stable event vocabulary''',
    'SELECT ''SKIPPED_CREATE_EVENT_TYPES'' AS apply_step'
);
PREPARE ac1_create_types FROM @ac1_sql;
EXECUTE ac1_create_types;
DEALLOCATE PREPARE ac1_create_types;

SET @ac1_sql := IF(
    @ac1_apply_ready AND @ac1_events_exists = 0,
    'CREATE TABLE `alrowad_uni_rust`.`academic_calendar_events` (
       `academic_calendar_event_id` INT NOT NULL AUTO_INCREMENT,
       `academic_year_id` INT NOT NULL,
       `semester_id` INT DEFAULT NULL,
       `academic_calendar_event_type_id` INT NOT NULL,
       `created_by_user_id` INT NOT NULL,
       `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       `cancelled_by_user_id` INT DEFAULT NULL,
       `cancelled_at` DATETIME DEFAULT NULL,
       `cancellation_reason` TEXT DEFAULT NULL,
       PRIMARY KEY (`academic_calendar_event_id`),
       KEY `idx_ace_year_semester` (`academic_year_id`, `semester_id`),
       KEY `idx_ace_event_type` (`academic_calendar_event_type_id`),
       KEY `idx_ace_cancelled_at` (`cancelled_at`),
       KEY `idx_ace_created_by` (`created_by_user_id`),
       KEY `idx_ace_cancelled_by` (`cancelled_by_user_id`),
       CONSTRAINT `fk_ace_year` FOREIGN KEY (`academic_year_id`)
         REFERENCES `alrowad_uni_rust`.`academic_years` (`academic_year_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
       CONSTRAINT `fk_ace_semester` FOREIGN KEY (`semester_id`)
         REFERENCES `alrowad_uni_rust`.`semesters` (`semester_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
       CONSTRAINT `fk_ace_event_type` FOREIGN KEY (`academic_calendar_event_type_id`)
         REFERENCES `alrowad_uni_rust`.`academic_calendar_event_types` (`academic_calendar_event_type_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
       CONSTRAINT `fk_ace_created_by` FOREIGN KEY (`created_by_user_id`)
         REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
       CONSTRAINT `fk_ace_cancelled_by` FOREIGN KEY (`cancelled_by_user_id`)
         REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
       CONSTRAINT `chk_ace_cancellation` CHECK (
         (`cancelled_by_user_id` IS NULL AND `cancelled_at` IS NULL AND `cancellation_reason` IS NULL)
         OR
         (`cancelled_by_user_id` IS NOT NULL AND `cancelled_at` IS NOT NULL
          AND NULLIF(TRIM(`cancellation_reason`), '''') IS NOT NULL)
       )
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
       COMMENT=''[academic-calendar-phase1] logical university calendar events''',
    'SELECT ''SKIPPED_CREATE_EVENTS'' AS apply_step'
);
PREPARE ac1_create_events FROM @ac1_sql;
EXECUTE ac1_create_events;
DEALLOCATE PREPARE ac1_create_events;

SET @ac1_sql := IF(
    @ac1_apply_ready AND @ac1_versions_exists = 0,
    'CREATE TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` (
       `academic_calendar_event_version_id` INT NOT NULL AUTO_INCREMENT,
       `academic_calendar_event_id` INT NOT NULL,
       `version_number` INT NOT NULL,
       `replaces_version_id` INT DEFAULT NULL,
       `title` VARCHAR(255) NOT NULL,
       `public_notes` TEXT DEFAULT NULL,
       `starts_at` DATETIME NOT NULL,
       `ends_at` DATETIME NOT NULL,
       `is_enforcement` TINYINT(1) NOT NULL,
       `change_reason` TEXT NOT NULL,
       `created_by_user_id` INT NOT NULL,
       `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       `publication_status` VARCHAR(16) NOT NULL DEFAULT ''draft'',
       `published_by_user_id` INT DEFAULT NULL,
       `published_at` DATETIME DEFAULT NULL,
       `superseded_at` DATETIME DEFAULT NULL,
       `published_event_slot` INT GENERATED ALWAYS AS (
         CASE WHEN `publication_status` = ''published'' THEN `academic_calendar_event_id` ELSE NULL END
       ) STORED,
       PRIMARY KEY (`academic_calendar_event_version_id`),
       UNIQUE KEY `uq_acev_event_version` (`academic_calendar_event_id`, `version_number`),
       UNIQUE KEY `uq_acev_published_event_slot` (`published_event_slot`),
       KEY `idx_acev_event_status` (`academic_calendar_event_id`, `publication_status`),
       KEY `idx_acev_publication_window` (`publication_status`, `starts_at`, `ends_at`),
       KEY `idx_acev_replaces` (`replaces_version_id`),
       KEY `idx_acev_created_by` (`created_by_user_id`),
       KEY `idx_acev_published_by` (`published_by_user_id`),
       CONSTRAINT `fk_acev_event` FOREIGN KEY (`academic_calendar_event_id`)
         REFERENCES `alrowad_uni_rust`.`academic_calendar_events` (`academic_calendar_event_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
       CONSTRAINT `fk_acev_replaces` FOREIGN KEY (`replaces_version_id`)
         REFERENCES `alrowad_uni_rust`.`academic_calendar_event_versions` (`academic_calendar_event_version_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
       CONSTRAINT `fk_acev_created_by` FOREIGN KEY (`created_by_user_id`)
         REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
       CONSTRAINT `fk_acev_published_by` FOREIGN KEY (`published_by_user_id`)
         REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
       CONSTRAINT `chk_acev_version_number` CHECK (`version_number` >= 1),
       CONSTRAINT `chk_acev_window` CHECK (`ends_at` >= `starts_at`),
       CONSTRAINT `chk_acev_enforcement` CHECK (`is_enforcement` IN (0,1)),
       CONSTRAINT `chk_acev_change_reason` CHECK (NULLIF(TRIM(`change_reason`), '''') IS NOT NULL),
       CONSTRAINT `chk_acev_publication` CHECK (
         (`publication_status` = ''draft'' AND `published_by_user_id` IS NULL AND `published_at` IS NULL AND `superseded_at` IS NULL)
         OR
         (`publication_status` = ''published'' AND `published_by_user_id` IS NOT NULL AND `published_at` IS NOT NULL AND `superseded_at` IS NULL)
         OR
         (`publication_status` = ''superseded'' AND `published_by_user_id` IS NOT NULL AND `published_at` IS NOT NULL AND `superseded_at` IS NOT NULL)
       )
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
       COMMENT=''[academic-calendar-phase1] immutable event content revisions''',
    'SELECT ''SKIPPED_CREATE_EVENT_VERSIONS'' AS apply_step'
);
PREPARE ac1_create_versions FROM @ac1_sql;
EXECUTE ac1_create_versions;
DEALLOCATE PREPARE ac1_create_versions;

SET @ac1_sql := IF(
    @ac1_apply_ready AND @ac1_year_events_exists = 0,
    'CREATE TABLE `alrowad_uni_rust`.`academic_calendar_year_lifecycle_events` (
       `academic_calendar_year_lifecycle_event_id` INT NOT NULL AUTO_INCREMENT,
       `academic_year_id` INT NOT NULL,
       `from_status` VARCHAR(16) DEFAULT NULL,
       `to_status` VARCHAR(16) NOT NULL,
       `actor_user_id` INT NOT NULL,
       `reason` TEXT NOT NULL,
       `occurred_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (`academic_calendar_year_lifecycle_event_id`),
       KEY `idx_acyle_year_occurred` (`academic_year_id`, `occurred_at`),
       KEY `idx_acyle_status_occurred` (`to_status`, `occurred_at`),
       KEY `idx_acyle_actor` (`actor_user_id`),
       CONSTRAINT `fk_acyle_year` FOREIGN KEY (`academic_year_id`)
         REFERENCES `alrowad_uni_rust`.`academic_years` (`academic_year_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
       CONSTRAINT `fk_acyle_actor` FOREIGN KEY (`actor_user_id`)
         REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
       CONSTRAINT `chk_acyle_from_status` CHECK (`from_status` IS NULL OR `from_status` IN (''draft'', ''active'', ''closed'')),
       CONSTRAINT `chk_acyle_to_status` CHECK (`to_status` IN (''draft'', ''active'', ''closed'') AND (`from_status` IS NULL OR `from_status` <> `to_status`)),
       CONSTRAINT `chk_acyle_reason` CHECK (NULLIF(TRIM(`reason`), '''') IS NOT NULL)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
       COMMENT=''[academic-calendar-phase1] append-only academic year lifecycle history''',
    'SELECT ''SKIPPED_CREATE_YEAR_LIFECYCLE_EVENTS'' AS apply_step'
);
PREPARE ac1_create_year_events FROM @ac1_sql;
EXECUTE ac1_create_year_events;
DEALLOCATE PREPARE ac1_create_year_events;

START TRANSACTION;
SET @ac1_sql := IF(
    @ac1_apply_ready,
    'INSERT INTO `alrowad_uni_rust`.`academic_calendar_event_types`
       (`event_type_code`, `name_ar`, `name_en`, `event_type_kind`, `default_is_enforcement`, `is_active`)
     VALUES
       (''admission_registration'', ''القبول والتسجيل'', ''Admission and registration'', ''system'', 1, 1),
       (''course_registration'', ''تسجيل المقررات'', ''Course registration'', ''system'', 1, 1),
       (''withdrawal'', ''الانسحاب'', ''Withdrawal'', ''system'', 1, 1),
       (''study_period'', ''فترة الدراسة'', ''Study period'', ''system'', 0, 1),
       (''exam_preparation'', ''التحضير للامتحانات'', ''Exam preparation'', ''system'', 0, 1),
       (''practical_exams'', ''الامتحانات العملية'', ''Practical examinations'', ''system'', 1, 1),
       (''theoretical_exams'', ''الامتحانات النظرية'', ''Theoretical examinations'', ''system'', 1, 1),
       (''grade_appeals'', ''الاعتراض على الدرجات'', ''Grade appeals'', ''system'', 1, 1),
       (''supplementary_exams'', ''الامتحانات التكميلية'', ''Supplementary examinations'', ''system'', 1, 1),
       (''university_break'', ''عطلة جامعية'', ''University break'', ''general'', 0, 1),
       (''preparation_period'', ''فترة تحضيرية'', ''Preparation period'', ''general'', 0, 1),
       (''holiday'', ''عطلة رسمية'', ''Holiday'', ''general'', 0, 1),
       (''general_event'', ''حدث أكاديمي عام'', ''General academic event'', ''general'', 0, 1)
     ON DUPLICATE KEY UPDATE
       `name_ar` = VALUES(`name_ar`),
       `name_en` = VALUES(`name_en`),
       `event_type_kind` = VALUES(`event_type_kind`),
       `default_is_enforcement` = VALUES(`default_is_enforcement`),
       `is_active` = VALUES(`is_active`)',
    'SELECT ''SKIPPED_EVENT_TYPE_SEED'' AS apply_step'
);
PREPARE ac1_seed_types FROM @ac1_sql;
EXECUTE ac1_seed_types;
DEALLOCATE PREPARE ac1_seed_types;
SET @ac1_sql := IF(@ac1_apply_ready, 'COMMIT', 'ROLLBACK');
PREPARE ac1_finish_seed FROM @ac1_sql;
EXECUTE ac1_finish_seed;
DEALLOCATE PREPARE ac1_finish_seed;

SET @ac1_post_tables := (
    SELECT COUNT(*) = 4 FROM information_schema.tables
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_type = 'BASE TABLE'
      AND table_comment LIKE '%[academic-calendar-phase1]%'
      AND table_name IN (
        'academic_calendar_event_types', 'academic_calendar_events',
        'academic_calendar_event_versions', 'academic_calendar_year_lifecycle_events'
      )
);
SET @ac1_post_columns := (
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
          'published_by_user_id', 'published_at', 'superseded_at', 'published_event_slot'
        ))
        OR (table_name = 'academic_calendar_year_lifecycle_events' AND column_name IN (
          'academic_calendar_year_lifecycle_event_id', 'academic_year_id',
          'from_status', 'to_status', 'actor_user_id', 'reason', 'occurred_at'
        ))
      )
);
SET @ac1_post_extension := (
    SELECT COUNT(*) = 2 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'academic_years'
      AND column_name IN ('calendar_lifecycle_status', 'calendar_active_slot')
      AND column_comment LIKE '%[academic-calendar-phase1]%'
);
SET @ac1_post_constraints := (
    SELECT COUNT(*) = 14 FROM information_schema.table_constraints
    WHERE table_schema = 'alrowad_uni_rust'
      AND constraint_name IN (
        'chk_ay_calendar_lifecycle_status', 'chk_acet_kind', 'chk_acet_flags',
        'chk_ace_cancellation', 'chk_acev_version_number', 'chk_acev_window',
        'chk_acev_enforcement', 'chk_acev_change_reason', 'chk_acev_publication',
        'chk_acyle_from_status', 'chk_acyle_to_status', 'chk_acyle_reason',
        'uq_ay_calendar_active_slot', 'uq_acev_published_event_slot'
      )
);
SET @ac1_post_foreign_keys := (
    SELECT COUNT(*) = 11
    FROM information_schema.key_column_usage k
    JOIN information_schema.referential_constraints r
      ON r.constraint_schema = k.constraint_schema
     AND r.table_name = k.table_name
     AND r.constraint_name = k.constraint_name
    WHERE k.table_schema = 'alrowad_uni_rust'
      AND k.table_name IN (
        'academic_calendar_events', 'academic_calendar_event_versions',
        'academic_calendar_year_lifecycle_events'
      )
      AND r.delete_rule IN ('RESTRICT', 'NO ACTION')
      AND r.update_rule IN ('RESTRICT', 'NO ACTION')
);
SET @ac1_post_generated_slots := (
    SELECT COUNT(*) = 2 FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND is_generated = 'ALWAYS'
      AND (UPPER(extra) LIKE '%PERSISTENT%' OR UPPER(extra) LIKE '%STORED%')
      AND (
        (table_name = 'academic_years' AND column_name = 'calendar_active_slot')
        OR (table_name = 'academic_calendar_event_versions' AND column_name = 'published_event_slot')
      )
);

SET @ac1_sql := IF(
    @ac1_post_tables,
    'SELECT
       COUNT(*) = 13,
       COUNT(DISTINCT event_type_code) = 13,
       COALESCE(SUM(event_type_kind = ''system''), 0) = 9,
       COALESCE(SUM(event_type_kind = ''general''), 0) = 4
     INTO @ac1_seed_count_ok, @ac1_seed_unique_ok, @ac1_system_count_ok, @ac1_general_count_ok
     FROM `alrowad_uni_rust`.`academic_calendar_event_types`
     WHERE event_type_code IN (
       ''admission_registration'', ''course_registration'', ''withdrawal'',
       ''study_period'', ''exam_preparation'', ''practical_exams'',
       ''theoretical_exams'', ''grade_appeals'', ''supplementary_exams'',
       ''university_break'', ''preparation_period'', ''holiday'', ''general_event''
     )',
    'SELECT 0, 0, 0, 0 INTO @ac1_seed_count_ok, @ac1_seed_unique_ok, @ac1_system_count_ok, @ac1_general_count_ok'
);
PREPARE ac1_post_seed FROM @ac1_sql;
EXECUTE ac1_post_seed;
DEALLOCATE PREPARE ac1_post_seed;

SET @ac1_sql := IF(
    @ac1_post_extension,
    'SELECT
       COALESCE(SUM(calendar_lifecycle_status = ''active''), 0) = 1,
       COALESCE(SUM(is_current = 1 AND calendar_lifecycle_status = ''active''), 0) = 1,
       COALESCE(SUM(calendar_lifecycle_status NOT IN (''draft'', ''active'', ''closed'')), 0) = 0
     INTO @ac1_one_active_ok, @ac1_current_preserved_ok, @ac1_status_values_ok
     FROM `alrowad_uni_rust`.`academic_years`',
    'SELECT 0, 0, 0 INTO @ac1_one_active_ok, @ac1_current_preserved_ok, @ac1_status_values_ok'
);
PREPARE ac1_post_years FROM @ac1_sql;
EXECUTE ac1_post_years;
DEALLOCATE PREPARE ac1_post_years;

SET @ac1_apply_pass := @ac1_apply_ready
    AND @ac1_post_tables
    AND @ac1_post_columns
    AND @ac1_post_extension
    AND @ac1_post_constraints
    AND @ac1_post_foreign_keys
    AND @ac1_post_generated_slots
    AND @ac1_seed_count_ok
    AND @ac1_seed_unique_ok
    AND @ac1_system_count_ok
    AND @ac1_general_count_ok
    AND @ac1_one_active_ok
    AND @ac1_current_preserved_ok
    AND @ac1_status_values_ok;

SET @ac1_apply_result := CASE
    WHEN NOT @ac1_apply_pass THEN 'BLOCKED'
    WHEN @ac1_initial_complete THEN 'ALREADY_APPLIED'
    ELSE 'APPLIED'
END;

SELECT 'APPLY_RESULT' AS report_section,
       @ac1_apply_result AS result,
       @ac1_current_count AS preserved_is_current_count,
       13 AS required_event_type_code_count;
