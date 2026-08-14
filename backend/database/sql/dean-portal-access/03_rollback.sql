-- OPTIONAL MANUAL ROLLBACK: run only when you intentionally want to remove the test dean account.
-- It deletes nothing unless username, user email, employee number, and employee email all match.

START TRANSACTION;
SET @rollback_employee_id := (SELECT employee_id FROM employees
 WHERE employee_number='TEMP-DEAN-FMF-2026' AND email='dean.fmf.test@rowad.edu' LIMIT 1);
SET @rollback_user_id := (SELECT user_id FROM users
 WHERE username='dean.fmf.test' AND email='dean.fmf.test@rowad.edu'
   AND employee_id=@rollback_employee_id LIMIT 1);
SET @rollback_identity_matches := IF(@rollback_employee_id IS NOT NULL AND @rollback_user_id IS NOT NULL, 1, 0);

DELETE FROM user_access_scopes WHERE user_id=@rollback_user_id AND @rollback_identity_matches=1;
DELETE FROM user_roles WHERE user_id=@rollback_user_id AND @rollback_identity_matches=1;
DELETE FROM personal_access_tokens WHERE tokenable_type='App\\Models\\User'
 AND tokenable_id=@rollback_user_id AND @rollback_identity_matches=1;
DELETE FROM users WHERE user_id=@rollback_user_id AND username='dean.fmf.test'
 AND email='dean.fmf.test@rowad.edu' AND employee_id=@rollback_employee_id AND @rollback_identity_matches=1;
DELETE FROM employees WHERE employee_id=@rollback_employee_id AND employee_number='TEMP-DEAN-FMF-2026'
 AND email='dean.fmf.test@rowad.edu' AND @rollback_identity_matches=1;
SELECT IF(@rollback_identity_matches=1, 'ROLLED_BACK', 'SKIPPED_IDENTITY_MISMATCH') AS result;
COMMIT;
