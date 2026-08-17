-- Rollback ONLY RBAC records that can be proven to have been created by Phase 3.
-- Fully qualified objects. Do not use DATABASE().
-- Ownership proof: description contains [phase3-vp-rbac] (stamped by 01_apply.sql).
-- Compatible pre-existing objects are not rewritten by apply and therefore lack
-- that token; they are reported SKIPPED_NOT_PROVABLY_PHASE_OWNED and kept.
--
-- Fail closed: if a target role is assigned to any user, do not delete it.
-- Never delete users, user_roles, user_access_scopes, organizational_units,
-- or generic vice_president.
-- Do not create a tracking table.

SET @roles_has_description := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'roles'
          AND column_name = 'description'
    ),
    1,
    0
);
SET @permissions_has_description := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'permissions'
          AND column_name = 'description'
    ),
    1,
    0
);
SET @modules_has_description := IF(
    EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'alrowad_uni_rust'
          AND table_name = 'system_modules'
          AND column_name = 'description'
    ),
    1,
    0
);

SET @scientific_assigned := (
    SELECT COUNT(*)
    FROM `alrowad_uni_rust`.`user_roles` ur
    JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id
    WHERE r.role_code = 'vice_president_scientific'
);
SET @administrative_assigned := (
    SELECT COUNT(*)
    FROM `alrowad_uni_rust`.`user_roles` ur
    JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id
    WHERE r.role_code = 'vice_president_administrative'
);

SET @sci_role_exists := (
    SELECT COUNT(*)
    FROM `alrowad_uni_rust`.`roles`
    WHERE role_code = 'vice_president_scientific'
);
SET @adm_role_exists := (
    SELECT COUNT(*)
    FROM `alrowad_uni_rust`.`roles`
    WHERE role_code = 'vice_president_administrative'
);
SET @sci_role_owned := IF(
    @roles_has_description = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles`
        WHERE role_code = 'vice_president_scientific'
          AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%'
    ),
    0
);
SET @adm_role_owned := IF(
    @roles_has_description = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`roles`
        WHERE role_code = 'vice_president_administrative'
          AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%'
    ),
    0
);

SET @sci_perm_exists := (
    SELECT COUNT(*)
    FROM `alrowad_uni_rust`.`permissions`
    WHERE permission_code = 'vice_presidency.scientific.access'
);
SET @adm_perm_exists := (
    SELECT COUNT(*)
    FROM `alrowad_uni_rust`.`permissions`
    WHERE permission_code = 'vice_presidency.administrative.access'
);
SET @sci_perm_owned := IF(
    @permissions_has_description = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions`
        WHERE permission_code = 'vice_presidency.scientific.access'
          AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%'
    ),
    0
);
SET @adm_perm_owned := IF(
    @permissions_has_description = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`permissions`
        WHERE permission_code = 'vice_presidency.administrative.access'
          AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%'
    ),
    0
);

SET @module_exists := (
    SELECT COUNT(*)
    FROM `alrowad_uni_rust`.`system_modules`
    WHERE module_code = 'vice_presidency'
);
SET @module_owned := IF(
    @modules_has_description = 1,
    (
        SELECT COUNT(*)
        FROM `alrowad_uni_rust`.`system_modules`
        WHERE module_code = 'vice_presidency'
          AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%'
    ),
    0
);

-- Mappings may be removed only when the role itself is Phase-3-owned and unassigned.
-- A reused compatible role may already have had the same mapping.
DELETE rp
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @sci_role_owned = 1
  AND @scientific_assigned = 0
  AND r.role_code = 'vice_president_scientific'
  AND COALESCE(r.description, '') LIKE '%[phase3-vp-rbac]%'
  AND p.permission_code IN (
      'vice_presidency.scientific.access',
      'vice_presidency.administrative.access'
  );

DELETE rp
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @adm_role_owned = 1
  AND @administrative_assigned = 0
  AND r.role_code = 'vice_president_administrative'
  AND COALESCE(r.description, '') LIKE '%[phase3-vp-rbac]%'
  AND p.permission_code IN (
      'vice_presidency.scientific.access',
      'vice_presidency.administrative.access'
  );

DELETE FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'vice_president_scientific'
  AND @sci_role_owned = 1
  AND @scientific_assigned = 0
  AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%';

DELETE FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'vice_president_administrative'
  AND @adm_role_owned = 1
  AND @administrative_assigned = 0
  AND COALESCE(description, '') LIKE '%[phase3-vp-rbac]%';

