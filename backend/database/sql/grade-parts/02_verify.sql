SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('grade_part_approvals','grade_part_approval_events');
SELECT course_offering_id, component_type, COUNT(*) AS current_rows FROM grade_part_approvals GROUP BY course_offering_id, component_type HAVING COUNT(*) > 1;
SELECT status, COUNT(*) AS rows_count FROM grade_part_approvals GROUP BY status;
SELECT ga.grade_approval_id AS legacy_approved_missing_part
FROM grade_approvals ga JOIN approval_statuses aps ON aps.approval_status_id=ga.approval_status_id AND aps.status_code='approved'
JOIN grade_components gc ON gc.course_offering_id=ga.course_offering_id AND gc.is_required=1 AND gc.component_type IN ('practical','theoretical')
LEFT JOIN grade_part_approvals gpa ON gpa.course_offering_id=ga.course_offering_id AND gpa.component_type=gc.component_type AND gpa.status='approved'
WHERE gpa.grade_part_approval_id IS NULL;

SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_results' AND COLUMN_NAME IN ('theoretical_total','practical_total','final_mark');
