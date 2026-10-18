-- P0-1 structural rollback only. It cannot reverse merged FK references or restore
-- the former permission matrix; restore those data from the mandatory backup.
DROP TABLE IF EXISTS user_access_scopes;
DELIMITER //
DROP PROCEDURE IF EXISTS p01_drop_identity_indexes//
CREATE PROCEDURE p01_drop_identity_indexes()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='users' AND index_name='uq_users_student_identity') THEN ALTER TABLE users DROP INDEX uq_users_student_identity; END IF;
 IF EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='users' AND index_name='uq_users_employee_identity') THEN ALTER TABLE users DROP INDEX uq_users_employee_identity; END IF;
END//
CALL p01_drop_identity_indexes()//
DROP PROCEDURE p01_drop_identity_indexes//
DELIMITER ;
