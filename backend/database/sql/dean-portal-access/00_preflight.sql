-- Read-only preflight. Run this file first and continue only when OVERALL is READY.
-- No data or schema is changed by this script.

SELECT 'required_structure' AS report_section, table_name, column_name, column_type, is_nullable, column_key
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ((table_name = 'colleges' AND column_name IN ('college_id','organizational_unit_id','college_code','college_name'))
    OR (table_name = 'roles' AND column_name IN ('role_id','role_code','is_active'))
    OR (table_name = 'account_statuses' AND column_name IN ('account_status_id','status_code','is_active'))
    OR (table_name = 'employee_types' AND column_name IN ('employee_type_id','type_code','is_active'))
    OR (table_name = 'employee_statuses' AND column_name IN ('employee_status_id','status_code','is_active'))
    OR (table_name = 'employees' AND column_name IN ('employee_id','employee_number','first_name','last_name','email','employee_type_id','employee_status_id','organizational_unit_id'))
    OR (table_name = 'users' AND column_name IN ('user_id','username','email','password_hash','account_status_id','employee_id'))
    OR (table_name = 'user_roles' AND column_name IN ('user_role_id','user_id','role_id','is_active'))
    OR (table_name = 'user_access_scopes' AND column_name IN ('user_access_scope_id','user_id','scope_type','scope_id','is_active')))
ORDER BY table_name, ordinal_position;

SELECT 'target_college' AS report_section, college_id, college_code, college_name, organizational_unit_id, is_active
FROM colleges WHERE college_code = 'FMF';
SELECT 'dean_role' AS report_section, role_id, role_code, role_name, is_active
FROM roles WHERE role_code = 'dean';
SELECT 'active_account_status' AS report_section, account_status_id, status_code, status_name, is_active
FROM account_statuses WHERE status_code = 'active';
SELECT 'supported_college_scope' AS report_section, column_type,
       IF(column_type LIKE '%college%', 'SUPPORTED', 'BLOCKED') AS result
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'user_access_scopes' AND column_name = 'scope_type';
SELECT 'existing_test_identity' AS report_section, u.user_id, u.username, u.email, u.employee_id,
       e.employee_number
FROM users u LEFT JOIN employees e ON e.employee_id = u.employee_id
WHERE u.email = 'dean.fmf.test@rowad.edu' OR u.username = 'dean.fmf.test'
   OR e.employee_number = 'TEMP-DEAN-FMF-2026' OR e.email = 'dean.fmf.test@rowad.edu';

DROP TEMPORARY TABLE IF EXISTS dean_preflight_blockers;
CREATE TEMPORARY TABLE dean_preflight_blockers (reason VARCHAR(255) NOT NULL);
INSERT INTO dean_preflight_blockers
SELECT 'FMF college must exist exactly once' WHERE (SELECT COUNT(*) FROM colleges WHERE college_code='FMF') <> 1;
INSERT INTO dean_preflight_blockers
SELECT CONCAT('FMF college_id is ', COALESCE(CAST((SELECT MAX(college_id) FROM colleges WHERE college_code='FMF') AS CHAR), 'NULL'), ', expected 10')
WHERE (SELECT COUNT(*) FROM colleges WHERE college_code='FMF' AND college_id=10) <> 1;
INSERT INTO dean_preflight_blockers
SELECT 'FMF college name does not match كلية العلوم الإدارية والمالية'
WHERE (SELECT COUNT(*) FROM colleges WHERE college_code='FMF' AND college_name='كلية العلوم الإدارية والمالية') <> 1;
INSERT INTO dean_preflight_blockers SELECT 'active dean role must exist exactly once'
WHERE (SELECT COUNT(*) FROM roles WHERE role_code='dean' AND is_active=1) <> 1;
INSERT INTO dean_preflight_blockers SELECT 'active account status must exist exactly once'
WHERE (SELECT COUNT(*) FROM account_statuses WHERE status_code='active' AND is_active=1) <> 1;
INSERT INTO dean_preflight_blockers SELECT 'active administrative employee type must exist exactly once'
WHERE (SELECT COUNT(*) FROM employee_types WHERE type_code='administrative' AND is_active=1) <> 1;
INSERT INTO dean_preflight_blockers SELECT 'active employee status must exist exactly once'
WHERE (SELECT COUNT(*) FROM employee_statuses WHERE status_code='active' AND is_active=1) <> 1;
INSERT INTO dean_preflight_blockers SELECT 'required table/column structure is incomplete'
WHERE (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND
 ((table_name='colleges' AND column_name IN ('college_id','organizational_unit_id','college_code','college_name')) OR
  (table_name='roles' AND column_name IN ('role_id','role_code','is_active')) OR
  (table_name='account_statuses' AND column_name IN ('account_status_id','status_code','is_active')) OR
  (table_name='employee_types' AND column_name IN ('employee_type_id','type_code','is_active')) OR
  (table_name='employee_statuses' AND column_name IN ('employee_status_id','status_code','is_active')) OR
  (table_name='employees' AND column_name IN ('employee_id','employee_number','first_name','last_name','email','employee_type_id','employee_status_id','organizational_unit_id')) OR
  (table_name='users' AND column_name IN ('user_id','username','email','password_hash','account_status_id','employee_id')) OR
  (table_name='user_roles' AND column_name IN ('user_role_id','user_id','role_id','is_active')) OR
  (table_name='user_access_scopes' AND column_name IN ('user_access_scope_id','user_id','scope_type','scope_id','is_active')))) <> 39;
INSERT INTO dean_preflight_blockers SELECT 'user_access_scopes does not support a direct college scope'
WHERE NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE()
 AND table_name='user_access_scopes' AND column_name='scope_type' AND column_type LIKE '%college%');
INSERT INTO dean_preflight_blockers SELECT 'employee number is already attached to a different email'
WHERE EXISTS (SELECT 1 FROM employees WHERE employee_number='TEMP-DEAN-FMF-2026'
 AND COALESCE(email,'') <> 'dean.fmf.test@rowad.edu');
INSERT INTO dean_preflight_blockers SELECT 'test employee email is already attached to a different employee number'
WHERE EXISTS (SELECT 1 FROM employees WHERE email='dean.fmf.test@rowad.edu'
 AND employee_number <> 'TEMP-DEAN-FMF-2026');
INSERT INTO dean_preflight_blockers SELECT 'test user email or username belongs to a different employee identity'
WHERE EXISTS (SELECT 1 FROM users WHERE (email='dean.fmf.test@rowad.edu' OR username='dean.fmf.test')
 AND (email<>'dean.fmf.test@rowad.edu' OR username<>'dean.fmf.test' OR employee_id IS NULL
      OR employee_id <> COALESCE((SELECT employee_id FROM employees WHERE employee_number='TEMP-DEAN-FMF-2026' LIMIT 1), -1)));
INSERT INTO dean_preflight_blockers SELECT 'test employee identity is linked to a different user'
WHERE EXISTS (SELECT 1 FROM users WHERE employee_id=(SELECT employee_id FROM employees
 WHERE employee_number='TEMP-DEAN-FMF-2026' LIMIT 1) AND email<>'dean.fmf.test@rowad.edu');

SELECT 'BLOCKED_REASON' AS report_section, reason FROM dean_preflight_blockers ORDER BY reason;
SELECT 'OVERALL' AS report_section, IF(COUNT(*)=0, 'READY', 'BLOCKED') AS result,
       COUNT(*) AS blocker_count FROM dean_preflight_blockers;
DROP TEMPORARY TABLE dean_preflight_blockers;
