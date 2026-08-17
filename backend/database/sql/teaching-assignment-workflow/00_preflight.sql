-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE application data.
-- SET user variables and temporary reporting tables only.
-- Do not use DATABASE().
--
-- Target workflow tables and RBAC objects are classified ABSENT / COMPATIBLE / CONFLICT.
-- OVERALL is BLOCKED when any target object is CONFLICT or a prerequisite is missing.
-- Existing legacy course_offering_instructors rows are informational and are NOT a blocker.

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
            SELECT 'course_offering_instructors' AS table_name, 'course_offering_instructor_id' AS column_name
            UNION ALL SELECT 'course_offering_instructors', 'course_offering_id'
            UNION ALL SELECT 'course_offering_instructors', 'faculty_member_id'
            UNION ALL SELECT 'course_offering_instructors', 'instructor_role'
            UNION ALL SELECT 'course_offering_instructors', 'is_active'
            UNION ALL SELECT 'course_offering_instructors', 'is_primary'
            UNION ALL SELECT 'course_offerings', 'course_offering_id'
            UNION ALL SELECT 'course_offerings', 'course_id'
            UNION ALL SELECT 'course_offerings', 'academic_program_id'
            UNION ALL SELECT 'course_offerings', 'academic_year_id'
            UNION ALL SELECT 'course_offerings', 'semester_id'
            UNION ALL SELECT 'faculty_members', 'faculty_member_id'
            UNION ALL SELECT 'faculty_members', 'employee_id'
            UNION ALL SELECT 'faculty_members', 'is_active'
            UNION ALL SELECT 'employees', 'employee_id'
            UNION ALL SELECT 'employees', 'organizational_unit_id'
            UNION ALL SELECT 'users', 'user_id'
            UNION ALL SELECT 'roles', 'role_id'
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'roles', 'is_active'
            UNION ALL SELECT 'permissions', 'permission_id'
            UNION ALL SELECT 'permissions', 'module_id'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'permissions', 'permission_name'
            UNION ALL SELECT 'permissions', 'description'
            UNION ALL SELECT 'permissions', 'is_active'
            UNION ALL SELECT 'role_permissions', 'role_id'
            UNION ALL SELECT 'role_permissions', 'permission_id'
            UNION ALL SELECT 'system_modules', 'module_id'
            UNION ALL SELECT 'system_modules', 'module_code'
            UNION ALL SELECT 'system_modules', 'is_active'
            UNION ALL SELECT 'user_roles', 'user_id'
            UNION ALL SELECT 'user_roles', 'role_id'
            UNION ALL SELECT 'user_access_scopes', 'scope_type'
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

-- ---------------------------------------------------------------------------
-- A. EXISTING EFFECTIVE ASSIGNMENT TABLE
-- ---------------------------------------------------------------------------
SELECT 'A_coi_columns' AS report_section, column_name, column_type, is_nullable, column_key, extra
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offering_instructors'
ORDER BY ordinal_position;

SELECT 'A_coi_indexes' AS report_section, index_name, column_name, non_unique, seq_in_index
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offering_instructors'
ORDER BY index_name, seq_in_index;

SELECT 'A_coi_foreign_keys' AS report_section,
       constraint_name, column_name, referenced_table_name, referenced_column_name
FROM information_schema.key_column_usage
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'course_offering_instructors'
  AND referenced_table_name IS NOT NULL
ORDER BY constraint_name, ordinal_position;

SELECT 'A_coi_role_values' AS report_section,
       instructor_role,
       COUNT(*) AS row_count,
       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count,
       SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
FROM `alrowad_uni_rust`.`course_offering_instructors`
WHERE @structure_ok = 1
GROUP BY instructor_role
ORDER BY instructor_role;

-- ---------------------------------------------------------------------------
-- B. EXISTING ASSIGNMENTS
-- ---------------------------------------------------------------------------
SELECT 'B_effective_active_count' AS report_section,
       COUNT(*) AS active_assignments
