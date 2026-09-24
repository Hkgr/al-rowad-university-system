-- Permission rollback only. Dean accounts and faculty records are never deleted.
-- Refuse if a permission was bound to any other role or has no ownership tag.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @safe := IF((SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p
  JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.permission_id = p.permission_id
  JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = rp.role_id
  WHERE p.permission_code IN ('administrative_staff.view', 'administrative_staff.manage',
                              'administrative_deans.view', 'administrative_deans.manage')
    AND r.role_code <> 'vice_president_administrative') = 0
  AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p
    WHERE p.permission_code IN ('administrative_staff.view', 'administrative_staff.manage',
                                'administrative_deans.view', 'administrative_deans.manage')
      AND COALESCE(p.description, '') NOT LIKE '%[administrative-vp-personnel]%') = 0, 1, 0);
START TRANSACTION;
DELETE FROM `alrowad_uni_rust`.`role_permissions` WHERE @safe = 1
 AND permission_id IN (SELECT p.permission_id FROM `alrowad_uni_rust`.`permissions` p
   WHERE p.permission_code IN ('administrative_staff.view', 'administrative_staff.manage',
                               'administrative_deans.view', 'administrative_deans.manage')
     AND COALESCE(p.description, '') LIKE '%[administrative-vp-personnel]%')
 AND role_id = (SELECT role_id FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative');
DELETE FROM `alrowad_uni_rust`.`permissions` WHERE @safe = 1
 AND permission_code IN ('administrative_staff.view', 'administrative_staff.manage',
                         'administrative_deans.view', 'administrative_deans.manage')
 AND COALESCE(description, '') LIKE '%[administrative-vp-personnel]%'
 AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`role_permissions` rp
                 WHERE rp.permission_id = `alrowad_uni_rust`.`permissions`.`permission_id`);
COMMIT;
SELECT IF(@safe = 1, 'ROLLED_BACK', 'BLOCKED') AS rollback_status;
