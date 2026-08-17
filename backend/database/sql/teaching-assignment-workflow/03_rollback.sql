-- Conservative rollback for Phase 4 teaching-assignment workflow.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not use DATABASE(), stored procedures, DELIMITER, or SIGNAL.
--
-- If ANY workflow business rows exist, do not drop tables. Return BLOCKED_IN_USE.
-- Remove only Phase-4-owned RBAC mappings/permissions
-- (description contains [phase4-teaching-assignment-workflow]).
--
-- Does NOT remove:
--   Phase 3 VP roles or base permissions
--   generic vice_president
--   dean role
--   users / user_roles / user_access_scopes
--   organizational units
--   course_offering_instructors rows or structure

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @requests_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests'), 0);
SET @reviews_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_reviews'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_events'), 0);

SET @request_rows := IF(@requests_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_requests`), 0);
SET @review_rows := IF(@reviews_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_reviews`), 0);
SET @event_rows := IF(@events_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`teaching_assignment_events`), 0);

SET @in_use := IF(@request_rows > 0 OR @review_rows > 0 OR @event_rows > 0, 1, 0);

SET @rollback_status := IF(
    @db_ready = 0,
    'BLOCKED',
    IF(@in_use = 1, 'BLOCKED_IN_USE', 'READY')
);

SELECT 'ROLLBACK_GUARD' AS report_section,
       @rollback_status AS result,
       @request_rows AS request_rows,
       @review_rows AS review_rows,
       @event_rows AS event_rows;

SET @sql := IF(
    @rollback_status = 'READY' AND @events_exist = 1,
    'DROP TABLE `alrowad_uni_rust`.`teaching_assignment_events`',
    'SELECT ''SKIPPED_DROP_EVENTS'' AS rollback_result'
);
PREPARE phase4_drop_events FROM @sql;
EXECUTE phase4_drop_events;
DEALLOCATE PREPARE phase4_drop_events;

SET @sql := IF(
    @rollback_status = 'READY' AND @reviews_exist = 1,
    'DROP TABLE `alrowad_uni_rust`.`teaching_assignment_reviews`',
    'SELECT ''SKIPPED_DROP_REVIEWS'' AS rollback_result'
);
PREPARE phase4_drop_reviews FROM @sql;
EXECUTE phase4_drop_reviews;
DEALLOCATE PREPARE phase4_drop_reviews;

SET @sql := IF(
    @rollback_status = 'READY' AND @requests_exist = 1,
    'DROP TABLE `alrowad_uni_rust`.`teaching_assignment_requests`',
    'SELECT ''SKIPPED_DROP_REQUESTS'' AS rollback_result'
);
PREPARE phase4_drop_requests FROM @sql;
EXECUTE phase4_drop_requests;
DEALLOCATE PREPARE phase4_drop_requests;

START TRANSACTION;

DELETE rp
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @rollback_status = 'READY'
  AND p.permission_code IN (
      'teaching_assignments.view',
      'teaching_assignments.manage',
      'teaching_assignments.review_scientific',
      'teaching_assignments.review_administrative'
  )
  AND COALESCE(p.description, '') LIKE '%[phase4-teaching-assignment-workflow]%';

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @rollback_status = 'READY'
  AND permission_code IN (
      'teaching_assignments.view',
      'teaching_assignments.manage',
      'teaching_assignments.review_scientific',
      'teaching_assignments.review_administrative'
  )
  AND COALESCE(description, '') LIKE '%[phase4-teaching-assignment-workflow]%';

COMMIT;

SELECT IF(@in_use = 1, 'BLOCKED_IN_USE', IF(@rollback_status = 'READY', 'ROLLED_BACK', 'BLOCKED')) AS rollback_status,
       @request_rows AS request_rows,
       @review_rows AS review_rows,
       @event_rows AS event_rows;
