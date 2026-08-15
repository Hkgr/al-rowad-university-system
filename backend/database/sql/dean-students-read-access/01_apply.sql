-- Manual and idempotent. Run only after 00_preflight.sql returns OVERALL = READY.
-- Grants only students.view and academic_structure.view to the active dean role.
-- This script never inserts, updates, or deletes user_access_scopes.

DELIMITER $$
DROP PROCEDURE IF EXISTS apply_dean_student_read_access$$
CREATE PROCEDURE apply_dean_student_read_access()
main: BEGIN
  DECLARE v_dean_role_id INT DEFAULT NULL;
  DECLARE v_fmf_college_id INT DEFAULT NULL;
  DECLARE v_test_dean_count INT DEFAULT 0;
  DECLARE v_test_dean_user_id INT DEFAULT NULL;
  DECLARE v_students_view_existed TINYINT DEFAULT 0;
  DECLARE v_academic_structure_view_existed TINYINT DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  IF (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND ((table_name = 'roles' AND column_name IN ('role_id', 'role_code', 'is_active'))
          OR (table_name = 'permissions' AND column_name IN ('permission_id', 'permission_code', 'is_active'))
          OR (table_name = 'role_permissions' AND column_name IN ('role_permission_id', 'role_id', 'permission_id', 'granted_at'))
          OR (table_name = 'user_access_scopes' AND column_name IN ('user_access_scope_id', 'user_id', 'scope_type', 'scope_id', 'is_active', 'created_at', 'updated_at'))
          OR (table_name = 'colleges' AND column_name IN ('college_id', 'college_code', 'college_name', 'is_active'))
          OR (table_name = 'users' AND column_name IN ('user_id', 'username', 'email', 'employee_id'))
          OR (table_name = 'employees' AND column_name IN ('employee_id', 'employee_number', 'email'))
          OR (table_name = 'user_roles' AND column_name IN ('user_id', 'role_id', 'is_active')))) <> 31 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: required table/column structure is incomplete';
  END IF;

  IF NOT EXISTS (
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'role_permissions'
        AND column_name = 'role_permission_id' AND extra LIKE '%auto_increment%'
    )
    OR NOT EXISTS (
      SELECT 1
      FROM (
        SELECT index_name
        FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'role_permissions' AND non_unique = 0
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'role_id,permission_id'
      ) role_permission_indexes
    )
    OR NOT EXISTS (
      SELECT 1
      FROM (
        SELECT index_name
        FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'user_access_scopes' AND non_unique = 0
        GROUP BY index_name
        HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'user_id,scope_type,scope_id'
      ) scope_indexes
    ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: required unique indexes or auto-increment are missing';
  END IF;

  IF (SELECT COUNT(*) FROM roles WHERE role_code = 'dean' AND is_active = 1) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: active dean role must exist exactly once';
  END IF;

  IF (SELECT COUNT(*) FROM permissions
      WHERE permission_code IN ('students.view', 'academic_structure.view') AND is_active = 1) <> 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: both required read permissions must exist and be active';
  END IF;

  IF (SELECT COUNT(*) FROM permissions
      WHERE permission_code = 'students.manage' AND is_active = 1) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: active students.manage permission must exist exactly once for safety verification';
  END IF;

  SELECT role_id INTO v_dean_role_id
  FROM roles
  WHERE role_code = 'dean' AND is_active = 1;

  IF EXISTS (
    SELECT 1
    FROM role_permissions rp
    JOIN permissions p ON p.permission_id = rp.permission_id
    WHERE rp.role_id = v_dean_role_id AND p.permission_code = 'students.manage'
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: dean already has students.manage; review and remove broad access before applying';
  END IF;

  IF (SELECT COUNT(*) FROM colleges
      WHERE college_code = 'FMF' AND college_name = 'كلية العلوم الإدارية والمالية' AND is_active = 1) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: active FMF college must exist exactly once with the expected name';
  END IF;

  SELECT college_id INTO v_fmf_college_id
  FROM colleges
  WHERE college_code = 'FMF' AND college_name = 'كلية العلوم الإدارية والمالية' AND is_active = 1;

  IF EXISTS (
    SELECT 1
    FROM (
      SELECT ur.user_id
      FROM user_roles ur
      JOIN roles r ON r.role_id = ur.role_id
      LEFT JOIN user_access_scopes s ON s.user_id = ur.user_id AND s.is_active = 1
      LEFT JOIN colleges c ON s.scope_type = 'college' AND c.college_id = s.scope_id AND c.is_active = 1
      WHERE ur.is_active = 1 AND r.role_code = 'dean' AND r.is_active = 1
      GROUP BY ur.user_id
      HAVING COUNT(s.user_access_scope_id) <> 1
         OR SUM(s.scope_type = 'college' AND c.college_id IS NOT NULL) <> 1
    ) unsafe_deans
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'BLOCKED: every active dean user must have exactly one valid active college scope';
  END IF;

  SELECT COUNT(DISTINCT u.user_id), MAX(u.user_id)
  INTO v_test_dean_count, v_test_dean_user_id
  FROM users u
  LEFT JOIN employees e ON e.employee_id = u.employee_id
  WHERE u.username = 'dean.fmf.test'
     OR u.email = 'dean.fmf.test@rowad.edu'
     OR e.employee_number = 'TEMP-DEAN-FMF-2026';

  IF v_test_dean_count > 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: FMF test dean identity is ambiguous';
  END IF;

  IF v_test_dean_count = 1 AND (
      (SELECT COUNT(*)
       FROM user_roles ur
       JOIN roles r ON r.role_id = ur.role_id
       WHERE ur.user_id = v_test_dean_user_id AND ur.is_active = 1
         AND r.role_code = 'dean' AND r.is_active = 1) <> 1
      OR
      (SELECT COUNT(*)
       FROM user_roles
       WHERE user_id = v_test_dean_user_id AND is_active = 1) <> 1
      OR
      (SELECT COUNT(*)
       FROM user_access_scopes
       WHERE user_id = v_test_dean_user_id AND is_active = 1) <> 1
      OR
      (SELECT COUNT(*)
       FROM user_access_scopes
       WHERE user_id = v_test_dean_user_id AND is_active = 1
         AND scope_type = 'college' AND scope_id = v_fmf_college_id) <> 1
    ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: FMF test dean must have only the active dean role and active FMF college scope';
  END IF;

  SELECT EXISTS (
    SELECT 1
    FROM role_permissions rp
    JOIN permissions p ON p.permission_id = rp.permission_id
    WHERE rp.role_id = v_dean_role_id AND p.permission_code = 'students.view'
  ) INTO v_students_view_existed;
  SELECT EXISTS (
    SELECT 1
    FROM role_permissions rp
    JOIN permissions p ON p.permission_id = rp.permission_id
    WHERE rp.role_id = v_dean_role_id AND p.permission_code = 'academic_structure.view'
  ) INTO v_academic_structure_view_existed;

  START TRANSACTION;

  INSERT INTO role_permissions (role_id, permission_id, granted_at)
  SELECT v_dean_role_id, p.permission_id, CURRENT_TIMESTAMP
  FROM permissions p
  WHERE p.permission_code IN ('students.view', 'academic_structure.view')
    AND p.is_active = 1
    AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = v_dean_role_id AND rp.permission_id = p.permission_id
    );

  IF (SELECT COUNT(*)
      FROM role_permissions rp
      JOIN permissions p ON p.permission_id = rp.permission_id
      WHERE rp.role_id = v_dean_role_id
        AND p.permission_code IN ('students.view', 'academic_structure.view')) <> 2
    OR EXISTS (
      SELECT 1
      FROM role_permissions rp
      JOIN permissions p ON p.permission_id = rp.permission_id
      WHERE rp.role_id = v_dean_role_id AND p.permission_code = 'students.manage'
    ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BLOCKED: post-insert permission safety verification failed';
  END IF;

  COMMIT;

  SELECT 'APPLIED' AS result, r.role_code, p.permission_code,
         CASE
           WHEN p.permission_code = 'students.view' AND v_students_view_existed = 0
             THEN 'INSERTED_BY_THIS_RUN'
           WHEN p.permission_code = 'academic_structure.view' AND v_academic_structure_view_existed = 0
             THEN 'INSERTED_BY_THIS_RUN'
           ELSE 'PREEXISTING'
         END AS provenance,
         rp.role_permission_id, rp.granted_at
  FROM role_permissions rp
  JOIN roles r ON r.role_id = rp.role_id
  JOIN permissions p ON p.permission_id = rp.permission_id
  WHERE r.role_id = v_dean_role_id
    AND p.permission_code IN ('students.view', 'academic_structure.view')
  ORDER BY p.permission_code;
END$$
CALL apply_dean_student_read_access()$$
DROP PROCEDURE apply_dean_student_read_access$$
DELIMITER ;
