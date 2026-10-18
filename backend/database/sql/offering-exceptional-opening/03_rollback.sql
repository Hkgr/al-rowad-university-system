-- Conservative rollback for Phase 6 exceptional-opening workflow.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not use DATABASE(), stored procedures, DELIMITER, or SIGNAL.
--
-- Tables are dropped ONLY when:
--   1. information_schema.tables.TABLE_COMMENT contains
--      [phase6-offering-exceptional-opening]
--   2. no workflow business rows exist in any of the three tables
--
-- A same-named empty table that lacks the ownership marker is
-- SKIPPED_NOT_PROVABLY_PHASE_OWNED and is never dropped.
-- If ANY business rows exist, return BLOCKED_IN_USE and drop nothing.
--
-- Drop order: events, reviews, requests.
--
-- Remove only Phase-6-owned RBAC mappings/permissions
-- (description contains [phase6-offering-exceptional-opening]).
--
-- Does NOT remove:
--   Phase 3 VP roles or base permissions
--   Phase 4 teaching-assignment objects
--   generic vice_president
--   dean role
--   users / user_roles / user_access_scopes
--   organizational units
--   course_offerings rows or structure

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @requests_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_requests' AND table_type = 'BASE TABLE'), 0);
SET @reviews_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_reviews' AND table_type = 'BASE TABLE'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_exception_events' AND table_type = 'BASE TABLE'), 0);

SET @requests_owned := IF(
    @requests_exist = 1,
    IF((
        SELECT COALESCE(table_comment, '')
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_exception_requests'
          AND table_type = 'BASE TABLE'
    ) LIKE '%[phase6-offering-exceptional-opening]%', 1, 0),
    0
);
SET @reviews_owned := IF(
    @reviews_exist = 1,
    IF((
        SELECT COALESCE(table_comment, '')
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_exception_reviews'
          AND table_type = 'BASE TABLE'
    ) LIKE '%[phase6-offering-exceptional-opening]%', 1, 0),
    0
);
SET @events_owned := IF(
    @events_exist = 1,
    IF((
        SELECT COALESCE(table_comment, '')
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_exception_events'
          AND table_type = 'BASE TABLE'
    ) LIKE '%[phase6-offering-exceptional-opening]%', 1, 0),
    0
);

