-- Manual and idempotent. Fail-closed.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- DDL commits implicitly in MariaDB; table CREATE statements are not wrapped
-- in a transaction. RBAC DML is transactional.
-- Do not use stored procedures, DELIMITER, or SIGNAL.
-- Independently recomputes the same critical safety conditions as 00_preflight.sql.
--
-- Does NOT:
--   modify course_offering_instructors rows
--   create users, user_roles, or user_access_scopes
--   create fake workflow requests or approvals
--   modify organizational units
--   grant review permissions to generic vice_president

SET @apply_ready := 0;
SET @phase4_complete := 0;
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
            SELECT 'course_offering_instructors' AS table_name, 'course_offering_id' AS column_name
            UNION ALL SELECT 'course_offering_instructors', 'instructor_role'
            UNION ALL SELECT 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'faculty_members', 'faculty_member_id'
            UNION ALL SELECT 'users', 'user_id'
            UNION ALL SELECT 'roles', 'role_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'permissions', 'permission_id'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'permissions', 'description'
            UNION ALL SELECT 'role_permissions', 'role_id'
            UNION ALL SELECT 'role_permissions', 'permission_id'
            UNION ALL SELECT 'system_modules', 'module_id'
            UNION ALL SELECT 'system_modules', 'module_code'
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

SET @requests_rows := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests'), 0);
SET @reviews_rows := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_reviews'), 0);
SET @events_rows := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_events'), 0);

SET @requests_expected_cols := IF(
    @requests_rows = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'teaching_assignment_request_id' AS column_name
            UNION ALL SELECT 'course_offering_id'
            UNION ALL SELECT 'faculty_member_id'
            UNION ALL SELECT 'instructor_role'
            UNION ALL SELECT 'status'
            UNION ALL SELECT 'submission_version'
            UNION ALL SELECT 'current_slot'
            UNION ALL SELECT 'requested_by_user_id'
            UNION ALL SELECT 'submitted_at'
            UNION ALL SELECT 'approved_at'
            UNION ALL SELECT 'superseded_at'
            UNION ALL SELECT 'superseded_by_request_id'
            UNION ALL SELECT 'created_at'
            UNION ALL SELECT 'updated_at'
        ) expected
        JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = 'teaching_assignment_requests'
           AND existing.column_name = expected.column_name
    ),
    0
);
SET @reviews_expected_cols := IF(
    @reviews_rows = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'teaching_assignment_review_id' AS column_name
            UNION ALL SELECT 'teaching_assignment_request_id'
            UNION ALL SELECT 'review_authority'
            UNION ALL SELECT 'status'
            UNION ALL SELECT 'reviewed_by_user_id'
            UNION ALL SELECT 'reviewed_at'
            UNION ALL SELECT 'reason'
            UNION ALL SELECT 'created_at'
            UNION ALL SELECT 'updated_at'
        ) expected
        JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = 'teaching_assignment_reviews'
           AND existing.column_name = expected.column_name
    ),
    0
);
SET @events_expected_cols := IF(
    @events_rows = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'teaching_assignment_event_id' AS column_name
            UNION ALL SELECT 'teaching_assignment_request_id'
            UNION ALL SELECT 'event_type'
            UNION ALL SELECT 'actor_user_id'
            UNION ALL SELECT 'submission_version'
            UNION ALL SELECT 'notes'
            UNION ALL SELECT 'created_at'
        ) expected
        JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = 'teaching_assignment_events'
           AND existing.column_name = expected.column_name
    ),
    0
);

SET @requests_unique_ok := IF(
    @requests_rows = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'uq_tar_current_slot'
          AND non_unique = 0
          AND (
              (seq_in_index = 1 AND column_name = 'course_offering_id')
           OR (seq_in_index = 2 AND column_name = 'instructor_role')
           OR (seq_in_index = 3 AND column_name = 'current_slot')
          )
    ) = 3,
    0
);
SET @reviews_unique_ok := IF(
    @reviews_rows = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_reviews'
          AND index_name = 'uq_tarv_request_authority'
          AND non_unique = 0
          AND (
              (seq_in_index = 1 AND column_name = 'teaching_assignment_request_id')
           OR (seq_in_index = 2 AND column_name = 'review_authority')
          )
    ) = 2,
    0
);

SET @requests_state := IF(@requests_rows = 0, 'ABSENT', IF(@requests_expected_cols = 14 AND @requests_unique_ok = 1, 'COMPATIBLE', 'CONFLICT'));
SET @reviews_state := IF(@reviews_rows = 0, 'ABSENT', IF(@reviews_expected_cols = 9 AND @reviews_unique_ok = 1, 'COMPATIBLE', 'CONFLICT'));
SET @events_state := IF(@events_rows = 0, 'ABSENT', IF(@events_expected_cols = 7, 'COMPATIBLE', 'CONFLICT'));

