-- Safe only before the application starts writing grade-part workflow data.
-- The guard query must return zero; otherwise archive the tables instead of dropping them.
SELECT COUNT(*) AS non_backfill_events FROM grade_part_approval_events;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS grade_part_approval_events;
DROP TABLE IF EXISTS grade_part_approvals;
SET FOREIGN_KEY_CHECKS=1;
-- Legacy grade_approvals, marks, results, and grade_audit_logs are intentionally untouched.

-- Nullable result columns are deliberately retained: restoring NOT NULL could destroy the entered/not-entered distinction.