FROM `alrowad_uni_rust`.`course_offering_instructors`
WHERE @structure_ok = 1
  AND is_active = 1;

SELECT 'B_offering_role_duplicates' AS report_section,
       course_offering_id, instructor_role, COUNT(*) AS row_count
FROM `alrowad_uni_rust`.`course_offering_instructors`
WHERE @structure_ok = 1
GROUP BY course_offering_id, instructor_role
HAVING COUNT(*) > 1;

SELECT 'B_teacher_multiple_offerings' AS report_section,
       faculty_member_id,
       COUNT(DISTINCT course_offering_id) AS offering_count
FROM `alrowad_uni_rust`.`course_offering_instructors`
WHERE @structure_ok = 1
  AND is_active = 1
GROUP BY faculty_member_id
HAVING COUNT(DISTINCT course_offering_id) > 1
ORDER BY offering_count DESC;

SELECT 'B_teacher_both_components' AS report_section,
       faculty_member_id,
       course_offering_id,
       COUNT(DISTINCT instructor_role) AS role_count
FROM `alrowad_uni_rust`.`course_offering_instructors`
WHERE @structure_ok = 1
  AND is_active = 1
GROUP BY faculty_member_id, course_offering_id
HAVING COUNT(DISTINCT instructor_role) > 1;

-- ---------------------------------------------------------------------------
-- C. TEACHING WORKFLOW TABLE COLLISIONS
-- ---------------------------------------------------------------------------
SET @requests_rows := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_requests'),
    0
);
SET @reviews_rows := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_reviews'),
    0
);
SET @events_rows := IF(
    @db_ready = 1,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'teaching_assignment_events'),
    0
);

SET @requests_expected_cols := IF(
    @requests_rows = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'teaching_assignment_request_id' AS column_name
            UNION ALL SELECT 'course_offering_id'
            UNION ALL SELECT 'faculty_member_id'
            UNION ALL SELECT 'instructor_role'
            UNION ALL SELECT 'status'
            UNION ALL SELECT 'submission_version'
            UNION ALL SELECT 'current_slot'
            UNION ALL SELECT 'requested_by_user_id'
            UNION ALL SELECT 'submitted_at'
            UNION ALL SELECT 'approved_at'
            UNION ALL SELECT 'superseded_at'
            UNION ALL SELECT 'superseded_by_request_id'
            UNION ALL SELECT 'created_at'
            UNION ALL SELECT 'updated_at'
        ) expected
        JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = 'teaching_assignment_requests'
           AND existing.column_name = expected.column_name
    ),
    0
);
SET @reviews_expected_cols := IF(
    @reviews_rows = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'teaching_assignment_review_id' AS column_name
            UNION ALL SELECT 'teaching_assignment_request_id'
            UNION ALL SELECT 'review_authority'
            UNION ALL SELECT 'status'
            UNION ALL SELECT 'reviewed_by_user_id'
            UNION ALL SELECT 'reviewed_at'
            UNION ALL SELECT 'reason'
            UNION ALL SELECT 'created_at'
            UNION ALL SELECT 'updated_at'
        ) expected
        JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = 'teaching_assignment_reviews'
           AND existing.column_name = expected.column_name
    ),
    0
);
SET @events_expected_cols := IF(
    @events_rows = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT 'teaching_assignment_event_id' AS column_name
            UNION ALL SELECT 'teaching_assignment_request_id'
            UNION ALL SELECT 'event_type'
            UNION ALL SELECT 'actor_user_id'
            UNION ALL SELECT 'submission_version'
            UNION ALL SELECT 'notes'
            UNION ALL SELECT 'created_at'
        ) expected
        JOIN information_schema.columns existing
            ON existing.table_schema = 'alrowad_uni_rust'
           AND existing.table_name = 'teaching_assignment_events'
           AND existing.column_name = expected.column_name
    ),
    0
);

