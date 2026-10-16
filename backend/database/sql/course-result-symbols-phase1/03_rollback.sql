-- Refuse all automatic rollback: deleting lifecycle history or inventing replacement zeros is unsafe.
-- Restore a tested pre-upgrade backup through an independently authorized recovery procedure instead.
DELIMITER $$
CREATE OR REPLACE PROCEDURE crs_phase1_rollback_refusal()
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='course_result_symbols_rollback_refused_preserve_history_and_null_marks'; END$$
CALL crs_phase1_rollback_refusal()$$
DROP PROCEDURE crs_phase1_rollback_refusal$$
DELIMITER ;
