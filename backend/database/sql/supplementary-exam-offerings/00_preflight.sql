-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE/ALTER application data.
-- SET user variables and reporting SELECTs only.
-- Do not use DATABASE(), SIGNAL, DELIMITER, or stored procedures.
--
-- Phase 2 Dean supplementary exam offerings.
-- Hard dependency: Phase 1 governed supplementary_exam_periods contract.
-- Does not recreate Phase 1 schema. Does not touch course_offerings,
-- student_course_registrations, or supplementary_exam_results.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @missing_required_columns := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'semesters' AS table_name, 'semester_id' AS column_name
            UNION ALL SELECT 'semesters', 'semester_code'
            UNION ALL SELECT 'semesters', 'semester_name'
            UNION ALL SELECT 'semesters', 'semester_order'
            UNION ALL SELECT 'semesters', 'is_active'
            UNION ALL SELECT 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'course_offerings', 'course_id'
            UNION ALL SELECT 'course_offerings', 'academic_year_id'
            UNION ALL SELECT 'course_offerings', 'semester_id'
            UNION ALL SELECT 'course_offerings', 'academic_program_id'
            UNION ALL SELECT 'academic_programs', 'academic_program_id'
            UNION ALL SELECT 'academic_programs', 'department_id'
            UNION ALL SELECT 'departments', 'department_id'
            UNION ALL SELECT 'departments', 'college_id'
            UNION ALL SELECT 'colleges', 'college_id'
            UNION ALL SELECT 'courses', 'course_id'
            UNION ALL SELECT 'student_course_registrations', 'student_course_registration_id'
            UNION ALL SELECT 'student_course_registrations', 'course_offering_id'
            UNION ALL SELECT 'student_course_registrations', 'registration_status_id'
            UNION ALL SELECT 'registration_statuses', 'registration_status_id'
            UNION ALL SELECT 'registration_statuses', 'status_code'
            UNION ALL SELECT 'users', 'user_id'
            UNION ALL SELECT 'roles', 'role_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'roles', 'is_active'
            UNION ALL SELECT 'permissions', 'permission_id'
            UNION ALL SELECT 'permissions', 'module_id'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'permissions', 'description'
            UNION ALL SELECT 'permissions', 'is_active'
            UNION ALL SELECT 'role_permissions', 'role_id'
            UNION ALL SELECT 'role_permissions', 'permission_id'
            UNION ALL SELECT 'system_modules', 'module_id'
            UNION ALL SELECT 'system_modules', 'module_code'
            UNION ALL SELECT 'user_roles', 'user_id'
            UNION ALL SELECT 'user_roles', 'role_id'
            UNION ALL SELECT 'user_access_scopes', 'user_id'
            UNION ALL SELECT 'user_access_scopes', 'scope_type'
            UNION ALL SELECT 'supplementary_exam_periods', 'supplementary_exam_period_id'
            UNION ALL SELECT 'supplementary_exam_periods', 'academic_year_id'
            UNION ALL SELECT 'supplementary_exam_periods', 'semester_id'
            UNION ALL SELECT 'supplementary_exam_results', 'supplementary_exam_result_id'
        ) required_columns
        LEFT JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = required_columns.table_name
           AND existing.column_name = required_columns.column_name
        WHERE existing.column_name IS NULL
    ),
    1
);

SET @structure_ok := IF(@db_ready = 1 AND @missing_required_columns = 0, 1, 0);

