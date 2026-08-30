-- READ ONLY verification after 01_apply.sql.
USE `alrowad_uni_rust`;

SET @sog_tables := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN ('semester_offering_requests','semester_offering_reviews','semester_offering_events'));
SET @sog_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND ((table_name='semester_offering_requests' AND column_name IN ('semester_offering_request_id','course_offering_id','program_course_id','course_type','is_selected','minimum_enrollment','status','submission_version','created_by_user_id','submitted_by_user_id','submitted_at','approved_at','materialized_at','created_at','updated_at')) OR (table_name='semester_offering_reviews' AND column_name IN ('semester_offering_review_id','semester_offering_request_id','submission_version','status','reviewed_by_user_id','reviewed_at','reason','created_at','updated_at')) OR (table_name='semester_offering_events' AND column_name IN ('semester_offering_event_id','semester_offering_request_id','submission_version','event_type','actor_user_id','note','occurred_at'))));
SET @sog_shape := (
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
SET @sog_pks := (SELECT COUNT(*) FROM (SELECT table_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_in_order FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND index_name='PRIMARY' AND table_name IN ('semester_offering_requests','semester_offering_reviews','semester_offering_events') GROUP BY table_name) x WHERE (table_name='semester_offering_requests' AND columns_in_order='semester_offering_request_id') OR (table_name='semester_offering_reviews' AND columns_in_order='semester_offering_review_id') OR (table_name='semester_offering_events' AND columns_in_order='semester_offering_event_id'));
SET @sog_unique := (SELECT COUNT(*) FROM (SELECT table_name,index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_in_order FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND non_unique=0 AND index_name IN ('uq_sor_offering','uq_sorv_request_version') GROUP BY table_name,index_name) x WHERE (table_name='semester_offering_requests' AND index_name='uq_sor_offering' AND columns_in_order='course_offering_id') OR (table_name='semester_offering_reviews' AND index_name='uq_sorv_request_version' AND columns_in_order='semester_offering_request_id,submission_version'));
SET @sog_indexes := (SELECT COUNT(*) FROM (SELECT table_name,index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_in_order FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND index_name IN ('idx_sor_status_submitted','idx_sor_program_course','idx_sor_materialized','idx_sorv_status','idx_soe_request_time','idx_soe_type_time') GROUP BY table_name,index_name) x WHERE (table_name='semester_offering_requests' AND index_name='idx_sor_status_submitted' AND columns_in_order='status,submitted_at') OR (table_name='semester_offering_requests' AND index_name='idx_sor_program_course' AND columns_in_order='program_course_id') OR (table_name='semester_offering_requests' AND index_name='idx_sor_materialized' AND columns_in_order='materialized_at') OR (table_name='semester_offering_reviews' AND index_name='idx_sorv_status' AND columns_in_order='status,reviewed_at') OR (table_name='semester_offering_events' AND index_name='idx_soe_request_time' AND columns_in_order='semester_offering_request_id,occurred_at') OR (table_name='semester_offering_events' AND index_name='idx_soe_type_time' AND columns_in_order='event_type,occurred_at'));
SET @sog_fks := (SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema='alrowad_uni_rust' AND referenced_table_name IS NOT NULL AND (
  (table_name='semester_offering_requests' AND column_name='course_offering_id' AND referenced_table_name='course_offerings' AND referenced_column_name='course_offering_id') OR
  (table_name='semester_offering_requests' AND column_name='program_course_id' AND referenced_table_name='program_courses' AND referenced_column_name='program_course_id') OR
  (table_name='semester_offering_requests' AND column_name='created_by_user_id' AND referenced_table_name='users' AND referenced_column_name='user_id') OR
  (table_name='semester_offering_requests' AND column_name='submitted_by_user_id' AND referenced_table_name='users' AND referenced_column_name='user_id') OR
  (table_name='semester_offering_reviews' AND column_name='semester_offering_request_id' AND referenced_table_name='semester_offering_requests' AND referenced_column_name='semester_offering_request_id') OR
  (table_name='semester_offering_reviews' AND column_name='reviewed_by_user_id' AND referenced_table_name='users' AND referenced_column_name='user_id') OR
  (table_name='semester_offering_events' AND column_name='semester_offering_request_id' AND referenced_table_name='semester_offering_requests' AND referenced_column_name='semester_offering_request_id') OR
  (table_name='semester_offering_events' AND column_name='actor_user_id' AND referenced_table_name='users' AND referenced_column_name='user_id')
));
SET @sog_fk_rules := (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema='alrowad_uni_rust' AND constraint_name IN ('fk_sor_offering','fk_sor_program_course','fk_sor_created_by','fk_sor_submitted_by','fk_sorv_request','fk_sorv_reviewer','fk_soe_request','fk_soe_actor') AND update_rule='RESTRICT' AND delete_rule='RESTRICT');
SET @sog_checks := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema='alrowad_uni_rust' AND constraint_type='CHECK' AND table_name IN ('semester_offering_requests','semester_offering_reviews','semester_offering_events'));
SET @sog_comments := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN ('semester_offering_requests','semester_offering_reviews','semester_offering_events') AND table_comment LIKE 'Owned by semester-offering-governance-phase1%');
SET @sog_permissions := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id=p.module_id WHERE m.module_code='courses' AND m.is_active=1 AND p.is_active=1 AND p.permission_code IN ('course_offerings.semester_governance.view','course_offerings.semester_governance.manage','course_offerings.semester_governance.review_scientific'));
SET @sog_permission_conflicts := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`permissions` p LEFT JOIN `alrowad_uni_rust`.`system_modules` m ON m.module_id=p.module_id WHERE p.permission_code IN ('course_offerings.semester_governance.view','course_offerings.semester_governance.manage','course_offerings.semester_governance.review_scientific') AND (p.is_active<>1 OR COALESCE(m.module_code,'')<>'courses' OR COALESCE(m.is_active,0)<>1));
SET @sog_expected_mappings := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`roles` r ON r.role_id=rp.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id WHERE r.is_active=1 AND ((r.role_code='dean' AND p.permission_code IN ('course_offerings.semester_governance.view','course_offerings.semester_governance.manage')) OR (r.role_code='vice_president_scientific' AND p.permission_code IN ('course_offerings.semester_governance.view','course_offerings.semester_governance.review_scientific'))));
SET @sog_forbidden_mappings := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`roles` r ON r.role_id=rp.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id WHERE p.permission_code IN ('course_offerings.semester_governance.view','course_offerings.semester_governance.manage','course_offerings.semester_governance.review_scientific') AND NOT (r.is_active=1 AND ((r.role_code='dean' AND p.permission_code IN ('course_offerings.semester_governance.view','course_offerings.semester_governance.manage')) OR (r.role_code='vice_president_scientific' AND p.permission_code IN ('course_offerings.semester_governance.view','course_offerings.semester_governance.review_scientific')))));
SET @sog_invalid_requests := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`semester_offering_requests` r WHERE r.minimum_enrollment<1 OR r.status NOT IN ('draft','submitted','returned','approved') OR (r.materialized_at IS NOT NULL AND r.status<>'approved'));
SET @sog_invalid_reviews := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`semester_offering_reviews` v WHERE v.status NOT IN ('pending','approved','returned') OR (v.status='returned' AND CHAR_LENGTH(TRIM(COALESCE(v.reason,'')))=0));
SET @sog_duplicate_roots := (SELECT COUNT(*) FROM (SELECT course_offering_id FROM `alrowad_uni_rust`.`semester_offering_requests` GROUP BY course_offering_id HAVING COUNT(*)>1) x);
SET @sog_ok := (@sog_tables=3 AND @sog_columns=31 AND @sog_shape=31 AND @sog_pks=3 AND @sog_unique=2 AND @sog_indexes=6 AND @sog_fks=8 AND @sog_fk_rules=8 AND @sog_checks=13 AND @sog_comments=3 AND @sog_permissions=3 AND @sog_permission_conflicts=0 AND @sog_expected_mappings=4 AND @sog_forbidden_mappings=0 AND @sog_invalid_requests=0 AND @sog_invalid_reviews=0 AND @sog_duplicate_roots=0);

