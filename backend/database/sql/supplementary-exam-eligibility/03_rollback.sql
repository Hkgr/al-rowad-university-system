-- Emergency rollback: student decisions and adopted objects are never deleted.
SELECT COUNT(*) INTO @deferrals FROM `alrowad_uni_rust`.`supplementary_exam_theoretical_deferrals`;
SELECT COUNT(*) INTO @events FROM `alrowad_uni_rust`.`supplementary_exam_theoretical_deferral_events`;
SELECT COUNT(*) INTO @owned_tables FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name IN ('supplementary_exam_theoretical_deferrals','supplementary_exam_theoretical_deferral_events') AND table_comment LIKE '%[phase3-supplementary-exam-eligibility]%';
SET @safe := @deferrals=0 AND @events=0 AND @owned_tables=2;
SET @ddl:=IF(@safe,'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_theoretical_deferral_events`','SELECT 0');PREPARE stmt FROM @ddl;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @ddl:=IF(@safe,'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_theoretical_deferrals`','SELECT 0');PREPARE stmt FROM @ddl;EXECUTE stmt;DEALLOCATE PREPARE stmt;
DELETE rp FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id WHERE @safe AND p.permission_code IN ('supplementary_exams.eligibility.view','supplementary_exams.deferrals.self') AND p.description LIKE '%[phase3-supplementary-exam-eligibility]%';
DELETE FROM `alrowad_uni_rust`.`permissions` WHERE @safe AND permission_code IN ('supplementary_exams.eligibility.view','supplementary_exams.deferrals.self') AND description LIKE '%[phase3-supplementary-exam-eligibility]%';
SELECT 'ROLLBACK_RESULT' AS report_section,IF(@safe,'ROLLED_BACK','BLOCKED_IN_USE') AS result;
