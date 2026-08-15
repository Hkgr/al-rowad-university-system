-- Manual and idempotent. Fail-closed: writes run only when @apply_ready = 1.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- DDL commits implicitly in MariaDB; do not wrap this file in a transaction.
-- Do not use stored procedures, DELIMITER, or SIGNAL.
-- Do NOT create a new academic_advisor role. Reuse the existing one.
-- Do NOT grant registration.manage to dean, academic_advisor, or student.

SET @apply_ready := 0;

SET @missing_apply_columns := (
    SELECT COUNT(*)
    FROM (
        SELECT 'students' AS table_name, 'student_id' AS column_name
        UNION ALL SELECT 'academic_years', 'academic_year_id'
        UNION ALL SELECT 'semesters', 'semester_id'
        UNION ALL SELECT 'users', 'user_id'
        UNION ALL SELECT 'course_offerings', 'course_offering_id'
        UNION ALL SELECT 'student_course_registrations', 'student_course_registration_id'
        UNION ALL SELECT 'roles', 'role_id'
        UNION ALL SELECT 'roles', 'role_code'
        UNION ALL SELECT 'roles', 'is_active'
        UNION ALL SELECT 'system_modules', 'module_id'
        UNION ALL SELECT 'system_modules', 'module_code'
        UNION ALL SELECT 'system_modules', 'is_active'
        UNION ALL SELECT 'permissions', 'permission_id'
        UNION ALL SELECT 'permissions', 'module_id'
        UNION ALL SELECT 'permissions', 'permission_code'
        UNION ALL SELECT 'permissions', 'permission_name'
        UNION ALL SELECT 'permissions', 'is_active'
        UNION ALL SELECT 'permissions', 'created_at'
        UNION ALL SELECT 'permissions', 'updated_at'
        UNION ALL SELECT 'role_permissions', 'role_id'
        UNION ALL SELECT 'role_permissions', 'permission_id'
        UNION ALL SELECT 'role_permissions', 'granted_at'
    ) required
    LEFT JOIN information_schema.columns c
        ON c.table_schema = 'alrowad_uni_rust'
       AND c.table_name = required.table_name
       AND c.column_name = required.column_name
    WHERE c.column_name IS NULL
);

SET @apply_ready := CASE
    WHEN IFNULL(@missing_apply_columns, 1) > 0 THEN 0
    WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'academic_advisor') <> 1 THEN 0
    WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'academic_advisor' AND is_active = 1) <> 1 THEN 0
    WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'dean' AND is_active = 1) <> 1 THEN 0
    WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'student' AND is_active = 1) < 1 THEN 0
    WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'registration' AND is_active = 1) <> 1 THEN 0
    WHEN EXISTS (
        SELECT permission_code
        FROM `alrowad_uni_rust`.`permissions`
        WHERE permission_code IN (
            'registration_requests.view',
            'registration_requests.review',
            'registration.view',
            'registration.manage'
        )
        GROUP BY permission_code
        HAVING COUNT(*) > 1
    ) THEN 0
    ELSE 1
END;

SELECT
    'apply_ready' AS check_name,
    IF(@apply_ready = 1, 'READY', 'BLOCKED') AS result,
    @apply_ready AS apply_ready;

CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`student_registration_requests` (
    `student_registration_request_id` INT NOT NULL AUTO_INCREMENT,
    `student_id` INT NOT NULL,
    `academic_year_id` INT NOT NULL,
    `semester_id` INT NOT NULL,
    `status` VARCHAR(40) NOT NULL DEFAULT 'draft',
    `submission_version` INT NOT NULL DEFAULT 0,
    `student_notes` TEXT NULL,
    `advisor_user_id` INT NULL,
    `advisor_notes` TEXT NULL,
    `first_submitted_at` DATETIME NULL,
    `last_submitted_at` DATETIME NULL,
    `reviewed_at` DATETIME NULL,
    `approved_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`student_registration_request_id`),
    UNIQUE KEY `uq_student_registration_request_term` (`student_id`, `academic_year_id`, `semester_id`),
    KEY `idx_student_registration_requests_status` (`status`, `last_submitted_at`),
    KEY `idx_student_registration_requests_advisor` (`advisor_user_id`),
    CONSTRAINT `fk_srr_student` FOREIGN KEY (`student_id`) REFERENCES `alrowad_uni_rust`.`students` (`student_id`),
    CONSTRAINT `fk_srr_year` FOREIGN KEY (`academic_year_id`) REFERENCES `alrowad_uni_rust`.`academic_years` (`academic_year_id`),
    CONSTRAINT `fk_srr_semester` FOREIGN KEY (`semester_id`) REFERENCES `alrowad_uni_rust`.`semesters` (`semester_id`),
    CONSTRAINT `fk_srr_advisor_user` FOREIGN KEY (`advisor_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`student_registration_request_items` (
    `student_registration_request_item_id` INT NOT NULL AUTO_INCREMENT,
    `student_registration_request_id` INT NOT NULL,
    `course_offering_id` INT NOT NULL,
    `student_course_registration_id` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`student_registration_request_item_id`),
    UNIQUE KEY `uq_srr_item_offering` (`student_registration_request_id`, `course_offering_id`),
    KEY `idx_srr_item_offering` (`course_offering_id`),
    KEY `idx_srr_item_registration` (`student_course_registration_id`),
    CONSTRAINT `fk_srri_request` FOREIGN KEY (`student_registration_request_id`)
        REFERENCES `alrowad_uni_rust`.`student_registration_requests` (`student_registration_request_id`),
    CONSTRAINT `fk_srri_offering` FOREIGN KEY (`course_offering_id`)
        REFERENCES `alrowad_uni_rust`.`course_offerings` (`course_offering_id`),
    CONSTRAINT `fk_srri_registration` FOREIGN KEY (`student_course_registration_id`)
        REFERENCES `alrowad_uni_rust`.`student_course_registrations` (`student_course_registration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`student_registration_request_events` (
    `student_registration_request_event_id` INT NOT NULL AUTO_INCREMENT,
    `student_registration_request_id` INT NOT NULL,
    `event_type` VARCHAR(40) NOT NULL,
    `actor_user_id` INT NULL,
    `from_status` VARCHAR(40) NULL,
    `to_status` VARCHAR(40) NULL,
    `submission_version` INT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`student_registration_request_event_id`),
    KEY `idx_srr_events_request` (`student_registration_request_id`, `created_at`),
    KEY `idx_srr_events_actor` (`actor_user_id`),
    CONSTRAINT `fk_srre_request` FOREIGN KEY (`student_registration_request_id`)
        REFERENCES `alrowad_uni_rust`.`student_registration_requests` (`student_registration_request_id`),
    CONSTRAINT `fk_srre_actor` FOREIGN KEY (`actor_user_id`)
        REFERENCES `alrowad_uni_rust`.`users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id,
    permission_code,
    permission_name,
    description,
    is_active,
    created_at,
    updated_at
)
SELECT
    sm.module_id,
    'registration_requests.view',
    'View registration requests',
    'View student registration requests within authorized academic scope.',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND sm.module_code = 'registration'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'registration_requests.view'
  );

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id,
    permission_code,
    permission_name,
    description,
    is_active,
    created_at,
    updated_at
)
SELECT
    sm.module_id,
    'registration_requests.review',
    'Review registration requests',
    'Return or approve student registration requests within authorized academic scope.',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND sm.module_code = 'registration'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'registration_requests.review'
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT
    r.role_id,
    p.permission_id,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
CROSS JOIN `alrowad_uni_rust`.`permissions` p
WHERE @apply_ready = 1
  AND r.role_code = 'academic_advisor'
  AND r.is_active = 1
  AND p.permission_code IN (
      'registration.view',
      'registration_requests.view',
      'registration_requests.review'
  )
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id
        AND existing.permission_id = p.permission_id
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT
    r.role_id,
    p.permission_id,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
CROSS JOIN `alrowad_uni_rust`.`permissions` p
WHERE @apply_ready = 1
  AND r.role_code = 'dean'
  AND r.is_active = 1
  AND p.permission_code IN (
      'registration_requests.view',
      'registration_requests.review'
  )
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id
        AND existing.permission_id = p.permission_id
  );

SELECT
    'apply_complete' AS report_section,
    @apply_ready AS apply_ready,
    'Run 02_verify.sql now. Do not execute this file from application code.' AS next_step;