SET @pk_signed := IF(
    @structure_ok = 1
    AND (SELECT LOWER(c.data_type) FROM information_schema.columns c WHERE c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'users' AND c.column_name = 'user_id') IN ('int', 'integer')
    AND (SELECT LOWER(c.column_type) FROM information_schema.columns c WHERE c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'users' AND c.column_name = 'user_id') NOT LIKE '%unsigned%'
    AND (SELECT LOWER(c.data_type) FROM information_schema.columns c WHERE c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'courses' AND c.column_name = 'course_id') IN ('int', 'integer')
    AND (SELECT LOWER(c.column_type) FROM information_schema.columns c WHERE c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'courses' AND c.column_name = 'course_id') NOT LIKE '%unsigned%'
    AND (SELECT LOWER(c.data_type) FROM information_schema.columns c WHERE c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'academic_programs' AND c.column_name = 'academic_program_id') IN ('int', 'integer')
    AND (SELECT LOWER(c.column_type) FROM information_schema.columns c WHERE c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'academic_programs' AND c.column_name = 'academic_program_id') NOT LIKE '%unsigned%'
    AND (SELECT LOWER(c.data_type) FROM information_schema.columns c WHERE c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'course_offerings' AND c.column_name = 'course_offering_id') IN ('int', 'integer')
    AND (SELECT LOWER(c.column_type) FROM information_schema.columns c WHERE c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'course_offerings' AND c.column_name = 'course_offering_id') NOT LIKE '%unsigned%'
    AND (SELECT LOWER(c.data_type) FROM information_schema.columns c WHERE c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'supplementary_exam_periods' AND c.column_name = 'supplementary_exam_period_id') IN ('int', 'integer')
    AND (SELECT LOWER(c.column_type) FROM information_schema.columns c WHERE c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'supplementary_exam_periods' AND c.column_name = 'supplementary_exam_period_id') NOT LIKE '%unsigned%',
    1, 0
);

SET @periods_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND table_type = 'BASE TABLE'), 0);
SET @period_events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND table_type = 'BASE TABLE'), 0);
SET @status_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status'), 0);
SET @opened_by_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id'), 0);
SET @opened_at_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_at'), 0);
SET @decision_note_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'decision_note'), 0);

SET @phase1_cols_ok := IF(
    @status_exists = 1 AND @opened_by_exists = 1 AND @opened_at_exists = 1 AND @decision_note_exists = 1
    AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status') = 'varchar'
    AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status') = 'NO'
    AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id') = 'int'
    AND (SELECT LOWER(column_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id') NOT LIKE '%unsigned%'
    AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_at') IN ('datetime', 'timestamp')
    AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'decision_note') IN ('text', 'varchar', 'mediumtext', 'longtext'),
    1, 0
);

SET @identity_unique_exists := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT index_name
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'supplementary_exam_periods'
              AND non_unique = 0
              AND index_name <> 'PRIMARY'
            GROUP BY index_name
            HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'academic_year_id,semester_id'
        ) identity_indexes
    ),
    0
);

SET @p1_events_engine_ok := IF(@period_events_exist = 1 AND UPPER(IFNULL((SELECT t.engine FROM information_schema.tables t WHERE t.table_schema = 'alrowad_uni_rust' AND t.table_name = 'supplementary_exam_period_events' AND t.table_type = 'BASE TABLE'), '')) = 'INNODB', 1, 0);
SET @p1_events_pk_ok := IF(@period_events_exist = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND index_name = 'PRIMARY') <=> 'supplementary_exam_period_event_id', 1, 0);
SET @p1_events_pk_ai_ok := IF(@period_events_exist = 1 AND LOWER(IFNULL((SELECT extra FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND column_name = 'supplementary_exam_period_event_id'), '')) LIKE '%auto_increment%', 1, 0);
SET @p1_events_fk_period_ok := IF(
    @period_events_exist = 1
    AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
        WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_period_events'
          AND k.column_name = 'supplementary_exam_period_id' AND k.referenced_table_name = 'supplementary_exam_periods' AND k.referenced_column_name = 'supplementary_exam_period_id'
    ) > 0, 1, 0
);
SET @p1_events_fk_actor_ok := IF(
    @period_events_exist = 1
    AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
        WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_period_events'
          AND k.column_name = 'actor_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id'
    ) > 0, 1, 0
);
SET @p1_events_idx_period_ok := IF(@period_events_exist = 1 AND (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND seq_in_index = 1 AND column_name = 'supplementary_exam_period_id') > 0, 1, 0);
SET @p1_events_idx_actor_ok := IF(@period_events_exist = 1 AND (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND seq_in_index = 1 AND column_name = 'actor_user_id') > 0, 1, 0);
SET @p1_events_idx_lookup_ok := IF(
    @period_events_exist = 1
    AND (
        SELECT COUNT(*) FROM (
            SELECT index_name FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events'
            GROUP BY index_name
            HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') LIKE 'event_type,to_status%'
        ) typed
    ) > 0, 1, 0
);
SET @p1_events_cols_ok := IF(
    @period_events_exist = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND column_name IN ('supplementary_exam_period_event_id', 'supplementary_exam_period_id', 'event_type', 'from_status', 'to_status', 'actor_user_id', 'notes', 'created_at')) = 8,
    1, 0
);

