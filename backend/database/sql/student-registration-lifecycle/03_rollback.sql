-- Conservative rollback for Phase 9 withdrawal workflow objects.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not use DATABASE(), stored procedures, DELIMITER, or SIGNAL.
--
-- BACKUP FIRST.
-- Never drops original registration / advising tables.
-- Never deletes student_course_registrations, attendance, or grades.
--
-- IMPORTANT: optional Phase 9 tables are never referenced inside a
-- statically parsed IF() subquery. Presence is read from information_schema;
-- business-row counts use guarded PREPARE/EXECUTE.
--
-- Safe when Phase 9 objects are absent, partial, or fully present and unused.
-- BLOCKED_IN_USE if any withdrawal request or event row exists.
-- History is never deleted merely to allow rollback.
--
-- Drop order when READY: events table, requests table, then unused RBAC rows.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @srwr_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_withdrawal_requests' AND table_type = 'BASE TABLE'), 0);
SET @srwe_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'student_registration_withdrawal_events' AND table_type = 'BASE TABLE'), 0);

SET @request_rows := 0;
SET @event_rows := 0;

SET @sql := IF(
    @srwr_exists = 1,
    'SELECT @request_rows := COUNT(*) FROM `alrowad_uni_rust`.`student_registration_withdrawal_requests`',
    'SELECT @request_rows := 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @srwe_exists = 1,
    'SELECT @event_rows := COUNT(*) FROM `alrowad_uni_rust`.`student_registration_withdrawal_events`',
    'SELECT @event_rows := 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @in_use := IF(@request_rows > 0 OR @event_rows > 0, 1, 0);

SET @anything_present := IF(
    @srwr_exists = 1
    OR @srwe_exists = 1
    OR (
        @db_ready = 1
        AND EXISTS (
            SELECT 1 FROM `alrowad_uni_rust`.`permissions`
            WHERE permission_code IN ('registration_withdrawals.view', 'registration_withdrawals.review')
        )
    ),
    1, 0
);

SET @rollback_ready := IF(@db_ready = 1 AND @in_use = 0, 1, 0);

SELECT
    'rollback_precheck' AS report_section,
    @srwr_exists AS requests_table_present,
    @srwe_exists AS events_table_present,
    @request_rows AS request_rows,
    @event_rows AS event_rows,
    IF(@in_use = 1, 'BLOCKED_IN_USE', IF(@rollback_ready = 1, 'READY', 'BLOCKED')) AS rollback_status;

SET @sql := IF(
    @rollback_ready = 1 AND @srwe_exists = 1,
    'DROP TABLE `alrowad_uni_rust`.`student_registration_withdrawal_events`',
    'SELECT ''skip_drop_events'' AS rollback_step'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @rollback_ready = 1 AND @srwr_exists = 1,
    'DROP TABLE `alrowad_uni_rust`.`student_registration_withdrawal_requests`',
    'SELECT ''skip_drop_requests'' AS rollback_step'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @rollback_ready = 1,
    'DELETE rp FROM `alrowad_uni_rust`.`role_permissions` rp
     INNER JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
     WHERE p.permission_code IN (''registration_withdrawals.view'', ''registration_withdrawals.review'')',
    'SELECT ''skip_delete_role_permissions'' AS rollback_step'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @rollback_ready = 1,
    'DELETE FROM `alrowad_uni_rust`.`permissions`
     WHERE permission_code IN (''registration_withdrawals.view'', ''registration_withdrawals.review'')',
    'SELECT ''skip_delete_permissions'' AS rollback_step'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT
    'rollback_complete' AS report_section,
    IF(@in_use = 1, 'BLOCKED_IN_USE', IF(@rollback_ready = 1, 'ROLLED_BACK', 'BLOCKED')) AS rollback_status,
    'Original registration tables were not dropped.' AS note;
