-- Emergency only. Never touches regular results or any Phase 1-4 object.
SET @owned := (SELECT COUNT(*)=4 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN ('supplementary_exam_grader_assignments','supplementary_exam_grade_results','supplementary_exam_grade_submissions','supplementary_exam_grade_events') AND table_comment='owned:supplementary-exam-grading-phase5');
SET @history := 0;
SET @sql:=IF(EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_grade_events'),'SELECT COUNT(*) INTO @history FROM `alrowad_uni_rust`.`supplementary_exam_grade_events`','SET @history=0');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
SET @assignments := 0;
SET @sql:=IF(EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_grader_assignments'),'SELECT COUNT(*) INTO @assignments FROM `alrowad_uni_rust`.`supplementary_exam_grader_assignments`','SET @assignments=0');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
SET @in_use := @history + @assignments;
SET @can_rollback := @owned AND @in_use=0;
SET @sql:=IF(@can_rollback,'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_grade_events`','SELECT ''PRESERVED''');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
SET @sql:=IF(@can_rollback,'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_grade_results`','SELECT ''PRESERVED''');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
SET @sql:=IF(@can_rollback,'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_grade_submissions`','SELECT ''PRESERVED''');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
SET @sql:=IF(@can_rollback,'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_grader_assignments`','SELECT ''PRESERVED''');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
START TRANSACTION;
DELETE rp FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id WHERE @can_rollback AND p.permission_code IN ('supplementary_exams.grades.view','supplementary_exams.grades.assign','supplementary_exams.grades.enter','supplementary_exams.grades.review','supplementary_exams.grades.publish');
DELETE FROM `alrowad_uni_rust`.`permissions` WHERE @can_rollback AND permission_code IN ('supplementary_exams.grades.view','supplementary_exams.grades.assign','supplementary_exams.grades.enter','supplementary_exams.grades.review','supplementary_exams.grades.publish');
COMMIT;
SELECT 'ROLLBACK_RESULT' report_section,IF(@in_use>0,'BLOCKED_IN_USE',IF(@can_rollback,'ROLLED_BACK','PRESERVED_NOT_OWNED')) result;
