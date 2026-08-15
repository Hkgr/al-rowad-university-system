-- Manual and idempotent. Run only after 00_preflight.sql returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- DDL commits implicitly in MariaDB; do not wrap this file in a transaction.

-- ---------------------------------------------------------------------------
-- Resolve identities by code (never hardcode numeric IDs)
-- ---------------------------------------------------------------------------
SET @fmf_college_id := (
    SELECT college_id
    FROM `alrowad_uni_rust`.`colleges`
    WHERE college_code = 'FMF'
    LIMIT 1
);
SET @college_type_id := (
    SELECT unit_type_id
    FROM `alrowad_uni_rust`.`organizational_unit_types`
    WHERE type_code = 'college'
      AND is_active = 1
    LIMIT 1
);
SET @colleges_container_id := (
    SELECT u.organizational_unit_id
    FROM `alrowad_uni_rust`.`organizational_units` u
    INNER JOIN `alrowad_uni_rust`.`organizational_units` p
        ON p.organizational_unit_id = u.parent_unit_id
    WHERE u.unit_code = '811'
      AND u.is_active = 1
      AND p.unit_code = '81'
    LIMIT 1
);
SET @instructor_position_id := (
    SELECT position_id
    FROM `alrowad_uni_rust`.`positions`
    WHERE position_code = 'INSTRUCTOR'
    LIMIT 1
);
SET @hr_module_id := (
    SELECT module_id
    FROM `alrowad_uni_rust`.`system_modules`
    WHERE module_code = 'hr'
      AND is_active = 1
    LIMIT 1
);
SET @dean_role_id := (
    SELECT role_id
    FROM `alrowad_uni_rust`.`roles`
    WHERE role_code = 'dean'
      AND is_active = 1
    LIMIT 1
);

SELECT
    'resolved_ids' AS report_section,
    @fmf_college_id AS fmf_college_id,
    @college_type_id AS college_type_id,
    @colleges_container_id AS colleges_container_id,
    @instructor_position_id AS instructor_position_id,
    @hr_module_id AS hr_module_id,
    @dean_role_id AS dean_role_id;

-- ---------------------------------------------------------------------------
-- SECTION A: resolve / link FMF organizational unit
-- ---------------------------------------------------------------------------
INSERT INTO `alrowad_uni_rust`.`organizational_units` (
    unit_code,
    unit_name,
    unit_type_id,
    parent_unit_id,
    description,
    is_active,
    created_at,
    updated_at
)
SELECT
    'FMF',
    'كلية العلوم الإدارية والمالية',
    @college_type_id,
    @colleges_container_id,
    'Operational organizational unit linked to the academic College record for كلية العلوم الإدارية والمالية (FMF).',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM DUAL
WHERE @fmf_college_id IS NOT NULL
  AND @college_type_id IS NOT NULL
  AND @colleges_container_id IS NOT NULL
  AND (
      SELECT organizational_unit_id
      FROM `alrowad_uni_rust`.`colleges`
      WHERE college_id = @fmf_college_id
  ) IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`organizational_units`
      WHERE unit_code = 'FMF'
  )
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`organizational_units`
      WHERE unit_name = 'كلية العلوم الإدارية والمالية'
        AND parent_unit_id = @colleges_container_id
  );

SET @fmf_unit_id := (
    SELECT COALESCE(
        (
            SELECT c.organizational_unit_id
            FROM `alrowad_uni_rust`.`colleges` c
            WHERE c.college_id = @fmf_college_id
              AND c.organizational_unit_id IS NOT NULL
        ),
        (
            SELECT u.organizational_unit_id
            FROM `alrowad_uni_rust`.`organizational_units` u
            INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                ON t.unit_type_id = u.unit_type_id
            WHERE u.unit_code = 'FMF'
              AND u.parent_unit_id = @colleges_container_id
              AND t.type_code = 'college'
              AND u.is_active = 1
            LIMIT 1
        ),
        (
            SELECT u.organizational_unit_id
            FROM `alrowad_uni_rust`.`organizational_units` u
            INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                ON t.unit_type_id = u.unit_type_id
            WHERE u.unit_name = 'كلية العلوم الإدارية والمالية'
              AND u.parent_unit_id = @colleges_container_id
              AND t.type_code = 'college'
              AND u.is_active = 1
            LIMIT 1
        )
    )
);

