-- READ ONLY. Run in phpMyAdmin and continue only when the final row is OVERALL | READY.
USE `alrowad_uni_rust`;

SET @srd_database := (SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='alrowad_uni_rust');
SET @srd_core_tables := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN (
  'academic_years','semesters','users','students','course_offerings',
  'academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions',
  'student_registration_requests','student_registration_request_items','student_registration_request_events','student_course_registrations',
  'semester_offering_requests','semester_offering_reviews','semester_offering_events',
  'permissions','roles','role_permissions','user_roles','user_access_scopes','organizational_units'
));
SET @srd_core_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND (
  (table_name='academic_years' AND column_name IN ('academic_year_id','is_current','is_active','calendar_lifecycle_status')) OR
  (table_name='semesters' AND column_name IN ('semester_id','semester_code','is_active')) OR
  (table_name='users' AND column_name='user_id') OR
  (table_name='students' AND column_name IN ('student_id','academic_program_id')) OR
  (table_name='course_offerings' AND column_name IN ('course_offering_id','academic_year_id','semester_id','status')) OR
  (table_name='academic_calendar_event_types' AND column_name IN ('academic_calendar_event_type_id','event_type_code','is_active')) OR
  (table_name='academic_calendar_events' AND column_name IN ('academic_calendar_event_id','academic_year_id','semester_id','academic_calendar_event_type_id','cancelled_at','created_by_user_id')) OR
  (table_name='academic_calendar_event_versions' AND column_name IN ('academic_calendar_event_version_id','academic_calendar_event_id','version_number','starts_at','ends_at','is_enforcement','publication_status','superseded_at','published_event_slot')) OR
  (table_name='student_registration_requests' AND column_name IN ('student_registration_request_id','student_id','academic_year_id','semester_id','status','last_submitted_at','advisor_user_id')) OR
  (table_name='student_registration_request_items' AND column_name IN ('student_registration_request_item_id','student_registration_request_id','course_offering_id','student_course_registration_id')) OR
  (table_name='student_registration_request_events' AND column_name IN ('student_registration_request_event_id','student_registration_request_id','event_type','actor_user_id','from_status','to_status','created_at')) OR
  (table_name='student_course_registrations' AND column_name IN ('student_course_registration_id','student_id','course_offering_id','registration_status_id')) OR
  (table_name='semester_offering_requests' AND column_name IN ('semester_offering_request_id','course_offering_id')) OR
  (table_name='semester_offering_reviews' AND column_name IN ('semester_offering_review_id','semester_offering_request_id')) OR
  (table_name='semester_offering_events' AND column_name IN ('semester_offering_event_id','semester_offering_request_id')) OR
  (table_name='permissions' AND column_name IN ('permission_id','permission_code')) OR
  (table_name='roles' AND column_name IN ('role_id','role_code')) OR
  (table_name='role_permissions' AND column_name IN ('role_id','permission_id')) OR
  (table_name='user_roles' AND column_name IN ('user_id','role_id','is_active')) OR
  (table_name='user_access_scopes' AND column_name IN ('user_id','scope_type','scope_id','is_active')) OR
  (table_name='organizational_units' AND column_name IN ('organizational_unit_id','unit_code','is_active'))
));
SET @srd_signed_keys := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='int' AND column_type NOT LIKE '%unsigned%' AND (
  (table_name='academic_years' AND column_name='academic_year_id') OR (table_name='semesters' AND column_name='semester_id') OR
  (table_name='users' AND column_name='user_id') OR (table_name='academic_calendar_event_types' AND column_name='academic_calendar_event_type_id') OR
  (table_name='academic_calendar_events' AND column_name='academic_calendar_event_id') OR (table_name='academic_calendar_event_versions' AND column_name='academic_calendar_event_version_id') OR
  (table_name='student_registration_requests' AND column_name='student_registration_request_id') OR (table_name='student_registration_request_items' AND column_name='student_registration_request_item_id') OR
  (table_name='student_registration_request_events' AND column_name='student_registration_request_event_id') OR (table_name='student_course_registrations' AND column_name='student_course_registration_id') OR
  (table_name='course_offerings' AND column_name='course_offering_id')
));
SET @srd_version_contract := (SELECT COUNT(*) FROM (
  SELECT index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_in_order,MIN(non_unique) non_unique
  FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_versions'
    AND index_name IN ('uq_acev_event_version','uq_acev_published_event_slot','idx_acev_event_status','idx_acev_publication_window')
  GROUP BY index_name
) x WHERE (index_name='uq_acev_event_version' AND columns_in_order='academic_calendar_event_id,version_number' AND non_unique=0)
  OR (index_name='uq_acev_published_event_slot' AND columns_in_order='published_event_slot' AND non_unique=0)
  OR (index_name='idx_acev_event_status' AND columns_in_order='academic_calendar_event_id,publication_status')
  OR (index_name='idx_acev_publication_window' AND columns_in_order='publication_status,starts_at,ends_at'));
