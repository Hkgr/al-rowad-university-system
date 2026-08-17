-- Manual and idempotent. Fail-closed: writes run only when @apply_ready = 1.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not use DATABASE(), stored procedures, DELIMITER, or SIGNAL.
-- Independently recomputes the same critical safety conditions as 00_preflight.sql.
--
-- Creates only missing RBAC building blocks:
--   system_modules.vice_presidency (if missing)
--   vice_presidency.scientific.access
--   vice_presidency.administrative.access
--   vice_president_scientific
--   vice_president_administrative
--   role_permission mappings for those two roles only
--
-- Does NOT:
--   create organizational units
--   create users
--   assign roles
--   insert or update user_access_scopes
--   modify generic vice_president
--   modify other roles' permissions

SET @apply_ready := 0;

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
            SELECT 'roles' AS table_name, 'role_id' AS column_name
            UNION ALL SELECT 'roles', 'role_code'
            UNION ALL SELECT 'roles', 'role_name'
            UNION ALL SELECT 'roles', 'description'
            UNION ALL SELECT 'roles', 'is_system_role'
            UNION ALL SELECT 'roles', 'is_active'
            UNION ALL SELECT 'roles', 'created_at'
            UNION ALL SELECT 'roles', 'updated_at'
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
            UNION ALL SELECT 'user_roles', 'user_id'
            UNION ALL SELECT 'user_roles', 'role_id'
            UNION ALL SELECT 'organizational_units', 'organizational_unit_id'
            UNION ALL SELECT 'organizational_units', 'unit_code'
            UNION ALL SELECT 'organizational_units', 'unit_name'
            UNION ALL SELECT 'organizational_units', 'is_active'
            UNION ALL SELECT 'system_modules', 'module_id'
            UNION ALL SELECT 'system_modules', 'module_code'
            UNION ALL SELECT 'system_modules', 'module_name'
            UNION ALL SELECT 'system_modules', 'description'
            UNION ALL SELECT 'system_modules', 'is_active'
            UNION ALL SELECT 'system_modules', 'created_at'
            UNION ALL SELECT 'system_modules', 'updated_at'
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

SET @roles_role_code_unique := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'roles'
          AND column_name = 'role_code'
          AND non_unique = 0
    ),
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

SET @role_permission_unique := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'role_permissions'
          AND index_name = 'uq_role_permission'
          AND non_unique = 0
    ),
    0
);

SET @module_code_unique := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'system_modules'
          AND column_name = 'module_code'
          AND non_unique = 0
    ),
    0
);

SET @university_scope_supported := IF(
    @structure_ok = 1,
    (
        SELECT IF(column_type LIKE '%university%', 1, 0)
        FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'user_access_scopes'
          AND column_name = 'scope_type'
        LIMIT 1
    ),
    0
);

SET @pres_unit_count := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`organizational_units`
        WHERE unit_code = 'PRES'
    ),
    0
);

SET @scientific_unit_count := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`organizational_units` u
        WHERE u.is_active = 1
          AND (
              u.unit_code = 'VP_SCI'
              OR u.unit_name IN (
                  'نائب رئيس الجامعة للشؤون العلمية',
                  'Vice President for Scientific Affairs'
              )
          )
    ),
    0
);

SET @administrative_unit_count := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`organizational_units` u
        WHERE u.is_active = 1
          AND (
              u.unit_code IN ('7', 'VP_ADMIN')
              OR u.unit_name = 'نائب رئيس الجامعة للشؤون الإدارية'
          )
    ),
    0
);

SET @scientific_role_conflict := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles`
        WHERE role_code = 'vice_president_scientific'
          AND role_name NOT IN (
              'نائب رئيس الجامعة للشؤون العلمية',
              'Vice President for Scientific Affairs'
          )
    ),
    0
);

SET @administrative_role_conflict := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles`
        WHERE role_code = 'vice_president_administrative'
          AND role_name NOT IN (
              'نائب رئيس الجامعة للشؤون الإدارية',
              'Vice President for Administrative Affairs'
          )
    ),
    0
);

SET @scientific_name_on_other_code := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles`
        WHERE role_name IN (
              'نائب رئيس الجامعة للشؤون العلمية',
              'Vice President for Scientific Affairs'
          )
          AND role_code <> 'vice_president_scientific'
    ),
    0
);

SET @administrative_name_on_other_code := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles`
        WHERE role_name IN (
              'نائب رئيس الجامعة للشؤون الإدارية',
              'Vice President for Administrative Affairs'
          )
          AND role_code <> 'vice_president_administrative'
    ),
    0
);