SET @requests_unique_ok := IF(
    @requests_rows = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_requests'
          AND index_name = 'uq_tar_current_slot'
          AND non_unique = 0
          AND (
              (seq_in_index = 1 AND column_name = 'course_offering_id')
           OR (seq_in_index = 2 AND column_name = 'instructor_role')
           OR (seq_in_index = 3 AND column_name = 'current_slot')
          )
    ) = 3,
    0
);
SET @reviews_unique_ok := IF(
    @reviews_rows = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'teaching_assignment_reviews'
          AND index_name = 'uq_tarv_request_authority'
          AND non_unique = 0
          AND (
              (seq_in_index = 1 AND column_name = 'teaching_assignment_request_id')
           OR (seq_in_index = 2 AND column_name = 'review_authority')
          )
    ) = 2,
    0
);

SET @requests_state := IF(@requests_rows = 0, 'ABSENT', IF(@requests_expected_cols = 14 AND @requests_unique_ok = 1, 'COMPATIBLE', 'CONFLICT'));
SET @reviews_state := IF(@reviews_rows = 0, 'ABSENT', IF(@reviews_expected_cols = 9 AND @reviews_unique_ok = 1, 'COMPATIBLE', 'CONFLICT'));
SET @events_state := IF(@events_rows = 0, 'ABSENT', IF(@events_expected_cols = 7, 'COMPATIBLE', 'CONFLICT'));

SELECT 'C_workflow_table_states' AS report_section,
       @requests_state AS teaching_assignment_requests,
       @reviews_state AS teaching_assignment_reviews,
       @events_state AS teaching_assignment_events;

-- ---------------------------------------------------------------------------
-- D. FACULTY STRUCTURE
-- ---------------------------------------------------------------------------
SELECT 'D_faculty_members_columns' AS report_section, column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'faculty_members'
ORDER BY ordinal_position;

SELECT 'D_employees_active_fields' AS report_section, column_name, column_type
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN ('employees', 'employee_statuses', 'employee_unit_assignments')
  AND column_name IN ('employee_id', 'organizational_unit_id', 'status_code', 'is_active', 'employee_status_id')
ORDER BY table_name, column_name;

-- ---------------------------------------------------------------------------
-- E. CURRENT CROSS-COLLEGE DATA
-- ---------------------------------------------------------------------------
SELECT 'E_cross_college_assignments' AS report_section,
       coi.course_offering_instructor_id,
       coi.course_offering_id,
       coi.faculty_member_id,
       offering_dept.college_id AS offering_college_id,
       home_college.college_id AS instructor_home_college_id
FROM `alrowad_uni_rust`.`course_offering_instructors` coi
JOIN `alrowad_uni_rust`.`course_offerings` o
    ON o.course_offering_id = coi.course_offering_id
LEFT JOIN `alrowad_uni_rust`.`departments` offering_dept
    ON offering_dept.department_id = o.department_id
LEFT JOIN `alrowad_uni_rust`.`faculty_members` fm
    ON fm.faculty_member_id = coi.faculty_member_id
LEFT JOIN `alrowad_uni_rust`.`employees` e
    ON e.employee_id = fm.employee_id
LEFT JOIN `alrowad_uni_rust`.`colleges` home_college
    ON home_college.organizational_unit_id = e.organizational_unit_id
WHERE @structure_ok = 1
  AND coi.is_active = 1
  AND offering_dept.college_id IS NOT NULL
  AND home_college.college_id IS NOT NULL
  AND offering_dept.college_id <> home_college.college_id;

-- ---------------------------------------------------------------------------
-- F. EXISTING RBAC
-- ---------------------------------------------------------------------------
SELECT 'F_phase3_roles' AS report_section, role_id, role_code, role_name, is_active
FROM `alrowad_uni_rust`.`roles`
WHERE @structure_ok = 1
  AND role_code IN (
      'dean',
      'vice_president',
      'vice_president_scientific',
      'vice_president_administrative'
  )
ORDER BY role_code;

