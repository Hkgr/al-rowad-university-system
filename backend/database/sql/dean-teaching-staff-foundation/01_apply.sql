-- Manual and idempotent. Fail-closed: writes and ALTER TABLE run only when @apply_ready = 1.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- DDL commits implicitly in MariaDB; do not wrap this file in a transaction.
-- Do not use stored procedures, DELIMITER, or SIGNAL.

-- ---------------------------------------------------------------------------
-- Fail-closed readiness. Initialize to 0 so a failed computation cannot open writes.
-- CASE WHEN short-circuits: missing apply columns skip later table reads.
-- ---------------------------------------------------------------------------
SET @apply_ready := 0;

SET @missing_apply_columns := (
    SELECT COUNT(*)
    FROM (
        SELECT 'colleges' AS table_name, 'college_id' AS column_name
        UNION ALL SELECT 'colleges', 'organizational_unit_id'
        UNION ALL SELECT 'colleges', 'college_code'
        UNION ALL SELECT 'colleges', 'college_name'
        UNION ALL SELECT 'colleges', 'is_active'
        UNION ALL SELECT 'colleges', 'updated_at'
        UNION ALL SELECT 'organizational_units', 'organizational_unit_id'
        UNION ALL SELECT 'organizational_units', 'unit_code'
        UNION ALL SELECT 'organizational_units', 'unit_name'
        UNION ALL SELECT 'organizational_units', 'unit_type_id'
        UNION ALL SELECT 'organizational_units', 'parent_unit_id'
        UNION ALL SELECT 'organizational_units', 'description'
        UNION ALL SELECT 'organizational_units', 'is_active'
        UNION ALL SELECT 'organizational_units', 'created_at'
        UNION ALL SELECT 'organizational_units', 'updated_at'
        UNION ALL SELECT 'organizational_unit_types', 'unit_type_id'
        UNION ALL SELECT 'organizational_unit_types', 'type_code'
        UNION ALL SELECT 'organizational_unit_types', 'is_active'
        UNION ALL SELECT 'departments', 'department_id'
        UNION ALL SELECT 'departments', 'college_id'
        UNION ALL SELECT 'academic_programs', 'academic_program_id'
        UNION ALL SELECT 'academic_programs', 'department_id'
        UNION ALL SELECT 'employees', 'employee_id'
        UNION ALL SELECT 'employees', 'hire_date'
        UNION ALL SELECT 'employees', 'employee_status_id'
        UNION ALL SELECT 'employees', 'organizational_unit_id'
        UNION ALL SELECT 'employees', 'updated_at'
        UNION ALL SELECT 'employee_statuses', 'employee_status_id'
        UNION ALL SELECT 'employee_statuses', 'status_code'
        UNION ALL SELECT 'employee_statuses', 'is_active'
        UNION ALL SELECT 'employee_unit_assignments', 'assignment_id'
        UNION ALL SELECT 'employee_unit_assignments', 'employee_id'
        UNION ALL SELECT 'employee_unit_assignments', 'organizational_unit_id'
        UNION ALL SELECT 'employee_unit_assignments', 'start_date'
        UNION ALL SELECT 'employee_unit_assignments', 'end_date'
        UNION ALL SELECT 'employee_unit_assignments', 'assignment_notes'
        UNION ALL SELECT 'employee_unit_assignments', 'is_active'
        UNION ALL SELECT 'employee_unit_assignments', 'created_at'
        UNION ALL SELECT 'employee_unit_assignments', 'updated_at'
        UNION ALL SELECT 'employee_positions', 'employee_position_id'
        UNION ALL SELECT 'employee_positions', 'employee_id'
        UNION ALL SELECT 'employee_positions', 'position_id'
        UNION ALL SELECT 'employee_positions', 'organizational_unit_id'
        UNION ALL SELECT 'employee_positions', 'start_date'
        UNION ALL SELECT 'employee_positions', 'end_date'
        UNION ALL SELECT 'employee_positions', 'is_primary'
        UNION ALL SELECT 'employee_positions', 'is_active'
        UNION ALL SELECT 'employee_positions', 'created_at'
        UNION ALL SELECT 'employee_positions', 'updated_at'
        UNION ALL SELECT 'positions', 'position_id'
        UNION ALL SELECT 'positions', 'position_code'
        UNION ALL SELECT 'positions', 'is_active'
        UNION ALL SELECT 'faculty_members', 'faculty_member_id'
        UNION ALL SELECT 'faculty_members', 'employee_id'
        UNION ALL SELECT 'faculty_members', 'is_active'
        UNION ALL SELECT 'courses', 'course_id'
        UNION ALL SELECT 'courses', 'theoretical_hours'
        UNION ALL SELECT 'courses', 'practical_hours'
        UNION ALL SELECT 'course_instructors', 'course_instructor_id'
        UNION ALL SELECT 'course_instructors', 'course_id'
        UNION ALL SELECT 'course_instructors', 'faculty_member_id'
        UNION ALL SELECT 'course_instructors', 'is_primary'
        UNION ALL SELECT 'course_instructors', 'is_active'
        UNION ALL SELECT 'course_instructors', 'created_at'
        UNION ALL SELECT 'course_offerings', 'course_offering_id'
        UNION ALL SELECT 'course_offerings', 'course_id'
        UNION ALL SELECT 'course_offerings', 'department_id'
        UNION ALL SELECT 'course_offerings', 'academic_program_id'
        UNION ALL SELECT 'course_offerings', 'faculty_member_id'
        UNION ALL SELECT 'course_offerings', 'updated_at'
        UNION ALL SELECT 'course_offering_instructors', 'course_offering_instructor_id'
        UNION ALL SELECT 'course_offering_instructors', 'course_offering_id'
        UNION ALL SELECT 'course_offering_instructors', 'faculty_member_id'
        UNION ALL SELECT 'course_offering_instructors', 'instructor_role'
        UNION ALL SELECT 'course_offering_instructors', 'is_primary'
        UNION ALL SELECT 'course_offering_instructors', 'is_active'
        UNION ALL SELECT 'course_offering_instructors', 'created_at'
        UNION ALL SELECT 'course_offering_instructors', 'updated_at'
        UNION ALL SELECT 'roles', 'role_id'
        UNION ALL SELECT 'roles', 'role_code'
        UNION ALL SELECT 'roles', 'is_active'
        UNION ALL SELECT 'system_modules', 'module_id'
        UNION ALL SELECT 'system_modules', 'module_code'
        UNION ALL SELECT 'system_modules', 'is_active'
        UNION ALL SELECT 'permissions', 'permission_id'
        UNION ALL SELECT 'permissions', 'module_id'
        UNION ALL SELECT 'permissions', 'permission_code'
        UNION ALL SELECT 'permissions', 'permission_name'
        UNION ALL SELECT 'permissions', 'description'
        UNION ALL SELECT 'permissions', 'is_active'
        UNION ALL SELECT 'permissions', 'created_at'
        UNION ALL SELECT 'permissions', 'updated_at'
        UNION ALL SELECT 'role_permissions', 'role_id'
        UNION ALL SELECT 'role_permissions', 'permission_id'
        UNION ALL SELECT 'role_permissions', 'granted_at'
    ) required
    LEFT JOIN information_schema.columns c
        ON c.table_schema = 'alrowad_uni_rust'
       AND c.table_name = required.table_name
       AND c.column_name = required.column_name
    WHERE c.column_name IS NULL
);

