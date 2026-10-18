-- Conservative rollback. Removes only the owned mapping and owned permission.
-- Existing compatible permissions without the ownership marker are retained.

START TRANSACTION;

DELETE rp FROM `alrowad_uni_rust`.`role_permissions` rp
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
WHERE p.permission_code = 'academic_calendar.manage'
  AND p.description LIKE '%[academic-calendar-phase2-rbac]%'
  AND r.role_code = 'vice_president_scientific';

DELETE p FROM `alrowad_uni_rust`.`permissions` p
WHERE p.permission_code = 'academic_calendar.manage'
  AND p.description LIKE '%[academic-calendar-phase2-rbac]%'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.permission_id = p.permission_id);

COMMIT;

SELECT 'ROLLBACK_RESULT' AS report_section,
       IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'academic_calendar.manage' AND description LIKE '%[academic-calendar-phase2-rbac]%') = 0, 'ROLLED_BACK', 'BLOCKED') AS result;
