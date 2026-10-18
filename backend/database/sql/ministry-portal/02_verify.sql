-- Ministry of Education portal — VERIFY. Read-only. Every row must read PASS after 01_apply.sql.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT '1_module_present' AS check_name, IF(COUNT(*) = 1, 'PASS', 'FAIL') AS status, COUNT(*) AS actual
FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'ministry_portal' AND is_active = 1
UNION ALL
SELECT '2_role_present_active_system', IF(COUNT(*) = 1, 'PASS', 'FAIL'), COUNT(*)
FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'ministry_observer' AND is_active = 1 AND is_system_role = 1
UNION ALL
SELECT '3_eight_permissions_in_module', IF(COUNT(*) = 8, 'PASS', 'FAIL'), COUNT(*)
FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id AND sm.module_code = 'ministry_portal'
WHERE p.permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view') AND p.is_active = 1
UNION ALL
SELECT '4_role_holds_the_eight', IF(COUNT(*) = 8, 'PASS', 'FAIL'), COUNT(*)
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'ministry_observer'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view')
UNION ALL
SELECT '5_role_holds_nothing_else', IF(COUNT(*) = 0, 'PASS', 'FAIL'), COUNT(*)
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'ministry_observer'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code NOT LIKE 'ministry\_portal.%'
UNION ALL
SELECT '6_no_other_role_holds_ministry_permissions', IF(COUNT(*) = 0, 'PASS', 'FAIL'), COUNT(*)
FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code <> 'ministry_observer'
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code LIKE 'ministry\_portal.%'
UNION ALL
SELECT '7_all_objects_tagged', IF(
    (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view') AND COALESCE(description, '') LIKE '%[ministry-portal]%') = 8
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'ministry_observer' AND COALESCE(description, '') LIKE '%[ministry-portal]%') = 1
    AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'ministry_portal' AND COALESCE(description, '') LIKE '%[ministry-portal]%') = 1,
    'PASS', 'FAIL'), NULL
UNION ALL
SELECT '8_ministry_accounts_hold_only_this_role', IF(COUNT(*) = 0, 'PASS', 'FAIL'), COUNT(*)
FROM `alrowad_uni_rust`.`user_roles` ur
JOIN `alrowad_uni_rust`.`user_roles` other ON other.user_id = ur.user_id AND other.is_active = 1 AND other.role_id <> ur.role_id
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id AND r.role_code = 'ministry_observer'
WHERE ur.is_active = 1;
