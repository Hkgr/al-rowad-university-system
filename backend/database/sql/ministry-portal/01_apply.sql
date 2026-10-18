-- Ministry of Education portal — APPLY. Manual, idempotent, fail-closed.
-- Writes run only when @apply_ready = 1; every condition is recomputed here.
-- Fully qualified objects; no DATABASE(), procedures, DELIMITER or SIGNAL. Ids resolved by code.
-- Rows created here carry the description tag [ministry-portal].
--
-- Creates (only when ABSENT):
--   system_modules   ministry_portal
--   roles            ministry_observer (is_system_role = 1)  — read-only follow-up role
--   permissions      8 × ministry_portal.*.view / .access (module ministry_portal)
--   role_permissions ministry_observer → the 8 permissions (and nothing else)
-- Does NOT: create users or passwords, assign the role (user_roles), add access scopes,
--           map anything to super_admin or any other role, or add indexes/columns.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @apply_ready := 0;
SET @apply_complete := 0;
SET @tag := '[ministry-portal]';

SET @db_ready := IF(EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'), 1, 0);

SET @missing_required_columns := IF(@db_ready = 1, (
    SELECT COUNT(*) FROM (
        SELECT 'roles' AS t, 'role_code' AS c UNION ALL SELECT 'roles', 'role_name' UNION ALL SELECT 'roles', 'description'
        UNION ALL SELECT 'roles', 'is_system_role' UNION ALL SELECT 'roles', 'is_active'
        UNION ALL SELECT 'permissions', 'module_id' UNION ALL SELECT 'permissions', 'permission_code' UNION ALL SELECT 'permissions', 'permission_name'
        UNION ALL SELECT 'permissions', 'description' UNION ALL SELECT 'permissions', 'is_active'
        UNION ALL SELECT 'role_permissions', 'role_id' UNION ALL SELECT 'role_permissions', 'permission_id' UNION ALL SELECT 'role_permissions', 'granted_at'
        UNION ALL SELECT 'system_modules', 'module_code' UNION ALL SELECT 'system_modules', 'module_name' UNION ALL SELECT 'system_modules', 'description'
        UNION ALL SELECT 'system_modules', 'is_active'
    ) req
    LEFT JOIN information_schema.columns ex ON ex.table_schema = 'alrowad_uni_rust' AND ex.table_name = req.t AND ex.column_name = req.c
    WHERE ex.column_name IS NULL
), 1);
SET @structure_ok := IF(@db_ready = 1 AND @missing_required_columns = 0, 1, 0);

SET @unique_keys_ok := IF(@structure_ok = 1, (
    SELECT IF(
        EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'roles' AND column_name = 'role_code' AND non_unique = 0)
        AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'permissions' AND column_name = 'permission_code' AND non_unique = 0)
        AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'system_modules' AND column_name = 'module_code' AND non_unique = 0)
        AND EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = 'alrowad_uni_rust' AND table_name = 'role_permissions' AND index_name = 'uq_role_permission' AND non_unique = 0),
    1, 0)
), 0);

SET @module_state := IF(@structure_ok = 1, (
    SELECT CASE WHEN COUNT(*) = 0 THEN 'ABSENT'
                WHEN COUNT(*) = 1 AND SUM(is_active = 1) = 1 AND SUM(COALESCE(description, '') LIKE '%[ministry-portal]%') = 1 THEN 'COMPATIBLE'
                ELSE 'CONFLICT' END
    FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'ministry_portal'
), 'UNKNOWN');

SET @role_state := IF(@structure_ok = 1, (
    SELECT CASE WHEN COUNT(*) = 0 THEN 'ABSENT'
                WHEN COUNT(*) = 1 AND SUM(is_active = 1 AND is_system_role = 1) = 1 AND SUM(COALESCE(description, '') LIKE '%[ministry-portal]%') = 1 THEN 'COMPATIBLE'
                ELSE 'CONFLICT' END
    FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'ministry_observer'
), 'UNKNOWN');

-- Existing permission rows must be ours (tagged, active, in module ministry_portal).
SET @perm_conflicts := IF(@structure_ok = 1, (
    SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p
    LEFT JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
    WHERE p.permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view')
      AND NOT (p.is_active = 1 AND sm.module_code = 'ministry_portal' AND COALESCE(p.description, '') LIKE '%[ministry-portal]%')
), 99);
SET @perms_absent_at_start := IF(@structure_ok = 1, (
    SELECT COUNT(*) = 0 FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view')
), 0);

-- The ministry role must stay read-only and the portal permissions must stay on that role only.
SET @foreign_mappings := IF(@structure_ok = 1, (
    SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
    JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
    JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
    WHERE (p.permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view') AND r.role_code <> 'ministry_observer')
       OR (r.role_code = 'ministry_observer' AND p.permission_code NOT IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view'))
), 99);

SET @apply_ready := IF(
    @structure_ok = 1 AND @unique_keys_ok = 1 AND @module_state IN ('ABSENT', 'COMPATIBLE')
    AND @role_state IN ('ABSENT', 'COMPATIBLE') AND @perm_conflicts = 0 AND @foreign_mappings = 0,
    1, 0);

START TRANSACTION;

INSERT INTO `alrowad_uni_rust`.`system_modules` (module_code, module_name, description, is_active, created_at, updated_at)
SELECT 'ministry_portal', 'Ministry of Education portal', CONCAT('بوابة وزارة التربية والتعليم: اطلاع للقراءة فقط. ', @tag), 1, NOW(), NOW()
FROM DUAL
WHERE @apply_ready = 1 AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'ministry_portal');

INSERT INTO `alrowad_uni_rust`.`roles` (role_code, role_name, description, is_system_role, is_active, created_at, updated_at)
SELECT 'ministry_observer', 'متابعة وزارة التربية والتعليم',
       CONCAT('Read-only follow-up of the university by the Ministry of Education. Holds ministry_portal.* only, inherits no other role. ', @tag),
       1, 1, NOW(), NOW()
FROM DUAL
WHERE @apply_ready = 1 AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'ministry_observer');

