-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE application data.
-- SET user variables and temporary reporting tables only.
-- Do not use DATABASE().
--
-- Phase 8 Teaching Assignment lifecycle (assign / replace / remove).
-- Extends existing teaching_assignment_requests. No second workflow tables.
-- New objects classified independently: ABSENT / COMPATIBLE / CONFLICT.
-- A mix of ABSENT + COMPATIBLE is READY. CONFLICT or missing Phase 4/7 is BLOCKED.
-- Phase 7 closure tables are required because pure removal needs CLOSED offerings.
-- No RBAC mutation.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SET @missing_required_columns := IF(
    @db_ready = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'teaching_assignment_requests' AS table_name, 'teaching_assignment_request_id' AS column_name
            UNION ALL SELECT 'teaching_assignment_requests', 'course_offering_id'
            UNION ALL SELECT 'teaching_assignment_requests', 'faculty_member_id'
            UNION ALL SELECT 'teaching_assignment_requests', 'instructor_role'
            UNION ALL SELECT 'teaching_assignment_requests', 'status'
            UNION ALL SELECT 'teaching_assignment_requests', 'current_slot'
            UNION ALL SELECT 'teaching_assignment_reviews', 'teaching_assignment_review_id'
            UNION ALL SELECT 'teaching_assignment_reviews', 'teaching_assignment_request_id'
            UNION ALL SELECT 'teaching_assignment_reviews', 'review_authority'
            UNION ALL SELECT 'teaching_assignment_events', 'teaching_assignment_event_id'
            UNION ALL SELECT 'teaching_assignment_events', 'event_type'
            UNION ALL SELECT 'course_offering_instructors', 'course_offering_instructor_id'
            UNION ALL SELECT 'course_offering_instructors', 'course_offering_id'
            UNION ALL SELECT 'course_offering_instructors', 'faculty_member_id'
            UNION ALL SELECT 'course_offering_instructors', 'instructor_role'
            UNION ALL SELECT 'course_offering_instructors', 'is_active'
            UNION ALL SELECT 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'course_offerings', 'status'
            UNION ALL SELECT 'users', 'user_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'role_permissions', 'role_id'
        ) required_columns
        LEFT JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = required_columns.table_name
           AND existing.column_name = required_columns.column_name
        WHERE existing.column_name IS NULL
    ),
    1
);

SET @structure_ok := IF(@db_ready = 1 AND @missing_required_columns = 0, 1, 0);

SET @uq_current_slot := IF(
    @structure_ok = 1 AND (
        SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'uq_tar_current_slot'
          AND non_unique = 0
    ) <=> 'course_offering_id,instructor_role,current_slot',
    1, 0
);

SET @phase7_requests := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_requests' AND table_type = 'BASE TABLE'), 0);
SET @phase7_reviews := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_reviews' AND table_type = 'BASE TABLE'), 0);
SET @phase7_events := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offering_closure_events' AND table_type = 'BASE TABLE'), 0);
SET @phase7_ok := IF(@phase7_requests = 1 AND @phase7_reviews = 1 AND @phase7_events = 1, 1, 0);

