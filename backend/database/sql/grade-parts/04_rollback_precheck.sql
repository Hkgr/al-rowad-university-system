-- Read-only rollback safety report. This file never drops or changes anything.
SELECT 'approval_rows' AS check_name, COUNT(*) AS observed_count,
 CASE WHEN COUNT(*)=0 THEN 'PASS' ELSE 'REVIEW' END AS rollback_safety
FROM grade_part_approvals
UNION ALL
SELECT 'workflow_events', COUNT(*), CASE WHEN COUNT(*)=0 THEN 'PASS' ELSE 'FAIL' END
FROM grade_part_approval_events
UNION ALL
SELECT 'non_backfilled_approval_rows', COUNT(*), CASE WHEN COUNT(*)=0 THEN 'PASS' ELSE 'FAIL' END
FROM grade_part_approvals
WHERE review_notes IS NULL OR review_notes NOT LIKE 'Backfilled from approved legacy GradeApproval #%'
UNION ALL
SELECT 'submitted_returned_or_live_drafts', COUNT(*), CASE WHEN COUNT(*)=0 THEN 'PASS' ELSE 'FAIL' END
FROM grade_part_approvals WHERE status IN ('draft','submitted','returned');