SET @apply_ready := CASE
    WHEN IFNULL(@missing_apply_columns, 1) > 0 THEN 0
    WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') <> 1 THEN 0
    WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`colleges`
          WHERE college_code = 'FMF' AND college_name = 'كلية العلوم الإدارية والمالية') <> 1 THEN 0
    WHEN (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`organizational_units` u
        INNER JOIN `alrowad_uni_rust`.`organizational_units` p
            ON p.organizational_unit_id = u.parent_unit_id
        WHERE u.unit_code = '811' AND u.is_active = 1 AND p.unit_code = '81' AND p.is_active = 1
    ) <> 1 THEN 0
    WHEN EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`organizational_units` u
        INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
            ON t.unit_type_id = u.unit_type_id
        WHERE u.unit_code = '811'
          AND t.type_code IN ('department', 'office', 'lab', 'club', 'committee')
    ) THEN 0
    WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`organizational_unit_types`
          WHERE type_code = 'college' AND is_active = 1) <> 1 THEN 0
    WHEN NOT (
        EXISTS (
            SELECT 1
            FROM `alrowad_uni_rust`.`colleges` c
            INNER JOIN `alrowad_uni_rust`.`organizational_units` u
                ON u.organizational_unit_id = c.organizational_unit_id
            INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                ON t.unit_type_id = u.unit_type_id
            INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                ON p.organizational_unit_id = u.parent_unit_id
            WHERE c.college_code = 'FMF'
              AND u.is_active = 1 AND t.type_code = 'college' AND p.unit_code = '811'
        )
        OR (
            (SELECT organizational_unit_id FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') IS NULL
            AND (
                SELECT COUNT(*)
                FROM `alrowad_uni_rust`.`organizational_units` u
                INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                    ON t.unit_type_id = u.unit_type_id
                INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                    ON p.organizational_unit_id = u.parent_unit_id
                WHERE (
                      u.unit_code = 'FMF'
                      OR u.unit_name = 'كلية العلوم الإدارية والمالية'
                  )
                  AND u.is_active = 1 AND t.type_code = 'college' AND p.unit_code = '811'
            ) = 1
            AND NOT (
                EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`organizational_units` WHERE unit_code = 'FMF')
                AND (
                    SELECT COUNT(*)
                    FROM `alrowad_uni_rust`.`organizational_units` u
                    INNER JOIN `alrowad_uni_rust`.`organizational_unit_types` t
                        ON t.unit_type_id = u.unit_type_id
                    INNER JOIN `alrowad_uni_rust`.`organizational_units` p
                        ON p.organizational_unit_id = u.parent_unit_id
                    WHERE u.unit_code = 'FMF'
                      AND u.is_active = 1 AND t.type_code = 'college' AND p.unit_code = '811'
                ) <> 1
            )
        )
        OR (
            (SELECT organizational_unit_id FROM `alrowad_uni_rust`.`colleges` WHERE college_code = 'FMF') IS NULL
            AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`organizational_units` WHERE unit_code = 'FMF')
            AND NOT EXISTS (
                SELECT 1 FROM `alrowad_uni_rust`.`organizational_units`
                WHERE unit_name = 'كلية العلوم الإدارية والمالية'
            )
        )
    ) THEN 0
    WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'dean' AND is_active = 1) <> 1 THEN 0
    WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`positions`
          WHERE position_code = 'INSTRUCTOR' AND is_active = 1) <> 1 THEN 0
    WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules`
          WHERE module_code = 'hr' AND is_active = 1) <> 1 THEN 0
    WHEN EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`permissions` p
        LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
          AND (sm.module_code IS NULL OR sm.module_code <> 'hr')
    ) THEN 0
    WHEN EXISTS (
        SELECT permission_code
        FROM `alrowad_uni_rust`.`permissions`
        WHERE permission_code IN ('teaching_staff.view', 'teaching_staff.manage')
        GROUP BY permission_code
        HAVING COUNT(*) > 1
    ) THEN 0
    WHEN EXISTS (
        SELECT 1 FROM (
            SELECT index_name, non_unique,
                   GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
            FROM information_schema.statistics
            WHERE table_schema = 'alrowad_uni_rust'
              AND table_name = 'course_offering_instructors'
              AND index_name <> 'PRIMARY'
            GROUP BY index_name, non_unique
        ) idx
        WHERE (idx.index_name = 'uq_course_offering_instructor'
               AND (idx.non_unique <> 0 OR idx.columns <> 'course_offering_id,faculty_member_id'))
           OR (idx.index_name <> 'uq_course_offering_instructor'
               AND idx.non_unique = 0
               AND idx.columns = 'course_offering_id,faculty_member_id')
           OR (idx.index_name = 'uq_course_offering_role'
               AND (idx.non_unique <> 0 OR idx.columns <> 'course_offering_id,instructor_role'))
           OR (idx.index_name <> 'uq_course_offering_role'
               AND idx.non_unique = 0
               AND idx.columns = 'course_offering_id,instructor_role')
    ) THEN 0
    WHEN EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`course_offerings` co
        LEFT JOIN `alrowad_uni_rust`.`departments` d ON d.department_id = co.department_id
        LEFT JOIN `alrowad_uni_rust`.`academic_programs` ap ON ap.academic_program_id = co.academic_program_id
        LEFT JOIN `alrowad_uni_rust`.`departments` pd ON pd.department_id = ap.department_id
        WHERE d.college_id IS NOT NULL AND pd.college_id IS NOT NULL AND d.college_id <> pd.college_id
    ) THEN 0
    WHEN EXISTS (
        SELECT 1 FROM `alrowad_uni_rust`.`course_offering_instructors` coi
        WHERE LOWER(TRIM(COALESCE(coi.instructor_role, ''))) NOT IN ('', 'lead', 'theoretical', 'practical')
    ) THEN 0
    WHEN EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`course_offering_instructors` coi
        INNER JOIN `alrowad_uni_rust`.`course_offerings` co ON co.course_offering_id = coi.course_offering_id
        INNER JOIN `alrowad_uni_rust`.`courses` c ON c.course_id = co.course_id
        WHERE (
            LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
            AND (
                IFNULL(coi.is_primary, 0) <> 1
                OR (c.theoretical_hours > 0 AND c.practical_hours > 0)
                OR (co.faculty_member_id IS NOT NULL AND co.faculty_member_id <> coi.faculty_member_id)
            )
        )
           OR (LOWER(TRIM(coi.instructor_role)) = 'theoretical' AND IFNULL(c.theoretical_hours, 0) <= 0)
           OR (LOWER(TRIM(coi.instructor_role)) = 'practical' AND IFNULL(c.practical_hours, 0) <= 0)
    ) THEN 0
    WHEN EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`course_offerings` co
        INNER JOIN `alrowad_uni_rust`.`courses` c ON c.course_id = co.course_id
        WHERE co.faculty_member_id IS NOT NULL
          AND IFNULL(c.theoretical_hours, 0) <= 0
          AND IFNULL(c.practical_hours, 0) <= 0
    ) THEN 0
    WHEN EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`course_offerings` co
        INNER JOIN `alrowad_uni_rust`.`courses` c ON c.course_id = co.course_id
        INNER JOIN `alrowad_uni_rust`.`course_offering_instructors` primary_slot
            ON primary_slot.course_offering_id = co.course_offering_id
           AND primary_slot.instructor_role = CASE
                WHEN c.theoretical_hours > 0 THEN 'theoretical'
                WHEN IFNULL(c.theoretical_hours, 0) = 0 AND c.practical_hours > 0 THEN 'practical'
                ELSE NULL
            END
        WHERE co.faculty_member_id IS NOT NULL
          AND co.faculty_member_id <> primary_slot.faculty_member_id
    ) THEN 0
    WHEN EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`course_offerings` co
        INNER JOIN `alrowad_uni_rust`.`courses` c ON c.course_id = co.course_id
        WHERE co.faculty_member_id IS NOT NULL
          AND c.theoretical_hours > 0 AND c.practical_hours > 0
          AND NOT EXISTS (
              SELECT 1 FROM `alrowad_uni_rust`.`course_offering_instructors` coi
              WHERE coi.course_offering_id = co.course_offering_id
                AND coi.faculty_member_id = co.faculty_member_id
          )
    ) THEN 0
    WHEN EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`course_offering_instructors` coi
        INNER JOIN `alrowad_uni_rust`.`faculty_members` fm
            ON fm.faculty_member_id = coi.faculty_member_id
        LEFT JOIN `alrowad_uni_rust`.`employees` e ON e.employee_id = fm.employee_id
        LEFT JOIN `alrowad_uni_rust`.`employee_statuses` es
            ON es.employee_status_id = e.employee_status_id
        WHERE coi.is_active = 1
          AND (
              IFNULL(fm.is_active, 0) <> 1
              OR e.employee_id IS NULL
              OR es.status_code <> 'active'
              OR IFNULL(es.is_active, 0) <> 1
          )
    ) THEN 0
    WHEN EXISTS (
        SELECT 1 FROM (
            SELECT
                coi.course_offering_id,
                CASE
                    WHEN LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('theoretical', 'practical')
                        THEN LOWER(TRIM(coi.instructor_role))
                    WHEN LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
                     AND c.theoretical_hours > 0 AND IFNULL(c.practical_hours, 0) = 0
                        THEN 'theoretical'
                    WHEN LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
                     AND IFNULL(c.theoretical_hours, 0) = 0 AND c.practical_hours > 0
                        THEN 'practical'
                    ELSE CONCAT('unsafe:', COALESCE(coi.instructor_role, ''))
                END AS target_role
            FROM `alrowad_uni_rust`.`course_offering_instructors` coi
            INNER JOIN `alrowad_uni_rust`.`course_offerings` co ON co.course_offering_id = coi.course_offering_id
            INNER JOIN `alrowad_uni_rust`.`courses` c ON c.course_id = co.course_id
        ) classified
        GROUP BY classified.course_offering_id, classified.target_role
        HAVING COUNT(*) > 1
    ) THEN 0
    ELSE 1
END;

SELECT 'APPLY_STATUS' AS report_section, IF(@apply_ready = 1, 'READY', 'BLOCKED') AS result
UNION ALL
SELECT 'ACTION', IF(
    @apply_ready = 1,
    'Proceeding with fail-closed guarded writes',
    'Run 00_preflight.sql and resolve blockers first'
);

-- ---------------------------------------------------------------------------
-- Resolve identities by code (never hardcode numeric IDs). Reads only when ready.
-- ---------------------------------------------------------------------------
SET @fmf_college_id := CASE
    WHEN @apply_ready = 1 THEN (
        SELECT college_id
        FROM `alrowad_uni_rust`.`colleges`
        WHERE college_code = 'FMF'
        LIMIT 1
    )
    ELSE NULL
END;
SET @college_type_id := CASE
    WHEN @apply_ready = 1 THEN (
        SELECT unit_type_id
        FROM `alrowad_uni_rust`.`organizational_unit_types`
        WHERE type_code = 'college'
          AND is_active = 1
        LIMIT 1
    )
    ELSE NULL
END;
SET @colleges_container_id := CASE
    WHEN @apply_ready = 1 THEN (
        SELECT u.organizational_unit_id
        FROM `alrowad_uni_rust`.`organizational_units` u
        INNER JOIN `alrowad_uni_rust`.`organizational_units` p
            ON p.organizational_unit_id = u.parent_unit_id
        WHERE u.unit_code = '811'
          AND u.is_active = 1
          AND p.unit_code = '81'
        LIMIT 1
    )
    ELSE NULL
END;
SET @instructor_position_id := CASE
    WHEN @apply_ready = 1 THEN (
        SELECT position_id
        FROM `alrowad_uni_rust`.`positions`
        WHERE position_code = 'INSTRUCTOR'
          AND is_active = 1
        LIMIT 1
    )
    ELSE NULL
END;
SET @hr_module_id := CASE
    WHEN @apply_ready = 1 THEN (
        SELECT module_id
        FROM `alrowad_uni_rust`.`system_modules`
        WHERE module_code = 'hr'
          AND is_active = 1
        LIMIT 1
    )
    ELSE NULL
END;
SET @dean_role_id := CASE
    WHEN @apply_ready = 1 THEN (
        SELECT role_id
        FROM `alrowad_uni_rust`.`roles`
        WHERE role_code = 'dean'
          AND is_active = 1
        LIMIT 1
    )
    ELSE NULL
END;

SELECT
    'resolved_ids' AS report_section,
    @apply_ready AS apply_ready,
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
WHERE @apply_ready = 1
  AND @fmf_college_id IS NOT NULL
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

SET @fmf_unit_id := CASE
    WHEN @apply_ready = 1 THEN (
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
    )
    ELSE NULL
END;

UPDATE `alrowad_uni_rust`.`colleges`
SET organizational_unit_id = @fmf_unit_id,
    updated_at = CURRENT_TIMESTAMP
WHERE @apply_ready = 1
  AND college_code = 'FMF'
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
WHERE @apply_ready = 1
  AND @hr_module_id IS NOT NULL
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
WHERE @apply_ready = 1
  AND @hr_module_id IS NOT NULL
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
WHERE @apply_ready = 1
  AND r.role_code = 'dean'
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
-- Prepared ALTER is selected only when @apply_ready = 1.
-- ---------------------------------------------------------------------------
SET @instructor_role_type := (
    SELECT LOWER(column_type)
    FROM information_schema.columns
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'course_offering_instructors'
      AND column_name = 'instructor_role'
);
SET @sql := CASE
    WHEN @apply_ready = 1
     AND @instructor_role_type IS NOT NULL
     AND @instructor_role_type NOT LIKE '%theoretical%'
     AND @instructor_role_type NOT LIKE 'varchar%'
        THEN 'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` MODIFY `instructor_role` VARCHAR(20) NOT NULL'
    WHEN @apply_ready <> 1
        THEN 'SELECT ''ddl_skipped'' AS skipped_ddl'
    ELSE 'SELECT ''instructor_role already varchar or canonical'' AS skipped_enum_to_varchar'
