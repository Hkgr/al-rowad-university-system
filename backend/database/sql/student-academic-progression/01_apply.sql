-- Manual, idempotent, fail-closed apply for Phase 10 academic record / progression / graduation.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not use the DATABASE function, stored procedures, DELIMITER, or SIGNAL.
-- Independently recomputes the same critical guards as 00_preflight.sql.
-- DDL uses guarded dynamic SQL because MariaDB DDL causes implicit commits.
-- RBAC DML is transactional and rolled back on post-write mismatch.
-- Do NOT execute from application code, seeders, or Laravel migrations.

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
            SELECT 'students' AS table_name, 'student_id' AS column_name
            UNION ALL SELECT 'students', 'academic_program_id'
            UNION ALL SELECT 'students', 'current_academic_level_id'
            UNION ALL SELECT 'students', 'student_status_id'
            UNION ALL SELECT 'student_statuses', 'student_status_id'
            UNION ALL SELECT 'student_statuses', 'status_code'
            UNION ALL SELECT 'student_statuses', 'is_active'
            UNION ALL SELECT 'student_academic_terms', 'student_academic_term_id'
            UNION ALL SELECT 'student_academic_terms', 'student_id'
            UNION ALL SELECT 'student_academic_terms', 'academic_year_id'
            UNION ALL SELECT 'student_academic_terms', 'semester_id'
            UNION ALL SELECT 'student_academic_terms', 'academic_level_id'
            UNION ALL SELECT 'student_academic_terms', 'term_gpa'
            UNION ALL SELECT 'student_academic_terms', 'cumulative_gpa'
            UNION ALL SELECT 'student_academic_terms', 'total_registered_hours'
            UNION ALL SELECT 'academic_levels', 'academic_level_id'
            UNION ALL SELECT 'academic_levels', 'level_order'
            UNION ALL SELECT 'academic_levels', 'is_active'
            UNION ALL SELECT 'academic_programs', 'academic_program_id'
            UNION ALL SELECT 'program_courses', 'program_course_id'
            UNION ALL SELECT 'program_courses', 'academic_program_id'
            UNION ALL SELECT 'program_courses', 'academic_level_id'
            UNION ALL SELECT 'program_courses', 'is_active'
            UNION ALL SELECT 'student_course_registrations', 'student_course_registration_id'
            UNION ALL SELECT 'student_course_registrations', 'student_id'
            UNION ALL SELECT 'student_course_registrations', 'course_offering_id'
            UNION ALL SELECT 'student_course_results', 'student_course_result_id'
            UNION ALL SELECT 'result_statuses', 'result_status_id'
            UNION ALL SELECT 'result_statuses', 'status_code'
            UNION ALL SELECT 'grade_approvals', 'grade_approval_id'
            UNION ALL SELECT 'grade_approvals', 'course_offering_id'
            UNION ALL SELECT 'grade_approvals', 'approval_status_id'
            UNION ALL SELECT 'approval_statuses', 'approval_status_id'
            UNION ALL SELECT 'approval_statuses', 'status_code'
            UNION ALL SELECT 'academic_requirement_groups', 'requirement_group_id'
            UNION ALL SELECT 'program_course_requirement_groups', 'program_course_id'
            UNION ALL SELECT 'roles', 'role_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'roles', 'is_active'
            UNION ALL SELECT 'permissions', 'permission_id'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'permissions', 'module_id'
            UNION ALL SELECT 'permissions', 'is_active'
            UNION ALL SELECT 'role_permissions', 'role_id'
            UNION ALL SELECT 'role_permissions', 'permission_id'
            UNION ALL SELECT 'system_modules', 'module_id'
            UNION ALL SELECT 'system_modules', 'module_code'
            UNION ALL SELECT 'users', 'user_id'
        ) required
        LEFT JOIN information_schema.columns c
            ON c.table_schema = 'alrowad_uni_rust'
           AND c.table_name = required.table_name
           AND c.column_name = required.column_name
        WHERE c.column_name IS NULL
    ),
    1
);