INSERT INTO `alrowad_uni_rust`.`permissions` (module_id, permission_code, permission_name, description, is_active, created_at, updated_at)
SELECT sm.module_id, defs.code, defs.name, CONCAT(defs.description, ' ', @tag), 1, NOW(), NOW()
FROM (
    SELECT 'ministry_portal.access' AS code, 'Ministry portal access' AS name, 'يفتح بوابة الوزارة وخيارات المرشحات. هوية فقط.' AS description
    UNION ALL SELECT 'ministry_portal.dashboard.view', 'Ministry dashboard view', 'مؤشرات الجامعة الشاملة (قراءة فقط).'
    UNION ALL SELECT 'ministry_portal.deans.view', 'Ministry deans view', 'قائمة العمداء وتفاصيل تكليفاتهم (قراءة فقط).'
    UNION ALL SELECT 'ministry_portal.students.view', 'Ministry students view', 'قائمة الطلاب وتفاصيلهم الأكاديمية المعتمدة (قراءة فقط).'
    UNION ALL SELECT 'ministry_portal.colleges.view', 'Ministry colleges view', 'الكليات والأقسام والبرامج وأعدادها (قراءة فقط).'
    UNION ALL SELECT 'ministry_portal.courses.view', 'Ministry courses view', 'المقررات وارتباطها بالخطط والطروحات (قراءة فقط).'
    UNION ALL SELECT 'ministry_portal.faculty.view', 'Ministry faculty view', 'أعضاء الهيئة التدريسية وانتماؤهم وتدريسهم (قراءة فقط).'
    UNION ALL SELECT 'ministry_portal.leadership.view', 'Ministry leadership view', 'رئاسة الجامعة ونوابها والوحدات التابعة (قراءة فقط).'
) defs
JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_code = 'ministry_portal' AND sm.is_active = 1
WHERE @apply_ready = 1 AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` p WHERE p.permission_code = defs.code);

INSERT INTO `alrowad_uni_rust`.`role_permissions` (role_id, permission_id, granted_at)
SELECT r.role_id, p.permission_id, NOW()
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view')
WHERE @apply_ready = 1 AND r.role_code = 'ministry_observer'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id);

SET @apply_complete := IF(@apply_ready = 1, (
    SELECT IF(
        (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'ministry_portal' AND is_active = 1) = 1
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'ministry_observer' AND is_active = 1) = 1
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view') AND is_active = 1) = 8
        AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp
             JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id AND r.role_code = 'ministry_observer'
             JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
             WHERE p.permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view')) = 8,
    1, 0)
), 0);

-- Incomplete run: remove only rows this run could have created (tagged and absent at start).
DELETE FROM `alrowad_uni_rust`.`role_permissions`
WHERE @apply_ready = 1 AND @apply_complete = 0 AND @perms_absent_at_start = 1
  AND permission_id IN (SELECT p.permission_id FROM `alrowad_uni_rust`.`permissions` p
                        WHERE p.permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view') AND COALESCE(p.description, '') LIKE '%[ministry-portal]%');
DELETE FROM `alrowad_uni_rust`.`permissions`
WHERE @apply_ready = 1 AND @apply_complete = 0 AND @perms_absent_at_start = 1
  AND permission_code IN ('ministry_portal.access','ministry_portal.dashboard.view','ministry_portal.deans.view','ministry_portal.students.view','ministry_portal.colleges.view','ministry_portal.courses.view','ministry_portal.faculty.view','ministry_portal.leadership.view') AND COALESCE(description, '') LIKE '%[ministry-portal]%';
DELETE FROM `alrowad_uni_rust`.`roles`
WHERE @apply_ready = 1 AND @apply_complete = 0 AND @role_state = 'ABSENT'
  AND role_code = 'ministry_observer' AND COALESCE(description, '') LIKE '%[ministry-portal]%'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`user_roles` ur WHERE ur.role_id = `alrowad_uni_rust`.`roles`.`role_id`);
DELETE FROM `alrowad_uni_rust`.`system_modules`
WHERE @apply_ready = 1 AND @apply_complete = 0 AND @module_state = 'ABSENT'
  AND module_code = 'ministry_portal' AND COALESCE(description, '') LIKE '%[ministry-portal]%'
  AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`permissions` p WHERE p.module_id = `alrowad_uni_rust`.`system_modules`.`module_id`);

COMMIT;

SELECT IF(@apply_ready = 0, 'BLOCKED', IF(@apply_complete = 1, 'APPLIED', 'BLOCKED_INCOMPLETE')) AS apply_status,
       @apply_ready AS apply_ready,
       @missing_required_columns AS missing_required_columns,
       @unique_keys_ok AS unique_keys_ok,
       @module_state AS module_state_at_start,
       @role_state AS role_state_at_start,
       @perm_conflicts AS permission_conflicts,
       @foreign_mappings AS foreign_mappings;
