-- Manual and idempotent. Fail-closed.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- DDL (ALTER TABLE) commits implicitly in MariaDB; each object is added
-- independently so a retry after partial compatible DDL remains recoverable.
-- Independently recomputes the same critical safety conditions as 00_preflight.sql,
-- including Phase 7 closure tables and a READ-ONLY Teaching Assignment RBAC guard.
-- Does not INSERT/DELETE permissions or role_permissions.
-- No RBAC inserts. No data backfill beyond the column DEFAULT 'assign'.
-- Do not use stored procedures, DELIMITER, or SIGNAL.
--
-- Does NOT:
--   modify course_offering_instructors rows
--   modify teaching assignment business rows except via DEFAULT on ADD COLUMN
--   create users / user_roles / user_access_scopes
--   insert or delete permissions / role_permissions
--   drop original Teaching Assignment tables

SET @apply_status := 'BLOCKED';

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
            SELECT 'teaching_assignment_requests' AS table_name, 'teaching_assignment_request_id' AS column_name
            UNION ALL SELECT 'teaching_assignment_requests', 'course_offering_id'
            UNION ALL SELECT 'teaching_assignment_requests', 'faculty_member_id'
            UNION ALL SELECT 'teaching_assignment_requests', 'instructor_role'
            UNION ALL SELECT 'teaching_assignment_requests', 'status'
            UNION ALL SELECT 'teaching_assignment_requests', 'current_slot'
            UNION ALL SELECT 'teaching_assignment_reviews', 'teaching_assignment_review_id'
            UNION ALL SELECT 'teaching_assignment_events', 'teaching_assignment_event_id'
            UNION ALL SELECT 'course_offering_instructors', 'course_offering_instructor_id'
            UNION ALL SELECT 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'course_offerings', 'status'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'role_permissions', 'role_id'
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

SET @uq_current_slot := IF(
    @structure_ok = 1 AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'uq_tar_current_slot'
          AND non_unique = 0
    ) <=> 'course_offering_id,instructor_role,current_slot',
    1, 0
);

SET @phase7_ok := IF(
    @db_ready = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_requests' AND table_type = 'BASE TABLE') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_reviews' AND table_type = 'BASE TABLE') = 1
    AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_events' AND table_type = 'BASE TABLE') = 1,
    1, 0
);

SET @action_type_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), 0);
SET @action_reason_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason'), 0);
SET @target_slot_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id'), 0);

