-- Administrative Vice-Presidency governance — ROLLBACK. Manual, fail-closed, idempotent.
-- Removes ONLY the four tagged permissions and their mappings. It never deletes accounts,
-- employees, faculty profiles, affiliations, dean scopes/positions or audit rows: deans and
-- teachers created through the portal remain valid data; only the portal's permissions go.
--
-- BLOCKED when: a permission exists without the tag, or a role other than
-- vice_president_administrative / super_admin is mapped to one of them.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @rollback_ready := 0;

SET @perm_rows := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions`
                   WHERE permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                                             'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage'));
SET @perm_untagged := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions`
                       WHERE permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                                                 'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')
                         AND COALESCE(description, '') NOT LIKE '%[vp-admin-governance]%');
SET @foreign_mappings := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
                          JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
                          JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
                          WHERE p.permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                                                      'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')
                            AND r.role_code NOT IN ('vice_president_administrative', 'super_admin'));

SET @rollback_ready := IF(@perm_rows > 0 AND @perm_untagged = 0 AND @foreign_mappings = 0, 1, 0);

START TRANSACTION;

DELETE FROM `alrowad_uni_rust`.`role_permissions`
WHERE @rollback_ready = 1
  AND permission_id IN (
      SELECT p.permission_id FROM `alrowad_uni_rust`.`permissions` p
      WHERE p.permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                                  'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')
        AND COALESCE(p.description, '') LIKE '%[vp-admin-governance]%'
  )
  AND role_id IN (SELECT r.role_id FROM `alrowad_uni_rust`.`roles` r WHERE r.role_code IN ('vice_president_administrative', 'super_admin'));

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @rollback_ready = 1
  AND permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                          'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')
  AND COALESCE(description, '') LIKE '%[vp-admin-governance]%'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.permission_id = `alrowad_uni_rust`.`permissions`.`permission_id`);

COMMIT;

SELECT CASE
           WHEN @perm_rows = 0 THEN 'NOTHING_TO_DO'
           WHEN @rollback_ready = 0 THEN 'BLOCKED'
           WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions`
                 WHERE permission_code IN ('vice_presidency.administrative.faculty.view', 'vice_presidency.administrative.faculty.manage',
                                           'vice_presidency.administrative.deans.view', 'vice_presidency.administrative.deans.manage')) = 0 THEN 'ROLLED_BACK'
           ELSE 'BLOCKED_INCOMPLETE'
       END AS rollback_status,
       @perm_untagged AS untagged_permissions, @foreign_mappings AS permissions_mapped_to_other_roles;