UPDATE `alrowad_uni_rust`.`colleges`
SET organizational_unit_id = @fmf_unit_id,
    updated_at = CURRENT_TIMESTAMP
WHERE college_code = 'FMF'
  AND organizational_unit_id IS NULL
  AND @fmf_unit_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM (
          SELECT college_id
          FROM `alrowad_uni_rust`.`colleges`
          WHERE organizational_unit_id = @fmf_unit_id
            AND college_code <> 'FMF'
      ) other
  );

SELECT
    'fmf_unit_link' AS report_section,
    college_id,
    college_code,
    organizational_unit_id
FROM `alrowad_uni_rust`.`colleges`
WHERE college_code = 'FMF';

-- ---------------------------------------------------------------------------
-- SECTION B: permissions
-- ---------------------------------------------------------------------------
INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id,
    permission_code,
    permission_name,
    description,
    is_active,
    created_at,
    updated_at
)
SELECT
    @hr_module_id,
    'teaching_staff.view',
    'Teaching Staff View',
    'View teaching staff affiliated with an accessible college',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM DUAL
WHERE @hr_module_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`permissions`
      WHERE permission_code = 'teaching_staff.view'
  );

INSERT INTO `alrowad_uni_rust`.`permissions` (
    module_id,
    permission_code,
    permission_name,
    description,
    is_active,
    created_at,
    updated_at
)
SELECT
    @hr_module_id,
    'teaching_staff.manage',
    'Teaching Staff Manage',
    'Manage teaching assignments for an accessible college',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM DUAL
WHERE @hr_module_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`permissions`
      WHERE permission_code = 'teaching_staff.manage'
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT
    r.role_id,
    p.permission_id,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
CROSS JOIN `alrowad_uni_rust`.`permissions` p
WHERE r.role_code = 'dean'
  AND r.is_active = 1
  AND p.permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id
        AND existing.permission_id = p.permission_id
  );