SET @action_type_state := CASE
    WHEN @action_type_exists = 0 THEN 'ABSENT'
    WHEN @action_type_exists = 1
     AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type') = 'varchar'
     AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type') = 'NO'
     AND (SELECT IFNULL(character_maximum_length, 0) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type') >= 16
     AND TRIM(BOTH '''' FROM IFNULL((SELECT column_default FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), '')) = 'assign'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @action_reason_state := CASE
    WHEN @action_reason_exists = 0 THEN 'ABSENT'
    WHEN @action_reason_exists = 1
     AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason') IN ('text', 'varchar', 'mediumtext', 'longtext')
     AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason') = 'YES'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @target_slot_state := CASE
    WHEN @target_slot_exists = 0 THEN 'ABSENT'
    WHEN @target_slot_exists = 1
     AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id') = 'int'
     AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id') = 'YES'
     AND (SELECT LOWER(column_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id') NOT LIKE '%unsigned%'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @fk_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND constraint_name = 'fk_tar_target_instructor'
       AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @fk_state := CASE
    WHEN @fk_exists = 0 THEN 'ABSENT'
    WHEN @fk_exists = 1
     AND (SELECT COUNT(*) FROM information_schema.key_column_usage
          WHERE table_schema = 'alrowad_uni_rust'
            AND table_name = 'teaching_assignment_requests'
            AND constraint_name = 'fk_tar_target_instructor'
            AND column_name = 'target_course_offering_instructor_id'
            AND referenced_table_name = 'course_offering_instructors'
            AND referenced_column_name = 'course_offering_instructor_id') = 1
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @idx_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND index_name = 'idx_tar_action_status'),
    0
);
SET @idx_non_unique := IF(
    @idx_exists = 1
    AND (
        SELECT MIN(non_unique)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'idx_tar_action_status'
    ) = 1,
    1, 0
);
SET @idx_state := CASE
    WHEN @idx_exists = 0 THEN 'ABSENT'
    WHEN @idx_exists = 1
     AND @idx_non_unique = 1
     AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
          FROM information_schema.statistics
          WHERE table_schema = 'alrowad_uni_rust'
            AND table_name = 'teaching_assignment_requests'
            AND index_name = 'idx_tar_action_status') <=> 'action_type,status'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @rbac_matrix_conflict := IF(
    @structure_ok = 1,
    (
        SELECT IF(COUNT(*) > 0, 1, 0)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE p.permission_code IN (
            'teaching_assignments.review_scientific',
            'teaching_assignments.review_administrative'
        )
          AND NOT (
              (
                  p.permission_code = 'teaching_assignments.review_scientific'
                  AND r.role_code = 'vice_president_scientific'
              )
              OR (
                  p.permission_code = 'teaching_assignments.review_administrative'
                  AND r.role_code = 'vice_president_administrative'
              )
          )
    ),
    0
);

SET @rbac_ok := IF(
    @structure_ok = 1
    AND @rbac_matrix_conflict = 0
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.manage' AND is_active = 1)
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_scientific' AND is_active = 1)
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_administrative' AND is_active = 1)
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'dean' AND p.permission_code = 'teaching_assignments.manage'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'teaching_assignments.review_scientific'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'teaching_assignments.review_administrative'
    ),
    1, 0
);

SET @phase8_conflict := IF(
    @action_type_state = 'CONFLICT'
    OR @action_reason_state = 'CONFLICT'
    OR @target_slot_state = 'CONFLICT'
    OR @fk_state = 'CONFLICT'
    OR @idx_state = 'CONFLICT',
    1, 0
);

SET @apply_ready := IF(
    @structure_ok = 1
    AND @uq_current_slot = 1
    AND @phase7_ok = 1
    AND @rbac_ok = 1
    AND @rbac_matrix_conflict = 0
    AND @phase8_conflict = 0,
    1, 0
);

SELECT 'APPLY_GUARDS' AS report_section,
       @apply_ready AS apply_ready,
       @phase8_conflict AS phase8_conflict,
       @phase7_ok AS phase7_ok,
       @uq_current_slot AS uq_tar_current_slot,
       @rbac_ok AS rbac_ok,
       @rbac_matrix_conflict AS rbac_matrix_conflict,
       @action_type_state AS action_type_state,
       @action_reason_state AS action_reason_state,
       @target_slot_state AS target_slot_state,
       @fk_state AS fk_state,
       @idx_state AS idx_state;

SET @sql := IF(
    @apply_ready = 1 AND @action_type_state = 'ABSENT',
    'ALTER TABLE `alrowad_uni_rust`.`teaching_assignment_requests`
        ADD COLUMN `action_type` VARCHAR(16) NOT NULL DEFAULT ''assign''
        COMMENT ''[phase8-teaching-assignment-lifecycle]''',
    'SELECT ''SKIPPED_ACTION_TYPE'' AS apply_result'
);
PREPARE phase8_action_type_stmt FROM @sql;
EXECUTE phase8_action_type_stmt;
DEALLOCATE PREPARE phase8_action_type_stmt;

SET @sql := IF(
    @apply_ready = 1 AND @action_reason_state = 'ABSENT',
    'ALTER TABLE `alrowad_uni_rust`.`teaching_assignment_requests`
        ADD COLUMN `action_reason` TEXT NULL
        COMMENT ''[phase8-teaching-assignment-lifecycle]''',
    'SELECT ''SKIPPED_ACTION_REASON'' AS apply_result'
);
PREPARE phase8_action_reason_stmt FROM @sql;
EXECUTE phase8_action_reason_stmt;
DEALLOCATE PREPARE phase8_action_reason_stmt;

SET @sql := IF(
    @apply_ready = 1 AND @target_slot_state = 'ABSENT',
    'ALTER TABLE `alrowad_uni_rust`.`teaching_assignment_requests`
        ADD COLUMN `target_course_offering_instructor_id` INT NULL
        COMMENT ''[phase8-teaching-assignment-lifecycle]''',
    'SELECT ''SKIPPED_TARGET_SLOT'' AS apply_result'
);
PREPARE phase8_target_slot_stmt FROM @sql;
EXECUTE phase8_target_slot_stmt;
DEALLOCATE PREPARE phase8_target_slot_stmt;

SET @action_type_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), 0);
SET @idx_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND index_name = 'idx_tar_action_status'),
    0
);

