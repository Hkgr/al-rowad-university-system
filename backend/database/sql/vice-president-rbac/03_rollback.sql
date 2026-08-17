-- Rollback ONLY RBAC records introduced by this phase.
-- Fully qualified objects. Do not use DATABASE().
-- Fail closed: if a new role is already assigned to a user, skip deleting that role.
-- Does NOT delete generic vice_president, organizational units, users, or user_access_scopes.

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

DELETE rp
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @scientific_assigned = 0
  AND r.role_code = 'vice_president_scientific'
  AND p.permission_code IN (
      'vice_presidency.scientific.access',
      'vice_presidency.administrative.access'
  );

DELETE rp
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE @administrative_assigned = 0
  AND r.role_code = 'vice_president_administrative'
  AND p.permission_code IN (
      'vice_presidency.scientific.access',
      'vice_presidency.administrative.access'
  );

DELETE FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'vice_president_scientific'
  AND @scientific_assigned = 0;

DELETE FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'vice_president_administrative'
  AND @administrative_assigned = 0;

DELETE p
FROM `alrowad_uni_rust`.`permissions` p
WHERE p.permission_code = 'vice_presidency.scientific.access'
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` rp
      WHERE rp.permission_id = p.permission_id
  );

DELETE p
FROM `alrowad_uni_rust`.`permissions` p
WHERE p.permission_code = 'vice_presidency.administrative.access'
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`role_permissions` rp
      WHERE rp.permission_id = p.permission_id
  );

DELETE sm
FROM `alrowad_uni_rust`.`system_modules` sm
WHERE sm.module_code = 'vice_presidency'
  AND NOT EXISTS (
      SELECT 1
      FROM `alrowad_uni_rust`.`permissions` p
      WHERE p.module_id = sm.module_id
  );

SELECT 'scientific_role' AS item,
       IF(@scientific_assigned > 0, 'BLOCKED / SKIP', 'ROLLED_BACK_OR_ABSENT') AS result,
       @scientific_assigned AS assigned_user_role_rows,
       (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`roles`
           WHERE role_code = 'vice_president_scientific'
       ) AS remaining_role_rows;

SELECT 'administrative_role' AS item,
       IF(@administrative_assigned > 0, 'BLOCKED / SKIP', 'ROLLED_BACK_OR_ABSENT') AS result,
       @administrative_assigned AS assigned_user_role_rows,
       (
           SELECT COUNT(*)
           FROM `alrowad_uni_rust`.`roles`
           WHERE role_code = 'vice_president_administrative'
       ) AS remaining_role_rows;

SELECT 'generic_vice_president_preserved' AS item,
       IF(COUNT(*) = 1, 'PRESERVED', 'MISSING') AS result
FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'vice_president';