SET @phase1_perm_view := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'supplementary_exams.periods.view' AND p.is_active = 1 AND sm.module_code = 'exams'), 0);
SET @phase1_perm_decide := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'supplementary_exams.periods.decide' AND p.is_active = 1 AND sm.module_code = 'exams'), 0);

SET @phase1_ready := IF(
    @structure_ok = 1
    AND @periods_exist = 1
    AND @phase1_cols_ok = 1
    AND @identity_unique_exists >= 1
    AND @period_events_exist = 1
    AND @p1_events_engine_ok = 1
    AND @p1_events_pk_ok = 1
    AND @p1_events_pk_ai_ok = 1
    AND @p1_events_cols_ok = 1
    AND @p1_events_fk_period_ok = 1
    AND @p1_events_fk_actor_ok = 1
    AND @p1_events_idx_period_ok = 1
    AND @p1_events_idx_actor_ok = 1
    AND @p1_events_idx_lookup_ok = 1
    AND @phase1_perm_view >= 1
    AND @phase1_perm_decide >= 1,
    1, 0
);

SET @sem_order_1 := 0;
SET @sem_order_2 := 0;
SET @sem_order_3 := 0;
SET @sql := IF(
    @structure_ok = 1,
    'SELECT @sem_order_1 := COUNT(*) FROM `alrowad_uni_rust`.`semesters` WHERE is_active = 1 AND semester_order = 1',
    'SELECT @sem_order_1 := 0'
);
PREPARE phase2_pf_sem1 FROM @sql;
EXECUTE phase2_pf_sem1;
DEALLOCATE PREPARE phase2_pf_sem1;
SET @sql := IF(
    @structure_ok = 1,
    'SELECT @sem_order_2 := COUNT(*) FROM `alrowad_uni_rust`.`semesters` WHERE is_active = 1 AND semester_order = 2',
    'SELECT @sem_order_2 := 0'
);
PREPARE phase2_pf_sem2 FROM @sql;
EXECUTE phase2_pf_sem2;
DEALLOCATE PREPARE phase2_pf_sem2;
SET @sql := IF(
    @structure_ok = 1,
    'SELECT @sem_order_3 := COUNT(*) FROM `alrowad_uni_rust`.`semesters` WHERE is_active = 1 AND semester_order = 3',
    'SELECT @sem_order_3 := 0'
);
PREPARE phase2_pf_sem3 FROM @sql;
EXECUTE phase2_pf_sem3;
DEALLOCATE PREPARE phase2_pf_sem3;
SET @semester_policy_ready := IF(@sem_order_1 = 1 AND @sem_order_2 = 1 AND @sem_order_3 = 1, 1, 0);

-- Require status_code = 'registered'
-- Require status_code = 'completed'
SET @status_registered := 0;
SET @status_completed := 0;
SET @sql := IF(
    @structure_ok = 1,
    'SELECT @status_registered := COUNT(*) FROM `alrowad_uni_rust`.`registration_statuses` WHERE status_code = ''registered''',
    'SELECT @status_registered := 0'
);
PREPARE phase2_pf_reg FROM @sql;
EXECUTE phase2_pf_reg;
DEALLOCATE PREPARE phase2_pf_reg;
SET @sql := IF(
    @structure_ok = 1,
    'SELECT @status_completed := COUNT(*) FROM `alrowad_uni_rust`.`registration_statuses` WHERE status_code = ''completed''',
    'SELECT @status_completed := 0'
);
PREPARE phase2_pf_comp FROM @sql;
EXECUTE phase2_pf_comp;
DEALLOCATE PREPARE phase2_pf_comp;
SET @registration_status_ready := IF(@status_registered >= 1 AND @status_completed >= 1, 1, 0);

SET @offerings_any := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offerings'), 0);
SET @offerings_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offerings' AND table_type = 'BASE TABLE'), 0);
SET @sources_any := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_sources'), 0);
SET @sources_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_sources' AND table_type = 'BASE TABLE'), 0);
SET @events_any := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_events'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_events' AND table_type = 'BASE TABLE'), 0);

