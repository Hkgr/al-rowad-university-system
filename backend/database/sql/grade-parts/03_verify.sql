-- Read-only verification. Each row reports PASS/FAIL; the final row is OVERALL.
SELECT 'tables_exist' AS check_name,
 CASE WHEN COUNT(*) = 2 THEN 'PASS' ELSE 'FAIL' END AS result
FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
 AND TABLE_NAME IN ('grade_part_approvals','grade_part_approval_events')
UNION ALL
SELECT 'unique_current_approval_index',
 CASE WHEN COUNT(*) = 2 THEN 'PASS' ELSE 'FAIL' END
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
 AND TABLE_NAME='grade_part_approvals' AND INDEX_NAME='uq_grade_part_approvals_offering_type'
 AND NON_UNIQUE=0
UNION ALL
SELECT 'required_foreign_keys',
 CASE WHEN COUNT(DISTINCT CONSTRAINT_NAME)=5 THEN 'PASS' ELSE 'FAIL' END
FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
 AND CONSTRAINT_NAME IN ('fk_grade_part_approvals_offering','fk_grade_part_approvals_submitter','fk_grade_part_approvals_reviewer','fk_grade_part_approval_events_approval','fk_grade_part_approval_events_user')
UNION ALL
SELECT 'required_queue_and_event_indexes',
 CASE WHEN COUNT(DISTINCT INDEX_NAME)=2 THEN 'PASS' ELSE 'FAIL' END
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
 AND ((TABLE_NAME='grade_part_approvals' AND INDEX_NAME='idx_grade_part_approvals_queue')
   OR (TABLE_NAME='grade_part_approval_events' AND INDEX_NAME='idx_grade_part_approval_events_version'))
UNION ALL
SELECT 'no_duplicate_current_rows',
 CASE WHEN COUNT(*)=0 THEN 'PASS' ELSE 'FAIL' END
FROM (SELECT course_offering_id, component_type FROM grade_part_approvals
 GROUP BY course_offering_id, component_type HAVING COUNT(*)>1) duplicates
UNION ALL
SELECT 'approved_legacy_backfill_complete',
 CASE WHEN COUNT(*)=0 THEN 'PASS' ELSE 'FAIL' END
FROM grade_approvals ga
JOIN approval_statuses aps ON aps.approval_status_id=ga.approval_status_id AND aps.status_code='approved'
JOIN (SELECT DISTINCT course_offering_id,component_type FROM grade_components
 WHERE is_required=1 AND component_type IN ('practical','theoretical')) parts ON parts.course_offering_id=ga.course_offering_id
LEFT JOIN grade_part_approvals gpa ON gpa.course_offering_id=ga.course_offering_id
 AND gpa.component_type=parts.component_type AND gpa.status='approved'
WHERE gpa.grade_part_approval_id IS NULL
UNION ALL
SELECT 'backfilled_required_components_approved',
 CASE WHEN COUNT(*)=0 THEN 'PASS' ELSE 'FAIL' END
FROM student_grade_components sgc
JOIN grade_components gc ON gc.grade_component_id=sgc.grade_component_id AND gc.is_required=1
JOIN grade_part_approvals gpa ON gpa.course_offering_id=gc.course_offering_id
 AND gpa.component_type=gc.component_type AND gpa.status='approved'
 AND gpa.review_notes LIKE 'Backfilled from approved legacy GradeApproval #%'
WHERE sgc.grade_status<>'approved'
UNION ALL
SELECT 'no_nonapproved_legacy_promoted',
 CASE WHEN COUNT(*)=0 THEN 'PASS' ELSE 'FAIL' END
FROM grade_part_approvals gpa
WHERE gpa.review_notes LIKE 'Backfilled from approved legacy GradeApproval #%'
 AND NOT EXISTS (SELECT 1 FROM grade_approvals ga JOIN approval_statuses aps
 ON aps.approval_status_id=ga.approval_status_id AND aps.status_code='approved'
 WHERE ga.course_offering_id=gpa.course_offering_id)
UNION ALL
SELECT 'OVERALL', CASE WHEN
 (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('grade_part_approvals','grade_part_approval_events'))=2
 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='grade_part_approvals' AND INDEX_NAME='uq_grade_part_approvals_offering_type' AND NON_UNIQUE=0)=2
 AND (SELECT COUNT(DISTINCT CONSTRAINT_NAME) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('fk_grade_part_approvals_offering','fk_grade_part_approvals_submitter','fk_grade_part_approvals_reviewer','fk_grade_part_approval_events_approval','fk_grade_part_approval_events_user'))=5
 AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='grade_part_approvals' AND INDEX_NAME='idx_grade_part_approvals_queue') OR (TABLE_NAME='grade_part_approval_events' AND INDEX_NAME='idx_grade_part_approval_events_version')))=2
 AND (SELECT COUNT(*) FROM (SELECT course_offering_id,component_type FROM grade_part_approvals GROUP BY course_offering_id,component_type HAVING COUNT(*)>1) d)=0
 AND (SELECT COUNT(*) FROM student_grade_components sgc JOIN grade_components gc ON gc.grade_component_id=sgc.grade_component_id AND gc.is_required=1 JOIN grade_part_approvals gpa ON gpa.course_offering_id=gc.course_offering_id AND gpa.component_type=gc.component_type AND gpa.status='approved' AND gpa.review_notes LIKE 'Backfilled from approved legacy GradeApproval #%' WHERE sgc.grade_status<>'approved')=0
 AND (SELECT COUNT(*) FROM grade_approvals ga JOIN approval_statuses aps ON aps.approval_status_id=ga.approval_status_id AND aps.status_code='approved'
      JOIN (SELECT DISTINCT course_offering_id,component_type FROM grade_components WHERE is_required=1 AND component_type IN ('practical','theoretical')) p ON p.course_offering_id=ga.course_offering_id
      LEFT JOIN grade_part_approvals gpa ON gpa.course_offering_id=ga.course_offering_id AND gpa.component_type=p.component_type AND gpa.status='approved'
      WHERE gpa.grade_part_approval_id IS NULL)=0
 THEN 'PASS' ELSE 'FAIL' END;
