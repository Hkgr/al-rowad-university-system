-- Ministry of Education portal — ROLLBACK. Manual, fail-closed, idempotent.
-- Removes ONLY objects created by 01_apply.sql (description tag [ministry-portal]).
--
-- BLOCKED (nothing deleted) when any of these holds:
--   * user_roles has ANY row (active or inactive) for ministry_observer — assignment history is
--     never deleted here; revoke the role from the account(s) and review before rolling back;
--   * another role holds a ministry permission, or the ministry role holds another permission;
--   * the role, the module or a permission exists WITHOUT the tag (not created by this package).
-- Never deleted: users, user_roles, logs, any data read by the portal.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @rollback_ready := 0;
SET @role_rows := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'ministry_observer');
SET @role_untagged := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'ministry_observer' AND COALESCE(description, '') NOT LIKE '%[ministry-portal]%');
SET @perm_rows := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view'));
SET @perm_untagged := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view') AND COALESCE(description, '') NOT LIKE '%[ministry-portal]%');
SET @module_rows := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'ministry_portal');
SET @module_untagged := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'ministry_portal' AND COALESCE(description, '') NOT LIKE '%[ministry-portal]%');
SET @module_foreign_permissions := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
                                    WHERE sm.module_code = 'ministry_portal' AND p.permission_code NOT IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view'));
SET @assignment_rows := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`user_roles` ur JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id WHERE r.role_code = 'ministry_observer');
SET @foreign_mappings := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
                          JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
                          JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
                          WHERE (p.permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view') AND r.role_code <> 'ministry_observer')
                             OR (r.role_code = 'ministry_observer' AND p.permission_code NOT IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view')));

SET @rollback_ready := IF(
    @role_untagged = 0 AND @perm_untagged = 0 AND @module_untagged = 0 AND @module_foreign_permissions = 0
    AND @assignment_rows = 0 AND @foreign_mappings = 0 AND (@role_rows + @perm_rows + @module_rows) > 0,
    1, 0);

START TRANSACTION;

DELETE FROM `alrowad_uni_rust`.`role_permissions`
WHERE @rollback_ready = 1
  AND permission_id IN (SELECT p.permission_id FROM `alrowad_uni_rust`.`permissions` p
                        WHERE p.permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view') AND COALESCE(p.description, '') LIKE '%[ministry-portal]%')
  AND role_id IN (SELECT r.role_id FROM `alrowad_uni_rust`.`roles` r
                  WHERE r.role_code = 'ministry_observer' AND COALESCE(r.description, '') LIKE '%[ministry-portal]%');

DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @rollback_ready = 1 AND permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view') AND COALESCE(description, '') LIKE '%[ministry-portal]%'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.permission_id = `alrowad_uni_rust`.`permissions`.`permission_id`);

DELETE FROM `alrowad_uni_rust`.`roles`
WHERE @rollback_ready = 1 AND role_code = 'ministry_observer' AND COALESCE(description, '') LIKE '%[ministry-portal]%'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`user_roles` ur WHERE ur.role_id = `alrowad_uni_rust`.`roles`.`role_id`)
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.role_id = `alrowad_uni_rust`.`roles`.`role_id`);

DELETE FROM `alrowad_uni_rust`.`system_modules`
WHERE @rollback_ready = 1 AND module_code = 'ministry_portal' AND COALESCE(description, '') LIKE '%[ministry-portal]%'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` p WHERE p.module_id = `alrowad_uni_rust`.`system_modules`.`module_id`);

COMMIT;

SELECT IF((@role_rows + @perm_rows + @module_rows) = 0, 'NOTHING_TO_DO', IF(@rollback_ready = 1, 'ROLLED_BACK', 'BLOCKED')) AS rollback_status,
       @role_rows AS role_rows, @perm_rows AS permission_rows, @module_rows AS module_rows,
       @assignment_rows AS role_assignment_rows, @foreign_mappings AS foreign_mappings,
       @role_untagged + @perm_untagged + @module_untagged AS untagged_objects;