SET @srd_request_contract := (SELECT COUNT(*) FROM (
  SELECT table_name,index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_in_order,MIN(non_unique) non_unique
  FROM information_schema.statistics WHERE table_schema='alrowad_uni_rust' AND (
    (table_name='student_registration_requests' AND index_name IN ('uq_student_registration_request_term','idx_student_registration_requests_status')) OR
    (table_name='student_registration_request_events' AND index_name='idx_srr_events_request')
  ) GROUP BY table_name,index_name
) x WHERE (table_name='student_registration_requests' AND index_name='uq_student_registration_request_term' AND columns_in_order='student_id,academic_year_id,semester_id' AND non_unique=0)
  OR (table_name='student_registration_requests' AND index_name='idx_student_registration_requests_status' AND columns_in_order='status,last_submitted_at')
  OR (table_name='student_registration_request_events' AND index_name='idx_srr_events_request' AND columns_in_order='student_registration_request_id,created_at'));
SET @srd_nullable_system_actor := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND table_name='student_registration_request_events' AND column_name='actor_user_id' AND is_nullable='YES' AND data_type='int' AND column_type NOT LIKE '%unsigned%');
SET @srd_request_text_contract := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='varchar' AND (
  (table_name='student_registration_requests' AND column_name='status' AND character_maximum_length>=9) OR
  (table_name='student_registration_request_events' AND column_name='event_type' AND character_maximum_length>=16) OR
  (table_name='student_registration_request_events' AND column_name IN ('from_status','to_status') AND character_maximum_length>=9)
));
SET @srd_target_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND (
  (table_name='academic_calendar_event_versions' AND column_name IN ('student_registration_ends_at','advisor_approval_ends_at')) OR
  (table_name='student_registration_requests' AND column_name='expired_at')
));
SET @srd_target_compatible := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='datetime' AND is_nullable='YES' AND (
  (table_name='academic_calendar_event_versions' AND column_name IN ('student_registration_ends_at','advisor_approval_ends_at')) OR
  (table_name='student_registration_requests' AND column_name='expired_at')
));

SET @srd_registration_type:=0,@srd_operational_year:=0,@srd_semesters:=0,@srd_permission_codes:=0,@srd_permission_duplicates:=1,@srd_advisor_mappings:=0,@srd_root_duplicates:=1,@srd_deadline_conflicts:=0,@srd_request_conflicts:=0;
SET @srd_sql := IF(@srd_core_tables=21,
  'SET @srd_registration_type=(SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_types` WHERE event_type_code=''course_registration'' AND is_active=1),@srd_operational_year=((SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years` WHERE is_current=1)=1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years` WHERE calendar_lifecycle_status=''active'')=1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years` WHERE is_current=1 AND is_active=1 AND calendar_lifecycle_status=''active'')=1),@srd_semesters=(SELECT COUNT(DISTINCT semester_code) FROM `alrowad_uni_rust`.`semesters` WHERE semester_code IN (''first'',''second'',''summer'') AND is_active=1),@srd_permission_codes=(SELECT COUNT(DISTINCT permission_code) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN (''registration_requests.view'',''registration_requests.review'') AND is_active=1),@srd_permission_duplicates=(SELECT COUNT(*) FROM (SELECT permission_code FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN (''registration_requests.view'',''registration_requests.review'') AND is_active=1 GROUP BY permission_code HAVING COUNT(*)<>1) d),@srd_advisor_mappings=(SELECT COUNT(DISTINCT p.permission_code) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id=r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id WHERE r.role_code=''academic_advisor'' AND r.is_active=1 AND p.is_active=1 AND p.permission_code IN (''registration_requests.view'',''registration_requests.review'')),@srd_root_duplicates=(SELECT COUNT(*) FROM (SELECT e.academic_year_id,e.semester_id FROM `alrowad_uni_rust`.`academic_calendar_events` e JOIN `alrowad_uni_rust`.`academic_calendar_event_types` t ON t.academic_calendar_event_type_id=e.academic_calendar_event_type_id WHERE t.event_type_code=''course_registration'' AND e.cancelled_at IS NULL GROUP BY e.academic_year_id,e.semester_id HAVING COUNT(*)>1) d)',
  'SET @srd_registration_type=0,@srd_operational_year=0,@srd_semesters=0,@srd_permission_codes=0,@srd_permission_duplicates=1,@srd_advisor_mappings=0,@srd_root_duplicates=1');