SET @registration_officer_active := IF(
    @db_ready = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'registration_officer' AND is_active = 1) = 1,
    1, 0
);

SET @graduated_status_ok := IF(
    @db_ready = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_statuses` WHERE status_code = 'graduated' AND is_active = 1) = 1,
    1, 0
);

SET @students_module_ok := IF(
    @db_ready = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'students' AND is_active = 1) = 1,
    1, 0
);

SET @permission_code_unique_ok := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'permissions'
          AND non_unique = 0
          AND index_name <> 'PRIMARY'
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'permission_code'
    )
    AND NOT EXISTS (
        SELECT permission_code
        FROM `alrowad_uni_rust`.`permissions`
        WHERE permission_code IN (
            'academic_records.view', 'academic_records.finalize',
            'academic_progression.view', 'academic_progression.review',
            'graduation_decisions.view', 'graduation_decisions.review'
        )
        GROUP BY permission_code
        HAVING COUNT(*) > 1
    ),
    1, 0
);

SET @role_permissions_unique_ok := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'role_permissions'
          AND non_unique = 0
          AND index_name <> 'PRIMARY'
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) IN ('role_id,permission_id', 'permission_id,role_id')
    ),
    1, 0
);

SET @legacy_term_duplicates := IF(
    @db_ready = 1 AND @missing_required_columns = 0,
    (
        SELECT COUNT(*) FROM (
            SELECT student_id, academic_year_id, semester_id
            FROM `alrowad_uni_rust`.`student_academic_terms`
            GROUP BY student_id, academic_year_id, semester_id
            HAVING COUNT(*) > 1
        ) d
    ),
    1
);

SET @legacy_term_null_identity := IF(
    @db_ready = 1 AND @missing_required_columns = 0,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`student_academic_terms`
        WHERE student_id IS NULL OR academic_year_id IS NULL OR semester_id IS NULL OR academic_level_id IS NULL
    ),
    1
);

SET @legacy_invalid_levels := IF(
    @db_ready = 1 AND @missing_required_columns = 0,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`student_academic_terms` t
        LEFT JOIN `alrowad_uni_rust`.`academic_levels` l
            ON l.academic_level_id = t.academic_level_id
        WHERE l.academic_level_id IS NULL
    ),
    1
);

SET @legacy_malformed_gpa := IF(
    @db_ready = 1 AND @missing_required_columns = 0,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`student_academic_terms`
        WHERE (term_gpa IS NOT NULL AND (term_gpa < 0 OR term_gpa > 4.00))
           OR (cumulative_gpa IS NOT NULL AND (cumulative_gpa < 0 OR cumulative_gpa > 4.00))
    ),
    1
);

SET @uq_student_term_ok := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_academic_terms'
          AND index_name = 'uq_student_term'
          AND non_unique = 0
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,academic_year_id,semester_id'
    ),
    1, 0
);

SET @uq_student_term_conflict := IF(
    @db_ready = 1
    AND EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'student_academic_terms'
          AND index_name = 'uq_student_term'
    )
    AND @uq_student_term_ok = 0,
    1, 0
);

SET @col_is_finalized := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'is_finalized'), 0);
SET @col_finalized_at := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_at'), 0);
SET @col_finalized_by := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_by_user_id'), 0);
SET @col_earned_hours := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'earned_hours'), 0);
SET @col_attempted_hours := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'attempted_hours'), 0);

SET @col_is_finalized_ok := IF(@col_is_finalized = 0, 1, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'is_finalized'
      AND data_type = 'tinyint' AND is_nullable = 'NO' AND column_default IN ('0', '0.0')
) = 1, 1, 0));
SET @col_finalized_at_ok := IF(@col_finalized_at = 0, 1, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_at'
      AND data_type = 'timestamp' AND is_nullable = 'YES'
) = 1, 1, 0));
SET @col_finalized_by_ok := IF(@col_finalized_by = 0, 1, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_by_user_id'
      AND data_type = 'int' AND is_nullable = 'YES'
) = 1, 1, 0));
SET @col_earned_hours_ok := IF(@col_earned_hours = 0, 1, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'earned_hours'
      AND data_type = 'int' AND is_nullable = 'NO'
) = 1, 1, 0));
SET @col_attempted_hours_ok := IF(@col_attempted_hours = 0, 1, IF((
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'attempted_hours'
      AND data_type = 'int' AND is_nullable = 'NO'
) = 1, 1, 0));

SET @spd_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions' AND table_type = 'BASE TABLE'), 0);
SET @spe_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events' AND table_type = 'BASE TABLE'), 0);
SET @sgd_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions' AND table_type = 'BASE TABLE'), 0);
SET @sge_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events' AND table_type = 'BASE TABLE'), 0);

SET @spd_columns_ok := IF(@spd_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'student_progression_decision_id' AS column_name UNION ALL SELECT 'student_id' UNION ALL SELECT 'academic_program_id'
        UNION ALL SELECT 'academic_year_id' UNION ALL SELECT 'from_academic_level_id' UNION ALL SELECT 'to_academic_level_id'
        UNION ALL SELECT 'status' UNION ALL SELECT 'decision_result' UNION ALL SELECT 'current_slot'
        UNION ALL SELECT 'term_gpa_snapshot' UNION ALL SELECT 'cumulative_gpa_snapshot'
        UNION ALL SELECT 'earned_hours_snapshot' UNION ALL SELECT 'attempted_hours_snapshot'
        UNION ALL SELECT 'failed_courses_count_snapshot' UNION ALL SELECT 'evidence_snapshot'
        UNION ALL SELECT 'submitted_by_user_id' UNION ALL SELECT 'submitted_at'
        UNION ALL SELECT 'reviewed_by_user_id' UNION ALL SELECT 'reviewed_at' UNION ALL SELECT 'review_notes'
        UNION ALL SELECT 'approved_at' UNION ALL SELECT 'materialized_at' UNION ALL SELECT 'superseded_at'
        UNION ALL SELECT 'created_at' UNION ALL SELECT 'updated_at'
    ) required
    LEFT JOIN information_schema.columns c
        ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'student_progression_decisions' AND c.column_name = required.column_name
    WHERE c.column_name IS NULL
) = 0, 1, 0));

SET @spe_columns_ok := IF(@spe_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'student_progression_event_id' AS column_name UNION ALL SELECT 'student_progression_decision_id'
        UNION ALL SELECT 'event_type' UNION ALL SELECT 'actor_user_id' UNION ALL SELECT 'from_status'
        UNION ALL SELECT 'to_status' UNION ALL SELECT 'notes' UNION ALL SELECT 'created_at'
    ) required
    LEFT JOIN information_schema.columns c
        ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'student_progression_events' AND c.column_name = required.column_name
    WHERE c.column_name IS NULL
) = 0, 1, 0));

SET @sgd_columns_ok := IF(@sgd_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'student_graduation_decision_id' AS column_name UNION ALL SELECT 'student_id'
        UNION ALL SELECT 'academic_program_id' UNION ALL SELECT 'current_academic_level_id'
        UNION ALL SELECT 'status' UNION ALL SELECT 'decision_result' UNION ALL SELECT 'current_slot'
        UNION ALL SELECT 'cumulative_gpa_snapshot' UNION ALL SELECT 'earned_hours_snapshot'
        UNION ALL SELECT 'required_hours_snapshot' UNION ALL SELECT 'eligibility_snapshot'
        UNION ALL SELECT 'submitted_by_user_id' UNION ALL SELECT 'submitted_at'
        UNION ALL SELECT 'reviewed_by_user_id' UNION ALL SELECT 'reviewed_at' UNION ALL SELECT 'review_notes'
        UNION ALL SELECT 'approved_at' UNION ALL SELECT 'materialized_at' UNION ALL SELECT 'superseded_at'
        UNION ALL SELECT 'created_at' UNION ALL SELECT 'updated_at'
    ) required
    LEFT JOIN information_schema.columns c
        ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'student_graduation_decisions' AND c.column_name = required.column_name
    WHERE c.column_name IS NULL
) = 0, 1, 0));

SET @sge_columns_ok := IF(@sge_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT 'student_graduation_event_id' AS column_name UNION ALL SELECT 'student_graduation_decision_id'
        UNION ALL SELECT 'event_type' UNION ALL SELECT 'actor_user_id' UNION ALL SELECT 'from_status'
        UNION ALL SELECT 'to_status' UNION ALL SELECT 'notes' UNION ALL SELECT 'created_at'
    ) required
    LEFT JOIN information_schema.columns c
        ON c.table_schema = 'alrowad_uni_rust' AND c.table_name = 'student_graduation_events' AND c.column_name = required.column_name
    WHERE c.column_name IS NULL
) = 0, 1, 0));

SET @spd_engine_ok := IF(@spd_exists = 0, 1, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions') = 'innodb', 1, 0));
SET @spe_engine_ok := IF(@spe_exists = 0, 1, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_events') = 'innodb', 1, 0));
SET @sgd_engine_ok := IF(@sgd_exists = 0, 1, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions') = 'innodb', 1, 0));
SET @sge_engine_ok := IF(@sge_exists = 0, 1, IF((SELECT LOWER(engine) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_events') = 'innodb', 1, 0));

SET @idx_spd_current_ok := IF(@spd_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions'
          AND index_name = 'uq_spd_current_slot' AND non_unique = 0
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,academic_year_id,current_slot'
    ) x
) = 1, 1, 0));
SET @idx_spd_status_ok := IF(@spd_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_progression_decisions'
          AND index_name = 'idx_spd_student_status' AND non_unique = 1
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,status'
    ) x
) = 1, 1, 0));
SET @idx_sgd_current_ok := IF(@sgd_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions'
          AND index_name = 'uq_sgd_current_slot' AND non_unique = 0
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,current_slot'
    ) x
) = 1, 1, 0));
SET @idx_sgd_status_ok := IF(@sgd_exists = 0, 1, IF((
    SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_graduation_decisions'
          AND index_name = 'idx_sgd_student_status' AND non_unique = 1
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'student_id,status'
    ) x
) = 1, 1, 0));

SET @perm_codes := '''academic_records.view'',''academic_records.finalize'',''academic_progression.view'',''academic_progression.review'',''graduation_decisions.view'',''graduation_decisions.review''';

SET @perm_view_records_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'academic_records.view'), 0);
SET @perm_finalize_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'academic_records.finalize'), 0);
SET @perm_prog_view_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'academic_progression.view'), 0);
SET @perm_prog_review_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'academic_progression.review'), 0);
SET @perm_grad_view_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'graduation_decisions.view'), 0);
SET @perm_grad_review_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'graduation_decisions.review'), 0);

SET @perm_module_ok := IF(
    @db_ready = 1,
    IF((
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code IN (
            'academic_records.view', 'academic_records.finalize',
            'academic_progression.view', 'academic_progression.review',
            'graduation_decisions.view', 'graduation_decisions.review'
        )
          AND NOT (p.is_active = 1 AND sm.module_code = 'students')
    ) = 0, 1, 0),
    0
);

SET @rbac_extra_grants := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code IN (
            'academic_records.view', 'academic_records.finalize',
            'academic_progression.view', 'academic_progression.review',
            'graduation_decisions.view', 'graduation_decisions.review'
        )
          AND r.role_code <> 'registration_officer'
    ),
    1
);

SET @terms_columns_state := CASE
    WHEN @col_is_finalized_ok = 1 AND @col_finalized_at_ok = 1 AND @col_finalized_by_ok = 1 AND @col_earned_hours_ok = 1 AND @col_attempted_hours_ok = 1
        AND (@col_is_finalized + @col_finalized_at + @col_finalized_by + @col_earned_hours + @col_attempted_hours) = 0 THEN 'ABSENT'
    WHEN @col_is_finalized_ok = 1 AND @col_finalized_at_ok = 1 AND @col_finalized_by_ok = 1 AND @col_earned_hours_ok = 1 AND @col_attempted_hours_ok = 1 THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;
SET @uq_term_state := CASE
    WHEN @uq_student_term_conflict = 1 THEN 'CONFLICT'
    WHEN @uq_student_term_ok = 1 THEN 'COMPATIBLE'
    ELSE 'ABSENT'
END;
SET @spd_state := CASE WHEN @spd_exists = 0 THEN 'ABSENT' WHEN @spd_columns_ok = 1 AND @spd_engine_ok = 1 AND @idx_spd_current_ok = 1 AND @idx_spd_status_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @spe_state := CASE WHEN @spe_exists = 0 THEN 'ABSENT' WHEN @spe_columns_ok = 1 AND @spe_engine_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @sgd_state := CASE WHEN @sgd_exists = 0 THEN 'ABSENT' WHEN @sgd_columns_ok = 1 AND @sgd_engine_ok = 1 AND @idx_sgd_current_ok = 1 AND @idx_sgd_status_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @sge_state := CASE WHEN @sge_exists = 0 THEN 'ABSENT' WHEN @sge_columns_ok = 1 AND @sge_engine_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_records_view_state := CASE WHEN @perm_view_records_exists = 0 THEN 'ABSENT' WHEN @perm_view_records_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_records_finalize_state := CASE WHEN @perm_finalize_exists = 0 THEN 'ABSENT' WHEN @perm_finalize_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_prog_view_state := CASE WHEN @perm_prog_view_exists = 0 THEN 'ABSENT' WHEN @perm_prog_view_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_prog_review_state := CASE WHEN @perm_prog_review_exists = 0 THEN 'ABSENT' WHEN @perm_prog_review_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_grad_view_state := CASE WHEN @perm_grad_view_exists = 0 THEN 'ABSENT' WHEN @perm_grad_view_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @perm_grad_review_state := CASE WHEN @perm_grad_review_exists = 0 THEN 'ABSENT' WHEN @perm_grad_review_exists = 1 AND @perm_module_ok = 1 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;
SET @rbac_extra_state := CASE WHEN @rbac_extra_grants = 0 THEN 'COMPATIBLE' ELSE 'CONFLICT' END;

SET @phase10_conflict := IF(
    @terms_columns_state = 'CONFLICT'
    OR @uq_term_state = 'CONFLICT'
    OR @spd_state = 'CONFLICT'
    OR @spe_state = 'CONFLICT'
    OR @sgd_state = 'CONFLICT'
    OR @sge_state = 'CONFLICT'
    OR @perm_records_view_state = 'CONFLICT'
    OR @perm_records_finalize_state = 'CONFLICT'
    OR @perm_prog_view_state = 'CONFLICT'
    OR @perm_prog_review_state = 'CONFLICT'
    OR @perm_grad_view_state = 'CONFLICT'
    OR @perm_grad_review_state = 'CONFLICT'
    OR @rbac_extra_state = 'CONFLICT',
    1, 0
);

SET @overall_ready := IF(
    @db_ready = 1
    AND @missing_required_columns = 0
    AND @registration_officer_active = 1
    AND @graduated_status_ok = 1
    AND @students_module_ok = 1
    AND @permission_code_unique_ok = 1
    AND @role_permissions_unique_ok = 1
    AND @legacy_term_duplicates = 0
    AND @legacy_term_null_identity = 0
    AND @legacy_invalid_levels = 0
    AND @legacy_malformed_gpa = 0
    AND @phase10_conflict = 0,
    1, 0
);

SET @apply_ready := @overall_ready;

SET @sql := IF(
    @apply_ready = 1 AND @col_is_finalized = 0,
    'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` ADD COLUMN `is_finalized` TINYINT(1) NOT NULL DEFAULT 0 AFTER `total_registered_hours`',
    'SELECT ''skip_is_finalized'' AS apply_step'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @apply_ready = 1 AND @col_earned_hours = 0,
    'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` ADD COLUMN `earned_hours` INT NOT NULL DEFAULT 0 AFTER `is_finalized`',
    'SELECT ''skip_earned_hours'' AS apply_step'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @apply_ready = 1 AND @col_attempted_hours = 0,
    'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` ADD COLUMN `attempted_hours` INT NOT NULL DEFAULT 0 AFTER `earned_hours`',
    'SELECT ''skip_attempted_hours'' AS apply_step'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @apply_ready = 1 AND @col_finalized_at = 0,
    'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` ADD COLUMN `finalized_at` TIMESTAMP NULL DEFAULT NULL AFTER `attempted_hours`',
    'SELECT ''skip_finalized_at'' AS apply_step'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @apply_ready = 1 AND @col_finalized_by = 0,
    'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` ADD COLUMN `finalized_by_user_id` INT NULL DEFAULT NULL AFTER `finalized_at`',
    'SELECT ''skip_finalized_by'' AS apply_step'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_sat_finalized_exists := IF(
    @apply_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND constraint_name = 'fk_sat_finalized_by' AND constraint_type = 'FOREIGN KEY'),
    1
);
SET @sql := IF(
    @apply_ready = 1 AND @fk_sat_finalized_exists = 0 AND EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_academic_terms' AND column_name = 'finalized_by_user_id'
    ),
    'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` ADD CONSTRAINT `fk_sat_finalized_by` FOREIGN KEY (`finalized_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`)',
    'SELECT ''skip_fk_sat_finalized_by'' AS apply_step'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @apply_ready = 1 AND @uq_student_term_ok = 0 AND @legacy_term_duplicates = 0 AND @uq_student_term_conflict = 0,
    'ALTER TABLE `alrowad_uni_rust`.`student_academic_terms` ADD UNIQUE KEY `uq_student_term` (`student_id`,`academic_year_id`,`semester_id`)',
    'SELECT ''skip_uq_student_term'' AS apply_step'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @apply_ready = 1,
    'CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`student_progression_decisions` (
        `student_progression_decision_id` INT NOT NULL AUTO_INCREMENT,
        `student_id` INT NOT NULL,
        `academic_program_id` INT NOT NULL,
        `academic_year_id` INT NOT NULL,
        `from_academic_level_id` INT NOT NULL,
        `to_academic_level_id` INT NULL,
        `status` VARCHAR(40) NOT NULL,
        `decision_result` VARCHAR(40) NULL,
        `current_slot` TINYINT NULL,
        `term_gpa_snapshot` DECIMAL(4,2) NULL,
        `cumulative_gpa_snapshot` DECIMAL(4,2) NULL,
        `earned_hours_snapshot` INT NOT NULL DEFAULT 0,
        `attempted_hours_snapshot` INT NOT NULL DEFAULT 0,
        `failed_courses_count_snapshot` INT NOT NULL DEFAULT 0,
        `evidence_snapshot` TEXT NULL,
        `submitted_by_user_id` INT NOT NULL,
        `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `reviewed_by_user_id` INT NULL,
        `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
        `review_notes` TEXT NULL,
        `approved_at` TIMESTAMP NULL DEFAULT NULL,
        `materialized_at` TIMESTAMP NULL DEFAULT NULL,
        `superseded_at` TIMESTAMP NULL DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`student_progression_decision_id`),
        UNIQUE KEY `uq_spd_current_slot` (`student_id`, `academic_year_id`, `current_slot`),
        KEY `idx_spd_student_status` (`student_id`, `status`),
        KEY `idx_spd_reviewer` (`reviewed_by_user_id`),
        CONSTRAINT `fk_spd_student` FOREIGN KEY (`student_id`) REFERENCES `alrowad_uni_rust`.`students` (`student_id`),
        CONSTRAINT `fk_spd_program` FOREIGN KEY (`academic_program_id`) REFERENCES `alrowad_uni_rust`.`academic_programs` (`academic_program_id`),
        CONSTRAINT `fk_spd_year` FOREIGN KEY (`academic_year_id`) REFERENCES `alrowad_uni_rust`.`academic_years` (`academic_year_id`),
        CONSTRAINT `fk_spd_from_level` FOREIGN KEY (`from_academic_level_id`) REFERENCES `alrowad_uni_rust`.`academic_levels` (`academic_level_id`),
        CONSTRAINT `fk_spd_to_level` FOREIGN KEY (`to_academic_level_id`) REFERENCES `alrowad_uni_rust`.`academic_levels` (`academic_level_id`),
        CONSTRAINT `fk_spd_submitted_by` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`),
        CONSTRAINT `fk_spd_reviewed_by` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''BLOCKED'' AS apply_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @apply_ready = 1,
    'CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`student_progression_events` (
        `student_progression_event_id` INT NOT NULL AUTO_INCREMENT,
        `student_progression_decision_id` INT NOT NULL,
        `event_type` VARCHAR(40) NOT NULL,
        `actor_user_id` INT NULL,
        `from_status` VARCHAR(40) NULL,
        `to_status` VARCHAR(40) NULL,
        `notes` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`student_progression_event_id`),
        KEY `idx_spe_decision` (`student_progression_decision_id`, `created_at`),
        KEY `idx_spe_actor` (`actor_user_id`),
        CONSTRAINT `fk_spe_decision` FOREIGN KEY (`student_progression_decision_id`)
            REFERENCES `alrowad_uni_rust`.`student_progression_decisions` (`student_progression_decision_id`),
        CONSTRAINT `fk_spe_actor` FOREIGN KEY (`actor_user_id`)
            REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''BLOCKED'' AS apply_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @apply_ready = 1,
    'CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`student_graduation_decisions` (
        `student_graduation_decision_id` INT NOT NULL AUTO_INCREMENT,
        `student_id` INT NOT NULL,
        `academic_program_id` INT NOT NULL,
        `current_academic_level_id` INT NOT NULL,
        `status` VARCHAR(40) NOT NULL,
        `decision_result` VARCHAR(40) NULL,
        `current_slot` TINYINT NULL,
        `cumulative_gpa_snapshot` DECIMAL(4,2) NULL,
        `earned_hours_snapshot` INT NOT NULL DEFAULT 0,
        `required_hours_snapshot` INT NOT NULL DEFAULT 0,
        `eligibility_snapshot` TEXT NULL,
        `submitted_by_user_id` INT NOT NULL,
        `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `reviewed_by_user_id` INT NULL,
        `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
        `review_notes` TEXT NULL,
        `approved_at` TIMESTAMP NULL DEFAULT NULL,
        `materialized_at` TIMESTAMP NULL DEFAULT NULL,
        `superseded_at` TIMESTAMP NULL DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`student_graduation_decision_id`),
        UNIQUE KEY `uq_sgd_current_slot` (`student_id`, `current_slot`),
        KEY `idx_sgd_student_status` (`student_id`, `status`),
        KEY `idx_sgd_reviewer` (`reviewed_by_user_id`),
        CONSTRAINT `fk_sgd_student` FOREIGN KEY (`student_id`) REFERENCES `alrowad_uni_rust`.`students` (`student_id`),
        CONSTRAINT `fk_sgd_program` FOREIGN KEY (`academic_program_id`) REFERENCES `alrowad_uni_rust`.`academic_programs` (`academic_program_id`),
        CONSTRAINT `fk_sgd_level` FOREIGN KEY (`current_academic_level_id`) REFERENCES `alrowad_uni_rust`.`academic_levels` (`academic_level_id`),
        CONSTRAINT `fk_sgd_submitted_by` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`),
        CONSTRAINT `fk_sgd_reviewed_by` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''BLOCKED'' AS apply_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @apply_ready = 1,
    'CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`student_graduation_events` (
        `student_graduation_event_id` INT NOT NULL AUTO_INCREMENT,
        `student_graduation_decision_id` INT NOT NULL,
        `event_type` VARCHAR(40) NOT NULL,
        `actor_user_id` INT NULL,
        `from_status` VARCHAR(40) NULL,
        `to_status` VARCHAR(40) NULL,
        `notes` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`student_graduation_event_id`),
        KEY `idx_sge_decision` (`student_graduation_decision_id`, `created_at`),
        KEY `idx_sge_actor` (`actor_user_id`),
        CONSTRAINT `fk_sge_decision` FOREIGN KEY (`student_graduation_decision_id`)
            REFERENCES `alrowad_uni_rust`.`student_graduation_decisions` (`student_graduation_decision_id`),
        CONSTRAINT `fk_sge_actor` FOREIGN KEY (`actor_user_id`)
            REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''BLOCKED'' AS apply_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

