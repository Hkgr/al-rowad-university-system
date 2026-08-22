-- Phase 3 read-only preflight. Run manually in phpMyAdmin.
SET @required_tables := 19;
SELECT COUNT(DISTINCT table_name) INTO @present_tables FROM information_schema.tables WHERE table_schema = 'alrowad_uni_rust' AND table_name IN ('supplementary_exam_periods','supplementary_exam_period_events','supplementary_exam_offerings','supplementary_exam_offering_sources','supplementary_exam_offering_events','student_course_registrations','registration_statuses','student_course_results','result_statuses','grade_components','student_grade_components','grade_part_approvals','grade_approvals','approval_statuses','grading_policies','users','roles','permissions','role_permissions');
SET @phase2_ready := IF(@present_tables = @required_tables, 1, 0);
SELECT COUNT(*) INTO @required_statuses FROM (SELECT status_code FROM `alrowad_uni_rust`.`registration_statuses` WHERE status_code IN ('registered','completed') UNION ALL SELECT status_code FROM `alrowad_uni_rust`.`result_statuses` WHERE status_code IN ('failed','passed','deprived') UNION ALL SELECT status_code FROM `alrowad_uni_rust`.`approval_statuses` WHERE status_code='approved') s;
SELECT COUNT(*) INTO @policy_count FROM `alrowad_uni_rust`.`grading_policies` WHERE is_active=1 AND is_default=1;
SET @grade_contract_ready := IF(@required_statuses=6 AND @policy_count=1,1,0);
SELECT COUNT(*) INTO @target_count FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN ('supplementary_exam_theoretical_deferrals','supplementary_exam_theoretical_deferral_events');
SET @target_schema_safe := IF(@target_count=0,1,IF(@target_count=2,1,0));
SELECT COUNT(*) INTO @role_count FROM `alrowad_uni_rust`.`roles` WHERE is_active=1 AND role_code IN ('student','dean','registration_officer','exam_officer','vice_president_scientific');
SET @rbac_safe := IF(@role_count=5,1,0);
SET @overall := IF(@phase2_ready=1 AND @grade_contract_ready=1 AND @target_schema_safe=1 AND @rbac_safe=1,'READY','BLOCKED');
SET @blocker_code := IF(@phase2_ready=0,'PHASE2_NOT_DEPLOYED',IF(@grade_contract_ready=0,'GRADE_CONTRACT_INCOMPLETE',IF(@target_schema_safe=0,'TARGET_SCHEMA_CONFLICT',IF(@rbac_safe=0,'RBAC_INCOMPLETE',NULL))));
SELECT 'OVERALL' AS report_section,@overall AS result,@blocker_code AS blocker_code,@phase2_ready AS phase2_ready,@grade_contract_ready AS grade_contract_ready,@target_schema_safe AS target_schema_safe,@rbac_safe AS rbac_safe;
