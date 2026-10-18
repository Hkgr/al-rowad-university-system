-- READ ONLY. Every detail check and OVERALL must return PASS.
-- Fully qualified objects. Do not use DATABASE().
-- SET user variables only.

SET @db_ready := IF(
    EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = 'alrowad_uni_rust'),
    1,
    0
);

SELECT 'vice_presidency_module_exactly_once_active' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`system_modules`
WHERE module_code = 'vice_presidency'
  AND is_active = 1;

SELECT 'scientific_role_exactly_once' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'vice_president_scientific'
  AND role_name = 'نائب رئيس الجامعة للشؤون العلمية'
  AND is_active = 1
  AND is_system_role = 1;

SELECT 'administrative_role_exactly_once' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'vice_president_administrative'
  AND role_name = 'نائب رئيس الجامعة للشؤون الإدارية'
  AND is_active = 1
  AND is_system_role = 1;

SELECT 'generic_vice_president_unchanged' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`roles`
WHERE role_code = 'vice_president';

SELECT 'role_codes_unique' AS check_name,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
       COUNT(*) AS duplicate_groups
FROM (
    SELECT role_code
    FROM `alrowad_uni_rust`.`roles`
    GROUP BY role_code
    HAVING COUNT(*) > 1
) duplicates;

SELECT 'scientific_permission_exactly_once' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`permissions`
WHERE permission_code = 'vice_presidency.scientific.access'
  AND is_active = 1;

SELECT 'administrative_permission_exactly_once' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`permissions`
WHERE permission_code = 'vice_presidency.administrative.access'
  AND is_active = 1;

SELECT 'scientific_permission_on_vice_presidency_module' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`permissions` p
JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
WHERE p.permission_code = 'vice_presidency.scientific.access'
  AND p.is_active = 1
  AND sm.module_code = 'vice_presidency'
  AND sm.is_active = 1;

SELECT 'administrative_permission_on_vice_presidency_module' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`permissions` p
JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
WHERE p.permission_code = 'vice_presidency.administrative.access'
  AND p.is_active = 1
  AND sm.module_code = 'vice_presidency'
  AND sm.is_active = 1;

SELECT 'scientific_role_has_own_access_only' AS check_name,
       IF(
           SUM(p.permission_code = 'vice_presidency.scientific.access') = 1
           AND SUM(p.permission_code = 'vice_presidency.administrative.access') = 0,
           'PASS',
           'FAIL'
       ) AS result,
       COUNT(*) AS mapped_phase_permissions
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'vice_president_scientific'
  AND p.permission_code IN (
      'vice_presidency.scientific.access',
      'vice_presidency.administrative.access'
  );

SELECT 'administrative_role_has_own_access_only' AS check_name,
       IF(
           SUM(p.permission_code = 'vice_presidency.administrative.access') = 1
           AND SUM(p.permission_code = 'vice_presidency.scientific.access') = 0,
           'PASS',
           'FAIL'
       ) AS result,
       COUNT(*) AS mapped_phase_permissions
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'vice_president_administrative'
  AND p.permission_code IN (
      'vice_presidency.scientific.access',
      'vice_presidency.administrative.access'
  );

SELECT 'generic_vp_does_not_have_new_access' AS check_name,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE r.role_code = 'vice_president'
  AND p.permission_code IN (
      'vice_presidency.scientific.access',
      'vice_presidency.administrative.access'
  );

SELECT 'no_other_role_received_new_access' AS check_name,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`roles` r
JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
WHERE p.permission_code IN (
      'vice_presidency.scientific.access',
      'vice_presidency.administrative.access'
  )
  AND r.role_code NOT IN (
      'vice_president_scientific',
      'vice_president_administrative'
  );

SELECT 'vp_org_units_still_unique' AS check_name,
       IF(
           (
               SELECT COUNT(*)
               FROM `alrowad_uni_rust`.`organizational_units` u
               WHERE u.is_active = 1
                 AND (
                     u.unit_code = 'VP_SCI'
                     OR u.unit_name IN (
                         'نائب رئيس الجامعة للشؤون العلمية',
                         'Vice President for Scientific Affairs'
                     )
                 )
           ) = 1
           AND (
               SELECT COUNT(*)
               FROM `alrowad_uni_rust`.`organizational_units` u
               WHERE u.is_active = 1
                 AND (
                     u.unit_code IN ('7', 'VP_ADMIN')
                     OR u.unit_name = 'نائب رئيس الجامعة للشؤون الإدارية'
                 )
           ) = 1,
           'PASS',
           'FAIL'
       ) AS result;

SELECT 'scientific_org_unit_unique' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`organizational_units` u
WHERE u.is_active = 1
  AND (
      u.unit_code = 'VP_SCI'
      OR u.unit_name IN (
          'نائب رئيس الجامعة للشؤون العلمية',
          'Vice President for Scientific Affairs'
      )
  );

