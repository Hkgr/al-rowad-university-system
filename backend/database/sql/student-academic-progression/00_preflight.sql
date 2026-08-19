-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- SET user variables only; this file must not CREATE/INSERT/UPDATE/DELETE.
-- Do not use the DATABASE function, stored procedures, DELIMITER, or SIGNAL.
-- Compatibility predicates below must stay equivalent in 01_apply.sql and 02_verify.sql.
-- Phase 9 withdrawal tables are NOT required. Do not invent a student_affairs role.
-- Authority role is the existing registration_officer.
-- Canonical graduated status_code is graduated.
-- An existing intended NON-UNIQUE index that is UNIQUE is CONFLICT.
-- Do not grant Phase 10 academic mutation permissions to super_admin or vice_president.

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

SELECT 'required_infrastructure' AS check_name, IF(@missing_required_columns = 0, 'PASS', 'FAIL') AS result;
SELECT 'registration_officer_role' AS check_name, IF(@registration_officer_active = 1, 'PASS', 'FAIL') AS result;
SELECT 'graduated_student_status' AS check_name, IF(@graduated_status_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'students_module' AS check_name, IF(@students_module_ok = 1, 'PASS', 'FAIL') AS result;
SELECT 'legacy_term_duplicates' AS check_name, IF(@legacy_term_duplicates = 0, 'PASS', 'FAIL') AS result, @legacy_term_duplicates AS duplicate_groups;
SELECT 'legacy_term_null_identity' AS check_name, IF(@legacy_term_null_identity = 0, 'PASS', 'FAIL') AS result;
SELECT 'legacy_invalid_academic_levels' AS check_name, IF(@legacy_invalid_levels = 0, 'PASS', 'FAIL') AS result;
SELECT 'legacy_malformed_gpa' AS check_name, IF(@legacy_malformed_gpa = 0, 'PASS', 'FAIL') AS result;
SELECT 'student_academic_terms_new_columns' AS object_name, @terms_columns_state AS classification;
SELECT 'uq_student_term' AS object_name, @uq_term_state AS classification;
SELECT 'student_progression_decisions' AS object_name, @spd_state AS classification;
SELECT 'student_progression_events' AS object_name, @spe_state AS classification;
SELECT 'student_graduation_decisions' AS object_name, @sgd_state AS classification;
SELECT 'student_graduation_events' AS object_name, @sge_state AS classification;
SELECT 'academic_records.view' AS object_name, @perm_records_view_state AS classification;
SELECT 'academic_records.finalize' AS object_name, @perm_records_finalize_state AS classification;
SELECT 'academic_progression.view' AS object_name, @perm_prog_view_state AS classification;
SELECT 'academic_progression.review' AS object_name, @perm_prog_review_state AS classification;
SELECT 'graduation_decisions.view' AS object_name, @perm_grad_view_state AS classification;
SELECT 'graduation_decisions.review' AS object_name, @perm_grad_review_state AS classification;
SELECT 'phase10_rbac_extra_grants' AS object_name, @rbac_extra_state AS classification;
SELECT 'OVERALL' AS report_section, IF(@overall_ready = 1, 'READY', 'BLOCKED') AS result;