-- ---------------------------------------------------------------------------
-- SECTION C: normalize instructor_role
-- ENUM('Lead','Assistant') cannot store 'theoretical'; convert via VARCHAR.
-- ---------------------------------------------------------------------------
SET @instructor_role_type := (
    SELECT LOWER(column_type)
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'course_offering_instructors'
      AND column_name = 'instructor_role'
);
SET @sql := IF(
    @instructor_role_type IS NOT NULL
    AND @instructor_role_type NOT LIKE '%theoretical%'
    AND @instructor_role_type NOT LIKE 'varchar%',
    'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` MODIFY `instructor_role` VARCHAR(20) NOT NULL',
    'SELECT ''instructor_role already varchar or canonical'' AS skipped_enum_to_varchar'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `alrowad_uni_rust`.`course_offering_instructors` coi
INNER JOIN `alrowad_uni_rust`.`course_offerings` co
    ON co.course_offering_id = coi.course_offering_id
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
SET coi.instructor_role = 'theoretical',
    coi.updated_at = CURRENT_TIMESTAMP
WHERE LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
  AND c.theoretical_hours > 0
  AND IFNULL(c.practical_hours, 0) = 0
  AND IFNULL(coi.is_primary, 0) = 1
  AND (co.faculty_member_id IS NULL OR co.faculty_member_id = coi.faculty_member_id);

UPDATE `alrowad_uni_rust`.`course_offering_instructors` coi
INNER JOIN `alrowad_uni_rust`.`course_offerings` co
    ON co.course_offering_id = coi.course_offering_id
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
SET coi.instructor_role = 'practical',
    coi.updated_at = CURRENT_TIMESTAMP
WHERE LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
  AND IFNULL(c.theoretical_hours, 0) = 0
  AND c.practical_hours > 0
  AND IFNULL(coi.is_primary, 0) = 1
  AND (co.faculty_member_id IS NULL OR co.faculty_member_id = coi.faculty_member_id);

SELECT
    'instructor_role_after_normalize' AS report_section,
    instructor_role,
    COUNT(*) AS row_count
FROM `alrowad_uni_rust`.`course_offering_instructors`
GROUP BY instructor_role;

SET @invalid_roles := (
    SELECT COUNT(*)
    FROM `alrowad_uni_rust`.`course_offering_instructors`
    WHERE instructor_role NOT IN ('theoretical', 'practical')
);
SET @sql := IF(
    @invalid_roles = 0
    AND IFNULL(@instructor_role_type, '') NOT LIKE '%theoretical%practical%'
    AND IFNULL(@instructor_role_type, '') NOT LIKE '%practical%theoretical%',
    'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` MODIFY `instructor_role` ENUM(''theoretical'',''practical'') NOT NULL DEFAULT ''theoretical''',
    IF(
        @invalid_roles = 0,
        'SELECT ''instructor_role already canonical enum'' AS skipped_varchar_to_enum',
        'SELECT ''BLOCKED: invalid instructor_role values remain; ENUM conversion skipped'' AS result'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- SECTION D: unique index (offering + role)
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_instructors'
          AND index_name = 'idx_coi_offering'
    ) = 0,
    'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` ADD INDEX `idx_coi_offering` (`course_offering_id`)',
    'SELECT ''idx_coi_offering exists'' AS skipped_index'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_instructors'
          AND index_name = 'idx_coi_faculty'
    ) = 0,
    'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` ADD INDEX `idx_coi_faculty` (`faculty_member_id`)',
    'SELECT ''idx_coi_faculty exists'' AS skipped_index'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_instructors'
          AND index_name = 'uq_course_offering_instructor'
    ) > 0,
    'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` DROP INDEX `uq_course_offering_instructor`',
    'SELECT ''uq_course_offering_instructor already absent'' AS skipped_index'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_instructors'
          AND index_name = 'uq_course_offering_role'
    ) = 0,
    'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` ADD UNIQUE INDEX `uq_course_offering_role` (`course_offering_id`, `instructor_role`)',
    'SELECT ''uq_course_offering_role exists'' AS skipped_index'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- SECTION E: legacy offering pointer → canonical slot (preflight-safe cases)
-- ---------------------------------------------------------------------------
INSERT INTO `alrowad_uni_rust`.`course_offering_instructors` (
    course_offering_id,
    faculty_member_id,
    instructor_role,
    is_primary,
    is_active,
    created_at,
    updated_at
)
SELECT
    co.course_offering_id,
    co.faculty_member_id,
    CASE
        WHEN c.theoretical_hours > 0 AND IFNULL(c.practical_hours, 0) = 0 THEN 'theoretical'
        WHEN IFNULL(c.theoretical_hours, 0) = 0 AND c.practical_hours > 0 THEN 'practical'
    END,
    1,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`course_offerings` co
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
WHERE co.faculty_member_id IS NOT NULL
  AND (
      (c.theoretical_hours > 0 AND IFNULL(c.practical_hours, 0) = 0)
      OR (IFNULL(c.theoretical_hours, 0) = 0 AND c.practical_hours > 0)
  )
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`course_offering_instructors` coi
      WHERE coi.course_offering_id = co.course_offering_id
        AND coi.instructor_role = CASE
            WHEN c.theoretical_hours > 0 AND IFNULL(c.practical_hours, 0) = 0 THEN 'theoretical'
            WHEN IFNULL(c.theoretical_hours, 0) = 0 AND c.practical_hours > 0 THEN 'practical'
        END
  );

-- ---------------------------------------------------------------------------
-- SECTION F: is_primary flags
-- ---------------------------------------------------------------------------
UPDATE `alrowad_uni_rust`.`course_offering_instructors` coi
INNER JOIN `alrowad_uni_rust`.`course_offerings` co
    ON co.course_offering_id = coi.course_offering_id
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
SET coi.is_primary = CASE
        WHEN coi.instructor_role = 'theoretical' THEN 1
        WHEN coi.instructor_role = 'practical' AND IFNULL(c.theoretical_hours, 0) = 0 THEN 1
        ELSE 0
    END,
    coi.updated_at = CURRENT_TIMESTAMP
