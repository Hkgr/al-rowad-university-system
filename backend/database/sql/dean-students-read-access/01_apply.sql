-- Manual and idempotent. Run only after 00_preflight.sql returns READY.
USE `alrowad_uni_rust`;

INSERT INTO role_permissions (role_id, permission_id, granted_at)
SELECT
    r.role_id,
    p.permission_id,
    CURRENT_TIMESTAMP
FROM roles r
CROSS JOIN permissions p
WHERE r.role_code = 'dean'
  AND r.is_active = 1
  AND p.permission_code IN ('students.view', 'academic_structure.view')
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions existing
      WHERE existing.role_id = r.role_id
        AND existing.permission_id = p.permission_id
  );

SELECT
    ROW_COUNT() AS inserted_rows,
    'Run 02_verify.sql now. Expected inserted_rows: 0 on re-run, otherwise 1 or 2.' AS next_step;