SET @dean_role_exists := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'dean' AND is_active = 1), 0);
SET @sci_role_exists := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_scientific' AND is_active = 1), 0);
SET @adm_role_exists := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative' AND is_active = 1), 0);
SET @phase3_sci_perm := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'vice_presidency.scientific.access' AND is_active = 1), 0);
SET @phase3_adm_perm := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'vice_presidency.administrative.access' AND is_active = 1), 0);
SET @hr_module_ok := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'hr' AND is_active = 1), 0);
SET @offering_identity_index := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offerings'
          AND index_name = 'uq_course_offering_program_term'
          AND non_unique = 0
    ) = 4,
    0
);
SET @coi_unique_ok := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_instructors'
          AND index_name = 'uq_course_offering_role'
          AND non_unique = 0
    ) = 2,
    0
);
SET @engines_ok := IF(
    @structure_ok = 1
    AND (
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name IN ('users', 'faculty_members', 'course_offerings', 'course_offering_instructors', 'roles', 'permissions', 'role_permissions')
          AND engine = 'InnoDB'
    ) = 7,
    1,
    0
);

SET @view_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.view'), 0);
SET @manage_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.manage'), 0);
SET @sci_review_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_scientific'), 0);
SET @adm_review_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_administrative'), 0);

SET @view_perm_compatible := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'teaching_assignments.view' AND p.is_active = 1 AND sm.module_code = 'hr'),
    0
);
SET @manage_perm_compatible := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'teaching_assignments.manage' AND p.is_active = 1 AND sm.module_code = 'hr'),
    0
);
SET @sci_review_perm_compatible := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'teaching_assignments.review_scientific' AND p.is_active = 1 AND sm.module_code = 'hr'),
    0
);
SET @adm_review_perm_compatible := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id WHERE p.permission_code = 'teaching_assignments.review_administrative' AND p.is_active = 1 AND sm.module_code = 'hr'),
    0
);

