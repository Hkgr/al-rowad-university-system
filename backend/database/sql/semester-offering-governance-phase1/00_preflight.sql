-- READ ONLY. Run in phpMyAdmin and continue only when the final row is OVERALL | READY.
USE `alrowad_uni_rust`;

SET @sog_core_tables := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema='alrowad_uni_rust' AND table_name IN (
    'course_offerings','program_courses','courses','semesters','users',
    'teaching_assignment_requests','teaching_assignment_reviews','course_offering_instructors',
    'academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions',
    'academic_programs','academic_years','departments','colleges','faculty_members',
    'system_modules','permissions','roles','role_permissions','user_roles',
    'user_access_scopes','organizational_units'
  )
);
SET @sog_core_columns := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema='alrowad_uni_rust' AND (
    (table_name='course_offerings' AND column_name IN ('course_offering_id','course_id','academic_program_id','academic_year_id','semester_id','status')) OR
    (table_name='program_courses' AND column_name IN ('program_course_id','academic_program_id','course_id','course_type','is_active')) OR
    (table_name='courses' AND column_name IN ('course_id','theoretical_hours','practical_hours')) OR
    (table_name='semesters' AND column_name IN ('semester_id','semester_code')) OR
    (table_name='course_offering_instructors' AND column_name IN ('course_offering_instructor_id','course_offering_id','faculty_member_id','instructor_role','is_active')) OR
    (table_name='teaching_assignment_requests' AND column_name IN ('teaching_assignment_request_id','course_offering_id','faculty_member_id','instructor_role','current_slot','status','action_type','target_course_offering_instructor_id')) OR
    (table_name='teaching_assignment_reviews' AND column_name IN ('teaching_assignment_review_id','teaching_assignment_request_id','review_authority','status','reviewed_by_user_id','reviewed_at','reason','updated_at')) OR
    (table_name='academic_calendar_event_types' AND column_name IN ('academic_calendar_event_type_id','event_type_code','is_active')) OR
    (table_name='academic_calendar_events' AND column_name IN ('academic_calendar_event_id','academic_year_id','semester_id','academic_calendar_event_type_id','cancelled_at')) OR
    (table_name='academic_calendar_event_versions' AND column_name IN ('academic_calendar_event_id','publication_status','is_enforcement','starts_at','ends_at','superseded_at')) OR
    (table_name='users' AND column_name='user_id') OR
    (table_name='academic_programs' AND column_name IN ('academic_program_id','department_id','is_active')) OR
    (table_name='academic_years' AND column_name='academic_year_id') OR
    (table_name='departments' AND column_name IN ('department_id','college_id','is_active')) OR
    (table_name='colleges' AND column_name IN ('college_id','is_active')) OR
    (table_name='faculty_members' AND column_name='faculty_member_id') OR
    (table_name='system_modules' AND column_name IN ('module_id','module_code','is_active')) OR
    (table_name='permissions' AND column_name IN ('permission_id','module_id','permission_code','permission_name','description','is_active','created_at','updated_at')) OR
    (table_name='roles' AND column_name IN ('role_id','role_code','is_active')) OR
    (table_name='role_permissions' AND column_name IN ('role_id','permission_id','granted_at')) OR
    (table_name='user_roles' AND column_name IN ('user_id','role_id','is_active')) OR
    (table_name='user_access_scopes' AND column_name IN ('user_id','scope_type','scope_id','is_active')) OR
    (table_name='organizational_units' AND column_name IN ('organizational_unit_id','unit_code','is_active'))
  )
);
SET @sog_signed_keys := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema='alrowad_uni_rust' AND data_type='int' AND column_type NOT LIKE '%unsigned%'
    AND ((table_name='course_offerings' AND column_name='course_offering_id')
      OR (table_name='program_courses' AND column_name='program_course_id')
      OR (table_name='users' AND column_name='user_id'))
);
SET @sog_semesters := (
  SELECT COUNT(DISTINCT semester_code) FROM `alrowad_uni_rust`.`semesters`
  WHERE semester_code IN ('first','second','summer')
);
SET @sog_effective_slot_indexes := (
  SELECT COUNT(*) FROM (
    SELECT table_name,index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_in_order
    FROM information_schema.statistics
    WHERE table_schema='alrowad_uni_rust' AND non_unique=0
      AND index_name IN ('uq_tar_current_slot','uq_course_offering_role')
    GROUP BY table_name,index_name
  ) x WHERE
    (table_name='teaching_assignment_requests' AND index_name='uq_tar_current_slot' AND columns_in_order='course_offering_id,instructor_role,current_slot') OR
    (table_name='course_offering_instructors' AND index_name='uq_course_offering_role' AND columns_in_order='course_offering_id,instructor_role')
);
SET @sog_pres_scope := (
  SELECT COUNT(*) FROM `alrowad_uni_rust`.`organizational_units`
  WHERE unit_code='PRES' AND is_active=1
);
SET @sog_calendar_registration_type := (
  SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_types`
  WHERE event_type_code='course_registration' AND is_active=1
);
SET @sog_target_tables := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema='alrowad_uni_rust' AND table_name IN (
    'semester_offering_requests','semester_offering_reviews','semester_offering_events'
  )
);
SET @sog_target_columns := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema='alrowad_uni_rust' AND (
    (table_name='semester_offering_requests' AND column_name IN (
      'semester_offering_request_id','course_offering_id','program_course_id','course_type','is_selected',
      'minimum_enrollment','status','submission_version','created_by_user_id','submitted_by_user_id',
      'submitted_at','approved_at','materialized_at','created_at','updated_at')) OR
    (table_name='semester_offering_reviews' AND column_name IN (
      'semester_offering_review_id','semester_offering_request_id','submission_version','status',
      'reviewed_by_user_id','reviewed_at','reason','created_at','updated_at')) OR
    (table_name='semester_offering_events' AND column_name IN (
      'semester_offering_event_id','semester_offering_request_id','submission_version','event_type',
      'actor_user_id','note','occurred_at'))
  )
);
SET @sog_target_shape := (
  SELECT COUNT(*)
  FROM information_schema.columns c
  JOIN (
    SELECT 'semester_offering_requests' table_name,'semester_offering_request_id' column_name,'int' data_type,'NO' is_nullable
    UNION ALL SELECT 'semester_offering_requests','course_offering_id','int','NO'
    UNION ALL SELECT 'semester_offering_requests','program_course_id','int','NO'
    UNION ALL SELECT 'semester_offering_requests','course_type','varchar','NO'
    UNION ALL SELECT 'semester_offering_requests','is_selected','tinyint','NO'
    UNION ALL SELECT 'semester_offering_requests','minimum_enrollment','int','YES'
    UNION ALL SELECT 'semester_offering_requests','status','varchar','NO'
    UNION ALL SELECT 'semester_offering_requests','submission_version','int','NO'
    UNION ALL SELECT 'semester_offering_requests','created_by_user_id','int','NO'
    UNION ALL SELECT 'semester_offering_requests','submitted_by_user_id','int','YES'
    UNION ALL SELECT 'semester_offering_requests','submitted_at','datetime','YES'
    UNION ALL SELECT 'semester_offering_requests','approved_at','datetime','YES'
    UNION ALL SELECT 'semester_offering_requests','materialized_at','datetime','YES'
    UNION ALL SELECT 'semester_offering_requests','created_at','timestamp','NO'
    UNION ALL SELECT 'semester_offering_requests','updated_at','timestamp','NO'
    UNION ALL SELECT 'semester_offering_reviews','semester_offering_review_id','int','NO'
    UNION ALL SELECT 'semester_offering_reviews','semester_offering_request_id','int','NO'
    UNION ALL SELECT 'semester_offering_reviews','submission_version','int','NO'
    UNION ALL SELECT 'semester_offering_reviews','status','varchar','NO'
    UNION ALL SELECT 'semester_offering_reviews','reviewed_by_user_id','int','YES'
    UNION ALL SELECT 'semester_offering_reviews','reviewed_at','datetime','YES'
    UNION ALL SELECT 'semester_offering_reviews','reason','varchar','YES'
    UNION ALL SELECT 'semester_offering_reviews','created_at','timestamp','NO'
    UNION ALL SELECT 'semester_offering_reviews','updated_at','timestamp','NO'
    UNION ALL SELECT 'semester_offering_events','semester_offering_event_id','int','NO'
    UNION ALL SELECT 'semester_offering_events','semester_offering_request_id','int','NO'
    UNION ALL SELECT 'semester_offering_events','submission_version','int','NO'
    UNION ALL SELECT 'semester_offering_events','event_type','varchar','NO'
    UNION ALL SELECT 'semester_offering_events','actor_user_id','int','NO'
    UNION ALL SELECT 'semester_offering_events','note','varchar','YES'
    UNION ALL SELECT 'semester_offering_events','occurred_at','datetime','NO'
  ) expected ON expected.table_name=c.table_name AND expected.column_name=c.column_name
  WHERE c.table_schema='alrowad_uni_rust' AND c.data_type=expected.data_type
    AND c.is_nullable=expected.is_nullable
    AND (expected.data_type NOT IN ('int','tinyint') OR c.column_type NOT LIKE '%unsigned%')
    AND (c.table_name<>'semester_offering_requests' OR c.column_name<>'course_type' OR c.character_maximum_length>=16)
    AND (c.column_name<>'status' OR c.character_maximum_length>=16)
    AND (c.column_name<>'event_type' OR c.character_maximum_length>=32)
    AND (c.column_name NOT IN ('reason','note') OR c.character_maximum_length>=1000)
);
SET @sog_target_unique := (
  SELECT COUNT(*) FROM (
    SELECT table_name,index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_in_order
    FROM information_schema.statistics
    WHERE table_schema='alrowad_uni_rust' AND non_unique=0 AND index_name IN ('uq_sor_offering','uq_sorv_request_version')
    GROUP BY table_name,index_name
  ) x WHERE (table_name='semester_offering_requests' AND index_name='uq_sor_offering' AND columns_in_order='course_offering_id')
    OR (table_name='semester_offering_reviews' AND index_name='uq_sorv_request_version' AND columns_in_order='semester_offering_request_id,submission_version')
);
SET @sog_target_pks := (
  SELECT COUNT(*) FROM (
    SELECT table_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_in_order
    FROM information_schema.statistics
    WHERE table_schema='alrowad_uni_rust' AND index_name='PRIMARY'
      AND table_name IN ('semester_offering_requests','semester_offering_reviews','semester_offering_events')
    GROUP BY table_name
  ) x WHERE
    (table_name='semester_offering_requests' AND columns_in_order='semester_offering_request_id') OR
    (table_name='semester_offering_reviews' AND columns_in_order='semester_offering_review_id') OR
    (table_name='semester_offering_events' AND columns_in_order='semester_offering_event_id')
);
SET @sog_target_indexes := (
  SELECT COUNT(*) FROM (
    SELECT table_name,index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_in_order
    FROM information_schema.statistics
    WHERE table_schema='alrowad_uni_rust' AND index_name IN ('idx_sor_status_submitted','idx_sor_program_course','idx_sor_materialized','idx_sorv_status','idx_soe_request_time','idx_soe_type_time')
    GROUP BY table_name,index_name
  ) x WHERE
    (table_name='semester_offering_requests' AND index_name='idx_sor_status_submitted' AND columns_in_order='status,submitted_at') OR
    (table_name='semester_offering_requests' AND index_name='idx_sor_program_course' AND columns_in_order='program_course_id') OR
    (table_name='semester_offering_requests' AND index_name='idx_sor_materialized' AND columns_in_order='materialized_at') OR
    (table_name='semester_offering_reviews' AND index_name='idx_sorv_status' AND columns_in_order='status,reviewed_at') OR
    (table_name='semester_offering_events' AND index_name='idx_soe_request_time' AND columns_in_order='semester_offering_request_id,occurred_at') OR
    (table_name='semester_offering_events' AND index_name='idx_soe_type_time' AND columns_in_order='event_type,occurred_at')
);
SET @sog_target_fks := (
  SELECT COUNT(*)
  FROM information_schema.key_column_usage k
  JOIN information_schema.referential_constraints rc
    ON rc.constraint_schema=k.table_schema
   AND rc.table_name=k.table_name
   AND rc.constraint_name=k.constraint_name
  WHERE k.table_schema='alrowad_uni_rust' AND k.referenced_table_schema='alrowad_uni_rust'
    AND rc.update_rule='RESTRICT' AND rc.delete_rule='RESTRICT'
    AND (
      (k.constraint_name='fk_sor_offering' AND k.table_name='semester_offering_requests' AND k.column_name='course_offering_id' AND k.referenced_table_name='course_offerings' AND k.referenced_column_name='course_offering_id') OR
      (k.constraint_name='fk_sor_program_course' AND k.table_name='semester_offering_requests' AND k.column_name='program_course_id' AND k.referenced_table_name='program_courses' AND k.referenced_column_name='program_course_id') OR
      (k.constraint_name='fk_sor_created_by' AND k.table_name='semester_offering_requests' AND k.column_name='created_by_user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='user_id') OR
      (k.constraint_name='fk_sor_submitted_by' AND k.table_name='semester_offering_requests' AND k.column_name='submitted_by_user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='user_id') OR
      (k.constraint_name='fk_sorv_request' AND k.table_name='semester_offering_reviews' AND k.column_name='semester_offering_request_id' AND k.referenced_table_name='semester_offering_requests' AND k.referenced_column_name='semester_offering_request_id') OR
      (k.constraint_name='fk_sorv_reviewer' AND k.table_name='semester_offering_reviews' AND k.column_name='reviewed_by_user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='user_id') OR
      (k.constraint_name='fk_soe_request' AND k.table_name='semester_offering_events' AND k.column_name='semester_offering_request_id' AND k.referenced_table_name='semester_offering_requests' AND k.referenced_column_name='semester_offering_request_id') OR
      (k.constraint_name='fk_soe_actor' AND k.table_name='semester_offering_events' AND k.column_name='actor_user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='user_id')
    )
);
SET @sog_target_fk_rules := @sog_target_fks;
SET @sog_target_checks := (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE constraint_schema='alrowad_uni_rust' AND constraint_type='CHECK' AND (
    (table_name='semester_offering_requests' AND constraint_name IN ('chk_sor_course_type','chk_sor_selected','chk_sor_minimum','chk_sor_status','chk_sor_version','chk_sor_submission','chk_sor_approval','chk_sor_materialization')) OR
    (table_name='semester_offering_reviews' AND constraint_name IN ('chk_sorv_version','chk_sorv_status','chk_sorv_provenance')) OR
    (table_name='semester_offering_events' AND constraint_name IN ('chk_soe_version','chk_soe_type'))
  )
);
SET @sog_target_comments := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema='alrowad_uni_rust'
    AND table_name IN ('semester_offering_requests','semester_offering_reviews','semester_offering_events')
    AND table_comment LIKE 'Owned by semester-offering-governance-phase1%'
);
SET @sog_target_state := IF(
  @sog_target_tables=0,'ABSENT',
  IF(@sog_target_tables=3 AND @sog_target_columns=31 AND @sog_target_shape=31 AND @sog_target_pks=3 AND @sog_target_unique=2
    AND @sog_target_indexes=6 AND @sog_target_fks=8 AND @sog_target_fk_rules=8 AND @sog_target_checks=13
    AND @sog_target_comments=3,'COMPATIBLE','CONFLICTING')
);
SET @sog_roles := (
  SELECT COUNT(DISTINCT role_code) FROM `alrowad_uni_rust`.`roles`
  WHERE role_code IN ('dean','vice_president_scientific','vice_president_administrative') AND is_active=1
);
SET @sog_role_duplicates := (SELECT COUNT(*) FROM (SELECT role_code FROM `alrowad_uni_rust`.`roles` WHERE role_code IN ('dean','vice_president_scientific','vice_president_administrative') AND is_active=1 GROUP BY role_code HAVING COUNT(*)>1) x);
SET @sog_module := (
  SELECT COUNT(*) FROM `alrowad_uni_rust`.`system_modules` WHERE module_code='courses' AND is_active=1
);
SET @sog_permission_conflicts := (
  SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p
  LEFT JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id=p.module_id
  WHERE p.permission_code IN (
    'course_offerings.semester_governance.view',
    'course_offerings.semester_governance.manage',
    'course_offerings.semester_governance.review_scientific'
  ) AND (p.is_active<>1 OR COALESCE(m.module_code,'')<>'courses' OR COALESCE(m.is_active,0)<>1)
);
SET @sog_mapping_conflicts := (
  SELECT COUNT(*)
  FROM `alrowad_uni_rust`.`role_permissions` rp
  JOIN `alrowad_uni_rust`.`roles` r ON r.role_id=rp.role_id
  JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id
  WHERE p.permission_code IN (
    'course_offerings.semester_governance.view',
    'course_offerings.semester_governance.manage',
    'course_offerings.semester_governance.review_scientific'
  ) AND NOT (
    r.is_active=1 AND (
      (r.role_code='dean' AND p.permission_code IN ('course_offerings.semester_governance.view','course_offerings.semester_governance.manage'))
      OR (r.role_code='vice_president_scientific' AND p.permission_code IN ('course_offerings.semester_governance.view','course_offerings.semester_governance.review_scientific'))
    )
  )
);
SET @sog_ready := (
  @sog_core_tables=23 AND @sog_core_columns=89 AND @sog_signed_keys=3
  AND @sog_semesters=3 AND @sog_effective_slot_indexes=2 AND @sog_calendar_registration_type=1 AND @sog_pres_scope=1
  AND @sog_target_state<>'CONFLICTING' AND @sog_roles=3 AND @sog_role_duplicates=0 AND @sog_module=1
  AND @sog_permission_conflicts=0 AND @sog_mapping_conflicts=0
);

SELECT 'DATABASE_AND_CORE' AS report_section,
       IF(@sog_core_tables=23 AND @sog_core_columns=89 AND @sog_signed_keys=3,'PASS','FAIL') AS result,
       CONCAT('tables=',@sog_core_tables,'/23; columns=',@sog_core_columns,'/89; signed_keys=',@sog_signed_keys,'/3') AS detail;
SELECT 'SEMESTER_AND_WORKFLOW_PREREQUISITES' AS report_section,
       IF(@sog_semesters=3 AND @sog_effective_slot_indexes=2 AND @sog_calendar_registration_type=1 AND @sog_pres_scope=1,'PASS','FAIL') AS result,
       CONCAT('required_semester_codes=',@sog_semesters,'/3; effective_slot_indexes=',@sog_effective_slot_indexes,'/2; course_registration_type=',@sog_calendar_registration_type,'/1; PRES_scope=',@sog_pres_scope,'/1') AS detail;
SELECT 'PHASE1_OBJECTS' AS report_section,
       IF(@sog_target_state='CONFLICTING','FAIL','PASS') AS result,
       CONCAT('classification=',@sog_target_state,'; tables=',@sog_target_tables,'; required_columns=',@sog_target_columns,'/31; compatible_shapes=',@sog_target_shape,'/31; pks=',@sog_target_pks,'/3; unique=',@sog_target_unique,'/2; indexes=',@sog_target_indexes,'/6; fks=',@sog_target_fks,'/8; restrictive_fk_rules=',@sog_target_fk_rules,'/8; checks=',@sog_target_checks,'/13; ownership=',@sog_target_comments,'/3') AS detail;
SELECT 'RBAC' AS report_section,
       IF(@sog_roles=3 AND @sog_role_duplicates=0 AND @sog_module=1 AND @sog_permission_conflicts=0 AND @sog_mapping_conflicts=0,'PASS','FAIL') AS result,
       CONCAT('roles=',@sog_roles,'/3; duplicate_roles=',@sog_role_duplicates,'; courses_module=',@sog_module,'; permission_conflicts=',@sog_permission_conflicts,'; mapping_conflicts=',@sog_mapping_conflicts) AS detail;
SELECT 'OVERALL' AS report_section, IF(@sog_ready,'READY','BLOCKED') AS result;