DELETE p
FROM `alrowad_uni_rust`.`permissions` p
WHERE p.permission_code = 'vice_presidency.scientific.access'
  AND @sci_perm_owned = 1
  AND COALESCE(p.description, '') LIKE '%[phase3-vp-rbac]%'
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` rp
      WHERE rp.permission_id = p.permission_id
  );

DELETE p
FROM `alrowad_uni_rust`.`permissions` p
WHERE p.permission_code = 'vice_presidency.administrative.access'
  AND @adm_perm_owned = 1
  AND COALESCE(p.description, '') LIKE '%[phase3-vp-rbac]%'
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` rp
      WHERE rp.permission_id = p.permission_id
  );

DELETE sm
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE sm.module_code = 'vice_presidency'
  AND @module_owned = 1
  AND COALESCE(sm.description, '') LIKE '%[phase3-vp-rbac]%'
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`permissions` p
      WHERE p.module_id = sm.module_id
  );

SELECT 'scientific_role' AS item,
       CASE
           WHEN @sci_role_exists = 0 THEN 'ABSENT'
           WHEN @scientific_assigned > 0 THEN 'BLOCKED / SKIP'
           WHEN @sci_role_owned = 0 THEN 'SKIPPED_NOT_PROVABLY_PHASE_OWNED'
           WHEN (
               SELECT COUNT(*)
               FROM `alrowad_uni_rust`.`roles`
               WHERE role_code = 'vice_president_scientific'
           ) = 0 THEN 'ROLLED_BACK'
           ELSE 'BLOCKED / SKIP'
       END AS result,
       @scientific_assigned AS assigned_user_role_rows,
       @sci_role_owned AS phase3_owned;

SELECT 'administrative_role' AS item,
       CASE
           WHEN @adm_role_exists = 0 THEN 'ABSENT'
           WHEN @administrative_assigned > 0 THEN 'BLOCKED / SKIP'
           WHEN @adm_role_owned = 0 THEN 'SKIPPED_NOT_PROVABLY_PHASE_OWNED'
           WHEN (
               SELECT COUNT(*)
               FROM `alrowad_uni_rust`.`roles`
               WHERE role_code = 'vice_president_administrative'
           ) = 0 THEN 'ROLLED_BACK'
           ELSE 'BLOCKED / SKIP'
       END AS result,
       @administrative_assigned AS assigned_user_role_rows,
       @adm_role_owned AS phase3_owned;

SELECT 'scientific_permission' AS item,
       CASE
           WHEN @sci_perm_exists = 0 THEN 'ABSENT'
           WHEN @sci_perm_owned = 0 THEN 'SKIPPED_NOT_PROVABLY_PHASE_OWNED'
           WHEN (
               SELECT COUNT(*)
               FROM `alrowad_uni_rust`.`permissions`
               WHERE permission_code = 'vice_presidency.scientific.access'
           ) = 0 THEN 'ROLLED_BACK'
           ELSE 'SKIPPED_STILL_REFERENCED'
       END AS result,
       @sci_perm_owned AS phase3_owned;

SELECT 'administrative_permission' AS item,
       CASE
           WHEN @adm_perm_exists = 0 THEN 'ABSENT'
           WHEN @adm_perm_owned = 0 THEN 'SKIPPED_NOT_PROVABLY_PHASE_OWNED'
           WHEN (
               SELECT COUNT(*)
               FROM `alrowad_uni_rust`.`permissions`
               WHERE permission_code = 'vice_presidency.administrative.access'
           ) = 0 THEN 'ROLLED_BACK'
           ELSE 'SKIPPED_STILL_REFERENCED'
       END AS result,
       @adm_perm_owned AS phase3_owned;

SELECT 'vice_presidency_module' AS item,
       CASE
           WHEN @module_exists = 0 THEN 'ABSENT'
           WHEN @module_owned = 0 THEN 'SKIPPED_NOT_PROVABLY_PHASE_OWNED'
           WHEN (
               SELECT COUNT(*)
               FROM `alrowad_uni_rust`.`system_modules`
               WHERE module_code = 'vice_presidency'
           ) = 0 THEN 'ROLLED_BACK'
           ELSE 'SKIPPED_STILL_REFERENCED'
       END AS result,
       @module_owned AS phase3_owned;

SELECT 'generic_vice_president_preserved' AS item,
       IF(COUNT(*) = 1, 'PRESERVED', 'MISSING') AS result
FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'vice_president';
