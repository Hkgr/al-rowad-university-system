-- READ ONLY. Must say OVERALL | PASS after a successful apply.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE/ALTER application data.
-- Do not use DATABASE(), SIGNAL, DELIMITER, or stored procedures.
--
-- Does not require zero offering rows (production may create them after apply).

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @periods_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND table_type = 'BASE TABLE'), 0);
SET @period_events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_period_events' AND table_type = 'BASE TABLE'), 0);
SET @status_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'status'), 0);
SET @opened_by_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_by_user_id'), 0);
SET @opened_at_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'opened_at'), 0);
SET @decision_note_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods' AND column_name = 'decision_note'), 0);
SET @identity_unique_exists := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*) FROM (
            SELECT index_name FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_periods'
              AND non_unique = 0 AND index_name <> 'PRIMARY'
            GROUP BY index_name
            HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'academic_year_id,semester_id'
        ) identity_indexes
    ),
    0
);
SET @phase1_ready := IF(
    @periods_exist = 1 AND @period_events_exist = 1 AND @status_exists = 1
    AND @opened_by_exists = 1 AND @opened_at_exists = 1 AND @decision_note_exists = 1
    AND @identity_unique_exists >= 1,
    1, 0
);

SET @offerings_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offerings' AND table_type = 'BASE TABLE'), 0);
SET @sources_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_sources' AND table_type = 'BASE TABLE'), 0);
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
        JOIN information_schema.columns c ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'supplementary_exam_offerings' AND c.column_name = required.column_name
        WHERE c.is_nullable = required.is_nullable
          AND (
              (required.data_type = 'int' AND LOWER(c.data_type) IN ('int', 'integer') AND LOWER(c.column_type) NOT LIKE '%unsigned%')
              OR (required.data_type = 'varchar' AND LOWER(c.data_type) IN ('varchar', 'char') AND IFNULL(c.character_maximum_length, 0) >= 16)
              OR (required.data_type = 'datetime' AND LOWER(c.data_type) IN ('datetime', 'timestamp'))
              OR (required.data_type = 'timestamp' AND LOWER(c.data_type) IN ('timestamp', 'datetime'))
          )
    ) = 11, 1, 0
);
SET @offerings_unique_ok := IF(@offerings_exist = 1 AND (SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offerings' AND non_unique = 0 AND index_name <> 'PRIMARY' GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'supplementary_exam_period_id,academic_program_id,course_id') uq) >= 1, 1, 0);
SET @offerings_fk_period_ok := IF(@offerings_exist = 1 AND (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY' WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offerings' AND k.column_name = 'supplementary_exam_period_id' AND k.referenced_table_name = 'supplementary_exam_periods' AND k.referenced_column_name = 'supplementary_exam_period_id') > 0, 1, 0);
SET @offerings_fk_program_ok := IF(@offerings_exist = 1 AND (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY' WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offerings' AND k.column_name = 'academic_program_id' AND k.referenced_table_name = 'academic_programs' AND k.referenced_column_name = 'academic_program_id') > 0, 1, 0);
SET @offerings_fk_course_ok := IF(@offerings_exist = 1 AND (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY' WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offerings' AND k.column_name = 'course_id' AND k.referenced_table_name = 'courses' AND k.referenced_column_name = 'course_id') > 0, 1, 0);
SET @offerings_fk_opened_ok := IF(@offerings_exist = 1 AND (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY' WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offerings' AND k.column_name = 'opened_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id') > 0, 1, 0);
SET @offerings_fk_closed_ok := IF(@offerings_exist = 1 AND (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY' WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offerings' AND k.column_name = 'closed_by_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id') > 0, 1, 0);
SET @offerings_idx_period_ok := IF(@offerings_exist = 1 AND (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offerings' AND seq_in_index = 1 AND column_name = 'supplementary_exam_period_id') > 0, 1, 0);
SET @offerings_contract_ok := IF(@offerings_exist = 1 AND @offerings_engine_ok = 1 AND @offerings_pk_ok = 1 AND @offerings_pk_ai_ok = 1 AND @offerings_types_ok = 1 AND @offerings_unique_ok = 1 AND @offerings_fk_period_ok = 1 AND @offerings_fk_program_ok = 1 AND @offerings_fk_course_ok = 1 AND @offerings_fk_opened_ok = 1 AND @offerings_fk_closed_ok = 1 AND @offerings_idx_period_ok = 1, 1, 0);

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
        JOIN information_schema.columns c ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'supplementary_exam_offering_sources' AND c.column_name = required.column_name
        WHERE c.is_nullable = required.is_nullable
          AND (
              (required.data_type = 'int' AND LOWER(c.data_type) IN ('int', 'integer') AND LOWER(c.column_type) NOT LIKE '%unsigned%')
              OR (required.data_type = 'timestamp' AND LOWER(c.data_type) IN ('timestamp', 'datetime'))
          )
    ) = 4, 1, 0
);
SET @sources_unique_ok := IF(@sources_exist = 1 AND (SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_sources' AND non_unique = 0 AND index_name <> 'PRIMARY' GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'supplementary_exam_offering_id,course_offering_id') uq) >= 1, 1, 0);
SET @sources_fk_offering_ok := IF(@sources_exist = 1 AND (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY' WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offering_sources' AND k.column_name = 'supplementary_exam_offering_id' AND k.referenced_table_name = 'supplementary_exam_offerings' AND k.referenced_column_name = 'supplementary_exam_offering_id') > 0, 1, 0);
SET @sources_fk_co_ok := IF(@sources_exist = 1 AND (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY' WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offering_sources' AND k.column_name = 'course_offering_id' AND k.referenced_table_name = 'course_offerings' AND k.referenced_column_name = 'course_offering_id') > 0, 1, 0);
SET @sources_contract_ok := IF(@sources_exist = 1 AND @sources_engine_ok = 1 AND @sources_pk_ok = 1 AND @sources_pk_ai_ok = 1 AND @sources_types_ok = 1 AND @sources_unique_ok = 1 AND @sources_fk_offering_ok = 1 AND @sources_fk_co_ok = 1, 1, 0);

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
        JOIN information_schema.columns c ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'supplementary_exam_offering_events' AND c.column_name = required.column_name
        WHERE c.is_nullable = required.is_nullable
          AND (
              (required.data_type = 'int' AND LOWER(c.data_type) IN ('int', 'integer') AND LOWER(c.column_type) NOT LIKE '%unsigned%')
              OR (required.data_type = 'varchar' AND LOWER(c.data_type) IN ('varchar', 'char') AND IFNULL(c.character_maximum_length, 0) >= 16)
              OR (required.data_type = 'text' AND LOWER(c.data_type) IN ('text', 'varchar', 'mediumtext', 'longtext'))
              OR (required.data_type = 'timestamp' AND LOWER(c.data_type) IN ('timestamp', 'datetime'))
          )
    ) = 8, 1, 0
);
SET @events_fk_offering_ok := IF(@events_exist = 1 AND (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY' WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offering_events' AND k.column_name = 'supplementary_exam_offering_id' AND k.referenced_table_name = 'supplementary_exam_offerings' AND k.referenced_column_name = 'supplementary_exam_offering_id') > 0, 1, 0);
SET @events_fk_actor_ok := IF(@events_exist = 1 AND (SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.table_constraints tc ON tc.constraint_schema = k.constraint_schema AND tc.table_name = k.table_name AND tc.constraint_name = k.constraint_name AND tc.constraint_type = 'FOREIGN KEY' WHERE k.table_schema = 'alrowad_uni_rust' AND k.table_name = 'supplementary_exam_offering_events' AND k.column_name = 'actor_user_id' AND k.referenced_table_name = 'users' AND k.referenced_column_name = 'user_id') > 0, 1, 0);
SET @events_idx_offering_ok := IF(@events_exist = 1 AND (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_events' AND seq_in_index = 1 AND column_name = 'supplementary_exam_offering_id') > 0, 1, 0);
SET @events_idx_actor_ok := IF(@events_exist = 1 AND (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_events' AND seq_in_index = 1 AND column_name = 'actor_user_id') > 0, 1, 0);
SET @events_idx_lookup_ok := IF(@events_exist = 1 AND (SELECT COUNT(*) FROM (SELECT index_name FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_events' GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') LIKE 'event_type,to_status%') typed) > 0, 1, 0);
SET @events_contract_ok := IF(@events_exist = 1 AND @events_engine_ok = 1 AND @events_pk_ok = 1 AND @events_pk_ai_ok = 1 AND @events_types_ok = 1 AND @events_fk_offering_ok = 1 AND @events_fk_actor_ok = 1 AND @events_idx_offering_ok = 1 AND @events_idx_actor_ok = 1 AND @events_idx_lookup_ok = 1, 1, 0);

SET @view_perm := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'supplementary_exams.offerings.view' AND is_active = 1), 0);
SET @manage_perm := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'supplementary_exams.offerings.manage' AND is_active = 1), 0);
SET @dean_view := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'dean' AND p.permission_code = 'supplementary_exams.offerings.view'), 0);
SET @dean_manage := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE r.role_code = 'dean' AND p.permission_code = 'supplementary_exams.offerings.manage'), 0);
SET @forbidden_manage := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id WHERE p.permission_code = 'supplementary_exams.offerings.manage' AND r.role_code IN ('super_admin', 'vice_president', 'vice_president_scientific', 'vice_president_administrative', 'registration_officer', 'exam_officer')), 0);
SET @rbac_ok := IF(@view_perm >= 1 AND @manage_perm >= 1 AND @dean_view >= 1 AND @dean_manage >= 1 AND @forbidden_manage = 0, 1, 0);

