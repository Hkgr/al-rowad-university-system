-- Conservative rollback for Phase 2 supplementary exam offerings.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not use DATABASE(), stored procedures, DELIMITER, or SIGNAL.
--
-- BACKUP FIRST.
-- Emergency only.
--
-- NEVER drop supplementary_exam_periods or supplementary_exam_period_events.
-- NEVER modify course_offerings, student_course_registrations, supplementary_exam_results.
-- NEVER drop a populated Phase 2 table.
-- NEVER drop adopted pre-existing tables without the ownership marker.
--
-- BLOCKED_IN_USE when any supplementary exam offering, source, or event row exists.
--
-- Rollback may remove only objects whose COMMENT contains
-- [phase2-supplementary-exam-offerings].
-- Permissions: remove only Phase-2-owned permissions/mappings using the marker.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @offerings_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offerings' AND table_type = 'BASE TABLE'), 0);
SET @sources_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_sources' AND table_type = 'BASE TABLE'), 0);
SET @events_exist := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'supplementary_exam_offering_events' AND table_type = 'BASE TABLE'), 0);

SET @offerings_owned := IF(
    @offerings_exist = 1
    AND (
        SELECT COALESCE(t.table_comment, '')
        FROM information_schema.tables t
        WHERE t.table_schema = 'alrowad_uni_rust'
          AND t.table_name = 'supplementary_exam_offerings'
          AND t.table_type = 'BASE TABLE'
    ) LIKE '%[phase2-supplementary-exam-offerings]%',
    1, 0
);
SET @sources_owned := IF(
    @sources_exist = 1
    AND (
        SELECT COALESCE(t.table_comment, '')
        FROM information_schema.tables t
        WHERE t.table_schema = 'alrowad_uni_rust'
          AND t.table_name = 'supplementary_exam_offering_sources'
          AND t.table_type = 'BASE TABLE'
    ) LIKE '%[phase2-supplementary-exam-offerings]%',
    1, 0
);
SET @events_owned := IF(
    @events_exist = 1
    AND (
        SELECT COALESCE(t.table_comment, '')
        FROM information_schema.tables t
        WHERE t.table_schema = 'alrowad_uni_rust'
          AND t.table_name = 'supplementary_exam_offering_events'
          AND t.table_type = 'BASE TABLE'
    ) LIKE '%[phase2-supplementary-exam-offerings]%',
    1, 0
);

SET @offering_rows := 0;
SET @source_rows := 0;
SET @event_rows := 0;
SET @sql := IF(@offerings_exist = 1, 'SELECT @offering_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_offerings`', 'SELECT @offering_rows := 0');
PREPARE phase2_rb_off FROM @sql;
EXECUTE phase2_rb_off;
DEALLOCATE PREPARE phase2_rb_off;
SET @sql := IF(@sources_exist = 1, 'SELECT @source_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_offering_sources`', 'SELECT @source_rows := 0');
PREPARE phase2_rb_src FROM @sql;
EXECUTE phase2_rb_src;
DEALLOCATE PREPARE phase2_rb_src;
SET @sql := IF(@events_exist = 1, 'SELECT @event_rows := COUNT(*) FROM `alrowad_uni_rust`.`supplementary_exam_offering_events`', 'SELECT @event_rows := 0');
PREPARE phase2_rb_evt FROM @sql;
EXECUTE phase2_rb_evt;
DEALLOCATE PREPARE phase2_rb_evt;

SET @rollback_status := CASE
    WHEN @db_ready = 0 THEN 'BLOCKED'
    WHEN @offering_rows > 0 OR @source_rows > 0 OR @event_rows > 0 THEN 'BLOCKED_IN_USE'
    ELSE 'READY'
END;

SELECT 'ROLLBACK_GUARD' AS report_section,
       @rollback_status AS rollback_status,
       @offering_rows AS offering_rows,
       @source_rows AS source_rows,
       @event_rows AS event_rows,
       @offerings_owned AS offerings_owned,
       @sources_owned AS sources_owned,
       @events_owned AS events_owned;

SELECT 'ROLLBACK_ADOPTED' AS report_section, object_name,
       CASE WHEN exists_flag = 1 AND owned_flag = 0 THEN 'ADOPTED_DO_NOT_DROP' ELSE IF(owned_flag = 1, 'OWNED', 'ABSENT') END AS ownership
FROM (
    SELECT 'supplementary_exam_offerings' AS object_name, @offerings_exist AS exists_flag, @offerings_owned AS owned_flag
    UNION ALL SELECT 'supplementary_exam_offering_sources', @sources_exist, @sources_owned
    UNION ALL SELECT 'supplementary_exam_offering_events', @events_exist, @events_owned
) objects;

-- Drop child tables first. Never drop adopted objects. Never drop when in use.
SET @sql := IF(
    @rollback_status = 'READY' AND @events_exist = 1 AND @events_owned = 1 AND @event_rows = 0,
    'DROP TABLE IF EXISTS `alrowad_uni_rust`.`supplementary_exam_offering_events`',
    'SELECT ''SKIPPED_DROP_EVENTS'' AS rollback_result'
);
PREPARE phase2_rb_drop_events FROM @sql;
EXECUTE phase2_rb_drop_events;
DEALLOCATE PREPARE phase2_rb_drop_events;

SET @sql := IF(
    @rollback_status = 'READY' AND @sources_exist = 1 AND @sources_owned = 1 AND @source_rows = 0,
    'DROP TABLE IF EXISTS `alrowad_uni_rust`.`supplementary_exam_offering_sources`',
    'SELECT ''SKIPPED_DROP_SOURCES'' AS rollback_result'
);
PREPARE phase2_rb_drop_sources FROM @sql;
EXECUTE phase2_rb_drop_sources;
DEALLOCATE PREPARE phase2_rb_drop_sources;

SET @sql := IF(
    @rollback_status = 'READY' AND @offerings_exist = 1 AND @offerings_owned = 1 AND @offering_rows = 0,
    'DROP TABLE IF EXISTS `alrowad_uni_rust`.`supplementary_exam_offerings`',
    'SELECT ''SKIPPED_DROP_OFFERINGS'' AS rollback_result'
);
PREPARE phase2_rb_drop_offerings FROM @sql;
EXECUTE phase2_rb_drop_offerings;
DEALLOCATE PREPARE phase2_rb_drop_offerings;

START TRANSACTION;

DELETE rp FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @rollback_status = 'READY'
  AND p.permission_code IN ('supplementary_exams.offerings.view', 'supplementary_exams.offerings.manage')
  AND p.description LIKE '%[phase2-supplementary-exam-offerings]%';

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @rollback_status = 'READY'
  AND permission_code IN ('supplementary_exams.offerings.view', 'supplementary_exams.offerings.manage')
  AND description LIKE '%[phase2-supplementary-exam-offerings]%';

SET @sql := IF(@rollback_status = 'READY', 'COMMIT', 'ROLLBACK');
PREPARE phase2_rb_rbac FROM @sql;
EXECUTE phase2_rb_rbac;
DEALLOCATE PREPARE phase2_rb_rbac;

SELECT
    'ROLLBACK_RESULT' AS report_section,
    @rollback_status AS result,
    @offering_rows AS offering_rows,
    @source_rows AS source_rows,
    @event_rows AS event_rows;
