-- READ ONLY. Accept deployment only when the final row is OVERALL | PASS.
USE `alrowad_uni_rust`;

SET @srd_verify_columns := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND data_type='datetime' AND is_nullable='YES' AND (
  (table_name='academic_calendar_event_versions' AND column_name IN ('student_registration_ends_at','advisor_approval_ends_at')) OR
  (table_name='student_registration_requests' AND column_name='expired_at')
));
SET @srd_verify_comments := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND column_comment LIKE 'Owned by semester-registration-deadlines-phase2%' AND (
  (table_name='academic_calendar_event_versions' AND column_name IN ('student_registration_ends_at','advisor_approval_ends_at')) OR
  (table_name='student_registration_requests' AND column_name='expired_at')
));
SET @srd_verify_runtime_contract := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='alrowad_uni_rust' AND (
  (table_name='student_registration_requests' AND column_name='status' AND data_type='varchar' AND character_maximum_length>=9) OR
  (table_name='student_registration_request_events' AND column_name='event_type' AND data_type='varchar' AND character_maximum_length>=16) OR
  (table_name='student_registration_request_events' AND column_name IN ('from_status','to_status') AND data_type='varchar' AND character_maximum_length>=9) OR
  (table_name='student_registration_request_events' AND column_name='actor_user_id' AND data_type='int' AND column_type NOT LIKE '%unsigned%' AND is_nullable='YES')
));
SET @srd_verify_registration_type := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_types` WHERE event_type_code='course_registration' AND is_active=1);
SET @srd_verify_operational_year := ((SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years` WHERE is_current=1)=1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years` WHERE calendar_lifecycle_status='active')=1 AND (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_years` WHERE is_current=1 AND is_active=1 AND calendar_lifecycle_status='active')=1);
SET @srd_verify_permission_codes := (SELECT COUNT(DISTINCT permission_code) FROM `alrowad_uni_rust`.`permissions` WHERE permission_code IN ('registration_requests.view','registration_requests.review') AND is_active=1);
SET @srd_verify_advisor_mappings := (SELECT COUNT(DISTINCT p.permission_code) FROM `alrowad_uni_rust`.`roles` r JOIN `alrowad_uni_rust`.`role_permissions` rp ON rp.role_id=r.role_id JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id WHERE r.role_code='academic_advisor' AND r.is_active=1 AND p.is_active=1 AND p.permission_code IN ('registration_requests.view','registration_requests.review'));
SET @srd_verify_phase1_tables := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN ('semester_offering_requests','semester_offering_reviews','semester_offering_events'));
SET @srd_verify_deadline_conflicts := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions` v JOIN `alrowad_uni_rust`.`academic_calendar_events` e ON e.academic_calendar_event_id=v.academic_calendar_event_id JOIN `alrowad_uni_rust`.`academic_calendar_event_types` t ON t.academic_calendar_event_type_id=e.academic_calendar_event_type_id WHERE
  (t.event_type_code<>'course_registration' AND (v.student_registration_ends_at IS NOT NULL OR v.advisor_approval_ends_at IS NOT NULL)) OR
  (t.event_type_code='course_registration' AND ((v.student_registration_ends_at IS NULL)<>(v.advisor_approval_ends_at IS NULL) OR (v.student_registration_ends_at IS NOT NULL AND (v.starts_at>v.student_registration_ends_at OR v.student_registration_ends_at>v.advisor_approval_ends_at OR v.ends_at<>v.advisor_approval_ends_at))))
);
SET @srd_verify_request_conflicts := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`student_registration_requests` WHERE (status='expired' AND expired_at IS NULL) OR (status<>'expired' AND expired_at IS NOT NULL));
SET @srd_verify_root_duplicates := (SELECT COUNT(*) FROM (SELECT e.academic_year_id,e.semester_id FROM `alrowad_uni_rust`.`academic_calendar_events` e JOIN `alrowad_uni_rust`.`academic_calendar_event_types` t ON t.academic_calendar_event_type_id=e.academic_calendar_event_type_id WHERE t.event_type_code='course_registration' AND e.cancelled_at IS NULL GROUP BY e.academic_year_id,e.semester_id HAVING COUNT(*)>1) duplicate_roots);
SET @srd_verify_legacy_rows := (SELECT COUNT(*) FROM `alrowad_uni_rust`.`academic_calendar_event_versions` v JOIN `alrowad_uni_rust`.`academic_calendar_events` e ON e.academic_calendar_event_id=v.academic_calendar_event_id JOIN `alrowad_uni_rust`.`academic_calendar_event_types` t ON t.academic_calendar_event_type_id=e.academic_calendar_event_type_id WHERE t.event_type_code='course_registration' AND v.student_registration_ends_at IS NULL AND v.advisor_approval_ends_at IS NULL);
SET @srd_verify_ready := (@srd_verify_columns=3 AND @srd_verify_comments=3 AND @srd_verify_runtime_contract=5 AND @srd_verify_registration_type=1 AND @srd_verify_operational_year=1 AND @srd_verify_permission_codes=2 AND @srd_verify_advisor_mappings=2 AND @srd_verify_phase1_tables=3 AND @srd_verify_deadline_conflicts=0 AND @srd_verify_request_conflicts=0 AND @srd_verify_root_duplicates=0);

SELECT 'PHASE2_COLUMNS' report_section,IF(@srd_verify_columns=3 AND @srd_verify_comments=3 AND @srd_verify_runtime_contract=5,'PASS','FAIL') result,CONCAT('compatible=',@srd_verify_columns,'/3; ownership=',@srd_verify_comments,'/3; runtime_columns=',@srd_verify_runtime_contract,'/5') detail;
SELECT 'PRESERVED_CONTRACTS' report_section,IF(@srd_verify_registration_type=1 AND @srd_verify_operational_year=1 AND @srd_verify_permission_codes=2 AND @srd_verify_advisor_mappings=2 AND @srd_verify_phase1_tables=3,'PASS','FAIL') result,CONCAT('course_registration=',@srd_verify_registration_type,'; operational_year=',@srd_verify_operational_year,'; advisor_permissions=',@srd_verify_permission_codes,'/2; advisor_mappings=',@srd_verify_advisor_mappings,'/2; phase1_tables=',@srd_verify_phase1_tables,'/3') detail;
SELECT 'DATA_COMPATIBILITY' report_section,IF(@srd_verify_deadline_conflicts=0 AND @srd_verify_request_conflicts=0 AND @srd_verify_root_duplicates=0,'PASS','FAIL') result,CONCAT('deadline_conflicts=',@srd_verify_deadline_conflicts,'; request_conflicts=',@srd_verify_request_conflicts,'; duplicate_roots=',@srd_verify_root_duplicates,'; legacy_fallback_rows=',@srd_verify_legacy_rows) detail;
SELECT 'OVERALL' report_section,IF(@srd_verify_ready,'PASS','FAIL') result;
