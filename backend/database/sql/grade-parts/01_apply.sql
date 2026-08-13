-- Grade-parts workflow (MySQL 8+). Run once after taking a database backup.
START TRANSACTION;
ALTER TABLE student_course_results MODIFY theoretical_total DECIMAL(5,2) NULL DEFAULT NULL, MODIFY practical_total DECIMAL(5,2) NULL DEFAULT NULL, MODIFY final_mark DECIMAL(5,2) NULL DEFAULT NULL;
CREATE TABLE IF NOT EXISTS grade_part_approvals (
  grade_part_approval_id BIGINT NOT NULL AUTO_INCREMENT,
  course_offering_id INT NOT NULL,
  component_type ENUM('practical','theoretical') NOT NULL,
  status ENUM('draft','submitted','returned','approved') NOT NULL DEFAULT 'draft',
  submission_version INT UNSIGNED NOT NULL DEFAULT 0,
  submitted_by_user_id INT NULL,
  submitted_at DATETIME NULL,
  reviewed_by_user_id INT NULL,
  reviewed_at DATETIME NULL,
  review_notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (grade_part_approval_id),
  UNIQUE KEY uq_grade_part_current (course_offering_id, component_type),
  KEY idx_grade_part_queue (status, submitted_at),
  CONSTRAINT fk_grade_part_offering FOREIGN KEY (course_offering_id) REFERENCES course_offerings(course_offering_id),
  CONSTRAINT fk_grade_part_submitter FOREIGN KEY (submitted_by_user_id) REFERENCES users(user_id),
  CONSTRAINT fk_grade_part_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS grade_part_approval_events (
  grade_part_approval_event_id BIGINT NOT NULL AUTO_INCREMENT,
  grade_part_approval_id BIGINT NOT NULL,
  submission_version INT UNSIGNED NOT NULL,
  action VARCHAR(30) NOT NULL,
  old_values JSON NULL,
  new_values JSON NOT NULL,
  performed_by_user_id INT NOT NULL,
  performed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (grade_part_approval_event_id),
  KEY idx_grade_part_event_approval (grade_part_approval_id, submission_version),
  CONSTRAINT fk_grade_part_event_approval FOREIGN KEY (grade_part_approval_id) REFERENCES grade_part_approvals(grade_part_approval_id),
  CONSTRAINT fk_grade_part_event_user FOREIGN KEY (performed_by_user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill only existing final approvals. Pending legacy approvals remain unpublished by this migration.
INSERT INTO grade_part_approvals (course_offering_id, component_type, status, submission_version, submitted_by_user_id, submitted_at, reviewed_by_user_id, reviewed_at, review_notes)
SELECT ga.course_offering_id, gc.component_type, 'approved', 1, ga.submitted_by_user_id, ga.submitted_at,
       ga.approved_by_user_id, ga.approval_date, CONCAT('Backfilled from legacy GradeApproval #', ga.grade_approval_id)
FROM grade_approvals ga
JOIN approval_statuses aps ON aps.approval_status_id = ga.approval_status_id AND aps.status_code = 'approved'
JOIN (SELECT DISTINCT course_offering_id, component_type FROM grade_components WHERE is_required = 1 AND component_type IN ('practical','theoretical')) gc ON gc.course_offering_id = ga.course_offering_id
ON DUPLICATE KEY UPDATE status = IF(status = 'draft', VALUES(status), status);
COMMIT;
