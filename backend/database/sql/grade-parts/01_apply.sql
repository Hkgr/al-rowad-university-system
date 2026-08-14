-- DDL only. MySQL/MariaDB commits DDL implicitly; do not wrap this file in a transaction.
CREATE TABLE `grade_part_approvals` (
  `grade_part_approval_id` bigint NOT NULL AUTO_INCREMENT,
  `course_offering_id` int(11) NOT NULL,
  `component_type` enum('practical','theoretical') NOT NULL,
  `status` enum('draft','submitted','returned','approved') NOT NULL DEFAULT 'draft',
  `submission_version` int unsigned NOT NULL DEFAULT 0,
  `submitted_by_user_id` int(11) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_by_user_id` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grade_part_approval_id`),
  UNIQUE KEY `uq_grade_part_approvals_offering_type` (`course_offering_id`,`component_type`),
  KEY `idx_grade_part_approvals_queue` (`status`,`submitted_at`),
  KEY `idx_grade_part_approvals_submitter` (`submitted_by_user_id`),
  KEY `idx_grade_part_approvals_reviewer` (`reviewed_by_user_id`),
  CONSTRAINT `fk_grade_part_approvals_offering` FOREIGN KEY (`course_offering_id`) REFERENCES `course_offerings` (`course_offering_id`),
  CONSTRAINT `fk_grade_part_approvals_submitter` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_grade_part_approvals_reviewer` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `grade_part_approval_events` (
  `grade_part_approval_event_id` bigint NOT NULL AUTO_INCREMENT,
  `grade_part_approval_id` bigint NOT NULL,
  `submission_version` int unsigned NOT NULL,
  `action` varchar(30) NOT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json NOT NULL,
  `performed_by_user_id` int(11) NOT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`grade_part_approval_event_id`),
  KEY `idx_grade_part_approval_events_version` (`grade_part_approval_id`,`submission_version`),
  KEY `idx_grade_part_approval_events_user` (`performed_by_user_id`),
  CONSTRAINT `fk_grade_part_approval_events_approval` FOREIGN KEY (`grade_part_approval_id`) REFERENCES `grade_part_approvals` (`grade_part_approval_id`),
  CONSTRAINT `fk_grade_part_approval_events_user` FOREIGN KEY (`performed_by_user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
