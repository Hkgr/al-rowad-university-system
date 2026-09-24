-- Technical Office portal — ROLLBACK. Manual, fail-closed, idempotent.
-- Removes ONLY objects created by 01_apply.sql (description tag [technical-team-portal]).
-- Every DELETE repeats its own ownership and reference conditions in its WHERE clause;
-- none depends on session variables alone.
--
-- BLOCKED (nothing deleted) when any of these holds:
--   * user_roles has ANY row (active or inactive) for technical_team.
--     Assignment history is never deleted here; revoke and review it first.
--   * a role other than technical_team / super_admin is mapped to one of the three permissions.
--   * technical_team or one of the three permissions exists WITHOUT the tag (not ours).
--
-- Never deleted: user_roles, users, user_activity_logs, system_modules,
--                organizational_units, and any super_admin mapping to other permissions.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @rollback_ready := 0;

SET @role_rows := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'technical_team');
SET @role_untagged := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles`
                       WHERE role_code = 'technical_team' AND COALESCE(description, '') NOT LIKE '%[technical-team-portal]%');
SET @perm_rows := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions`
                   WHERE permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage'));
SET @perm_untagged := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions`
                       WHERE permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage')
                         AND COALESCE(description, '') NOT LIKE '%[technical-team-portal]%');
SET @assignment_rows := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`user_roles` ur
                         JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id
                         WHERE r.role_code = 'technical_team');
SET @foreign_mappings := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
                          JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
                          JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
                          WHERE p.permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage')
                            AND r.role_code NOT IN ('technical_team', 'super_admin'));
SET @super_admin_portal_mapping := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
                          JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'super_admin'
                          JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
                          WHERE p.permission_code = 'technical_portal.access');

SET @rollback_ready := IF(
    @role_untagged = 0 AND @perm_untagged = 0 AND @assignment_rows = 0
    AND @foreign_mappings = 0 AND @super_admin_portal_mapping = 0
    AND (@role_rows + @perm_rows) > 0,
    1, 0);

START TRANSACTION;

-- Single-table DELETEs with fully qualified names: they work without a selected database.

-- Delete 1: only mappings to the three TAGGED permissions, and only from
-- the TAGGED technical_team role or (for user_accounts.*) from super_admin.
DELETE FROM `alrowad_uni_rust`.`role_permissions`
WHERE @rollback_ready = 1
  AND permission_id IN (
      SELECT p.permission_id FROM `alrowad_uni_rust`.`permissions` p
      WHERE p.permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage')
        AND COALESCE(p.description, '') LIKE '%[technical-team-portal]%'
  )
  AND (
      role_id IN (
          SELECT r.role_id FROM `alrowad_uni_rust`.`roles` r
          WHERE r.role_code = 'technical_team' AND COALESCE(r.description, '') LIKE '%[technical-team-portal]%'
      )
      OR (
          role_id IN (SELECT r.role_id FROM `alrowad_uni_rust`.`roles` r WHERE r.role_code = 'super_admin')
          AND permission_id IN (
              SELECT p.permission_id FROM `alrowad_uni_rust`.`permissions` p
              WHERE p.permission_code IN ('user_accounts.view', 'user_accounts.manage')
                AND COALESCE(p.description, '') LIKE '%[technical-team-portal]%'
          )
      )
  );

-- Delete 2: tagged permissions with no remaining role mapping.
DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @rollback_ready = 1
  AND permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage')
  AND COALESCE(description, '') LIKE '%[technical-team-portal]%'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.permission_id = `alrowad_uni_rust`.`permissions`.`permission_id`);

-- Delete 3: the tagged role, only when nothing references it (no mappings, no assignment history).
DELETE FROM `alrowad_uni_rust`.`roles`
WHERE @rollback_ready = 1
  AND role_code = 'technical_team'
  AND COALESCE(description, '') LIKE '%[technical-team-portal]%'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.role_id = `alrowad_uni_rust`.`roles`.`role_id`)
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`user_roles` ur WHERE ur.role_id = `alrowad_uni_rust`.`roles`.`role_id`);

COMMIT;

SELECT CASE
           WHEN (@role_rows + @perm_rows) = 0 THEN 'NOTHING_TO_DO'
           WHEN @rollback_ready = 0 THEN 'BLOCKED'
           WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'technical_team') = 0
            AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions`
                 WHERE permission_code IN ('technical_portal.access', 'user_accounts.view', 'user_accounts.manage')) = 0
               THEN 'ROLLED_BACK'
           ELSE 'BLOCKED_INCOMPLETE'
       END AS rollback_status,
       @assignment_rows AS technical_team_user_roles_rows,
       @foreign_mappings AS permissions_mapped_to_other_roles,
       @super_admin_portal_mapping AS super_admin_portal_access_mappings,
       @role_untagged AS untagged_technical_team_role,
       @perm_untagged AS untagged_permissions;
