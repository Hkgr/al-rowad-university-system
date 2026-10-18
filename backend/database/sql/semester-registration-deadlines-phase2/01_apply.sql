-- Guarded, rerunnable MariaDB 10.11 DDL. No historical rows are rewritten.
-- This script recomputes its own prerequisites and does not trust a prior preflight run.
USE `alrowad_uni_rust`;

SET @srd_apply_database := (SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='alrowad_uni_rust');
SET @srd_apply_core_tables := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN (
  'academic_years','semesters','users','students','course_offerings',
  'academic_calendar_event_types','academic_calendar_events','academic_calendar_event_versions',
  'student_registration_requests','student_registration_request_items','student_registration_request_events','student_course_registrations',
  'semester_offering_requests','semester_offering_reviews','semester_offering_events',
  'permissions','roles','role_permissions','user_roles','user_access_scopes','organizational_units'
));
SET @srd_apply_core_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND (
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
SET @srd_apply_data_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND column_name='is_active' AND table_name IN ('permissions','roles'));
SET @srd_apply_signed_keys := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='int' AND column_type NOT LIKE '%unsigned%' AND (
  (table_name='academic_years' AND column_name='academic_year_id') OR (table_name='semesters' AND column_name='semester_id') OR
  (table_name='users' AND column_name='user_id') OR (table_name='academic_calendar_event_types' AND column_name='academic_calendar_event_type_id') OR
  (table_name='academic_calendar_events' AND column_name='academic_calendar_event_id') OR (table_name='academic_calendar_event_versions' AND column_name='academic_calendar_event_version_id') OR
  (table_name='student_registration_requests' AND column_name='student_registration_request_id') OR (table_name='student_registration_request_items' AND column_name='student_registration_request_item_id') OR
  (table_name='student_registration_request_events' AND column_name='student_registration_request_event_id') OR (table_name='student_course_registrations' AND column_name='student_course_registration_id') OR
  (table_name='course_offerings' AND column_name='course_offering_id')
));
SET @srd_apply_version_indexes := (SELECT COUNT(*) FROM (
  SELECT index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_in_order,MIN(non_unique) non_unique FROM information_schema.statistics
  WHERE table_schema='alrowad_uni_rust' AND table_name='academic_calendar_event_versions' AND index_name IN ('uq_acev_event_version','uq_acev_published_event_slot','idx_acev_event_status','idx_acev_publication_window') GROUP BY index_name
) x WHERE (index_name='uq_acev_event_version' AND columns_in_order='academic_calendar_event_id,version_number' AND non_unique=0)
  OR (index_name='uq_acev_published_event_slot' AND columns_in_order='published_event_slot' AND non_unique=0)
  OR (index_name='idx_acev_event_status' AND columns_in_order='academic_calendar_event_id,publication_status')
  OR (index_name='idx_acev_publication_window' AND columns_in_order='publication_status,starts_at,ends_at'));
SET @srd_apply_request_indexes := (SELECT COUNT(*) FROM (
  SELECT table_name,index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_in_order,MIN(non_unique) non_unique FROM information_schema.statistics
  WHERE table_schema='alrowad_uni_rust' AND ((table_name='student_registration_requests' AND index_name IN ('uq_student_registration_request_term','idx_student_registration_requests_status')) OR (table_name='student_registration_request_events' AND index_name='idx_srr_events_request')) GROUP BY table_name,index_name
) x WHERE (table_name='student_registration_requests' AND index_name='uq_student_registration_request_term' AND columns_in_order='student_id,academic_year_id,semester_id' AND non_unique=0)
  OR (table_name='student_registration_requests' AND index_name='idx_student_registration_requests_status' AND columns_in_order='status,last_submitted_at')
  OR (table_name='student_registration_request_events' AND index_name='idx_srr_events_request' AND columns_in_order='student_registration_request_id,created_at'));
SET @srd_apply_runtime_contract := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND (
  (table_name='student_registration_requests' AND column_name='status' AND data_type='varchar' AND character_maximum_length>=9) OR
  (table_name='student_registration_request_events' AND column_name='event_type' AND data_type='varchar' AND character_maximum_length>=16) OR
  (table_name='student_registration_request_events' AND column_name IN ('from_status','to_status') AND data_type='varchar' AND character_maximum_length>=9) OR
  (table_name='student_registration_request_events' AND column_name='actor_user_id' AND data_type='int' AND column_type NOT LIKE '%unsigned%' AND is_nullable='YES')
));
SET @srd_apply_existing := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND ((table_name='academic_calendar_event_versions' AND column_name IN ('student_registration_ends_at','advisor_approval_ends_at')) OR (table_name='student_registration_requests' AND column_name='expired_at')));
SET @srd_apply_compatible := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='datetime' AND is_nullable='YES' AND ((table_name='academic_calendar_event_versions' AND column_name IN ('student_registration_ends_at','advisor_approval_ends_at')) OR (table_name='student_registration_requests' AND column_name='expired_at')));
SET @srd_apply_structure_ready := (@srd_apply_database=1 AND @srd_apply_core_tables=21 AND @srd_apply_core_columns=76 AND @srd_apply_data_columns=2 AND @srd_apply_signed_keys=11 AND @srd_apply_version_indexes=4 AND @srd_apply_request_indexes=3 AND @srd_apply_runtime_contract=5 AND @srd_apply_existing=@srd_apply_compatible);

