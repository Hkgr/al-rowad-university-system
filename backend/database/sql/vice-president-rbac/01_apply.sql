-- Manual and idempotent. Fail-closed: writes run only when @apply_ready = 1.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not use DATABASE(), stored procedures, DELIMITER, or SIGNAL.
-- Independently recomputes the same critical safety conditions as 00_preflight.sql.
-- Does not depend on session variables left over from 00_preflight.sql.
--
-- Target object states (computed here):
--   ABSENT     → create
--   COMPATIBLE → reuse, do not rewrite
--   CONFLICT   → no Phase 3 writes
--
-- Created rows are stamped with description token [phase3-vp-rbac]
-- so 03_rollback.sql can prove Phase 3 ownership.
--
-- DML only on InnoDB tables. Wrapped in a transaction.
-- APPLIED is reported only after COMMIT when both permissions, both roles,
-- and both intended role_permission mappings are complete.
--
-- Does NOT:
--   create organizational units
--   create users
--   assign roles
--   insert or update user_access_scopes
--   modify generic vice_president
--   modify other roles' permissions
--   rewrite conflicting existing objects

SET @apply_ready := 0;
SET @phase3_complete := 0;
SET @apply_status := 'BLOCKED';

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

SET @vp_module_rows := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'vice_presidency'),
    0
);
SET @vp_module_compatible_rows := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`system_modules`
        WHERE module_code = 'vice_presidency'
          AND is_active = 1
          AND (
              module_name = 'Vice Presidency'
              OR module_name LIKE '%Vice Presidenc%'
              OR COALESCE(description, '') LIKE '%[phase3-vp-rbac]%'
          )
    ),
    0
);
SET @vp_module_state := IF(
    @vp_module_rows = 0,
    'ABSENT',
    IF(@vp_module_rows = 1 AND @vp_module_compatible_rows = 1, 'COMPATIBLE', 'CONFLICT')
);

SET @sci_perm_rows := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'vice_presidency.scientific.access'),
    0
);
SET @sci_perm_compatible_rows := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'vice_presidency.scientific.access'
          AND p.is_active = 1
          AND p.permission_name IN (
              'Scientific vice presidency access',
              'Scientific Vice Presidency Access'
          )
          AND sm.module_code = 'vice_presidency'
          AND sm.is_active = 1
    ),
    0
);
SET @sci_perm_state := IF(
    @sci_perm_rows = 0,
    'ABSENT',
    IF(@sci_perm_rows = 1 AND @sci_perm_compatible_rows = 1, 'COMPATIBLE', 'CONFLICT')
);

SET @adm_perm_rows := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'vice_presidency.administrative.access'),
    0
);
SET @adm_perm_compatible_rows := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'vice_presidency.administrative.access'
          AND p.is_active = 1
          AND p.permission_name IN (
              'Administrative vice presidency access',
              'Administrative Vice Presidency Access'
          )
          AND sm.module_code = 'vice_presidency'
          AND sm.is_active = 1
    ),
    0
);
SET @adm_perm_state := IF(
    @adm_perm_rows = 0,
    'ABSENT',
    IF(@adm_perm_rows = 1 AND @adm_perm_compatible_rows = 1, 'COMPATIBLE', 'CONFLICT')
);

SET @sci_role_rows := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_scientific'),
    0
);
SET @sci_role_compatible_rows := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles`
        WHERE role_code = 'vice_president_scientific'
          AND role_name IN (
              'نائب رئيس الجامعة للشؤون العلمية',
              'Vice President for Scientific Affairs'
          )
          AND is_active = 1
          AND is_system_role = 1
    ),
    0
);
SET @sci_role_state := IF(
    @sci_role_rows = 0,
    'ABSENT',
    IF(@sci_role_rows = 1 AND @sci_role_compatible_rows = 1, 'COMPATIBLE', 'CONFLICT')
);

SET @adm_role_rows := IF(
    @structure_ok = 1,
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative'),
    0
);
SET @adm_role_compatible_rows := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles`
        WHERE role_code = 'vice_president_administrative'
          AND role_name IN (
              'نائب رئيس الجامعة للشؤون الإدارية',
              'Vice President for Administrative Affairs'
          )
          AND is_active = 1
          AND is_system_role = 1
    ),
    0
);
SET @adm_role_state := IF(
    @adm_role_rows = 0,
    'ABSENT',
    IF(@adm_role_rows = 1 AND @adm_role_compatible_rows = 1, 'COMPATIBLE', 'CONFLICT')
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

SET @sci_mapping_existed := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific'
          AND p.permission_code = 'vice_presidency.scientific.access'
    ),
    0
);
SET @adm_mapping_existed := IF(
    @structure_ok = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative'
          AND p.permission_code = 'vice_presidency.administrative.access'
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
    AND @vp_module_state IN ('ABSENT', 'COMPATIBLE')
    AND @sci_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @adm_perm_state IN ('ABSENT', 'COMPATIBLE')
    AND @sci_role_state IN ('ABSENT', 'COMPATIBLE')
    AND @adm_role_state IN ('ABSENT', 'COMPATIBLE')
    AND @scientific_name_on_other_code = 0
    AND @administrative_name_on_other_code = 0,
    1,
    0
);

START TRANSACTION;

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
    'Dedicated access identities for Scientific and Administrative Vice Presidents [phase3-vp-rbac]',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