WHERE coi.is_primary <> CASE
        WHEN coi.instructor_role = 'theoretical' THEN 1
        WHEN coi.instructor_role = 'practical' AND IFNULL(c.theoretical_hours, 0) = 0 THEN 1
        ELSE 0
    END;

-- ---------------------------------------------------------------------------
-- SECTION G: synchronize legacy course_offerings.faculty_member_id
-- ---------------------------------------------------------------------------
UPDATE `alrowad_uni_rust`.`course_offerings` co
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
LEFT JOIN `alrowad_uni_rust`.`course_offering_instructors` primary_slot
    ON primary_slot.course_offering_id = co.course_offering_id
   AND primary_slot.is_active = 1
   AND primary_slot.instructor_role = CASE
        WHEN c.theoretical_hours > 0 THEN 'theoretical'
        WHEN c.practical_hours > 0 THEN 'practical'
        ELSE NULL
    END
SET co.faculty_member_id = primary_slot.faculty_member_id,
    co.updated_at = CURRENT_TIMESTAMP
WHERE NOT (
    co.faculty_member_id <=> primary_slot.faculty_member_id
);

-- ---------------------------------------------------------------------------
-- SECTION H: generic course_instructors
-- ---------------------------------------------------------------------------
INSERT INTO `alrowad_uni_rust`.`course_instructors` (
    course_id,
    faculty_member_id,
    is_primary,
    is_active,
    created_at
)
SELECT DISTINCT
    co.course_id,
    coi.faculty_member_id,
    0,
    1,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`course_offering_instructors` coi
INNER JOIN `alrowad_uni_rust`.`course_offerings` co
    ON co.course_offering_id = coi.course_offering_id
WHERE coi.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`course_instructors` ci
      WHERE ci.course_id = co.course_id
        AND ci.faculty_member_id = coi.faculty_member_id
  );

UPDATE `alrowad_uni_rust`.`course_instructors` ci
INNER JOIN `alrowad_uni_rust`.`course_offerings` co
    ON co.course_id = ci.course_id
INNER JOIN `alrowad_uni_rust`.`course_offering_instructors` coi
    ON coi.course_offering_id = co.course_offering_id
   AND coi.faculty_member_id = ci.faculty_member_id
SET ci.is_active = 1
WHERE ci.is_active = 0
  AND coi.is_active = 1;

-- ---------------------------------------------------------------------------
-- SECTION I: faculty College membership via employee_unit_assignments
-- ---------------------------------------------------------------------------
INSERT INTO `alrowad_uni_rust`.`employee_unit_assignments` (
    employee_id,
    organizational_unit_id,
    start_date,
    end_date,
    assignment_notes,
    is_active,
    created_at,
    updated_at
)
SELECT
    staff.employee_id,
    staff.organizational_unit_id,
    COALESCE(DATE(staff.earliest_assignment_at), staff.hire_date, CURRENT_DATE),
    NULL,
    'College teaching-staff affiliation backfilled from course offering assignments.',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM (
    SELECT
        fm.employee_id,
        col.organizational_unit_id,
        MAX(e.hire_date) AS hire_date,
        MIN(coi.created_at) AS earliest_assignment_at
    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
    INNER JOIN `alrowad_uni_rust`.`faculty_members` fm
        ON fm.faculty_member_id = coi.faculty_member_id
    INNER JOIN `alrowad_uni_rust`.`employees` e
        ON e.employee_id = fm.employee_id
    INNER JOIN `alrowad_uni_rust`.`course_offerings` co
        ON co.course_offering_id = coi.course_offering_id
    LEFT JOIN `alrowad_uni_rust`.`departments` d
        ON d.department_id = co.department_id
    LEFT JOIN `alrowad_uni_rust`.`academic_programs` ap
        ON ap.academic_program_id = co.academic_program_id
    LEFT JOIN `alrowad_uni_rust`.`departments` pd
        ON pd.department_id = ap.department_id
    INNER JOIN `alrowad_uni_rust`.`colleges` col
        ON col.college_id = COALESCE(d.college_id, pd.college_id)
    WHERE coi.is_active = 1
      AND col.organizational_unit_id IS NOT NULL
      AND (d.college_id IS NULL OR pd.college_id IS NULL OR d.college_id = pd.college_id)
    GROUP BY fm.employee_id, col.organizational_unit_id
) staff
WHERE NOT EXISTS (
    SELECT 1
    FROM `alrowad_uni_rust`.`employee_unit_assignments` existing
    WHERE existing.employee_id = staff.employee_id
      AND existing.organizational_unit_id = staff.organizational_unit_id
      AND existing.is_active = 1
);