SET @srd_apply_registration_type:=0,@srd_apply_permission_duplicates:=1,@srd_apply_advisor_role:=0,@srd_apply_advisor_mappings:=0,@srd_apply_root_duplicates:=1;
SET @srd_sql := IF(@srd_apply_structure_ready,
  'SET @srd_apply_registration_type=(SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_types` WHERE event_type_code=''course_registration'' AND is_active=1),@srd_apply_permission_duplicates=(SELECT COUNT(*) FROM (SELECT permission_code FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN (''registration_requests.view'',''registration_requests.review'') AND is_active=1 GROUP BY permission_code HAVING COUNT(*)<>1) d),@srd_apply_advisor_role=(SELECT COUNT(*) FROM `alrowad_uni_rust`.`roles` WHERE role_code=''academic_advisor'' AND is_active=1),@srd_apply_advisor_mappings=(SELECT COUNT(DISTINCT p.permission_code) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id=r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id WHERE r.role_code=''academic_advisor'' AND r.is_active=1 AND p.is_active=1 AND p.permission_code IN (''registration_requests.view'',''registration_requests.review'')),@srd_apply_root_duplicates=(SELECT COUNT(*) FROM (SELECT e.academic_year_id,e.semester_id FROM `alrowad_uni_rust`.`academic_calendar_events` e JOIN `alrowad_uni_rust`.`academic_calendar_event_types` t ON t.academic_calendar_event_type_id=e.academic_calendar_event_type_id WHERE t.event_type_code=''course_registration'' AND e.cancelled_at IS NULL GROUP BY e.academic_year_id,e.semester_id HAVING COUNT(*)>1) d)',
  'SET @srd_apply_registration_type=0,@srd_apply_permission_duplicates=1,@srd_apply_advisor_role=0,@srd_apply_advisor_mappings=0,@srd_apply_root_duplicates=1');
PREPARE srd_apply_guard_stmt FROM @srd_sql; EXECUTE srd_apply_guard_stmt; DEALLOCATE PREPARE srd_apply_guard_stmt;

SET @srd_apply_ready := (@srd_apply_structure_ready AND @srd_apply_registration_type=1 AND @srd_apply_permission_duplicates=0 AND @srd_apply_advisor_role=1 AND @srd_apply_advisor_mappings=2 AND @srd_apply_root_duplicates=0);

SET @srd_sql := IF(@srd_apply_ready, 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` ADD COLUMN IF NOT EXISTS `student_registration_ends_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; final student mutation time'' AFTER `ends_at`, ADD COLUMN IF NOT EXISTS `advisor_approval_ends_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; final advisor decision time'' AFTER `student_registration_ends_at`', 'SELECT ''PHASE2_CALENDAR_COLUMNS'' report_section,''BLOCKED'' result');
PREPARE srd_apply_stmt FROM @srd_sql; EXECUTE srd_apply_stmt; DEALLOCATE PREPARE srd_apply_stmt;
SET @srd_sql := IF(@srd_apply_ready, 'ALTER TABLE `alrowad_uni_rust`.`academic_calendar_event_versions` MODIFY COLUMN `student_registration_ends_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; final student mutation time'', MODIFY COLUMN `advisor_approval_ends_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; final advisor decision time''', 'SELECT ''PHASE2_CALENDAR_OWNERSHIP'' report_section,''BLOCKED'' result');
PREPARE srd_apply_stmt FROM @srd_sql; EXECUTE srd_apply_stmt; DEALLOCATE PREPARE srd_apply_stmt;
SET @srd_sql := IF(@srd_apply_ready, 'ALTER TABLE `alrowad_uni_rust`.`student_registration_requests` ADD COLUMN IF NOT EXISTS `expired_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; unresolved advisor-deadline expiration'' AFTER `approved_at`', 'SELECT ''PHASE2_REQUEST_COLUMN'' report_section,''BLOCKED'' result');
PREPARE srd_apply_stmt FROM @srd_sql; EXECUTE srd_apply_stmt; DEALLOCATE PREPARE srd_apply_stmt;
SET @srd_sql := IF(@srd_apply_ready, 'ALTER TABLE `alrowad_uni_rust`.`student_registration_requests` MODIFY COLUMN `expired_at` DATETIME NULL COMMENT ''Owned by semester-registration-deadlines-phase2; unresolved advisor-deadline expiration''', 'SELECT ''PHASE2_REQUEST_OWNERSHIP'' report_section,''BLOCKED'' result');
PREPARE srd_apply_stmt FROM @srd_sql; EXECUTE srd_apply_stmt; DEALLOCATE PREPARE srd_apply_stmt;

SELECT 'APPLY_STRUCTURE' report_section,IF(@srd_apply_structure_ready,'PASS','FAIL') result,CONCAT('tables=',@srd_apply_core_tables,'/21; columns=',@srd_apply_core_columns,'/76; signed_keys=',@srd_apply_signed_keys,'/11; calendar_indexes=',@srd_apply_version_indexes,'/4; request_indexes=',@srd_apply_request_indexes,'/3; targets=',@srd_apply_existing,'/',@srd_apply_compatible) detail;
SELECT 'APPLY_DATA_AND_RBAC' report_section,IF(@srd_apply_registration_type=1 AND @srd_apply_permission_duplicates=0 AND @srd_apply_advisor_role=1 AND @srd_apply_advisor_mappings=2 AND @srd_apply_root_duplicates=0,'PASS','FAIL') result,CONCAT('course_registration=',@srd_apply_registration_type,'; advisor_role=',@srd_apply_advisor_role,'; advisor_mappings=',@srd_apply_advisor_mappings,'/2; duplicate_roots=',@srd_apply_root_duplicates) detail;
SELECT 'OVERALL' report_section,IF(@srd_apply_ready,'APPLIED','BLOCKED') result;
