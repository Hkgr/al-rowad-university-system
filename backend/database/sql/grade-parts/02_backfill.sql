-- Idempotent legacy backfill: approved final approvals only; existing workflow rows win.
INSERT INTO `grade_part_approvals`
 (`course_offering_id`,`component_type`,`status`,`submission_version`,`submitted_by_user_id`,`submitted_at`,`reviewed_by_user_id`,`reviewed_at`,`review_notes`)
SELECT ga.course_offering_id, required_parts.component_type, 'approved', 1,
       ga.submitted_by_user_id, ga.submitted_at, ga.approved_by_user_id,
       ga.approval_date, CONCAT('Backfilled from approved legacy GradeApproval #', ga.grade_approval_id)
FROM grade_approvals ga
JOIN (
  SELECT approved.course_offering_id, MAX(approved.grade_approval_id) AS grade_approval_id
  FROM grade_approvals approved
  JOIN approval_statuses approved_status ON approved_status.approval_status_id = approved.approval_status_id
   AND approved_status.status_code = 'approved'
  GROUP BY approved.course_offering_id
) latest_approved ON latest_approved.grade_approval_id = ga.grade_approval_id
JOIN (
  SELECT DISTINCT course_offering_id, component_type
  FROM grade_components
  WHERE is_required = 1 AND component_type IN ('practical','theoretical')
) required_parts ON required_parts.course_offering_id = ga.course_offering_id
LEFT JOIN grade_part_approvals existing ON existing.course_offering_id = ga.course_offering_id
 AND existing.component_type = required_parts.component_type
WHERE existing.grade_part_approval_id IS NULL;


-- Mark only required components belonging to approved legacy offerings; marks and audit logs are untouched.
UPDATE student_grade_components sgc
JOIN grade_components gc ON gc.grade_component_id = sgc.grade_component_id
 AND gc.is_required = 1 AND gc.component_type IN ('practical','theoretical')
JOIN grade_part_approvals gpa ON gpa.course_offering_id = gc.course_offering_id
 AND gpa.component_type = gc.component_type AND gpa.status = 'approved'
 AND gpa.review_notes LIKE 'Backfilled from approved legacy GradeApproval #%'
SET sgc.grade_status = 'approved'
WHERE sgc.grade_status <> 'approved';
