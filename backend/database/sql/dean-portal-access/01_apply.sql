-- Manual, idempotent creation of the FMF test dean. Run only after 00_preflight.sql returns READY.
-- Before running, choose a new temporary password and generate its Laravel-compatible hash locally:
-- php -r "echo password_hash(getenv('DEAN_TEMP_PASSWORD'), PASSWORD_BCRYPT), PHP_EOL;"
-- Set DEAN_TEMP_PASSWORD only in the operator's shell, then replace the placeholder below with the output.
-- Never place the plaintext password in this file or commit it.
SET @dean_temporary_password_hash = 'REPLACE_WITH_OPERATOR_GENERATED_BCRYPT_HASH';

DELIMITER $$
DROP PROCEDURE IF EXISTS apply_fmf_test_dean$$
CREATE PROCEDURE apply_fmf_test_dean()
main: BEGIN
  DECLARE v_college_id INT;
  DECLARE v_org_unit_id INT;
  DECLARE v_role_id INT;
  DECLARE v_account_status_id INT;
  DECLARE v_employee_type_id INT;
  DECLARE v_employee_status_id INT;
  DECLARE v_employee_id INT;
  DECLARE v_user_id INT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  IF (SELECT COUNT(*) FROM colleges WHERE college_code='FMF' AND college_id=10
      AND college_name='كلية العلوم الإدارية والمالية' AND is_active=1) <> 1
     OR (SELECT COUNT(*) FROM roles WHERE role_code='dean' AND is_active=1) <> 1
     OR (SELECT COUNT(*) FROM account_statuses WHERE status_code='active' AND is_active=1) <> 1
     OR (SELECT COUNT(*) FROM employee_types WHERE type_code='administrative' AND is_active=1) <> 1
     OR (SELECT COUNT(*) FROM employee_statuses WHERE status_code='active' AND is_active=1) <> 1
     OR (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND
       ((table_name='colleges' AND column_name IN ('college_id','organizational_unit_id','college_code','college_name','is_active')) OR
        (table_name='roles' AND column_name IN ('role_id','role_code','role_name','is_active')) OR
        (table_name='account_statuses' AND column_name IN ('account_status_id','status_code','status_name','is_active')) OR
        (table_name='employee_types' AND column_name IN ('employee_type_id','type_code','is_active')) OR
        (table_name='employee_statuses' AND column_name IN ('employee_status_id','status_code','is_active')) OR
        (table_name='employees' AND column_name IN ('employee_id','employee_number','first_name','last_name','email','employee_type_id','employee_status_id','organizational_unit_id','created_at','updated_at')) OR
        (table_name='users' AND column_name IN ('user_id','username','email','password_hash','account_status_id','employee_id','failed_login_attempts','created_at','updated_at')) OR
        (table_name='user_roles' AND column_name IN ('user_role_id','user_id','role_id','assigned_by_user_id','assigned_at','is_active')) OR
        (table_name='user_access_scopes' AND column_name IN ('user_access_scope_id','user_id','scope_type','scope_id','is_active','created_at','updated_at')))) <> 51
     OR NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE()
                    AND table_name='user_access_scopes' AND column_name='scope_type' AND column_type LIKE '%college%') THEN
    SELECT 'BLOCKED' AS result, 'Preflight prerequisites are not READY; no changes made' AS reason;
    LEAVE main;
  END IF;

  IF @dean_temporary_password_hash IS NULL
     OR @dean_temporary_password_hash = 'REPLACE_WITH_OPERATOR_GENERATED_BCRYPT_HASH'
     OR @dean_temporary_password_hash NOT REGEXP '^[$]2[ayb][$][0-9]{2}[$].{53}$' THEN
    SELECT 'BLOCKED' AS result, 'Replace @dean_temporary_password_hash with an operator-generated bcrypt hash' AS reason;
    LEAVE main;
  END IF;

  IF EXISTS (SELECT 1 FROM employees WHERE employee_number='TEMP-DEAN-FMF-2026'
             AND COALESCE(email,'') <> 'dean.fmf.test@rowad.edu')
     OR EXISTS (SELECT 1 FROM employees WHERE email='dean.fmf.test@rowad.edu'
                AND employee_number <> 'TEMP-DEAN-FMF-2026')
     OR EXISTS (SELECT 1 FROM users WHERE email='dean.fmf.test@rowad.edu'
                AND (username <> 'dean.fmf.test' OR employee_id IS NULL
                     OR employee_id <> COALESCE((SELECT employee_id FROM employees
                         WHERE employee_number='TEMP-DEAN-FMF-2026' LIMIT 1), -1)))
     OR EXISTS (SELECT 1 FROM users WHERE username='dean.fmf.test'
                AND email <> 'dean.fmf.test@rowad.edu')
     OR EXISTS (SELECT 1 FROM users WHERE employee_id=(SELECT employee_id FROM employees
                    WHERE employee_number='TEMP-DEAN-FMF-2026' LIMIT 1)
                AND email<>'dean.fmf.test@rowad.edu') THEN
    SELECT 'BLOCKED' AS result, 'Conflicting employee number, email, username, or identity; no changes made' AS reason;
    LEAVE main;
  END IF;

  IF EXISTS (SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.user_id
             JOIN roles r ON r.role_id=ur.role_id
             WHERE u.email='dean.fmf.test@rowad.edu' AND r.role_code<>'dean')
     OR EXISTS (SELECT 1 FROM users u JOIN user_access_scopes s ON s.user_id=u.user_id
                WHERE u.email='dean.fmf.test@rowad.edu' AND s.is_active=1
                  AND NOT (s.scope_type='college' AND s.scope_id=10)) THEN
    SELECT 'BLOCKED' AS result, 'Existing test account has another role or active scope; review it manually' AS reason;
    LEAVE main;
  END IF;

  SELECT college_id, organizational_unit_id INTO v_college_id, v_org_unit_id FROM colleges WHERE college_code='FMF';
  SELECT role_id INTO v_role_id FROM roles WHERE role_code='dean' AND is_active=1;
  SELECT account_status_id INTO v_account_status_id FROM account_statuses WHERE status_code='active' AND is_active=1;
  SELECT employee_type_id INTO v_employee_type_id FROM employee_types WHERE type_code='administrative' AND is_active=1;
  SELECT employee_status_id INTO v_employee_status_id FROM employee_statuses WHERE status_code='active' AND is_active=1;

  START TRANSACTION;
  INSERT INTO employees (employee_number, first_name, last_name, email, employee_type_id,
                         employee_status_id, organizational_unit_id, created_at, updated_at)
  SELECT 'TEMP-DEAN-FMF-2026', 'عميد', 'تجريبي', 'dean.fmf.test@rowad.edu', v_employee_type_id,
         v_employee_status_id, v_org_unit_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
  WHERE NOT EXISTS (SELECT 1 FROM employees WHERE employee_number='TEMP-DEAN-FMF-2026');
  SELECT employee_id INTO v_employee_id FROM employees WHERE employee_number='TEMP-DEAN-FMF-2026';

  INSERT INTO users (username, email, password_hash, account_status_id, employee_id,
                     failed_login_attempts, created_at, updated_at)
  SELECT 'dean.fmf.test', 'dean.fmf.test@rowad.edu',
         @dean_temporary_password_hash,
         v_account_status_id, v_employee_id, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
  WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='dean.fmf.test@rowad.edu');
  SELECT user_id INTO v_user_id FROM users WHERE email='dean.fmf.test@rowad.edu';

  UPDATE users SET account_status_id=v_account_status_id, updated_at=CURRENT_TIMESTAMP
  WHERE user_id=v_user_id AND username='dean.fmf.test' AND employee_id=v_employee_id;
  INSERT INTO user_roles (user_id, role_id, assigned_by_user_id, assigned_at, is_active)
  VALUES (v_user_id, v_role_id, NULL, CURRENT_TIMESTAMP, 1)
  ON DUPLICATE KEY UPDATE is_active=1;
  INSERT INTO user_access_scopes (user_id, scope_type, scope_id, is_active, created_at, updated_at)
  VALUES (v_user_id, 'college', v_college_id, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
  ON DUPLICATE KEY UPDATE is_active=1, updated_at=CURRENT_TIMESTAMP;
  COMMIT;

  SELECT 'APPLIED' AS result, v_user_id AS user_id, v_employee_id AS employee_id,
         v_college_id AS college_id, 'college' AS scope_type;
END$$
CALL apply_fmf_test_dean()$$
DROP PROCEDURE apply_fmf_test_dean$$
DELIMITER ;
