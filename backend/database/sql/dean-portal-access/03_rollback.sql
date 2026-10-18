-- OPTIONAL MANUAL ROLLBACK: run only when you intentionally want to remove the test dean account.
-- The procedure reports BLOCKED (including referencing table/column names) rather than risking a partial delete.

DELIMITER $$
DROP PROCEDURE IF EXISTS rollback_fmf_test_dean$$
CREATE PROCEDURE rollback_fmf_test_dean()
main: BEGIN
  DECLARE v_employee_id INT DEFAULT NULL;
  DECLARE v_user_id INT DEFAULT NULL;
  DECLARE v_done TINYINT DEFAULT 0;
  DECLARE v_table_name VARCHAR(64);
  DECLARE v_column_name VARCHAR(64);
  DECLARE v_reference_kind VARCHAR(16);
  DECLARE reference_cursor CURSOR FOR
    SELECT table_name, column_name, 'user'
    FROM information_schema.key_column_usage
    WHERE table_schema=DATABASE() AND referenced_table_name='users' AND referenced_column_name='user_id'
      AND NOT ((table_name='user_roles' AND column_name='user_id')
            OR (table_name='user_access_scopes' AND column_name='user_id'))
    UNION ALL
    SELECT table_name, column_name, 'employee'
    FROM information_schema.key_column_usage
    WHERE table_schema=DATABASE() AND referenced_table_name='employees' AND referenced_column_name='employee_id'
      AND NOT (table_name='users' AND column_name='employee_id');
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done=1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  SELECT employee_id INTO v_employee_id FROM employees
  WHERE employee_number='TEMP-DEAN-FMF-2026' AND email='dean.fmf.test@rowad.edu' LIMIT 1;
  SET v_done=0;
  SELECT user_id INTO v_user_id FROM users
  WHERE username='dean.fmf.test' AND email='dean.fmf.test@rowad.edu'
    AND employee_id=v_employee_id LIMIT 1;
  SET v_done=0;

  IF v_employee_id IS NULL OR v_user_id IS NULL THEN
    SELECT 'BLOCKED' AS result, 'Test username, user email, employee number, and employee email do not all match' AS reason;
    LEAVE main;
  END IF;

  DROP TEMPORARY TABLE IF EXISTS dean_rollback_blockers;
  CREATE TEMPORARY TABLE dean_rollback_blockers (blocking_reference VARCHAR(255) NOT NULL, row_count BIGINT NOT NULL);

  OPEN reference_cursor;
  reference_loop: LOOP
    FETCH reference_cursor INTO v_table_name, v_column_name, v_reference_kind;
    IF v_done=1 THEN LEAVE reference_loop; END IF;
    SET @rollback_reference_id = IF(v_reference_kind='user', v_user_id, v_employee_id);
    SET @rollback_reference_sql = CONCAT(
      'INSERT INTO dean_rollback_blockers(blocking_reference,row_count) ',
      'SELECT ', QUOTE(CONCAT(v_table_name, '.', v_column_name)), ', COUNT(*) FROM `',
      REPLACE(v_table_name, '`', '``'), '` WHERE `', REPLACE(v_column_name, '`', '``'),
      '` = ? HAVING COUNT(*) > 0');
    PREPARE rollback_reference_statement FROM @rollback_reference_sql;
    EXECUTE rollback_reference_statement USING @rollback_reference_id;
    DEALLOCATE PREPARE rollback_reference_statement;
  END LOOP;
  CLOSE reference_cursor;

  INSERT INTO dean_rollback_blockers(blocking_reference,row_count)
  SELECT 'users.employee_id (another user)', COUNT(*) FROM users
  WHERE employee_id=v_employee_id AND user_id<>v_user_id HAVING COUNT(*)>0;

  IF EXISTS (SELECT 1 FROM dean_rollback_blockers) THEN
    SELECT 'BLOCKED' AS result, blocking_reference, row_count
    FROM dean_rollback_blockers ORDER BY blocking_reference;
    DROP TEMPORARY TABLE dean_rollback_blockers;
    LEAVE main;
  END IF;

  START TRANSACTION;
  DELETE FROM user_access_scopes WHERE user_id=v_user_id;
  DELETE FROM user_roles WHERE user_id=v_user_id;
  IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='personal_access_tokens') THEN
    SET @delete_dean_tokens_sql = 'DELETE FROM personal_access_tokens WHERE tokenable_type = ''App\\\\Models\\\\User'' AND tokenable_id = ?';
    SET @rollback_reference_id = v_user_id;
    PREPARE delete_dean_tokens_statement FROM @delete_dean_tokens_sql;
    EXECUTE delete_dean_tokens_statement USING @rollback_reference_id;
    DEALLOCATE PREPARE delete_dean_tokens_statement;
  END IF;
  DELETE FROM users WHERE user_id=v_user_id AND username='dean.fmf.test'
    AND email='dean.fmf.test@rowad.edu' AND employee_id=v_employee_id;
  DELETE FROM employees WHERE employee_id=v_employee_id AND employee_number='TEMP-DEAN-FMF-2026'
    AND email='dean.fmf.test@rowad.edu';

  IF EXISTS (SELECT 1 FROM users WHERE user_id=v_user_id)
     OR EXISTS (SELECT 1 FROM employees WHERE employee_id=v_employee_id) THEN
    ROLLBACK;
    DROP TEMPORARY TABLE dean_rollback_blockers;
    SELECT 'BLOCKED' AS result, 'Deletion verification failed; transaction was rolled back' AS reason;
  ELSE
    DROP TEMPORARY TABLE dean_rollback_blockers;
    COMMIT;
    SELECT 'ROLLED_BACK' AS result, v_user_id AS deleted_user_id, v_employee_id AS deleted_employee_id;
  END IF;
END$$
CALL rollback_fmf_test_dean()$$
DROP PROCEDURE rollback_fmf_test_dean$$
DELIMITER ;