SELECT 'STRUCTURE' report_section, IF(@sog_tables=3 AND @sog_columns=31 AND @sog_shape=31 AND @sog_pks=3 AND @sog_comments=3,'PASS','FAIL') result, CONCAT('tables=',@sog_tables,'/3; columns=',@sog_columns,'/31; compatible_shapes=',@sog_shape,'/31; pks=',@sog_pks,'/3; ownership=',@sog_comments,'/3') detail;
SELECT 'CONSTRAINTS' report_section, IF(@sog_unique=2 AND @sog_indexes=6 AND @sog_fks=8 AND @sog_fk_rules=8 AND @sog_checks=13,'PASS','FAIL') result, CONCAT('unique=',@sog_unique,'/2; indexes=',@sog_indexes,'/6; fks=',@sog_fks,'/8; restrictive_fk_rules=',@sog_fk_rules,'/8; checks=',@sog_checks,'/13') detail;
SELECT 'RBAC' report_section, IF(@sog_permissions=3 AND @sog_permission_conflicts=0 AND @sog_expected_mappings=4 AND @sog_forbidden_mappings=0,'PASS','FAIL') result, CONCAT('permissions=',@sog_permissions,'/3; permission_conflicts=',@sog_permission_conflicts,'; mappings=',@sog_expected_mappings,'/4; forbidden=',@sog_forbidden_mappings) detail;
SELECT 'DATA_INVARIANTS' report_section, IF(@sog_invalid_requests=0 AND @sog_invalid_reviews=0 AND @sog_duplicate_roots=0,'PASS','FAIL') result, CONCAT('invalid_requests=',@sog_invalid_requests,'; invalid_reviews=',@sog_invalid_reviews,'; duplicate_roots=',@sog_duplicate_roots) detail;
SELECT 'OVERALL' report_section, IF(@sog_ok,'PASS','FAIL') result;