SET @request_rows := IF(@requests_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offering_exception_requests`), 0);
SET @review_rows := IF(@reviews_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offering_exception_reviews`), 0);
SET @event_rows := IF(@events_exist = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offering_exception_events`), 0);

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
       @event_rows AS event_rows,
       @events_owned AS events_owned,
       @reviews_owned AS reviews_owned,
       @requests_owned AS requests_owned;

SELECT 'ROLLBACK_TABLE' AS report_section,
       'course_offering_exception_events' AS table_name,
       CASE
           WHEN @events_exist = 0 THEN 'ABSENT'
           WHEN @in_use = 1 THEN 'BLOCKED_IN_USE'
           WHEN @events_owned = 0 THEN 'SKIPPED_NOT_PROVABLY_PHASE_OWNED'
           WHEN @rollback_status = 'READY' THEN 'WILL_DROP'
           ELSE 'BLOCKED'
       END AS result;

SELECT 'ROLLBACK_TABLE' AS report_section,
       'course_offering_exception_reviews' AS table_name,
       CASE
           WHEN @reviews_exist = 0 THEN 'ABSENT'
           WHEN @in_use = 1 THEN 'BLOCKED_IN_USE'
           WHEN @reviews_owned = 0 THEN 'SKIPPED_NOT_PROVABLY_PHASE_OWNED'
           WHEN @rollback_status = 'READY' THEN 'WILL_DROP'
           ELSE 'BLOCKED'
       END AS result;

SELECT 'ROLLBACK_TABLE' AS report_section,
       'course_offering_exception_requests' AS table_name,
       CASE
           WHEN @requests_exist = 0 THEN 'ABSENT'
           WHEN @in_use = 1 THEN 'BLOCKED_IN_USE'
           WHEN @requests_owned = 0 THEN 'SKIPPED_NOT_PROVABLY_PHASE_OWNED'
           WHEN @rollback_status = 'READY' THEN 'WILL_DROP'
           ELSE 'BLOCKED'
       END AS result;

SET @sql := IF(
    @rollback_status = 'READY' AND @events_exist = 1 AND @events_owned = 1,
    'DROP TABLE `alrowad_uni_rust`.`course_offering_exception_events`',
    IF(
        @events_exist = 1 AND @events_owned = 0,
        'SELECT ''SKIPPED_NOT_PROVABLY_PHASE_OWNED'' AS rollback_result, ''course_offering_exception_events'' AS table_name',
        IF(
            @in_use = 1 AND @events_exist = 1,
            'SELECT ''BLOCKED_IN_USE'' AS rollback_result, ''course_offering_exception_events'' AS table_name',
            'SELECT ''SKIPPED_DROP_EVENTS'' AS rollback_result'
        )
    )
);
PREPARE phase6_drop_events FROM @sql;
EXECUTE phase6_drop_events;
DEALLOCATE PREPARE phase6_drop_events;

SET @sql := IF(
    @rollback_status = 'READY' AND @reviews_exist = 1 AND @reviews_owned = 1,
    'DROP TABLE `alrowad_uni_rust`.`course_offering_exception_reviews`',
    IF(
        @reviews_exist = 1 AND @reviews_owned = 0,
        'SELECT ''SKIPPED_NOT_PROVABLY_PHASE_OWNED'' AS rollback_result, ''course_offering_exception_reviews'' AS table_name',
        IF(
            @in_use = 1 AND @reviews_exist = 1,
            'SELECT ''BLOCKED_IN_USE'' AS rollback_result, ''course_offering_exception_reviews'' AS table_name',
            'SELECT ''SKIPPED_DROP_REVIEWS'' AS rollback_result'
        )
    )
);
PREPARE phase6_drop_reviews FROM @sql;
EXECUTE phase6_drop_reviews;
DEALLOCATE PREPARE phase6_drop_reviews;

SET @sql := IF(
    @rollback_status = 'READY' AND @requests_exist = 1 AND @requests_owned = 1,
    'DROP TABLE `alrowad_uni_rust`.`course_offering_exception_requests`',
    IF(
        @requests_exist = 1 AND @requests_owned = 0,
        'SELECT ''SKIPPED_NOT_PROVABLY_PHASE_OWNED'' AS rollback_result, ''course_offering_exception_requests'' AS table_name',
        IF(
            @in_use = 1 AND @requests_exist = 1,
            'SELECT ''BLOCKED_IN_USE'' AS rollback_result, ''course_offering_exception_requests'' AS table_name',
            'SELECT ''SKIPPED_DROP_REQUESTS'' AS rollback_result'
        )
    )
);
PREPARE phase6_drop_requests FROM @sql;
EXECUTE phase6_drop_requests;
DEALLOCATE PREPARE phase6_drop_requests;

START TRANSACTION;

DELETE rp
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @rollback_status = 'READY'
  AND p.permission_code IN (
      'course_offerings.exceptional_open.view',
      'course_offerings.exceptional_open.request',
      'course_offerings.exceptional_open.review_scientific',
      'course_offerings.exceptional_open.review_administrative'
  )
  AND COALESCE(p.description, '') LIKE '%[phase6-offering-exceptional-opening]%';

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @rollback_status = 'READY'
  AND permission_code IN (
      'course_offerings.exceptional_open.view',
      'course_offerings.exceptional_open.request',
      'course_offerings.exceptional_open.review_scientific',
      'course_offerings.exceptional_open.review_administrative'
  )
  AND COALESCE(description, '') LIKE '%[phase6-offering-exceptional-opening]%';

COMMIT;

SELECT IF(@in_use = 1, 'BLOCKED_IN_USE', IF(@rollback_status = 'READY', 'ROLLED_BACK', 'BLOCKED')) AS rollback_status,
       @request_rows AS request_rows,
       @review_rows AS review_rows,
       @event_rows AS event_rows,
       @events_owned AS events_owned,
       @reviews_owned AS reviews_owned,
       @requests_owned AS requests_owned;