SET @scientific_permission_conflict := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions`
        WHERE permission_code = 'vice_presidency.scientific.access'
          AND permission_name NOT IN (
              'Scientific vice presidency access',
              'Scientific Vice Presidency Access'
          )
    ),
    0
);

SET @administrative_permission_conflict := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions`
        WHERE permission_code = 'vice_presidency.administrative.access'
          AND permission_name NOT IN (
              'Administrative vice presidency access',
              'Administrative Vice Presidency Access'
          )
    ),
    0
);

SET @apply_ready := IF(
    @db_ready = 1
    AND @missing_required_columns = 0
    AND @roles_role_code_unique > 0
    AND @permissions_code_unique > 0
    AND @role_permission_unique > 0
    AND @module_code_unique > 0
    AND @university_scope_supported = 1
    AND @pres_unit_count = 1
    AND @scientific_unit_count = 1
    AND @administrative_unit_count = 1
    AND @scientific_role_conflict = 0
    AND @administrative_role_conflict = 0
    AND @scientific_name_on_other_code = 0
    AND @administrative_name_on_other_code = 0
    AND @scientific_permission_conflict = 0
    AND @administrative_permission_conflict = 0,
    1,
    0
);

INSERT INTO `alrowad_uni_rust`.`system_modules` (
    module_code,
    module_name,
    description,
    is_active,
    created_at,
    updated_at
)
SELECT
    'vice_presidency',
    'Vice Presidency',
    'Dedicated access identities for Scientific and Administrative Vice Presidents',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
WHERE @apply_ready = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`system_modules` existing
      WHERE existing.module_code = 'vice_presidency'
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
    sm.module_id,
    'vice_presidency.scientific.access',
    'Scientific vice presidency access',
    'Base identity for the Scientific Vice President. Does not grant workflow actions.',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND sm.module_code = 'vice_presidency'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'vice_presidency.scientific.access'
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
    sm.module_id,
    'vice_presidency.administrative.access',
    'Administrative vice presidency access',
    'Base identity for the Administrative Vice President. Does not grant workflow actions.',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND sm.module_code = 'vice_presidency'
  AND sm.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`permissions` existing
      WHERE existing.permission_code = 'vice_presidency.administrative.access'
  );

INSERT INTO `alrowad_uni_rust`.`roles` (
    role_code,
    role_name,
    description,
    is_system_role,
    is_active,
    created_at,
    updated_at
)
SELECT
    'vice_president_scientific',
    'نائب رئيس الجامعة للشؤون العلمية',
    'University-level Scientific Vice President. Distinct from generic vice_president.',
    1,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
WHERE @apply_ready = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`roles` existing
      WHERE existing.role_code = 'vice_president_scientific'
  );

INSERT INTO `alrowad_uni_rust`.`roles` (
    role_code,
    role_name,
    description,
    is_system_role,
    is_active,
    created_at,
    updated_at
)
SELECT
    'vice_president_administrative',
    'نائب رئيس الجامعة للشؤون الإدارية',
    'University-level Administrative Vice President. Distinct from generic vice_president.',
    1,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
WHERE @apply_ready = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`roles` existing
      WHERE existing.role_code = 'vice_president_administrative'
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (
    role_id,
    permission_id,
    granted_at
)
SELECT
    r.role_id,
    p.permission_id,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p
    ON p.permission_code = 'vice_presidency.scientific.access'
WHERE @apply_ready = 1
  AND r.role_code = 'vice_president_scientific'
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id
        AND existing.permission_id = p.permission_id
  );

INSERT INTO `alrowad_uni_rust`.`role_permissions` (
    role_id,
    permission_id,
    granted_at
)
SELECT
    r.role_id,
    p.permission_id,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p
    ON p.permission_code = 'vice_presidency.administrative.access'
WHERE @apply_ready = 1
  AND r.role_code = 'vice_president_administrative'
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id
        AND existing.permission_id = p.permission_id
  );

SELECT IF(@apply_ready = 1, 'APPLIED', 'BLOCKED') AS apply_status,
       @missing_required_columns AS missing_required_columns,
       @scientific_unit_count AS scientific_unit_count,
       @administrative_unit_count AS administrative_unit_count,
       (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`roles`
           WHERE role_code = 'vice_president_scientific'
       ) AS scientific_role_rows,
       (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`roles`
           WHERE role_code = 'vice_president_administrative'
       ) AS administrative_role_rows,
       (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`permissions`
           WHERE permission_code IN (
               'vice_presidency.scientific.access',
               'vice_presidency.administrative.access'
           )
       ) AS dedicated_permission_rows;