SET @action_type_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), 0);
SET @action_reason_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason'), 0);
SET @target_slot_exists := IF(@db_ready = 1, (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id'), 0);

SET @action_type_state := CASE
    WHEN @action_type_exists = 0 THEN 'ABSENT'
    WHEN @action_type_exists = 1
     AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type') = 'varchar'
     AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type') = 'NO'
     AND (SELECT IFNULL(character_maximum_length, 0) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type') >= 16
     AND TRIM(BOTH '''' FROM IFNULL((SELECT column_default FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_type'), '')) = 'assign'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @action_reason_state := CASE
    WHEN @action_reason_exists = 0 THEN 'ABSENT'
    WHEN @action_reason_exists = 1
     AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason') IN ('text', 'varchar', 'mediumtext', 'longtext')
     AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'action_reason') = 'YES'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @target_slot_state := CASE
    WHEN @target_slot_exists = 0 THEN 'ABSENT'
    WHEN @target_slot_exists = 1
     AND (SELECT LOWER(data_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id') = 'int'
     AND (SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id') = 'YES'
     AND (SELECT LOWER(column_type) FROM information_schema.columns WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests' AND column_name = 'target_course_offering_instructor_id') NOT LIKE '%unsigned%'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @fk_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND constraint_name = 'fk_tar_target_instructor'
       AND constraint_type = 'FOREIGN KEY'),
    0
);
SET @fk_state := CASE
    WHEN @fk_exists = 0 THEN 'ABSENT'
    WHEN @fk_exists = 1
     AND (SELECT COUNT(*) FROM information_schema.key_column_usage
          WHERE table_schema = 'alrowad_uni_rust'
            AND table_name = 'teaching_assignment_requests'
            AND constraint_name = 'fk_tar_target_instructor'
            AND column_name = 'target_course_offering_instructor_id'
            AND referenced_table_name = 'course_offering_instructors'
            AND referenced_column_name = 'course_offering_instructor_id') = 1
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @idx_exists := IF(
    @db_ready = 1,
    (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
     WHERE table_schema = 'alrowad_uni_rust'
       AND table_name = 'teaching_assignment_requests'
       AND index_name = 'idx_tar_action_status'),
    0
);
SET @idx_state := CASE
    WHEN @idx_exists = 0 THEN 'ABSENT'
    WHEN @idx_exists = 1
     AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
          FROM information_schema.statistics
          WHERE table_schema = 'alrowad_uni_rust'
            AND table_name = 'teaching_assignment_requests'
            AND index_name = 'idx_tar_action_status') <=> 'action_type,status'
    THEN 'COMPATIBLE'
    ELSE 'CONFLICT'
END;

SET @rbac_ok := IF(
    @structure_ok = 1
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.manage' AND is_active = 1)
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_scientific' AND is_active = 1)
    AND EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_administrative' AND is_active = 1)
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'dean' AND p.permission_code = 'teaching_assignments.manage'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific' AND p.permission_code = 'teaching_assignments.review_scientific'
    )
    AND EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative' AND p.permission_code = 'teaching_assignments.review_administrative'
    ),
    1, 0
);

SET @phase8_conflict := IF(
    @action_type_state = 'CONFLICT'
    OR @action_reason_state = 'CONFLICT'
    OR @target_slot_state = 'CONFLICT'
    OR @fk_state = 'CONFLICT'
    OR @idx_state = 'CONFLICT',
    1, 0
);

SELECT 'A_required_tables' AS report_section, table_name, engine
FROM information_schema.tables
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'teaching_assignment_requests', 'teaching_assignment_reviews', 'teaching_assignment_events',
      'course_offering_instructors', 'course_offerings',
      'course_offering_closure_requests', 'course_offering_closure_reviews', 'course_offering_closure_events'
  )
ORDER BY table_name;

SELECT 'B_missing_required_columns' AS report_section, required_columns.table_name, required_columns.column_name
FROM (
    SELECT 'teaching_assignment_requests' AS table_name, 'teaching_assignment_request_id' AS column_name
    UNION ALL SELECT 'teaching_assignment_requests', 'course_offering_id'
    UNION ALL SELECT 'teaching_assignment_requests', 'faculty_member_id'
    UNION ALL SELECT 'teaching_assignment_requests', 'instructor_role'
    UNION ALL SELECT 'teaching_assignment_requests', 'status'
    UNION ALL SELECT 'teaching_assignment_requests', 'current_slot'
    UNION ALL SELECT 'teaching_assignment_reviews', 'teaching_assignment_review_id'
    UNION ALL SELECT 'teaching_assignment_reviews', 'teaching_assignment_request_id'
    UNION ALL SELECT 'teaching_assignment_reviews', 'review_authority'
    UNION ALL SELECT 'teaching_assignment_events', 'teaching_assignment_event_id'
    UNION ALL SELECT 'teaching_assignment_events', 'event_type'
    UNION ALL SELECT 'course_offering_instructors', 'course_offering_instructor_id'
    UNION ALL SELECT 'course_offering_instructors', 'course_offering_id'
    UNION ALL SELECT 'course_offering_instructors', 'faculty_member_id'
    UNION ALL SELECT 'course_offering_instructors', 'instructor_role'
    UNION ALL SELECT 'course_offering_instructors', 'is_active'
    UNION ALL SELECT 'course_offerings', 'course_offering_id'
    UNION ALL SELECT 'course_offerings', 'status'
    UNION ALL SELECT 'users', 'user_id'
    UNION ALL SELECT 'roles', 'role_code'
    UNION ALL SELECT 'permissions', 'permission_code'
    UNION ALL SELECT 'role_permissions', 'role_id'
) required_columns
LEFT JOIN information_schema.columns existing
    ON existing.table_schema = 'alrowad_uni_rust'
   AND existing.table_name = required_columns.table_name
   AND existing.column_name = required_columns.column_name
WHERE existing.column_name IS NULL
ORDER BY required_columns.table_name, required_columns.column_name;

SELECT 'C_phase8_objects' AS report_section, object_name, object_state
FROM (
    SELECT 'action_type' AS object_name, @action_type_state AS object_state
    UNION ALL SELECT 'action_reason', @action_reason_state
    UNION ALL SELECT 'target_course_offering_instructor_id', @target_slot_state
    UNION ALL SELECT 'fk_tar_target_instructor', @fk_state
    UNION ALL SELECT 'idx_tar_action_status', @idx_state
) objects;

SELECT 'D_phase7_closure_infrastructure' AS report_section,
       @phase7_requests AS course_offering_closure_requests,
       @phase7_reviews AS course_offering_closure_reviews,
       @phase7_events AS course_offering_closure_events,
       @phase7_ok AS phase7_ok;

SET @overall := IF(
    @db_ready = 1
    AND @structure_ok = 1
    AND @uq_current_slot = 1
    AND @phase7_ok = 1
    AND @rbac_ok = 1
    AND @phase8_conflict = 0,
    'READY',
    'BLOCKED'
);

SELECT 'OVERALL' AS report_section,
       @overall AS result,
       @missing_required_columns AS missing_required_columns,
       @uq_current_slot AS uq_tar_current_slot,
       @phase7_ok AS phase7_ok,
       @rbac_ok AS rbac_ok,
       @phase8_conflict AS phase8_conflict,
       @action_type_state AS action_type_state,
       @action_reason_state AS action_reason_state,
       @target_slot_state AS target_slot_state,
       @fk_state AS fk_state,
       @idx_state AS idx_state;