SET @sql := IF(
    @apply_ready = 1 AND @idx_exists = 0 AND @action_type_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`teaching_assignment_requests`
        ADD INDEX `idx_tar_action_status` (`action_type`, `status`)',
    'SELECT ''SKIPPED_IDX_ACTION_STATUS'' AS apply_result'
);
PREPARE phase8_idx_stmt FROM @sql;
EXECUTE phase8_idx_stmt;
DEALLOCATE PREPARE phase8_idx_stmt;

SET @target_slot_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id'), 0);
SET @fk_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND constraint_name = 'fk_tar_target_instructor'
       AND constraint_type = 'FOREIGN KEY'),
    0
);

SET @sql := IF(
    @apply_ready = 1 AND @fk_exists = 0 AND @target_slot_exists = 1,
    'ALTER TABLE `alrowad_uni_rust`.`teaching_assignment_requests`
        ADD CONSTRAINT `fk_tar_target_instructor`
        FOREIGN KEY (`target_course_offering_instructor_id`)
        REFERENCES `alrowad_uni_rust`.`course_offering_instructors` (`course_offering_instructor_id`)',
    'SELECT ''SKIPPED_FK_TARGET'' AS apply_result'
);
PREPARE phase8_fk_stmt FROM @sql;
EXECUTE phase8_fk_stmt;
DEALLOCATE PREPARE phase8_fk_stmt;

SET @action_type_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), 0);
SET @action_reason_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason'), 0);
SET @target_slot_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id'), 0);
SET @fk_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND constraint_name = 'fk_tar_target_instructor'
       AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @idx_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND index_name = 'idx_tar_action_status'),
    0
);

SET @idx_non_unique := IF(
    @idx_exists = 1
    AND (
        SELECT MIN(non_unique)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'idx_tar_action_status'
    ) = 1,
    1, 0
);

SET @action_type_compat := IF(
    @action_type_exists = 1
    AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type') = 'varchar'
    AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type') = 'NO'
    AND TRIM(BOTH '''' FROM IFNULL((SELECT column_default FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), '')) = 'assign',
    1, 0
);
SET @action_reason_compat := IF(
    @action_reason_exists = 1
    AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason') = 'YES',
    1, 0
);
SET @target_slot_compat := IF(
    @target_slot_exists = 1
    AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id') = 'YES',
    1, 0
);

SET @apply_status := IF(
    @apply_ready = 1
    AND @action_type_compat = 1
    AND @action_reason_compat = 1
    AND @target_slot_compat = 1
    AND @fk_exists = 1
    AND @idx_exists = 1
    AND @idx_non_unique = 1,
    'APPLIED',
    'BLOCKED'
);

SELECT 'APPLY_RESULT' AS report_section,
       @apply_status AS apply_status,
       @action_type_exists AS action_type_exists,
       @action_reason_exists AS action_reason_exists,
       @target_slot_exists AS target_slot_exists,
       @fk_exists AS fk_exists,
       @idx_exists AS idx_exists,
       @idx_non_unique AS idx_non_unique,
       @rbac_ok AS rbac_ok;