START TRANSACTION;

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id, permission_code, permission_name, description, is_active, created_at, updated_at
)
SELECT sm.module_id, codes.permission_code, codes.permission_name, codes.description, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
JOIN (
    SELECT 'academic_records.view' AS permission_code, 'View academic records' AS permission_name, 'View official student academic term snapshots.' AS description
    UNION ALL SELECT 'academic_records.finalize', 'Finalize academic records', 'Recalculate and finalize official student academic term snapshots.'
    UNION ALL SELECT 'academic_progression.view', 'View academic progression', 'View formal academic progression decisions and evidence.'
    UNION ALL SELECT 'academic_progression.review', 'Review academic progression', 'Submit, return, or approve formal academic progression decisions.'
    UNION ALL SELECT 'graduation_decisions.view', 'View graduation decisions', 'View formal graduation decisions and snapshots.'
    UNION ALL SELECT 'graduation_decisions.review', 'Review graduation decisions', 'Submit, return, or approve formal graduation decisions.'
) codes
WHERE @apply_ready = 1
  AND sm.module_code = 'students'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = codes.permission_code
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
CROSS JOIN `alrowad_uni_rust`.`permissions` p
WHERE @apply_ready = 1
  AND r.role_code = 'registration_officer'
  AND r.is_active = 1
  AND p.permission_code IN (
      'academic_records.view', 'academic_records.finalize',
      'academic_progression.view', 'academic_progression.review',
      'graduation_decisions.view', 'graduation_decisions.review'
  )
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