-- ---------------------------------------------------------------------------
-- SECTION J: INSTRUCTOR employee_positions
-- ---------------------------------------------------------------------------
INSERT INTO `alrowad_uni_rust`.`employee_positions` (
    employee_id,
    position_id,
    organizational_unit_id,
    start_date,
    end_date,
    is_primary,
    is_active,
    created_at,
    updated_at
)
SELECT
    staff.employee_id,
    @instructor_position_id,
    staff.organizational_unit_id,
    COALESCE(DATE(staff.earliest_assignment_at), staff.hire_date, CURRENT_DATE),
    NULL,
    0,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM (
    SELECT
        fm.employee_id,
        col.organizational_unit_id,
        MAX(e.hire_date) AS hire_date,
        MIN(coi.created_at) AS earliest_assignment_at
    FROM `alrowad_uni_rust`.`course_offering_instructors` coi
    INNER JOIN `alrowad_uni_rust`.`faculty_members` fm
        ON fm.faculty_member_id = coi.faculty_member_id
    INNER JOIN `alrowad_uni_rust`.`employees` e
        ON e.employee_id = fm.employee_id
    INNER JOIN `alrowad_uni_rust`.`course_offerings` co
        ON co.course_offering_id = coi.course_offering_id
    LEFT JOIN `alrowad_uni_rust`.`departments` d
        ON d.department_id = co.department_id
    LEFT JOIN `alrowad_uni_rust`.`academic_programs` ap
        ON ap.academic_program_id = co.academic_program_id
    LEFT JOIN `alrowad_uni_rust`.`departments` pd
        ON pd.department_id = ap.department_id
    INNER JOIN `alrowad_uni_rust`.`colleges` col
        ON col.college_id = COALESCE(d.college_id, pd.college_id)
    WHERE coi.is_active = 1
      AND @instructor_position_id IS NOT NULL
      AND col.organizational_unit_id IS NOT NULL
      AND (d.college_id IS NULL OR pd.college_id IS NULL OR d.college_id = pd.college_id)
    GROUP BY fm.employee_id, col.organizational_unit_id
) staff
WHERE NOT EXISTS (
    SELECT 1
    FROM `alrowad_uni_rust`.`employee_positions` existing
    WHERE existing.employee_id = staff.employee_id
      AND existing.position_id = @instructor_position_id
      AND existing.organizational_unit_id = staff.organizational_unit_id
      AND existing.is_active = 1
);

-- ---------------------------------------------------------------------------
-- SECTION K: employee legacy organizational_unit_id (NULL only)
-- ---------------------------------------------------------------------------
UPDATE `alrowad_uni_rust`.`employees` e
INNER JOIN (
    SELECT
        eua.employee_id,
        MIN(eua.organizational_unit_id) AS organizational_unit_id
    FROM `alrowad_uni_rust`.`employee_unit_assignments` eua
    INNER JOIN `alrowad_uni_rust`.`colleges` c
        ON c.organizational_unit_id = eua.organizational_unit_id
    WHERE eua.is_active = 1
    GROUP BY eua.employee_id
    HAVING COUNT(DISTINCT eua.organizational_unit_id) = 1
) one_college
    ON one_college.employee_id = e.employee_id
SET e.organizational_unit_id = one_college.organizational_unit_id,
    e.updated_at = CURRENT_TIMESTAMP
WHERE e.organizational_unit_id IS NULL;

SELECT 'apply_complete' AS report_section, 'Run 02_verify.sql now.' AS next_step;