SET @orphan_sources := 0;
SET @orphan_events := 0;
SET @duplicate_offerings := 0;
SET @duplicate_sources := 0;

SET @sql := IF(@sources_exist = 1 AND @offerings_exist = 1, 'SELECT @orphan_sources := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_offering_sources` s LEFT JOIN `alrowad_uni_rust`.`supplementary_exam_offerings` o ON o.supplementary_exam_offering_id = s.supplementary_exam_offering_id WHERE o.supplementary_exam_offering_id IS NULL', 'SELECT @orphan_sources := 0');
PREPARE phase2_vf_orphan_s FROM @sql;
EXECUTE phase2_vf_orphan_s;
DEALLOCATE PREPARE phase2_vf_orphan_s;
SET @sql := IF(@events_exist = 1 AND @offerings_exist = 1, 'SELECT @orphan_events := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_offering_events` e LEFT JOIN `alrowad_uni_rust`.`supplementary_exam_offerings` o ON o.supplementary_exam_offering_id = e.supplementary_exam_offering_id WHERE o.supplementary_exam_offering_id IS NULL', 'SELECT @orphan_events := 0');
PREPARE phase2_vf_orphan_e FROM @sql;
EXECUTE phase2_vf_orphan_e;
DEALLOCATE PREPARE phase2_vf_orphan_e;
SET @sql := IF(@offerings_exist = 1, 'SELECT @duplicate_offerings := COUNT(*) FROM (SELECT supplementary_exam_period_id, academic_program_id, course_id FROM `alrowad_uni_rust`.`supplementary_exam_offerings` GROUP BY supplementary_exam_period_id, academic_program_id, course_id HAVING COUNT(*) > 1) d', 'SELECT @duplicate_offerings := 0');
PREPARE phase2_vf_dup_o FROM @sql;
EXECUTE phase2_vf_dup_o;
DEALLOCATE PREPARE phase2_vf_dup_o;
SET @sql := IF(@sources_exist = 1, 'SELECT @duplicate_sources := COUNT(*) FROM (SELECT supplementary_exam_offering_id, course_offering_id FROM `alrowad_uni_rust`.`supplementary_exam_offering_sources` GROUP BY supplementary_exam_offering_id, course_offering_id HAVING COUNT(*) > 1) d', 'SELECT @duplicate_sources := 0');
PREPARE phase2_vf_dup_s FROM @sql;
EXECUTE phase2_vf_dup_s;
DEALLOCATE PREPARE phase2_vf_dup_s;

SET @overall := IF(
    @phase1_ready = 1
    AND @offerings_contract_ok = 1
    AND @sources_contract_ok = 1
    AND @events_contract_ok = 1
    AND @rbac_ok = 1
    AND @orphan_sources = 0
    AND @orphan_events = 0
    AND @duplicate_offerings = 0
    AND @duplicate_sources = 0,
    'PASS',
    'FAIL'
);

SELECT
    'OVERALL' AS report_section,
    @overall AS result,
    @offerings_contract_ok AS offerings_contract_ok,
    @sources_contract_ok AS sources_contract_ok,
    @events_contract_ok AS events_contract_ok,
    @rbac_ok AS rbac_ok,
    @orphan_sources AS orphan_sources,
    @orphan_events AS orphan_events,
    @duplicate_offerings AS duplicate_offerings,
    @duplicate_sources AS duplicate_sources;