SET @offerings_engine_ok := IF(@offerings_exist = 1 AND UPPER(IFNULL((SELECT t.engine FROM information_schema.tables t WHERE t.table_schema = 'alrowad_uni_rust' AND t.table_name = 'supplementary_exam_offerings' AND t.table_type = 'BASE TABLE'), '')) = 'INNODB', 1, 0);
SET @offerings_pk_ok := IF(@offerings_exist = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offerings' AND index_name = 'PRIMARY') <=> 'supplementary_exam_offering_id', 1, 0);
SET @offerings_pk_ai_ok := IF(@offerings_exist = 1 AND LOWER(IFNULL((SELECT extra FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offerings' AND column_name = 'supplementary_exam_offering_id'), '')) LIKE '%auto_increment%', 1, 0);
SET @offerings_types_ok := IF(
    @offerings_exist = 1
    AND (
        SELECT COUNT(*) FROM (
            SELECT 'supplementary_exam_offering_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'supplementary_exam_period_id', 'int', 'NO'
            UNION ALL SELECT 'academic_program_id', 'int', 'NO'
            UNION ALL SELECT 'course_id', 'int', 'NO'
            UNION ALL SELECT 'status', 'varchar', 'NO'
            UNION ALL SELECT 'opened_by_user_id', 'int', 'NO'
            UNION ALL SELECT 'opened_at', 'datetime', 'NO'
            UNION ALL SELECT 'closed_by_user_id', 'int', 'YES'
            UNION ALL SELECT 'closed_at', 'datetime', 'YES'
            UNION ALL SELECT 'created_at', 'timestamp', 'NO'
            UNION ALL SELECT 'updated_at', 'timestamp', 'NO'
        ) required
        JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = 'supplementary_exam_offerings'
           AND c.column_name = required.column_name
        WHERE c.is_nullable = required.is_nullable
          AND (
              (required.data_type = 'int' AND LOWER(c.data_type) IN ('int', 'integer') AND LOWER(c.column_type) NOT LIKE '%unsigned%')
              OR (required.data_type = 'varchar' AND LOWER(c.data_type) IN ('varchar', 'char') AND IFNULL(c.character_maximum_length, 0) >= 16)
              OR (required.data_type = 'datetime' AND LOWER(c.data_type) IN ('datetime', 'timestamp'))
              OR (required.data_type = 'timestamp' AND LOWER(c.data_type) IN ('timestamp', 'datetime'))
          )
    ) = 11,
    1, 0
);
SET @offerings_unique_ok := IF(
    @offerings_exist = 1
    AND (
        SELECT COUNT(*) FROM (
            SELECT index_name FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offerings'
              AND non_unique = 0 AND index_name <> 'PRIMARY'
            GROUP BY index_name
            HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'supplementary_exam_period_id,academic_program_id,course_id'
        ) uq
    ) >= 1,
    1, 0
);
SET @offerings_fk_period_ok := IF(
    @offerings_exist = 1 AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
        WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offerings'
          AND k.column_name = 'supplementary_exam_period_id' AND k.referenced_table_name = 'supplementary_exam_periods' AND k.referenced_column_name = 'supplementary_exam_period_id'
    ) > 0, 1, 0
);
SET @offerings_fk_program_ok := IF(
    @offerings_exist = 1 AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
        WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offerings'
          AND k.column_name = 'academic_program_id' AND k.referenced_table_name = 'academic_programs' AND k.referenced_column_name = 'academic_program_id'
    ) > 0, 1, 0
);
SET @offerings_fk_course_ok := IF(
    @offerings_exist = 1 AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
        WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offerings'
          AND k.column_name = 'course_id' AND k.referenced_table_name = 'courses' AND k.referenced_column_name = 'course_id'
    ) > 0, 1, 0
);
SET @offerings_fk_opened_ok := IF(
    @offerings_exist = 1 AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
        WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offerings'
          AND k.column_name = 'opened_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id'
    ) > 0, 1, 0
);
SET @offerings_fk_closed_ok := IF(
    @offerings_exist = 1 AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
        WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offerings'
          AND k.column_name = 'closed_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id'
    ) > 0, 1, 0
);
SET @offerings_idx_period_ok := IF(@offerings_exist = 1 AND (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offerings' AND seq_in_index = 1 AND column_name = 'supplementary_exam_period_id') > 0, 1, 0);
SET @offerings_full_ok := IF(
    @offerings_exist = 1 AND @offerings_engine_ok = 1 AND @offerings_pk_ok = 1 AND @offerings_pk_ai_ok = 1
    AND @offerings_types_ok = 1 AND @offerings_unique_ok = 1 AND @offerings_fk_period_ok = 1
    AND @offerings_fk_program_ok = 1 AND @offerings_fk_course_ok = 1 AND @offerings_fk_opened_ok = 1
    AND @offerings_fk_closed_ok = 1 AND @offerings_idx_period_ok = 1,
    1, 0
);
SET @offerings_state := CASE
    WHEN @offerings_any = 0 THEN 'ABSENT'
    WHEN @offerings_exist = 1 AND @offerings_full_ok = 1 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @sources_engine_ok := IF(@sources_exist = 1 AND UPPER(IFNULL((SELECT t.engine FROM information_schema.tables t WHERE t.table_schema = 'alrowad_uni_rust' AND t.table_name = 'supplementary_exam_offering_sources' AND t.table_type = 'BASE TABLE'), '')) = 'INNODB', 1, 0);
SET @sources_pk_ok := IF(@sources_exist = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_sources' AND index_name = 'PRIMARY') <=> 'supplementary_exam_offering_source_id', 1, 0);
SET @sources_pk_ai_ok := IF(@sources_exist = 1 AND LOWER(IFNULL((SELECT extra FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_sources' AND column_name = 'supplementary_exam_offering_source_id'), '')) LIKE '%auto_increment%', 1, 0);
SET @sources_types_ok := IF(
    @sources_exist = 1
    AND (
        SELECT COUNT(*) FROM (
            SELECT 'supplementary_exam_offering_source_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'supplementary_exam_offering_id', 'int', 'NO'
            UNION ALL SELECT 'course_offering_id', 'int', 'NO'
            UNION ALL SELECT 'created_at', 'timestamp', 'NO'
        ) required
        JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'supplementary_exam_offering_sources' AND c.column_name = required.column_name
        WHERE c.is_nullable = required.is_nullable
          AND (
              (required.data_type = 'int' AND LOWER(c.data_type) IN ('int', 'integer') AND LOWER(c.column_type) NOT LIKE '%unsigned%')
              OR (required.data_type = 'timestamp' AND LOWER(c.data_type) IN ('timestamp', 'datetime'))
          )
    ) = 4,
    1, 0
);
SET @sources_unique_ok := IF(
    @sources_exist = 1
    AND (
        SELECT COUNT(*) FROM (
            SELECT index_name FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_sources'
              AND non_unique = 0 AND index_name <> 'PRIMARY'
            GROUP BY index_name
            HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'supplementary_exam_offering_id,course_offering_id'
        ) uq
    ) >= 1,
    1, 0
);
SET @sources_fk_offering_ok := IF(
    @sources_exist = 1 AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
        WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offering_sources'
          AND k.column_name = 'supplementary_exam_offering_id' AND k.referenced_table_name = 'supplementary_exam_offerings' AND k.referenced_column_name = 'supplementary_exam_offering_id'
    ) > 0, 1, 0
);
SET @sources_fk_co_ok := IF(
    @sources_exist = 1 AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
        WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offering_sources'
          AND k.column_name = 'course_offering_id' AND k.referenced_table_name = 'course_offerings' AND k.referenced_column_name = 'course_offering_id'
    ) > 0, 1, 0
);
SET @sources_full_ok := IF(
    @sources_exist = 1 AND @sources_engine_ok = 1 AND @sources_pk_ok = 1 AND @sources_pk_ai_ok = 1
    AND @sources_types_ok = 1 AND @sources_unique_ok = 1 AND @sources_fk_offering_ok = 1 AND @sources_fk_co_ok = 1,
    1, 0
);
SET @sources_state := CASE
    WHEN @sources_any = 0 THEN 'ABSENT'
    WHEN @sources_exist = 1 AND @sources_full_ok = 1 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @events_engine_ok := IF(@events_exist = 1 AND UPPER(IFNULL((SELECT t.engine FROM information_schema.tables t WHERE t.table_schema = 'alrowad_uni_rust' AND t.table_name = 'supplementary_exam_offering_events' AND t.table_type = 'BASE TABLE'), '')) = 'INNODB', 1, 0);
SET @events_pk_ok := IF(@events_exist = 1 AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_events' AND index_name = 'PRIMARY') <=> 'supplementary_exam_offering_event_id', 1, 0);
SET @events_pk_ai_ok := IF(@events_exist = 1 AND LOWER(IFNULL((SELECT extra FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_events' AND column_name = 'supplementary_exam_offering_event_id'), '')) LIKE '%auto_increment%', 1, 0);
SET @events_types_ok := IF(
    @events_exist = 1
    AND (
        SELECT COUNT(*) FROM (
            SELECT 'supplementary_exam_offering_event_id' AS column_name, 'int' AS data_type, 'NO' AS is_nullable
            UNION ALL SELECT 'supplementary_exam_offering_id', 'int', 'NO'
            UNION ALL SELECT 'event_type', 'varchar', 'NO'
            UNION ALL SELECT 'from_status', 'varchar', 'YES'
            UNION ALL SELECT 'to_status', 'varchar', 'NO'
            UNION ALL SELECT 'actor_user_id', 'int', 'NO'
            UNION ALL SELECT 'notes', 'text', 'YES'
            UNION ALL SELECT 'created_at', 'timestamp', 'NO'
        ) required
        JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'supplementary_exam_offering_events' AND c.column_name = required.column_name
        WHERE c.is_nullable = required.is_nullable
          AND (
              (required.data_type = 'int' AND LOWER(c.data_type) IN ('int', 'integer') AND LOWER(c.column_type) NOT LIKE '%unsigned%')
              OR (required.data_type = 'varchar' AND LOWER(c.data_type) IN ('varchar', 'char') AND IFNULL(c.character_maximum_length, 0) >= 16)
              OR (required.data_type = 'text' AND LOWER(c.data_type) IN ('text', 'varchar', 'mediumtext', 'longtext'))
              OR (required.data_type = 'timestamp' AND LOWER(c.data_type) IN ('timestamp', 'datetime'))
          )
    ) = 8,
    1, 0
);
SET @events_fk_offering_ok := IF(
    @events_exist = 1 AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
        WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offering_events'
          AND k.column_name = 'supplementary_exam_offering_id' AND k.referenced_table_name = 'supplementary_exam_offerings' AND k.referenced_column_name = 'supplementary_exam_offering_id'
    ) > 0, 1, 0
);
SET @events_fk_actor_ok := IF(
    @events_exist = 1 AND (
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
        WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offering_events'
          AND k.column_name = 'actor_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id'
    ) > 0, 1, 0
);
SET @events_idx_offering_ok := IF(@events_exist = 1 AND (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_events' AND seq_in_index = 1 AND column_name = 'supplementary_exam_offering_id') > 0, 1, 0);
SET @events_idx_actor_ok := IF(@events_exist = 1 AND (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_events' AND seq_in_index = 1 AND column_name = 'actor_user_id') > 0, 1, 0);
SET @events_idx_lookup_ok := IF(
    @events_exist = 1
    AND (
        SELECT COUNT(*) FROM (
            SELECT index_name FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_events'
            GROUP BY index_name
            HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') LIKE 'event_type,to_status%'
        ) typed
    ) > 0, 1, 0
);
SET @events_full_ok := IF(
    @events_exist = 1 AND @events_engine_ok = 1 AND @events_pk_ok = 1 AND @events_pk_ai_ok = 1
    AND @events_types_ok = 1 AND @events_fk_offering_ok = 1 AND @events_fk_actor_ok = 1
    AND @events_idx_offering_ok = 1 AND @events_idx_actor_ok = 1 AND @events_idx_lookup_ok = 1,
    1, 0
);
SET @events_state := CASE
    WHEN @events_any = 0 THEN 'ABSENT'
    WHEN @events_exist = 1 AND @events_full_ok = 1 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @view_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'supplementary_exams.offerings.view'), 0);
SET @manage_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'supplementary_exams.offerings.manage'), 0);
SET @view_perm_ok := IF(
    @view_perm_rows = 0
    OR (
        SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'supplementary_exams.offerings.view' AND p.is_active = 1 AND sm.module_code = 'exams'
    ) = @view_perm_rows AND @view_perm_rows = 1,
    1, 0
);
SET @manage_perm_ok := IF(
    @manage_perm_rows = 0
    OR (
        SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'supplementary_exams.offerings.manage' AND p.is_active = 1 AND sm.module_code = 'exams'
    ) = @manage_perm_rows AND @manage_perm_rows = 1,
    1, 0
);
SET @view_perm_state := CASE WHEN @view_perm_rows = 0 THEN 'ABSENT' WHEN @view_perm_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @manage_perm_state := CASE WHEN @manage_perm_rows = 0 THEN 'ABSENT' WHEN @manage_perm_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;

SET @forbidden_manage := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code = 'supplementary_exams.offerings.manage'
          AND r.role_code IN ('super_admin', 'vice_president', 'vice_president_scientific', 'vice_president_administrative', 'registration_officer', 'exam_officer')
    ),
    0
);
SET @dean_role := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'dean' AND is_active = 1), 0);
SET @exams_module_ok := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'exams' AND is_active = 1), 0);

SET @target_schema_safe := IF(
    @offerings_state IN ('ABSENT', 'COMPATIBLE')
    AND @sources_state IN ('ABSENT', 'COMPATIBLE')
    AND @events_state IN ('ABSENT', 'COMPATIBLE'),
    1, 0
);
SET @rbac_safe := IF(
    @view_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @manage_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @forbidden_manage = 0
    AND @dean_role = 1
    AND @exams_module_ok = 1,
    1, 0
);

SET @blocker_code := CASE
    WHEN @db_ready = 0 OR @structure_ok = 0 OR @pk_signed = 0 THEN 'REQUIRED_STRUCTURE_MISSING'
    WHEN @phase1_ready = 0 THEN 'PHASE1_NOT_DEPLOYED'
    WHEN @semester_policy_ready = 0 THEN 'SEMESTER_POLICY_INVALID'
    WHEN @registration_status_ready = 0 THEN 'MISSING_REGISTRATION_STATUS'
    WHEN @target_schema_safe = 0 THEN 'TARGET_SCHEMA_CONFLICT'
    WHEN @rbac_safe = 0 THEN 'RBAC_CONFLICT'
    ELSE NULL
END;

SET @overall := IF(
    @db_ready = 1
    AND @structure_ok = 1
    AND @pk_signed = 1
    AND @phase1_ready = 1
    AND @semester_policy_ready = 1
    AND @registration_status_ready = 1
    AND @target_schema_safe = 1
    AND @rbac_safe = 1,
    'READY',
    'BLOCKED'
);

SELECT 'A_phase1' AS report_section, @phase1_ready AS phase1_ready, @periods_exist AS periods_exist, @period_events_exist AS period_events_exist, @identity_unique_exists AS identity_unique_exists;
SELECT 'B_semester_policy' AS report_section, @sem_order_1 AS active_order_1, @sem_order_2 AS active_order_2, @sem_order_3 AS active_order_3, @semester_policy_ready AS semester_policy_ready;
SELECT 'C_registration_status' AS report_section, @status_registered AS registered_exists, @status_completed AS completed_exists;
SELECT 'D_target_objects' AS report_section, object_name, object_state
FROM (
    SELECT 'supplementary_exam_offerings' AS object_name, @offerings_state AS object_state
    UNION ALL SELECT 'supplementary_exam_offering_sources', @sources_state
    UNION ALL SELECT 'supplementary_exam_offering_events', @events_state
    UNION ALL SELECT 'supplementary_exams.offerings.view', @view_perm_state
    UNION ALL SELECT 'supplementary_exams.offerings.manage', @manage_perm_state
) objects;
SELECT 'E_fk_signedness' AS report_section, @pk_signed AS parent_pk_signed_int;

SELECT
    'OVERALL' AS report_section,
    @overall AS result,
    @blocker_code AS blocker_code,
    @phase1_ready AS phase1_ready,
    @semester_policy_ready AS semester_policy_ready,
    @target_schema_safe AS target_schema_safe,
    @rbac_safe AS rbac_safe;
