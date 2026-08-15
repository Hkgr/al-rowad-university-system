-- OPTIONAL, OPERATOR-REVIEWED ROLLBACK.
-- role_permissions has no provenance column. Use only values from an 01_apply.sql
-- output row marked INSERTED_BY_THIS_RUN; never use a PREEXISTING row.
-- The safe defaults below select no rows and make no changes.

SET @students_view_role_permission_id := NULL;
SET @students_view_granted_at := NULL;
SET @academic_structure_view_role_permission_id := NULL;
SET @academic_structure_view_granted_at := NULL;
SET @operator_confirmed_inserted_rows := 'NO';

-- Example operator inputs (replace from the captured apply output):
-- SET @students_view_role_permission_id := 123;
-- SET @students_view_granted_at := '2026-08-15 09:00:00';
-- SET @operator_confirmed_inserted_rows := 'YES_THE_SELECTED_IDS_AND_TIMESTAMPS_WERE_INSERTED_BY_THIS_RUN';

SELECT 'rollback_candidates' AS report_section, rp.role_permission_id,
       r.role_code, p.permission_code, rp.granted_at,
       IF(
         (p.permission_code = 'students.view'
           AND rp.role_permission_id = @students_view_role_permission_id
           AND rp.granted_at = @students_view_granted_at)
         OR
         (p.permission_code = 'academic_structure.view'
           AND rp.role_permission_id = @academic_structure_view_role_permission_id
           AND rp.granted_at = @academic_structure_view_granted_at),
         'SELECTED',
         'NOT_SELECTED'
       ) AS rollback_selection
FROM role_permissions rp
JOIN roles r ON r.role_id = rp.role_id
JOIN permissions p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'dean'
  AND p.permission_code IN ('students.view', 'academic_structure.view')
ORDER BY p.permission_code;

DELIMITER $$
DROP PROCEDURE IF EXISTS rollback_dean_student_read_access$$
CREATE PROCEDURE rollback_dean_student_read_access()
main: BEGIN
  DECLARE v_dean_role_id INT DEFAULT NULL;
  DECLARE v_expected_rows INT DEFAULT 0;
  DECLARE v_rows_removed INT DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  IF (@students_view_role_permission_id IS NULL) <> (@students_view_granted_at IS NULL)
     OR (@academic_structure_view_role_permission_id IS NULL) <> (@academic_structure_view_granted_at IS NULL) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'BLOCKED: each selected row requires both role_permission_id and granted_at';
  END IF;

  SET v_expected_rows =
      IF(@students_view_role_permission_id IS NULL, 0, 1)
    + IF(@academic_structure_view_role_permission_id IS NULL, 0, 1);

  IF v_expected_rows = 0 THEN
    SELECT 'BLOCKED' AS result,
           'No apply-output rows selected; safe default made no changes' AS reason;
    LEAVE main;
  END IF;

  IF COALESCE(@operator_confirmed_inserted_rows, 'NO')
       <> 'YES_THE_SELECTED_IDS_AND_TIMESTAMPS_WERE_INSERTED_BY_THIS_RUN' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'BLOCKED: operator must confirm selected apply-output rows were INSERTED_BY_THIS_RUN';
  END IF;

  IF (SELECT COUNT(*) FROM roles WHERE role_code = 'dean') <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: dean role is missing or ambiguous';
  END IF;

  SELECT role_id INTO v_dean_role_id FROM roles WHERE role_code = 'dean';

  IF (
    SELECT COUNT(*)
    FROM role_permissions rp
    JOIN permissions p ON p.permission_id = rp.permission_id
    WHERE rp.role_id = v_dean_role_id
      AND (
        (p.permission_code = 'students.view'
          AND rp.role_permission_id = @students_view_role_permission_id
          AND rp.granted_at = @students_view_granted_at)
        OR
        (p.permission_code = 'academic_structure.view'
          AND rp.role_permission_id = @academic_structure_view_role_permission_id
          AND rp.granted_at = @academic_structure_view_granted_at)
      )
  ) <> v_expected_rows THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'BLOCKED: selected IDs, timestamps, role, or permission codes no longer match';
  END IF;

  START TRANSACTION;

  DELETE rp
  FROM role_permissions rp
  JOIN permissions p ON p.permission_id = rp.permission_id
  WHERE rp.role_id = v_dean_role_id
    AND (
      (p.permission_code = 'students.view'
        AND rp.role_permission_id = @students_view_role_permission_id
        AND rp.granted_at = @students_view_granted_at)
      OR
      (p.permission_code = 'academic_structure.view'
        AND rp.role_permission_id = @academic_structure_view_role_permission_id
        AND rp.granted_at = @academic_structure_view_granted_at)
    );

  SET v_rows_removed = ROW_COUNT();
  IF v_rows_removed <> v_expected_rows THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: delete count mismatch; transaction rolled back';
  END IF;

  COMMIT;

  SELECT 'ROLLED_BACK' AS result, v_rows_removed AS rows_removed,
         'Dean role, dean user, and all user_access_scopes were left unchanged' AS scope;
END$$
CALL rollback_dean_student_read_access()$$
DROP PROCEDURE rollback_dean_student_read_access$$
DELIMITER ;
