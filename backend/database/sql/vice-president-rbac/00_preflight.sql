-- READ ONLY. Continue only when OVERALL returns READY.
-- Fully qualified objects: do not depend on phpMyAdmin's selected database.
-- Do not CREATE/INSERT/UPDATE/DELETE application data.
-- SET user variables and temporary reporting tables only.
-- Do not use DATABASE().
-- Numeric organizational_unit_id values are reported from live rows; never hard-code them in application code.

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
            UNION ALL SELECT 'permissions', 'permission_id'
            UNION ALL SELECT 'permissions', 'module_id'
            UNION ALL SELECT 'permissions', 'permission_code'
            UNION ALL SELECT 'permissions', 'permission_name'
            UNION ALL SELECT 'permissions', 'description'
            UNION ALL SELECT 'permissions', 'is_active'
            UNION ALL SELECT 'role_permissions', 'role_permission_id'
            UNION ALL SELECT 'role_permissions', 'role_id'
            UNION ALL SELECT 'role_permissions', 'permission_id'
            UNION ALL SELECT 'role_permissions', 'granted_at'
            UNION ALL SELECT 'user_roles', 'user_id'
            UNION ALL SELECT 'user_roles', 'role_id'
            UNION ALL SELECT 'user_roles', 'is_active'
            UNION ALL SELECT 'user_access_scopes', 'user_access_scope_id'
            UNION ALL SELECT 'user_access_scopes', 'user_id'
            UNION ALL SELECT 'user_access_scopes', 'scope_type'
            UNION ALL SELECT 'user_access_scopes', 'scope_id'
            UNION ALL SELECT 'user_access_scopes', 'is_active'
            UNION ALL SELECT 'organizational_units', 'organizational_unit_id'
            UNION ALL SELECT 'organizational_units', 'unit_code'
            UNION ALL SELECT 'organizational_units', 'unit_name'
            UNION ALL SELECT 'organizational_units', 'unit_type_id'
            UNION ALL SELECT 'organizational_units', 'parent_unit_id'
            UNION ALL SELECT 'organizational_units', 'is_active'
            UNION ALL SELECT 'organizational_unit_types', 'unit_type_id'
            UNION ALL SELECT 'organizational_unit_types', 'type_code'
            UNION ALL SELECT 'organizational_unit_types', 'type_name'
            UNION ALL SELECT 'organizational_unit_types', 'is_active'
            UNION ALL SELECT 'system_modules', 'module_id'
            UNION ALL SELECT 'system_modules', 'module_code'
            UNION ALL SELECT 'system_modules', 'module_name'
            UNION ALL SELECT 'system_modules', 'is_active'
            UNION ALL SELECT 'users', 'user_id'
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
-- A. ROLE STRUCTURE
-- ---------------------------------------------------------------------------
SELECT 'A_role_columns' AS report_section, column_name, column_type, is_nullable, column_key, extra
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'roles'
ORDER BY ordinal_position;

SELECT 'A_role_indexes' AS report_section, index_name, column_name, non_unique, seq_in_index
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'roles'
ORDER BY index_name, seq_in_index;

-- ---------------------------------------------------------------------------
-- B. CURRENT RELEVANT ROLES
-- ---------------------------------------------------------------------------
SELECT 'B_relevant_roles' AS report_section,
       r.role_id, r.role_code, r.role_name, r.is_system_role, r.is_active
FROM `alrowad_uni_rust`.`roles` r
WHERE @structure_ok = 1
  AND (
      r.role_code IN (
          'super_admin',
          'university_president',
          'vice_president',
          'vice_president_scientific',
          'vice_president_administrative',
          'university_secretary_general',
          'dean'
      )
      OR r.role_code LIKE '%president%'
      OR r.role_code LIKE '%dean%'
      OR r.role_name LIKE '%نائب%'
      OR r.role_name LIKE '%علم%'
      OR r.role_name LIKE '%إدار%'
  )
ORDER BY r.role_id;

-- ---------------------------------------------------------------------------
-- C. TARGET ROLE EXISTENCE
-- ---------------------------------------------------------------------------
SELECT 'C_target_scientific_role' AS report_section,
       r.role_id, r.role_code, r.role_name, r.is_active
FROM `alrowad_uni_rust`.`roles` r
WHERE @structure_ok = 1
  AND r.role_code = 'vice_president_scientific';

SELECT 'C_target_administrative_role' AS report_section,
       r.role_id, r.role_code, r.role_name, r.is_active
FROM `alrowad_uni_rust`.`roles` r
WHERE @structure_ok = 1
  AND r.role_code = 'vice_president_administrative';

