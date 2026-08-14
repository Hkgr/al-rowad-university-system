-- Read-only verification. Every detail check and OVERALL must return PASS.
SET @test_user_id := (SELECT user_id FROM users WHERE email='dean.fmf.test@rowad.edu' LIMIT 1);
SET @test_employee_id := (SELECT employee_id FROM employees WHERE employee_number='TEMP-DEAN-FMF-2026' LIMIT 1);
SET @fmf_college_id := (SELECT college_id FROM colleges WHERE college_code='FMF' LIMIT 1);

SELECT 'employee_exactly_once' check_name, IF(COUNT(*)=1,'PASS','FAIL') result, COUNT(*) actual
FROM employees WHERE employee_number='TEMP-DEAN-FMF-2026';
SELECT 'user_exactly_once' check_name, IF(COUNT(*)=1,'PASS','FAIL') result, COUNT(*) actual
FROM users WHERE email='dean.fmf.test@rowad.edu';
SELECT 'active_account' check_name, IF(COUNT(*)=1,'PASS','FAIL') result, COUNT(*) actual
FROM users u JOIN account_statuses a ON a.account_status_id=u.account_status_id
WHERE u.user_id=@test_user_id AND a.status_code='active' AND a.is_active=1;
SELECT 'correct_employee_link' check_name, IF(COUNT(*)=1,'PASS','FAIL') result, COUNT(*) actual
FROM users WHERE user_id=@test_user_id AND employee_id=@test_employee_id;
SELECT 'one_effective_dean_role' check_name, IF(COUNT(*)=1,'PASS','FAIL') result, COUNT(*) actual
FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
WHERE ur.user_id=@test_user_id AND ur.is_active=1 AND r.role_code='dean' AND r.is_active=1;
SELECT 'one_active_fmf_scope' check_name, IF(COUNT(*)=1,'PASS','FAIL') result, COUNT(*) actual
FROM user_access_scopes s JOIN colleges c ON s.scope_type='college' AND c.college_id=s.scope_id
WHERE s.user_id=@test_user_id AND s.is_active=1 AND c.college_code='FMF' AND c.college_id=10;
SELECT 'no_university_scope' check_name, IF(COUNT(*)=0,'PASS','FAIL') result, COUNT(*) actual
FROM user_access_scopes WHERE user_id=@test_user_id AND is_active=1 AND scope_type='university';
SELECT 'no_other_college_scope' check_name, IF(COUNT(*)=0,'PASS','FAIL') result, COUNT(*) actual
FROM user_access_scopes WHERE user_id=@test_user_id AND is_active=1 AND scope_type='college'
 AND scope_id<>@fmf_college_id;
SELECT 'no_duplicate_role_or_scope' check_name,
 IF(NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=@test_user_id GROUP BY role_id HAVING COUNT(*)>1)
    AND NOT EXISTS(SELECT 1 FROM user_access_scopes WHERE user_id=@test_user_id
                   GROUP BY scope_type,scope_id HAVING COUNT(*)>1),'PASS','FAIL') result;

SELECT 'OVERALL' check_name,
 IF((SELECT COUNT(*) FROM employees WHERE employee_number='TEMP-DEAN-FMF-2026')=1
 AND (SELECT COUNT(*) FROM users WHERE email='dean.fmf.test@rowad.edu')=1
 AND (SELECT COUNT(*) FROM users u JOIN account_statuses a ON a.account_status_id=u.account_status_id
      WHERE u.user_id=@test_user_id AND u.employee_id=@test_employee_id AND a.status_code='active' AND a.is_active=1)=1
 AND (SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
      WHERE ur.user_id=@test_user_id AND ur.is_active=1 AND r.role_code='dean' AND r.is_active=1)=1
 AND (SELECT COUNT(*) FROM user_access_scopes s JOIN colleges c ON s.scope_type='college' AND c.college_id=s.scope_id
      WHERE s.user_id=@test_user_id AND s.is_active=1 AND c.college_code='FMF' AND c.college_id=10)=1
 AND NOT EXISTS(SELECT 1 FROM user_access_scopes WHERE user_id=@test_user_id AND is_active=1
                AND (scope_type='university' OR (scope_type='college' AND scope_id<>@fmf_college_id)))
 AND NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=@test_user_id GROUP BY role_id HAVING COUNT(*)>1)
 AND NOT EXISTS(SELECT 1 FROM user_access_scopes WHERE user_id=@test_user_id GROUP BY scope_type,scope_id HAVING COUNT(*)>1),
 'PASS','FAIL') result;
