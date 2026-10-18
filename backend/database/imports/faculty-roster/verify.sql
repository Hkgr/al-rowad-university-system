-- Faculty roster import — VERIFY (read-only). Run in phpMyAdmin after `faculty:import-roster --apply`.
-- Effective date of affiliations and DEAN positions: 2025-01-01. Nothing here writes.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1) Per college: teachers affiliated from 2025-01-01 by the import path, with a linked doctor_instructor account.
SELECT c.college_code, c.college_name,
       COUNT(DISTINCT e.employee_id) AS affiliated_from_2025_01_01,
       COUNT(DISTINCT IF(r.role_code = 'doctor_instructor', u.user_id, NULL)) AS with_instructor_account,
       COUNT(DISTINCT IF(u.user_id IS NULL, e.employee_id, NULL)) AS without_account
FROM `alrowad_uni_rust`.`employee_unit_assignments` a
JOIN `alrowad_uni_rust`.`colleges` c ON c.organizational_unit_id = a.organizational_unit_id
JOIN `alrowad_uni_rust`.`employees` e ON e.employee_id = a.employee_id
JOIN `alrowad_uni_rust`.`faculty_members` fm ON fm.employee_id = e.employee_id AND fm.is_active = 1
LEFT JOIN `alrowad_uni_rust`.`users` u ON u.employee_id = e.employee_id
LEFT JOIN `alrowad_uni_rust`.`user_roles` ur ON ur.user_id = u.user_id AND ur.is_active = 1
LEFT JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id
WHERE a.start_date = '2025-01-01' AND a.end_date IS NULL AND a.is_active = 1
  AND a.assignment_notes LIKE '%[vp-admin-affiliation]%'
GROUP BY c.college_code, c.college_name ORDER BY c.college_code;

-- 2) Per college: active deans (dean role + active college scope), DEAN position start and whether they are also instructors.
SELECT c.college_code, u.username, e.employee_number,
       MAX(IF(r.role_code = 'doctor_instructor', 1, 0)) AS also_instructor,
       (SELECT MIN(p.start_date) FROM `alrowad_uni_rust`.`employee_positions` p
          JOIN `alrowad_uni_rust`.`positions` ps ON ps.position_id = p.position_id AND ps.position_code = 'DEAN'
         WHERE p.employee_id = u.employee_id AND p.organizational_unit_id = c.organizational_unit_id AND p.end_date IS NULL AND p.is_active = 1) AS dean_position_start
FROM `alrowad_uni_rust`.`user_access_scopes` s
JOIN `alrowad_uni_rust`.`colleges` c ON c.college_id = s.scope_id
JOIN `alrowad_uni_rust`.`users` u ON u.user_id = s.user_id
LEFT JOIN `alrowad_uni_rust`.`employees` e ON e.employee_id = u.employee_id
JOIN `alrowad_uni_rust`.`user_roles` dr ON dr.user_id = u.user_id AND dr.is_active = 1
JOIN `alrowad_uni_rust`.`roles` d ON d.role_id = dr.role_id AND d.role_code = 'dean'
LEFT JOIN `alrowad_uni_rust`.`user_roles` ur ON ur.user_id = u.user_id AND ur.is_active = 1
LEFT JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id
WHERE s.scope_type = 'college' AND s.is_active = 1
GROUP BY c.college_code, c.organizational_unit_id, u.user_id, u.username, u.employee_id, e.employee_number ORDER BY c.college_code;

-- 3) Must all be 0.
SELECT 'employees with more than one account' AS check_name, COUNT(*) AS offending FROM (SELECT employee_id FROM `alrowad_uni_rust`.`users` WHERE employee_id IS NOT NULL GROUP BY employee_id HAVING COUNT(*) > 1) x
UNION ALL SELECT 'employees with more than one faculty profile', COUNT(*) FROM (SELECT employee_id FROM `alrowad_uni_rust`.`faculty_members` GROUP BY employee_id HAVING COUNT(*) > 1) x
UNION ALL SELECT 'dean accounts with a university scope', COUNT(*) FROM `alrowad_uni_rust`.`user_roles` ur JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id AND r.role_code = 'dean'
   JOIN `alrowad_uni_rust`.`user_access_scopes` s ON s.user_id = ur.user_id AND s.scope_type = 'university' AND s.is_active = 1 WHERE ur.is_active = 1
UNION ALL SELECT 'dean accounts with more than one active college scope', COUNT(*) FROM (SELECT s.user_id FROM `alrowad_uni_rust`.`user_access_scopes` s JOIN `alrowad_uni_rust`.`user_roles` ur ON ur.user_id = s.user_id AND ur.is_active = 1 JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id AND r.role_code = 'dean' WHERE s.scope_type = 'college' AND s.is_active = 1 GROUP BY s.user_id HAVING COUNT(DISTINCT s.scope_id) > 1) x
UNION ALL SELECT 'instructor accounts holding super_admin', COUNT(*) FROM `alrowad_uni_rust`.`user_roles` a JOIN `alrowad_uni_rust`.`roles` ra ON ra.role_id = a.role_id AND ra.role_code = 'doctor_instructor'
   JOIN `alrowad_uni_rust`.`user_roles` b ON b.user_id = a.user_id AND b.is_active = 1 JOIN `alrowad_uni_rust`.`roles` rb ON rb.role_id = b.role_id AND rb.role_code = 'super_admin' WHERE a.is_active = 1
UNION ALL SELECT 'positions or affiliations closed before they started', (SELECT COUNT(*) FROM `alrowad_uni_rust`.`employee_positions` WHERE end_date IS NOT NULL AND end_date < start_date) + (SELECT COUNT(*) FROM `alrowad_uni_rust`.`employee_unit_assignments` WHERE end_date IS NOT NULL AND end_date < start_date)
UNION ALL SELECT 'audit rows dated before 2026 by the import', COUNT(*) FROM `alrowad_uni_rust`.`user_activity_logs` WHERE action_code = 'faculty_roster.import_applied' AND created_at < '2026-01-01';

-- 4) Import runs (execution time, not the effective date).
SELECT activity_log_id, user_id, created_at, description FROM `alrowad_uni_rust`.`user_activity_logs`
WHERE module_code = 'vice_presidency' AND action_code = 'faculty_roster.import_applied' ORDER BY activity_log_id DESC LIMIT 10;