-- ---------------------------------------------------------------------------
-- D. GENERIC vice_president
-- ---------------------------------------------------------------------------
SELECT 'D_generic_vice_president' AS report_section,
       r.role_id, r.role_code, r.role_name, r.is_active,
       (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`user_roles` ur
           WHERE ur.role_id = r.role_id
       ) AS assigned_user_role_rows,
       (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`user_roles` ur
           WHERE ur.role_id = r.role_id
             AND ur.is_active = 1
       ) AS active_assigned_user_role_rows
FROM `alrowad_uni_rust`.`roles` r
WHERE @structure_ok = 1
  AND r.role_code = 'vice_president';

SELECT 'D_generic_vice_president_permissions' AS report_section,
       p.permission_id, p.permission_code, p.permission_name, p.is_active
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @structure_ok = 1
  AND r.role_code = 'vice_president'
ORDER BY p.permission_code;

-- ---------------------------------------------------------------------------
-- E. PERMISSION STRUCTURE
-- ---------------------------------------------------------------------------
SELECT 'E_permission_columns' AS report_section, table_name, column_name, column_type, is_nullable, column_key
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN ('permissions', 'role_permissions', 'system_modules')
ORDER BY table_name, ordinal_position;

SELECT 'E_permission_indexes' AS report_section, table_name, index_name, column_name, non_unique, seq_in_index
FROM information_schema.statistics
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name IN ('permissions', 'role_permissions', 'system_modules')
ORDER BY table_name, index_name, seq_in_index;

-- ---------------------------------------------------------------------------
-- F. CURRENT PERMISSION CODES (relevant domains)
-- ---------------------------------------------------------------------------
SELECT 'F_relevant_permissions' AS report_section,
       p.permission_id, sm.module_code, p.permission_code, p.permission_name, p.is_active
FROM `alrowad_uni_rust`.`permissions` p
LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
WHERE @structure_ok = 1
  AND (
      p.permission_code LIKE 'course%'
      OR p.permission_code LIKE '%offering%'
      OR p.permission_code LIKE '%faculty%'
      OR p.permission_code LIKE '%staff%'
      OR p.permission_code LIKE 'student%'
      OR p.permission_code LIKE 'dashboard%'
      OR p.permission_code LIKE '%admin%'
      OR p.permission_code LIKE '%academic%'
      OR p.permission_code LIKE 'vice%'
      OR p.permission_code LIKE 'hr%'
      OR p.permission_code LIKE 'registration%'
  )
ORDER BY p.permission_code;

SELECT 'F_target_permissions' AS report_section,
       p.permission_id, p.permission_code, p.permission_name, p.is_active
FROM `alrowad_uni_rust`.`permissions` p
WHERE @structure_ok = 1
  AND p.permission_code IN (
      'vice_presidency.scientific.access',
      'vice_presidency.administrative.access'
  );

SELECT 'F_vice_presidency_module' AS report_section,
       sm.module_id, sm.module_code, sm.module_name, sm.is_active
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE @structure_ok = 1
  AND sm.module_code = 'vice_presidency';

-- ---------------------------------------------------------------------------
-- G. ORGANIZATIONAL UNITS — rediscover by stable code / name, never assume IDs
-- ---------------------------------------------------------------------------
SELECT 'G_presidency_and_vp_candidates' AS report_section,
       u.organizational_unit_id,
       u.unit_code,
       u.unit_name,
       t.type_code,
       t.type_name,
       p.unit_code AS parent_unit_code,
       p.unit_name AS parent_unit_name,
       u.is_active
FROM `alrowad_uni_rust`.`organizational_units` u
JOIN `alrowad_uni_rust`.`organizational_unit_types` t ON t.unit_type_id = u.unit_type_id
LEFT JOIN `alrowad_uni_rust`.`organizational_units` p ON p.organizational_unit_id = u.parent_unit_id
WHERE @structure_ok = 1
  AND (
      u.unit_code IN ('PRES', 'VP_SCI', 'VP_ADMIN', '7')
      OR t.type_code = 'vice_presidency'
      OR u.unit_name IN (
          'نائب رئيس الجامعة للشؤون العلمية',
          'Vice President for Scientific Affairs',
          'نائب رئيس الجامعة للشؤون الإدارية'
      )
  )
ORDER BY u.unit_code, u.organizational_unit_id;

-- ---------------------------------------------------------------------------
-- H. USER ACCESS SCOPE STRUCTURE
-- ---------------------------------------------------------------------------
SELECT 'H_user_access_scope_columns' AS report_section, column_name, column_type, is_nullable, column_key
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'user_access_scopes'
ORDER BY ordinal_position;

SELECT 'H_scope_type_supports_university' AS report_section, column_type,
       IF(column_type LIKE '%university%', 'SUPPORTED', 'MISSING') AS university_enum