END;
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
WHERE @apply_ready = 1
  AND LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
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
WHERE @apply_ready = 1
  AND LOWER(TRIM(COALESCE(coi.instructor_role, ''))) IN ('', 'lead')
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

SET @invalid_roles := CASE
    WHEN @apply_ready = 1 THEN (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`course_offering_instructors`
        WHERE instructor_role NOT IN ('theoretical', 'practical')
    )
    ELSE 1
END;
SET @sql := CASE
    WHEN @apply_ready = 1
     AND @invalid_roles = 0
     AND IFNULL(@instructor_role_type, '') NOT LIKE '%theoretical%practical%'
     AND IFNULL(@instructor_role_type, '') NOT LIKE '%practical%theoretical%'
        THEN 'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` MODIFY `instructor_role` ENUM(''theoretical'',''practical'') NOT NULL DEFAULT ''theoretical'''
    WHEN @apply_ready <> 1
        THEN 'SELECT ''ddl_skipped'' AS skipped_ddl'
    WHEN @invalid_roles = 0
        THEN 'SELECT ''instructor_role already canonical enum'' AS skipped_varchar_to_enum'
    ELSE 'SELECT ''BLOCKED: invalid instructor_role values remain; ENUM conversion skipped'' AS result'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- SECTION D: unique index (offering + role)