SET @view_perm_state := IF(@view_perm_rows = 0, 'ABSENT', IF(@view_perm_rows = 1 AND @view_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @manage_perm_state := IF(@manage_perm_rows = 0, 'ABSENT', IF(@manage_perm_rows = 1 AND @manage_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @sci_review_perm_state := IF(@sci_review_perm_rows = 0, 'ABSENT', IF(@sci_review_perm_rows = 1 AND @sci_review_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @adm_review_perm_state := IF(@adm_review_perm_rows = 0, 'ABSENT', IF(@adm_review_perm_rows = 1 AND @adm_review_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));

SET @permissions_code_unique := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'permissions' AND column_name = 'permission_code' AND non_unique = 0),
    0
);

SET @apply_ready := IF(
    @db_ready = 1
    AND @missing_required_columns = 0
    AND @requests_state IN ('ABSENT', 'COMPATIBLE')
    AND @reviews_state IN ('ABSENT', 'COMPATIBLE')
    AND @events_state IN ('ABSENT', 'COMPATIBLE')
    AND @view_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @manage_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @sci_review_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @adm_review_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @dean_role_exists = 1
    AND @sci_role_exists = 1
    AND @adm_role_exists = 1
    AND @phase3_sci_perm = 1
    AND @phase3_adm_perm = 1
    AND @offering_identity_index = 1
    AND @coi_unique_ok = 1
    AND @engines_ok = 1
    AND @hr_module_ok = 1
    AND @permissions_code_unique > 0,
    1,
    0
);

SELECT 'APPLY_GUARD' AS report_section,
       IF(@apply_ready = 1, 'READY', 'BLOCKED') AS result,
       @requests_state AS requests_state,
       @reviews_state AS reviews_state,
       @events_state AS events_state;

SET @sql := IF(
    @apply_ready = 1 AND @requests_state = 'ABSENT',
    'CREATE TABLE `alrowad_uni_rust`.`teaching_assignment_requests` (
        `teaching_assignment_request_id` INT NOT NULL AUTO_INCREMENT,
        `course_offering_id` INT NOT NULL,
        `faculty_member_id` INT NOT NULL,
        `instructor_role` ENUM(''theoretical'',''practical'') NOT NULL,
        `status` VARCHAR(32) NOT NULL,
        `submission_version` INT NOT NULL DEFAULT 1,
        `current_slot` TINYINT NULL,
        `requested_by_user_id` INT NOT NULL,
        `submitted_at` TIMESTAMP NULL,
        `approved_at` TIMESTAMP NULL,
        `superseded_at` TIMESTAMP NULL,
        `superseded_by_request_id` INT NULL,
        `created_at` TIMESTAMP NULL,
        `updated_at` TIMESTAMP NULL,
        PRIMARY KEY (`teaching_assignment_request_id`),
        UNIQUE KEY `uq_tar_current_slot` (`course_offering_id`, `instructor_role`, `current_slot`),
        KEY `idx_tar_status` (`status`),
        KEY `idx_tar_faculty_member` (`faculty_member_id`),
        KEY `idx_tar_requested_by` (`requested_by_user_id`),
        KEY `idx_tar_submitted_at` (`submitted_at`),
        CONSTRAINT `fk_tar_course_offering` FOREIGN KEY (`course_offering_id`) REFERENCES `alrowad_uni_rust`.`course_offerings` (`course_offering_id`),
        CONSTRAINT `fk_tar_faculty_member` FOREIGN KEY (`faculty_member_id`) REFERENCES `alrowad_uni_rust`.`faculty_members` (`faculty_member_id`),
        CONSTRAINT `fk_tar_requested_by` FOREIGN KEY (`requested_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`),
        CONSTRAINT `fk_tar_superseded_by` FOREIGN KEY (`superseded_by_request_id`) REFERENCES `alrowad_uni_rust`.`teaching_assignment_requests` (`teaching_assignment_request_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''[phase4-teaching-assignment-workflow]''',
    'SELECT ''SKIPPED_REQUESTS'' AS apply_result'
);
PREPARE phase4_tar_stmt FROM @sql;
EXECUTE phase4_tar_stmt;
DEALLOCATE PREPARE phase4_tar_stmt;

SET @sql := IF(
    @apply_ready = 1 AND @reviews_state = 'ABSENT',
    'CREATE TABLE `alrowad_uni_rust`.`teaching_assignment_reviews` (
        `teaching_assignment_review_id` INT NOT NULL AUTO_INCREMENT,
        `teaching_assignment_request_id` INT NOT NULL,
        `review_authority` ENUM(''scientific'',''administrative'') NOT NULL,
        `status` VARCHAR(32) NOT NULL,
        `reviewed_by_user_id` INT NULL,
        `reviewed_at` TIMESTAMP NULL,
        `reason` TEXT NULL,
        `created_at` TIMESTAMP NULL,
        `updated_at` TIMESTAMP NULL,
        PRIMARY KEY (`teaching_assignment_review_id`),
        UNIQUE KEY `uq_tarv_request_authority` (`teaching_assignment_request_id`, `review_authority`),
        KEY `idx_tarv_authority_status` (`review_authority`, `status`),
        CONSTRAINT `fk_tarv_request` FOREIGN KEY (`teaching_assignment_request_id`) REFERENCES `alrowad_uni_rust`.`teaching_assignment_requests` (`teaching_assignment_request_id`),
        CONSTRAINT `fk_tarv_reviewer` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''[phase4-teaching-assignment-workflow]''',
    'SELECT ''SKIPPED_REVIEWS'' AS apply_result'
);
PREPARE phase4_tarv_stmt FROM @sql;
EXECUTE phase4_tarv_stmt;
DEALLOCATE PREPARE phase4_tarv_stmt;

SET @sql := IF(
    @apply_ready = 1 AND @events_state = 'ABSENT',
    'CREATE TABLE `alrowad_uni_rust`.`teaching_assignment_events` (
        `teaching_assignment_event_id` INT NOT NULL AUTO_INCREMENT,
        `teaching_assignment_request_id` INT NOT NULL,
        `event_type` VARCHAR(64) NOT NULL,
        `actor_user_id` INT NULL,
        `submission_version` INT NULL,
        `notes` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`teaching_assignment_event_id`),
        KEY `idx_tae_request_created` (`teaching_assignment_request_id`, `created_at`),
        CONSTRAINT `fk_tae_request` FOREIGN KEY (`teaching_assignment_request_id`) REFERENCES `alrowad_uni_rust`.`teaching_assignment_requests` (`teaching_assignment_request_id`),
        CONSTRAINT `fk_tae_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''[phase4-teaching-assignment-workflow]''',
    'SELECT ''SKIPPED_EVENTS'' AS apply_result'
);
PREPARE phase4_tae_stmt FROM @sql;
EXECUTE phase4_tae_stmt;
DEALLOCATE PREPARE phase4_tae_stmt;

SET @view_existed := @view_perm_rows;
SET @manage_existed := @manage_perm_rows;
SET @sci_review_existed := @sci_review_perm_rows;
SET @adm_review_existed := @adm_review_perm_rows;

START TRANSACTION;

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id, permission_code, permission_name, description, is_active, created_at, updated_at
)
SELECT sm.module_id, 'teaching_assignments.view', 'View teaching assignment workflow',
       'Read teaching-assignment workflow queues and details [phase4-teaching-assignment-workflow]',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @view_perm_state = 'ABSENT'
  AND sm.module_code = 'hr'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'teaching_assignments.view'
  );

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id, permission_code, permission_name, description, is_active, created_at, updated_at
)
SELECT sm.module_id, 'teaching_assignments.manage', 'Manage teaching assignment proposals',
       'Dean-side create, resubmit, and material replacement of teaching assignments [phase4-teaching-assignment-workflow]',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @manage_perm_state = 'ABSENT'
  AND sm.module_code = 'hr'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'teaching_assignments.manage'
  );

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id, permission_code, permission_name, description, is_active, created_at, updated_at
)
SELECT sm.module_id, 'teaching_assignments.review_scientific', 'Scientific teaching assignment review',
       'Scientific VP approve or return teaching-assignment requests [phase4-teaching-assignment-workflow]',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @sci_review_perm_state = 'ABSENT'
  AND sm.module_code = 'hr'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'teaching_assignments.review_scientific'
  );

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id, permission_code, permission_name, description, is_active, created_at, updated_at
)
SELECT sm.module_id, 'teaching_assignments.review_administrative', 'Administrative teaching assignment review',
       'Administrative VP approve or return teaching-assignment requests [phase4-teaching-assignment-workflow]',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @adm_review_perm_state = 'ABSENT'
  AND sm.module_code = 'hr'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'teaching_assignments.review_administrative'
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = 'teaching_assignments.view'
WHERE @apply_ready = 1
  AND r.role_code IN ('dean', 'vice_president_scientific', 'vice_president_administrative')
  AND r.is_active = 1
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = 'teaching_assignments.manage'
WHERE @apply_ready = 1
  AND r.role_code = 'dean'
  AND r.is_active = 1
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = 'teaching_assignments.review_scientific'
WHERE @apply_ready = 1
  AND r.role_code = 'vice_president_scientific'
  AND r.is_active = 1
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = 'teaching_assignments.review_administrative'
WHERE @apply_ready = 1
  AND r.role_code = 'vice_president_administrative'
  AND r.is_active = 1
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

SET @phase4_complete := IF(
    @apply_ready = 1
    AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests')
    AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_reviews')
    AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_events')
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.view' AND is_active = 1) = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.manage' AND is_active = 1) = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_scientific' AND is_active = 1) = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_administrative' AND is_active = 1) = 1
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
    )
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'teaching_assignments.review_administrative'
    )
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'teaching_assignments.review_scientific'
    )
    AND NOT EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president'
          AND p.permission_code IN ('teaching_assignments.review_scientific', 'teaching_assignments.review_administrative')
    ),
    1,
    0
);

DELETE rp
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @phase4_complete = 0
  AND p.permission_code IN (
      'teaching_assignments.view',
      'teaching_assignments.manage',
      'teaching_assignments.review_scientific',
      'teaching_assignments.review_administrative'
  )
  AND COALESCE(p.description, '') LIKE '%[phase4-teaching-assignment-workflow]%';

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @phase4_complete = 0
  AND @view_existed = 0
  AND permission_code = 'teaching_assignments.view'
  AND COALESCE(description, '') LIKE '%[phase4-teaching-assignment-workflow]%';

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @phase4_complete = 0
  AND @manage_existed = 0
  AND permission_code = 'teaching_assignments.manage'
  AND COALESCE(description, '') LIKE '%[phase4-teaching-assignment-workflow]%';

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @phase4_complete = 0
  AND @sci_review_existed = 0
  AND permission_code = 'teaching_assignments.review_scientific'
  AND COALESCE(description, '') LIKE '%[phase4-teaching-assignment-workflow]%';

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @phase4_complete = 0
  AND @adm_review_existed = 0
  AND permission_code = 'teaching_assignments.review_administrative'
  AND COALESCE(description, '') LIKE '%[phase4-teaching-assignment-workflow]%';

COMMIT;

SET @apply_status := IF(
    @apply_ready = 0,
    'BLOCKED',
    IF(@phase4_complete = 1, 'APPLIED', 'BLOCKED_INCOMPLETE')
);

SELECT @apply_status AS apply_status,
       @apply_ready AS apply_ready,
       @phase4_complete AS phase4_complete,
       @requests_state AS requests_state,
       @reviews_state AS reviews_state,
       @events_state AS events_state,
       @view_perm_state AS view_perm_state,
       @manage_perm_state AS manage_perm_state,
       @sci_review_perm_state AS sci_review_perm_state,
       @adm_review_perm_state AS adm_review_perm_state;