SELECT 'F_phase3_base_permissions' AS report_section, permission_id, permission_code, permission_name, is_active
FROM `alrowad_uni_rust`.`permissions`
WHERE @structure_ok = 1
  AND permission_code IN (
      'vice_presidency.scientific.access',
      'vice_presidency.administrative.access'
  )
ORDER BY permission_code;

SET @dean_role_exists := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'dean' AND is_active = 1), 0);
SET @sci_role_exists := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_scientific' AND is_active = 1), 0);
SET @adm_role_exists := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative' AND is_active = 1), 0);
SET @phase3_sci_perm := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'vice_presidency.scientific.access' AND is_active = 1), 0);
SET @phase3_adm_perm := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'vice_presidency.administrative.access' AND is_active = 1), 0);

-- ---------------------------------------------------------------------------
-- G. EXISTING TEACHING PERMISSIONS
-- ---------------------------------------------------------------------------
SELECT 'G_existing_teaching_permissions' AS report_section,
       p.permission_id, p.permission_code, p.permission_name, sm.module_code, p.is_active
FROM `alrowad_uni_rust`.`permissions` p
LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
WHERE @structure_ok = 1
  AND (
      p.permission_code LIKE 'teaching%'
      OR p.permission_code LIKE 'faculty%'
      OR p.permission_code LIKE '%instructor%'
  )
ORDER BY p.permission_code;

-- ---------------------------------------------------------------------------
-- H. COURSE OFFERING CONTEXT
-- ---------------------------------------------------------------------------
SET @offering_identity_index := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offerings'
          AND index_name = 'uq_course_offering_program_term'
          AND non_unique = 0
          AND (
              (seq_in_index = 1 AND column_name = 'course_id')
           OR (seq_in_index = 2 AND column_name = 'academic_program_id')
           OR (seq_in_index = 3 AND column_name = 'academic_year_id')
           OR (seq_in_index = 4 AND column_name = 'semester_id')
          )
    ) = 4,
    0
);

SELECT 'H_offering_identity_index' AS report_section,
       IF(@offering_identity_index = 1, 'PRESENT', 'MISSING') AS uq_course_offering_program_term;

-- ---------------------------------------------------------------------------
-- I. FK TARGETS
-- ---------------------------------------------------------------------------
SET @fk_targets_ok := IF(
    @structure_ok = 1
    AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'users')
    AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'faculty_members')
    AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'course_offerings'),
    1,
    0
);

SELECT 'I_fk_targets' AS report_section,
       IF(@fk_targets_ok = 1, 'PRESENT', 'MISSING') AS users_faculty_offerings;

-- ---------------------------------------------------------------------------
-- J. TABLE ENGINES
-- ---------------------------------------------------------------------------
SELECT 'J_table_engines' AS report_section, table_name, engine
FROM information_schema.tables
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN (
      'users',
      'faculty_members',
      'course_offerings',
      'course_offering_instructors',
      'roles',
      'permissions',
      'role_permissions',
      'system_modules',
      'teaching_assignment_requests',
      'teaching_assignment_reviews',
      'teaching_assignment_events'
  )
ORDER BY table_name;

SET @engines_ok := IF(
    @structure_ok = 1
    AND (
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name IN ('users', 'faculty_members', 'course_offerings', 'course_offering_instructors', 'roles', 'permissions', 'role_permissions')
          AND engine = 'InnoDB'
    ) = 7,
    1,
    0
);

-- ---------------------------------------------------------------------------
-- K. LEGACY EFFECTIVE ASSIGNMENT COUNT
-- ---------------------------------------------------------------------------
SET @legacy_effective_count := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`course_offering_instructors` WHERE is_active = 1),
    NULL
);

SELECT 'K_legacy_effective_count' AS report_section, @legacy_effective_count AS active_course_offering_instructors;

-- Permission target states
SET @hr_module_ok := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'hr' AND is_active = 1), 0);