-- DROP the legacy unique only when its actual columns are the expected definition.
-- ---------------------------------------------------------------------------
SET @sql := CASE
    WHEN @apply_ready = 1
     AND (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_instructors'
          AND index_name = 'idx_coi_offering'
     ) = 0
        THEN 'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` ADD INDEX `idx_coi_offering` (`course_offering_id`)'
    WHEN @apply_ready <> 1
        THEN 'SELECT ''ddl_skipped'' AS skipped_ddl'
    ELSE 'SELECT ''idx_coi_offering exists'' AS skipped_index'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := CASE
    WHEN @apply_ready = 1
     AND (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_instructors'
          AND index_name = 'idx_coi_faculty'
     ) = 0
        THEN 'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` ADD INDEX `idx_coi_faculty` (`faculty_member_id`)'
    WHEN @apply_ready <> 1
        THEN 'SELECT ''ddl_skipped'' AS skipped_ddl'
    ELSE 'SELECT ''idx_coi_faculty exists'' AS skipped_index'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @legacy_uq_columns := (
    SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')
    FROM information_schema.statistics
    WHERE table_schema = 'alrowad_uni_rust'
      AND table_name = 'course_offering_instructors'
      AND index_name = 'uq_course_offering_instructor'
      AND non_unique = 0
    GROUP BY index_name
);
SET @sql := CASE
    WHEN @apply_ready = 1
     AND @legacy_uq_columns = 'course_offering_id,faculty_member_id'
        THEN 'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` DROP INDEX `uq_course_offering_instructor`'
    WHEN @apply_ready <> 1
        THEN 'SELECT ''ddl_skipped'' AS skipped_ddl'
    ELSE 'SELECT ''uq_course_offering_instructor not dropped (absent or unexpected definition)'' AS skipped_index'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := CASE
    WHEN @apply_ready = 1
     AND (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'course_offering_instructors'
          AND index_name = 'uq_course_offering_role'
     ) = 0
        THEN 'ALTER TABLE `alrowad_uni_rust`.`course_offering_instructors` ADD UNIQUE INDEX `uq_course_offering_role` (`course_offering_id`, `instructor_role`)'
    WHEN @apply_ready <> 1
        THEN 'SELECT ''ddl_skipped'' AS skipped_ddl'
    ELSE 'SELECT ''uq_course_offering_role exists'' AS skipped_index'
