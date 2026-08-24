-- Manual, guarded, rerunnable DML for Academic Calendar Phase 2 RBAC only.
-- Run only after 00_preflight.sql returns OVERALL | READY.

SET @ac2_apply_ready :=
    (SELECT COUNT(*) = 1 FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'vice_presidency' AND is_active = 1)
    AND (SELECT COUNT(*) = 1 FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_scientific' AND is_active = 1)
    AND (
        (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'academic_calendar.manage') = 0
        OR
        (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id WHERE p.permission_code = 'academic_calendar.manage' AND p.is_active = 1 AND m.module_code = 'vice_presidency') = 1
    )
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id WHERE p.permission_code = 'academic_calendar.manage' AND r.role_code <> 'vice_president_scientific') = 0;

START TRANSACTION;

INSERT INTO `alrowad_uni_rust`.`permissions`
    (`module_id`, `permission_code`, `permission_name`, `description`, `is_active`)
SELECT m.module_id, 'academic_calendar.manage', 'Academic Calendar Manage',
       'Manage Academic Calendar publishing and lifecycle. [academic-calendar-phase2-rbac]', 1
FROM `alrowad_uni_rust`.`system_modules` m
WHERE @ac2_apply_ready = 1 AND m.module_code = 'vice_presidency' AND m.is_active = 1
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` p WHERE p.permission_code = 'academic_calendar.manage');

INSERT INTO `alrowad_uni_rust`.`role_permissions` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code = 'academic_calendar.manage' AND p.is_active = 1
JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id = p.module_id AND m.module_code = 'vice_presidency'
WHERE @ac2_apply_ready = 1 AND r.role_code = 'vice_president_scientific' AND r.is_active = 1
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id);

COMMIT;

SELECT 'APPLY_RESULT' AS report_section,
       IF(@ac2_apply_ready = 1
          AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'academic_calendar.manage') = 1
          AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id WHERE p.permission_code = 'academic_calendar.manage' AND r.role_code = 'vice_president_scientific') = 1,
          'APPLIED', 'BLOCKED') AS result;