SELECT 'administrative_org_unit_unique' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`organizational_units` u
WHERE u.is_active = 1
  AND (
      u.unit_code IN ('7', 'VP_ADMIN')
      OR u.unit_name = 'نائب رئيس الجامعة للشؤون الإدارية'
  );

SELECT 'no_users_assigned_new_roles' AS check_name,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`user_roles` ur
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id
WHERE r.role_code IN (
    'vice_president_scientific',
    'vice_president_administrative'
);

SELECT 'no_user_access_scopes_for_new_roles' AS check_name,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
       COUNT(*) AS actual
FROM `alrowad_uni_rust`.`user_access_scopes` s
JOIN `alrowad_uni_rust`.`user_roles` ur ON ur.user_id = s.user_id
JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id
WHERE r.role_code IN (
    'vice_president_scientific',
    'vice_president_administrative'
);

SELECT 'OVERALL' AS check_name,
       IF(
           @db_ready = 1
           AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code = 'vice_presidency' AND is_active = 1) = 1
           AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_scientific' AND role_name = 'نائب رئيس الجامعة للشؤون العلمية' AND is_active = 1 AND is_system_role = 1) = 1
           AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president_administrative' AND role_name = 'نائب رئيس الجامعة للشؤون الإدارية' AND is_active = 1 AND is_system_role = 1) = 1
           AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code = 'vice_president') = 1
           AND NOT EXISTS (SELECT 1 FROM `alrowad_uni_rust`.`roles` GROUP BY role_code HAVING COUNT(*) > 1)
           AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'vice_presidency.scientific.access' AND is_active = 1) = 1
           AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code = 'vice_presidency.administrative.access' AND is_active = 1) = 1
           AND (
               SELECT COUNT(*)
               FROM `alrowad_uni_rust`.`permissions` p
               JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
               WHERE p.permission_code = 'vice_presidency.scientific.access'
                 AND p.is_active = 1
                 AND sm.module_code = 'vice_presidency'
                 AND sm.is_active = 1
           ) = 1
           AND (
               SELECT COUNT(*)
               FROM `alrowad_uni_rust`.`permissions` p
               JOIN `alrowad_uni_rust`.`system_modules` sm ON sm.module_id = p.module_id
               WHERE p.permission_code = 'vice_presidency.administrative.access'
                 AND p.is_active = 1
                 AND sm.module_code = 'vice_presidency'
                 AND sm.is_active = 1
           ) = 1
           AND (
               SELECT SUM(p.permission_code = 'vice_presidency.scientific.access') = 1
                  AND SUM(p.permission_code = 'vice_presidency.administrative.access') = 0
               FROM `alrowad_uni_rust`.`roles` r
               JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
               JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
               WHERE r.role_code = 'vice_president_scientific'
                 AND p.permission_code IN (
                     'vice_presidency.scientific.access',
                     'vice_presidency.administrative.access'
                 )
           )
           AND (
               SELECT SUM(p.permission_code = 'vice_presidency.administrative.access') = 1
                  AND SUM(p.permission_code = 'vice_presidency.scientific.access') = 0
               FROM `alrowad_uni_rust`.`roles` r
               JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
               JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
               WHERE r.role_code = 'vice_president_administrative'
                 AND p.permission_code IN (
                     'vice_presidency.scientific.access',
                     'vice_presidency.administrative.access'
                 )
           )
           AND NOT EXISTS (
               SELECT 1
               FROM `alrowad_uni_rust`.`roles` r
               JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id = r.role_id
               JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id = rp.permission_id
               WHERE p.permission_code IN (
                   'vice_presidency.scientific.access',
                   'vice_presidency.administrative.access'
               )
                 AND r.role_code NOT IN (
                     'vice_president_scientific',
                     'vice_president_administrative'
                 )
           )
           AND (
               SELECT COUNT(*)
               FROM `alrowad_uni_rust`.`organizational_units` u
               WHERE u.is_active = 1
                 AND (
                     u.unit_code = 'VP_SCI'
                     OR u.unit_name IN (
                         'نائب رئيس الجامعة للشؤون العلمية',
                         'Vice President for Scientific Affairs'
                     )
                 )
           ) = 1
           AND (
               SELECT COUNT(*)
               FROM `alrowad_uni_rust`.`organizational_units` u
               WHERE u.is_active = 1
                 AND (
                     u.unit_code IN ('7', 'VP_ADMIN')
                     OR u.unit_name = 'نائب رئيس الجامعة للشؤون الإدارية'
                 )
           ) = 1
           AND NOT EXISTS (
               SELECT 1
               FROM `alrowad_uni_rust`.`user_roles` ur
               JOIN `alrowad_uni_rust`.`roles` r ON r.role_id = ur.role_id
               WHERE r.role_code IN (
                   'vice_president_scientific',
                   'vice_president_administrative'
               )
           ),
           'PASS',
           'FAIL'
       ) AS result;