END;
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
WHERE @apply_ready = 1
  AND co.faculty_member_id IS NOT NULL
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
WHERE @apply_ready = 1
  AND coi.is_primary <> CASE
        WHEN coi.instructor_role = 'theoretical' THEN 1
        WHEN coi.instructor_role = 'practical' AND IFNULL(c.theoretical_hours, 0) = 0 THEN 1
        ELSE 0
    END;

-- ---------------------------------------------------------------------------
-- SECTION G: fill-only legacy pointer sync for safely classified offerings.
-- Never SET NULL. Never overwrite a different non-null faculty_member_id.
-- ---------------------------------------------------------------------------
UPDATE `alrowad_uni_rust`.`course_offerings` co
INNER JOIN `alrowad_uni_rust`.`courses` c
    ON c.course_id = co.course_id
INNER JOIN `alrowad_uni_rust`.`course_offering_instructors` primary_slot
    ON primary_slot.course_offering_id = co.course_offering_id
   AND primary_slot.is_active = 1
   AND primary_slot.instructor_role = CASE
        WHEN c.theoretical_hours > 0 THEN 'theoretical'
        WHEN IFNULL(c.theoretical_hours, 0) = 0 AND c.practical_hours > 0 THEN 'practical'
        ELSE NULL
    END
SET co.faculty_member_id = primary_slot.faculty_member_id,
    co.updated_at = CURRENT_TIMESTAMP
