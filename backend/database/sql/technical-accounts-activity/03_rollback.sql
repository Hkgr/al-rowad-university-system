-- Technical Office: account editing + activity feed — ROLLBACK. Manual, fail-closed, idempotent.
-- Removes ONLY the two tagged permissions, their mappings to technical_team/super_admin and the
-- three indexes. Never deletes accounts, log rows, employees or students; name corrections and
-- audit rows written while the feature was active stay (they are real history).
-- BLOCKED when a permission is untagged or mapped to any other role.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @perm_rows := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view'));
SET @perm_untagged := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions`
                       WHERE permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')
                         AND COALESCE(description, '') NOT LIKE '%[technical-accounts-activity]%');
SET @foreign_mappings := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
                          JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
                          JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
                          WHERE p.permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')
                            AND r.role_code NOT IN ('technical_team', 'super_admin'));
SET @rollback_ready := IF(@perm_untagged = 0 AND @foreign_mappings = 0, 1, 0);

START TRANSACTION;
DELETE FROM `alrowad_uni_rust`.`role_permissions`
WHERE @rollback_ready = 1
  AND permission_id IN (SELECT p.permission_id FROM `alrowad_uni_rust`.`permissions` p
                        WHERE p.permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')
                          AND COALESCE(p.description, '') LIKE '%[technical-accounts-activity]%')
  AND role_id IN (SELECT r.role_id FROM `alrowad_uni_rust`.`roles` r WHERE r.role_code IN ('technical_team', 'super_admin'));
DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @rollback_ready = 1
  AND permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')
  AND COALESCE(description, '') LIKE '%[technical-accounts-activity]%'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.permission_id = `alrowad_uni_rust`.`permissions`.`permission_id`);
COMMIT;

SET @sql := IF(@rollback_ready = 1, 'DROP INDEX IF EXISTS `idx_ual_created_at` ON `alrowad_uni_rust`.`user_activity_logs`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rollback_ready = 1, 'DROP INDEX IF EXISTS `idx_ual_module_created` ON `alrowad_uni_rust`.`user_activity_logs`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@rollback_ready = 1, 'DROP INDEX IF EXISTS `idx_lal_attempted_at` ON `alrowad_uni_rust`.`login_audit_logs`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT CASE WHEN @rollback_ready = 0 THEN 'BLOCKED'
            WHEN @perm_rows = 0 THEN 'NOTHING_TO_DO_PERMISSIONS_ABSENT'
            WHEN (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN ('user_accounts.holder_name.manage', 'system_activity.view')) = 0 THEN 'ROLLED_BACK'
            ELSE 'BLOCKED_INCOMPLETE' END AS rollback_status,
       @perm_untagged AS untagged_permissions, @foreign_mappings AS permissions_mapped_to_other_roles;