SET @view_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.view'), 0);
SET @manage_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.manage'), 0);
SET @sci_review_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_scientific'), 0);
SET @adm_review_perm_rows := IF(@structure_ok = 1, (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'teaching_assignments.review_administrative'), 0);

SET @view_perm_compatible := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'teaching_assignments.view'
          AND p.is_active = 1
          AND sm.module_code = 'hr'
    ),
    0
);
SET @manage_perm_compatible := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'teaching_assignments.manage'
          AND p.is_active = 1
          AND sm.module_code = 'hr'
    ),
    0
);
SET @sci_review_perm_compatible := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'teaching_assignments.review_scientific'
          AND p.is_active = 1
          AND sm.module_code = 'hr'
    ),
    0
);
SET @adm_review_perm_compatible := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'teaching_assignments.review_administrative'
          AND p.is_active = 1
          AND sm.module_code = 'hr'
    ),
    0
);

SET @view_perm_state := IF(@view_perm_rows = 0, 'ABSENT', IF(@view_perm_rows = 1 AND @view_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @manage_perm_state := IF(@manage_perm_rows = 0, 'ABSENT', IF(@manage_perm_rows = 1 AND @manage_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @sci_review_perm_state := IF(@sci_review_perm_rows = 0, 'ABSENT', IF(@sci_review_perm_rows = 1 AND @sci_review_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));
SET @adm_review_perm_state := IF(@adm_review_perm_rows = 0, 'ABSENT', IF(@adm_review_perm_rows = 1 AND @adm_review_perm_compatible = 1, 'COMPATIBLE', 'CONFLICT'));

SET @coi_unique_ok := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_instructors'
          AND index_name = 'uq_course_offering_role'
          AND non_unique = 0
          AND (
              (seq_in_index = 1 AND column_name = 'course_offering_id')
           OR (seq_in_index = 2 AND column_name = 'instructor_role')
          )
    ) = 2,
    0
);

SET @permissions_code_unique := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'permissions'
          AND column_name = 'permission_code'
          AND non_unique = 0
    ),
    0
);

SET @overall := IF(
    @db_ready = 1
    AND @missing_required_columns = 0
    AND @requests_state IN ('ABSENT', 'COMPATIBLE')
    AND @reviews_state IN ('ABSENT', 'COMPATIBLE')
    AND @events_state IN ('ABSENT', 'COMPATIBLE')
    AND @view_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @manage_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @sci_review_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @adm_review_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @dean_role_exists = 1
    AND @sci_role_exists = 1
    AND @adm_role_exists = 1
    AND @phase3_sci_perm = 1
    AND @phase3_adm_perm = 1
    AND @offering_identity_index = 1
    AND @fk_targets_ok = 1
    AND @engines_ok = 1
    AND @coi_unique_ok = 1
    AND @hr_module_ok = 1
    AND @permissions_code_unique > 0,
    'READY',
    'BLOCKED'
);

SELECT 'OVERALL' AS report_section,
       @overall AS result,
       @missing_required_columns AS missing_required_columns,
       @requests_state AS requests_state,
       @reviews_state AS reviews_state,
       @events_state AS events_state,
       @view_perm_state AS view_perm_state,
       @manage_perm_state AS manage_perm_state,
       @sci_review_perm_state AS sci_review_perm_state,
       @adm_review_perm_state AS adm_review_perm_state,
       @dean_role_exists AS dean_role_exists,
       @sci_role_exists AS sci_role_exists,
       @adm_role_exists AS adm_role_exists,
       @phase3_sci_perm AS phase3_sci_perm,
       @phase3_adm_perm AS phase3_adm_perm,
       @offering_identity_index AS offering_identity_index,
       @fk_targets_ok AS fk_targets_ok,
       @engines_ok AS engines_ok,
       @coi_unique_ok AS coi_unique_ok,
       @hr_module_ok AS hr_module_ok,
       @legacy_effective_count AS legacy_effective_count;