WHERE @apply_ready = 1
  AND primary_slot.faculty_member_id IS NOT NULL
  AND co.faculty_member_id IS NULL
  AND (
      (c.theoretical_hours > 0 AND IFNULL(c.practical_hours, 0) = 0)
      OR (IFNULL(c.theoretical_hours, 0) = 0 AND c.practical_hours > 0)
      OR (c.theoretical_hours > 0 AND c.practical_hours > 0)
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
INNER JOIN `alrowad_uni_rust`.`faculty_members` fm
    ON fm.faculty_member_id = coi.faculty_member_id
WHERE @apply_ready = 1
  AND coi.is_active = 1
  AND fm.is_active = 1
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
INNER JOIN `alrowad_uni_rust`.`faculty_members` fm
    ON fm.faculty_member_id = ci.faculty_member_id
SET ci.is_active = 1
WHERE @apply_ready = 1
  AND ci.is_active = 0
  AND coi.is_active = 1
  AND fm.is_active = 1;

-- ---------------------------------------------------------------------------
-- SECTION I: faculty College membership via employee_unit_assignments
-- Active membership is created only for active faculty + active employees.
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
    INNER JOIN `alrowad_uni_rust`.`employee_statuses` es
        ON es.employee_status_id = e.employee_status_id
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
    WHERE @apply_ready = 1
      AND coi.is_active = 1
      AND fm.is_active = 1
      AND es.status_code = 'active'
      AND es.is_active = 1
      AND col.organizational_unit_id IS NOT NULL
      AND (d.college_id IS NULL OR pd.college_id IS NULL OR d.college_id = pd.college_id)
    GROUP BY fm.employee_id, col.organizational_unit_id
) staff
WHERE @apply_ready = 1
  AND NOT EXISTS (
    SELECT 1
    FROM `alrowad_uni_rust`.`employee_unit_assignments` existing
    WHERE existing.employee_id = staff.employee_id
      AND existing.organizational_unit_id = staff.organizational_unit_id
      AND existing.is_active = 1
);

-- ---------------------------------------------------------------------------
-- SECTION J: INSTRUCTOR employee_positions (active INSTRUCTOR only)
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
    INNER JOIN `alrowad_uni_rust`.`employee_statuses` es
        ON es.employee_status_id = e.employee_status_id
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
    WHERE @apply_ready = 1
      AND @instructor_position_id IS NOT NULL
      AND coi.is_active = 1
      AND fm.is_active = 1
      AND es.status_code = 'active'
      AND es.is_active = 1
      AND col.organizational_unit_id IS NOT NULL
      AND (d.college_id IS NULL OR pd.college_id IS NULL OR d.college_id = pd.college_id)
    GROUP BY fm.employee_id, col.organizational_unit_id
) staff
WHERE @apply_ready = 1
  AND @instructor_position_id IS NOT NULL
  AND NOT EXISTS (
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
WHERE @apply_ready = 1
  AND e.organizational_unit_id IS NULL;

SELECT 'APPLY_STATUS' AS report_section, IF(@apply_ready = 1, 'READY', 'BLOCKED') AS result
UNION ALL
SELECT 'ACTION', IF(
    @apply_ready = 1,
    'Run 02_verify.sql now.',
    'Run 00_preflight.sql and resolve blockers first'
);