PREPARE srd_preflight_stmt FROM @srd_sql; EXECUTE srd_preflight_stmt; DEALLOCATE PREPARE srd_preflight_stmt;

SET @srd_sql := IF(@srd_target_columns=3 AND @srd_target_compatible=3,
  'SET @srd_deadline_conflicts=(SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions` v JOIN `alrowad_uni_rust`.`academic_calendar_events` e ON e.academic_calendar_event_id=v.academic_calendar_event_id JOIN `alrowad_uni_rust`.`academic_calendar_event_types` t ON t.academic_calendar_event_type_id=e.academic_calendar_event_type_id WHERE (t.event_type_code<>''course_registration'' AND (v.student_registration_ends_at IS NOT NULL OR v.advisor_approval_ends_at IS NOT NULL)) OR (t.event_type_code=''course_registration'' AND ((v.student_registration_ends_at IS NULL)<>(v.advisor_approval_ends_at IS NULL) OR (v.student_registration_ends_at IS NOT NULL AND (v.starts_at>v.student_registration_ends_at OR v.student_registration_ends_at>v.advisor_approval_ends_at OR v.ends_at<>v.advisor_approval_ends_at))))),@srd_request_conflicts=(SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_registration_requests` WHERE (status=''expired'' AND expired_at IS NULL) OR (status<>''expired'' AND expired_at IS NOT NULL))',
  'SET @srd_deadline_conflicts=0,@srd_request_conflicts=0');
PREPARE srd_target_stmt FROM @srd_sql; EXECUTE srd_target_stmt; DEALLOCATE PREPARE srd_target_stmt;

SET @srd_ready := (@srd_database=1 AND @srd_core_tables=21 AND @srd_core_columns=76 AND @srd_signed_keys=11
  AND @srd_version_contract=4 AND @srd_request_contract=3 AND @srd_nullable_system_actor=1 AND @srd_request_text_contract=4
  AND @srd_registration_type=1 AND @srd_operational_year=1 AND @srd_semesters=3 AND @srd_permission_codes=2 AND @srd_permission_duplicates=0 AND @srd_advisor_mappings=2
  AND @srd_root_duplicates=0 AND @srd_target_columns=@srd_target_compatible
  AND @srd_deadline_conflicts=0 AND @srd_request_conflicts=0);

SELECT 'DATABASE_AND_CORE' report_section,IF(@srd_database=1 AND @srd_core_tables=21 AND @srd_core_columns=76,'PASS','FAIL') result,CONCAT('tables=',@srd_core_tables,'/21; columns=',@srd_core_columns,'/76') detail;
SELECT 'KEYS_AND_WORKFLOWS' report_section,IF(@srd_signed_keys=11 AND @srd_version_contract=4 AND @srd_request_contract=3 AND @srd_nullable_system_actor=1 AND @srd_request_text_contract=4,'PASS','FAIL') result,CONCAT('signed_keys=',@srd_signed_keys,'/11; calendar_indexes=',@srd_version_contract,'/4; request_indexes=',@srd_request_contract,'/3; request_text_columns=',@srd_request_text_contract,'/4') detail;
SELECT 'CALENDAR_CONTEXT' report_section,IF(@srd_registration_type=1 AND @srd_operational_year=1 AND @srd_semesters=3,'PASS','FAIL') result,CONCAT('course_registration=',@srd_registration_type,'; operational_year=',@srd_operational_year,'; active_semesters=',@srd_semesters,'/3') detail;
SELECT 'ADVISOR_RBAC' report_section,IF(@srd_permission_codes=2 AND @srd_permission_duplicates=0 AND @srd_advisor_mappings=2,'PASS','FAIL') result,CONCAT('permissions=',@srd_permission_codes,'/2; academic_advisor_mappings=',@srd_advisor_mappings,'/2') detail;
SELECT 'PHASE2_COMPATIBILITY' report_section,IF(@srd_target_columns=@srd_target_compatible AND @srd_root_duplicates=0 AND @srd_deadline_conflicts=0 AND @srd_request_conflicts=0,'PASS','FAIL') result,CONCAT('target_columns=',@srd_target_columns,'/3; compatible=',@srd_target_compatible,'; duplicate_roots=',@srd_root_duplicates,'; data_conflicts=',@srd_deadline_conflicts+@srd_request_conflicts) detail;
SELECT 'OVERALL' report_section,IF(@srd_ready,'READY','BLOCKED') result;
