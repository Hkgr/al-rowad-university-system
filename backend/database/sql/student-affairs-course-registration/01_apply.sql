-- Manual and idempotent. Does not grant the permission to registration_officer.
-- Super admin assigns and revokes the role "تسجيل مواد الطلاب" from the accounts screen.
USE `alrowad_uni_rust`;

INSERT INTO permissions (module_id, permission_code, permission_name, description, is_active, created_at, updated_at)
SELECT m.module_id, 'student_affairs.course_registration.manage', 'تسجيل مواد الطلاب',
       'إدخال تسجيل المواد من شؤون الطلاب بعد اعتماد المرشد على الورق', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM system_modules m
WHERE m.module_code = 'registration' AND m.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM permissions p WHERE p.permission_code = 'student_affairs.course_registration.manage'
  );

INSERT INTO roles (role_code, role_name, description, is_system_role, is_active, created_at, updated_at)
SELECT 'student_affairs_course_registrar', 'تسجيل مواد الطلاب',
       'يسنده مدير النظام لحساب شؤون الطلاب ويسحبه في أي وقت. لا يُمنح مع دور شؤون الطلاب تلقائيًا.',
       1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1 FROM roles r WHERE r.role_code = 'student_affairs_course_registrar'
);

INSERT INTO role_permissions (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, CURRENT_TIMESTAMP
FROM roles r
JOIN permissions p ON p.permission_code = 'student_affairs.course_registration.manage' AND p.is_active = 1
WHERE r.role_code = 'student_affairs_course_registrar' AND r.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions existing
      WHERE existing.role_id = r.role_id AND existing.permission_id = p.permission_id
  );

SELECT
    (SELECT COUNT(*) FROM permissions WHERE permission_code = 'student_affairs.course_registration.manage' AND is_active = 1) AS permission_ready,
    (SELECT COUNT(*) FROM roles WHERE role_code = 'student_affairs_course_registrar' AND is_active = 1) AS role_ready,
    (SELECT COUNT(*) FROM role_permissions rp
        JOIN roles r ON r.role_id = rp.role_id AND r.role_code = 'registration_officer'
        JOIN permissions p ON p.permission_id = rp.permission_id AND p.permission_code = 'student_affairs.course_registration.manage') AS granted_to_student_affairs_by_default;
