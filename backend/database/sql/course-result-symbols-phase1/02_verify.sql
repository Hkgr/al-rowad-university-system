-- READ ONLY structural verification. Runtime evidence matching is enforced by
-- IncompleteOutcomeGuard under the same locks as finalization/settlement.
-- Never repairs grants or historic results.
SET @crs_tables=(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE='InnoDB' AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events') AND TABLE_COMMENT='course-result-symbols-phase1-v1');
SET @crs_nullable=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_results' AND COLUMN_NAME IN ('theoretical_total','practical_total','final_mark') AND COLUMN_TYPE='decimal(5,2)' AND IS_NULLABLE='YES');
SET @crs_fks=(SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('student_course_incomplete_cases','student_course_incomplete_events') AND DELETE_RULE IN ('RESTRICT','NO ACTION'));
SET @crs_triggers=(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('crs_events_immutable_u','crs_events_immutable_d','crs_case_history_u','crs_case_history_d'));
SET @crs_columns=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (
 (TABLE_NAME='student_course_incomplete_cases' AND COLUMN_NAME IN ('incomplete_case_id','student_course_registration_id','student_course_result_id','student_id','course_id','academic_year_id','semester_id','reason','requirements','granted_at','granted_by_user_id','deadline_at','deadline_source','calendar_snapshot','expiry_outcome','state','revision','outcome_snapshot','settled_at','created_at','updated_at')) OR
 (TABLE_NAME='student_course_incomplete_events' AND COLUMN_NAME IN ('incomplete_event_id','incomplete_case_id','revision','action','request_id','request_hash','actor_kind','actor_user_id','before_values','after_values','response_snapshot','performed_at'))));
SELECT 'OWNED_TABLES' AS check_name,@crs_tables AS observed UNION ALL SELECT 'NULLABLE_MARKS',@crs_nullable UNION ALL SELECT 'RESTRICTIVE_FKS',@crs_fks UNION ALL SELECT 'APPEND_ONLY_TRIGGERS',@crs_triggers UNION ALL SELECT 'REQUIRED_COLUMNS',@crs_columns;
SELECT 'OVERALL' AS check_name, IF(@crs_tables=2 AND @crs_nullable=3 AND @crs_fks=9 AND @crs_triggers=4 AND @crs_columns=33,'PASS','FAIL') AS result;
