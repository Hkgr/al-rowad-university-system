-- Run only after 00_preflight.sql reports OVERALL | READY.
USE `alrowad_uni_rust`;

CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`semester_offering_requests` (
  `semester_offering_request_id` INT NOT NULL AUTO_INCREMENT,
  `course_offering_id` INT NOT NULL,
  `program_course_id` INT NOT NULL,
  `course_type` VARCHAR(16) NOT NULL,
  `is_selected` TINYINT(1) NOT NULL DEFAULT 1,
  `minimum_enrollment` INT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
  `submission_version` INT NOT NULL DEFAULT 0,
  `created_by_user_id` INT NOT NULL,
  `submitted_by_user_id` INT NULL,
  `submitted_at` DATETIME NULL,
  `approved_at` DATETIME NULL,
  `materialized_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`semester_offering_request_id`),
  UNIQUE KEY `uq_sor_offering` (`course_offering_id`),
  KEY `idx_sor_status_submitted` (`status`,`submitted_at`),
  KEY `idx_sor_program_course` (`program_course_id`),
  KEY `idx_sor_materialized` (`materialized_at`),
  CONSTRAINT `chk_sor_course_type` CHECK (`course_type` IN ('mandatory','elective')),
  CONSTRAINT `chk_sor_selected` CHECK (`is_selected` IN (0,1)),
  CONSTRAINT `chk_sor_minimum` CHECK (`minimum_enrollment` IS NULL OR `minimum_enrollment` > 0),
  CONSTRAINT `chk_sor_status` CHECK (`status` IN ('draft','submitted','returned','approved')),
  CONSTRAINT `chk_sor_version` CHECK (`submission_version` >= 0),
  CONSTRAINT `chk_sor_submission` CHECK (
    (`status`='draft' AND `submission_version`=0 AND `submitted_by_user_id` IS NULL AND `submitted_at` IS NULL)
    OR (`status` IN ('submitted','returned','approved') AND `submission_version`>=1 AND `submitted_by_user_id` IS NOT NULL AND `submitted_at` IS NOT NULL)
  ),
  CONSTRAINT `chk_sor_approval` CHECK (
    (`status`='approved' AND `approved_at` IS NOT NULL) OR (`status`<>'approved' AND `approved_at` IS NULL)
  ),
  CONSTRAINT `chk_sor_materialization` CHECK (`materialized_at` IS NULL OR (`status`='approved' AND `approved_at` IS NOT NULL)),
  CONSTRAINT `fk_sor_offering` FOREIGN KEY (`course_offering_id`) REFERENCES `alrowad_uni_rust`.`course_offerings` (`course_offering_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_sor_program_course` FOREIGN KEY (`program_course_id`) REFERENCES `alrowad_uni_rust`.`program_courses` (`program_course_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_sor_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_sor_submitted_by` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Owned by semester-offering-governance-phase1; one governance root per normal offering';

CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`semester_offering_reviews` (
  `semester_offering_review_id` INT NOT NULL AUTO_INCREMENT,
  `semester_offering_request_id` INT NOT NULL,
  `submission_version` INT NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `reviewed_by_user_id` INT NULL,
  `reviewed_at` DATETIME NULL,
  `reason` VARCHAR(1000) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`semester_offering_review_id`),
  UNIQUE KEY `uq_sorv_request_version` (`semester_offering_request_id`,`submission_version`),
  KEY `idx_sorv_status` (`status`,`reviewed_at`),
  CONSTRAINT `chk_sorv_version` CHECK (`submission_version` > 0),
  CONSTRAINT `chk_sorv_status` CHECK (`status` IN ('pending','approved','returned')),
  CONSTRAINT `chk_sorv_provenance` CHECK (
    (`status`='pending' AND `reviewed_by_user_id` IS NULL AND `reviewed_at` IS NULL AND `reason` IS NULL)
    OR (`status`='approved' AND `reviewed_by_user_id` IS NOT NULL AND `reviewed_at` IS NOT NULL AND `reason` IS NULL)
    OR (`status`='returned' AND `reviewed_by_user_id` IS NOT NULL AND `reviewed_at` IS NOT NULL AND CHAR_LENGTH(TRIM(`reason`))>0)
  ),
  CONSTRAINT `fk_sorv_request` FOREIGN KEY (`semester_offering_request_id`) REFERENCES `alrowad_uni_rust`.`semester_offering_requests` (`semester_offering_request_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_sorv_reviewer` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Owned by semester-offering-governance-phase1; immutable scientific review per submission version';

CREATE TABLE IF NOT EXISTS `alrowad_uni_rust`.`semester_offering_events` (
  `semester_offering_event_id` INT NOT NULL AUTO_INCREMENT,
  `semester_offering_request_id` INT NOT NULL,
  `submission_version` INT NOT NULL DEFAULT 0,
  `event_type` VARCHAR(32) NOT NULL,
  `actor_user_id` INT NOT NULL,
  `note` VARCHAR(1000) NULL,
  `occurred_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`semester_offering_event_id`),
  KEY `idx_soe_request_time` (`semester_offering_request_id`,`occurred_at`),
  KEY `idx_soe_type_time` (`event_type`,`occurred_at`),
  CONSTRAINT `chk_soe_version` CHECK (`submission_version` >= 0),
  CONSTRAINT `chk_soe_type` CHECK (`event_type` IN (
    'prepared','updated','deselected','submitted','resubmitted',
    'scientific_returned','scientific_approved','materialized'
  )),
  CONSTRAINT `fk_soe_request` FOREIGN KEY (`semester_offering_request_id`) REFERENCES `alrowad_uni_rust`.`semester_offering_requests` (`semester_offering_request_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_soe_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `alrowad_uni_rust`.`users` (`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Owned by semester-offering-governance-phase1; append-only transition history';

INSERT INTO `alrowad_uni_rust`.`permissions`
  (`module_id`,`permission_code`,`permission_name`,`description`,`is_active`,`created_at`,`updated_at`)
SELECT m.module_id, seed.permission_code, seed.permission_name, seed.description, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` m
JOIN (
  SELECT 'course_offerings.semester_governance.view' permission_code, 'Semester Offering Governance View' permission_name, 'View semester offering governance' description
  UNION ALL SELECT 'course_offerings.semester_governance.manage','Semester Offering Governance Manage','Prepare and submit semester offerings as Dean'
  UNION ALL SELECT 'course_offerings.semester_governance.review_scientific','Semester Offering Scientific Review','Approve or return semester offerings as Scientific VP'
) seed
WHERE m.module_code='courses' AND m.is_active=1
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` p WHERE p.permission_code=seed.permission_code);

INSERT INTO `alrowad_uni_rust`.`role_permissions` (`role_id`,`permission_id`,`granted_at`)
SELECT r.role_id,p.permission_id,CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON (
  (r.role_code='dean' AND p.permission_code IN (
    'course_offerings.semester_governance.view','course_offerings.semester_governance.manage'))
  OR (r.role_code='vice_president_scientific' AND p.permission_code IN (
    'course_offerings.semester_governance.view','course_offerings.semester_governance.review_scientific'))
)
WHERE r.is_active=1 AND p.is_active=1 AND NOT EXISTS (
  SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp
  WHERE rp.role_id=r.role_id AND rp.permission_id=p.permission_id
);

SELECT 'APPLY' AS report_section, 'APPLIED' AS result,
       'Run 02_verify.sql and accept deployment only on OVERALL | PASS' AS detail;