SET @rbac_post_extra := IF(
    @apply_ready = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code IN (
            'academic_records.view', 'academic_records.finalize',
            'academic_progression.view', 'academic_progression.review',
            'graduation_decisions.view', 'graduation_decisions.review'
        )
          AND r.role_code <> 'registration_officer'
    ),
    1
);

SET @rbac_post_officer := IF(
    @apply_ready = 1
    AND (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'registration_officer'
          AND r.is_active = 1
          AND p.permission_code IN (
              'academic_records.view', 'academic_records.finalize',
              'academic_progression.view', 'academic_progression.review',
              'graduation_decisions.view', 'graduation_decisions.review'
          )
          AND p.is_active = 1
    ) = 6,
    1, 0
);

SET @rbac_post_module := IF(
    @apply_ready = 1
    AND (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code IN (
            'academic_records.view', 'academic_records.finalize',
            'academic_progression.view', 'academic_progression.review',
            'graduation_decisions.view', 'graduation_decisions.review'
        )
          AND p.is_active = 1
          AND sm.module_code = 'students'
    ) = 6,
    1, 0
);

SET @rbac_post_ok := IF(@apply_ready = 1 AND @rbac_post_extra = 0 AND @rbac_post_officer = 1 AND @rbac_post_module = 1, 1, 0);

SET @sql := IF(
    @apply_ready = 1 AND @rbac_post_ok = 1,
    'COMMIT',
    IF(@apply_ready = 1, 'ROLLBACK', 'COMMIT')
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT
    'apply_complete' AS report_section,
    @apply_ready AS apply_ready,
    @rbac_post_ok AS rbac_post_ok,
    IF(@apply_ready = 1 AND @rbac_post_ok = 1, 'APPLIED', 'BLOCKED') AS apply_status,
    'Run 02_verify.sql now. Do not execute this file from application code.' AS next_step;