FROM information_schema.columns
WHERE table_schema = 'alrowad_uni_rust'
  AND table_name = 'user_access_scopes'
  AND column_name = 'scope_type';

-- ---------------------------------------------------------------------------
-- I. EXISTING UNIVERSITY SCOPE
-- ---------------------------------------------------------------------------
SELECT 'I_university_scope_count' AS report_section,
       COUNT(*) AS active_university_scope_rows,
       COUNT(DISTINCT s.user_id) AS distinct_users
FROM `alrowad_uni_rust`.`user_access_scopes` s
WHERE @structure_ok = 1
  AND s.scope_type = 'university'
  AND s.is_active = 1;

SELECT 'I_university_scope_sample' AS report_section,
       s.user_id,
       u.username,
       s.scope_type,
       s.scope_id,
       ou.unit_code AS scope_unit_code,
       r.role_code
FROM `alrowad_uni_rust`.`user_access_scopes` s
JOIN `alrowad_uni_rust`.`users` u ON u.user_id = s.user_id
LEFT JOIN `alrowad_uni_rust`.`organizational_units` ou
    ON ou.organizational_unit_id = s.scope_id
LEFT JOIN `alrowad_uni_rust`.`user_roles` ur
    ON ur.user_id = s.user_id AND ur.is_active = 1
LEFT JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id
WHERE @structure_ok = 1
  AND s.scope_type = 'university'
  AND s.is_active = 1
ORDER BY s.user_id, r.role_code
LIMIT 20;

-- ---------------------------------------------------------------------------
-- READINESS FLAGS
-- ---------------------------------------------------------------------------
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

SELECT 'G_resolved_scientific_unit' AS report_section,
       u.organizational_unit_id, u.unit_code, u.unit_name, t.type_code,
       p.unit_code AS parent_unit_code, u.is_active,
       IF(@scientific_unit_count = 1, 'UNIQUE', IF(@scientific_unit_count = 0, 'MISSING', 'AMBIGUOUS')) AS discovery
FROM `alrowad_uni_rust`.`organizational_units` u
JOIN `alrowad_uni_rust`.`organizational_unit_types` t ON t.unit_type_id = u.unit_type_id
LEFT JOIN `alrowad_uni_rust`.`organizational_units` p ON p.organizational_unit_id = u.parent_unit_id
WHERE @structure_ok = 1
  AND u.is_active = 1
  AND (
      u.unit_code = 'VP_SCI'
      OR u.unit_name IN (
          'نائب رئيس الجامعة للشؤون العلمية',
          'Vice President for Scientific Affairs'
      )
  );

SELECT 'G_resolved_administrative_unit' AS report_section,
       u.organizational_unit_id, u.unit_code, u.unit_name, t.type_code,
       p.unit_code AS parent_unit_code, u.is_active,
       IF(@administrative_unit_count = 1, 'UNIQUE', IF(@administrative_unit_count = 0, 'MISSING', 'AMBIGUOUS')) AS discovery
FROM `alrowad_uni_rust`.`organizational_units` u
JOIN `alrowad_uni_rust`.`organizational_unit_types` t ON t.unit_type_id = u.unit_type_id
LEFT JOIN `alrowad_uni_rust`.`organizational_units` p ON p.organizational_unit_id = u.parent_unit_id
WHERE @structure_ok = 1
  AND u.is_active = 1
  AND (
      u.unit_code IN ('7', 'VP_ADMIN')
      OR u.unit_name = 'نائب رئيس الجامعة للشؤون الإدارية'
  );

SELECT 'J_code_audit_note' AS report_section,
       'Application code has no hasRole(vice_president) authorization checks. Keep the generic role. It must not satisfy the new VP identities.' AS note;

SELECT 'blocker_flags' AS report_section,
       @db_ready AS db_ready,
       @missing_required_columns AS missing_required_columns,
       @roles_role_code_unique AS roles_role_code_unique,
       @permissions_code_unique AS permissions_code_unique,
       @role_permission_unique AS role_permission_unique,
       @module_code_unique AS module_code_unique,
       @university_scope_supported AS university_scope_supported,
       @pres_unit_count AS pres_unit_count,
       @scientific_unit_count AS scientific_unit_count,
       @administrative_unit_count AS administrative_unit_count,
       @scientific_role_conflict AS scientific_role_conflict,
       @administrative_role_conflict AS administrative_role_conflict,
       @scientific_name_on_other_code AS scientific_name_on_other_code,
       @administrative_name_on_other_code AS administrative_name_on_other_code,
       @scientific_permission_conflict AS scientific_permission_conflict,
       @administrative_permission_conflict AS administrative_permission_conflict;

SELECT 'OVERALL' AS report_section,
       IF(
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
           'READY',
           'BLOCKED'
       ) AS result;