WHERE @apply_ready = 1
  AND @vp_module_state = 'ABSENT'
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
    'Base identity for the Scientific Vice President. Does not grant workflow actions. [phase3-vp-rbac]',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @sci_perm_state = 'ABSENT'
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
    'Base identity for the Administrative Vice President. Does not grant workflow actions. [phase3-vp-rbac]',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @apply_ready = 1
  AND @adm_perm_state = 'ABSENT'
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
    'University-level Scientific Vice President. Distinct from generic vice_president. [phase3-vp-rbac]',
    1,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
WHERE @apply_ready = 1
  AND @sci_role_state = 'ABSENT'
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
    'University-level Administrative Vice President. Distinct from generic vice_president. [phase3-vp-rbac]',
    1,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
WHERE @apply_ready = 1
  AND @adm_role_state = 'ABSENT'
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
  AND r.is_active = 1
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
  AND r.is_active = 1
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` existing
      WHERE existing.role_id = r.role_id
        AND existing.permission_id = p.permission_id
  );

SET @phase3_complete := IF(
    @apply_ready = 1
    AND (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`system_modules`
        WHERE module_code = 'vice_presidency' AND is_active = 1
    ) = 1
    AND (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'vice_presidency.scientific.access'
          AND p.is_active = 1
          AND sm.module_code = 'vice_presidency'
          AND sm.is_active = 1
    ) = 1
    AND (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions` p
        JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
        WHERE p.permission_code = 'vice_presidency.administrative.access'
          AND p.is_active = 1
          AND sm.module_code = 'vice_presidency'
          AND sm.is_active = 1
    ) = 1
    AND (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles`
        WHERE role_code = 'vice_president_scientific'
          AND is_active = 1
          AND is_system_role = 1
    ) = 1
    AND (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles`
        WHERE role_code = 'vice_president_administrative'
          AND is_active = 1
          AND is_system_role = 1
    ) = 1
    AND EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific'
          AND p.permission_code = 'vice_presidency.scientific.access'
    )
    AND EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative'
          AND p.permission_code = 'vice_presidency.administrative.access'
    )
    AND NOT EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_scientific'
          AND p.permission_code = 'vice_presidency.administrative.access'
    )
    AND NOT EXISTS (
        SELECT 1
        FROM `alrowad_uni_rust`.`roles` r
        JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
        JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
        WHERE r.role_code = 'vice_president_administrative'
          AND p.permission_code = 'vice_presidency.scientific.access'
    ),
    1,
    0
);

-- If this run cannot finish the full RBAC set, undo only objects this run added.
-- Pre-existing COMPATIBLE rows are left untouched. Then COMMIT so a partial
-- apply is never persisted or reported as APPLIED.
DELETE rp
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @phase3_complete = 0
  AND @sci_mapping_existed = 0
  AND r.role_code = 'vice_president_scientific'
  AND p.permission_code = 'vice_presidency.scientific.access';

DELETE rp
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @phase3_complete = 0
  AND @adm_mapping_existed = 0
  AND r.role_code = 'vice_president_administrative'
  AND p.permission_code = 'vice_presidency.administrative.access';

DELETE rp
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
WHERE @phase3_complete = 0
  AND @sci_role_state = 'ABSENT'
  AND r.role_code = 'vice_president_scientific'
  AND COALESCE(r.description, '') LIKE '%[phase3-vp-rbac]%';

DELETE FROM `alrowad_uni_rust`.`roles`
WHERE @phase3_complete = 0
  AND @sci_role_state = 'ABSENT'
  AND role_code = 'vice_president_scientific'
  AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%';

DELETE rp
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
WHERE @phase3_complete = 0
  AND @adm_role_state = 'ABSENT'
  AND r.role_code = 'vice_president_administrative'
  AND COALESCE(r.description, '') LIKE '%[phase3-vp-rbac]%';

DELETE FROM `alrowad_uni_rust`.`roles`
WHERE @phase3_complete = 0
  AND @adm_role_state = 'ABSENT'
  AND role_code = 'vice_president_administrative'
  AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%';

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @phase3_complete = 0
  AND @sci_perm_state = 'ABSENT'
  AND permission_code = 'vice_presidency.scientific.access'
  AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%';

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @phase3_complete = 0
  AND @adm_perm_state = 'ABSENT'
  AND permission_code = 'vice_presidency.administrative.access'
  AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%';

DELETE FROM `alrowad_uni_rust`.`system_modules`
WHERE @phase3_complete = 0
  AND @vp_module_state = 'ABSENT'
  AND module_code = 'vice_presidency'
  AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%';

COMMIT;

SET @apply_status := IF(
    @apply_ready = 0,
    'BLOCKED',
    IF(@phase3_complete = 1, 'APPLIED', 'BLOCKED_INCOMPLETE')
);

SELECT @apply_status AS apply_status,
       @apply_ready AS apply_ready,
       @phase3_complete AS phase3_complete,
       @vp_module_state AS vp_module_state,
       @sci_perm_state AS sci_perm_state,
       @adm_perm_state AS adm_perm_state,
       @sci_role_state AS sci_role_state,
       @adm_role_state AS adm_role_state,
       @missing_required_columns AS missing_required_columns,
       @scientific_unit_count AS scientific_unit_count,
       @administrative_unit_count AS administrative_unit_count;
