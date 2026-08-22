-- Emergency, partial-deployment-safe rollback. Optional tables are never referenced statically.
SELECT COUNT(*) INTO @deferral_exists FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_theoretical_deferrals' AND table_type='BASE TABLE';
SELECT COUNT(*) INTO @event_exists FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_theoretical_deferral_events' AND table_type='BASE TABLE';
SELECT COUNT(*) INTO @deferral_owned FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_theoretical_deferrals' AND table_comment LIKE '%[phase3-supplementary-exam-eligibility]%';
SELECT COUNT(*) INTO @event_owned FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_theoretical_deferral_events' AND table_comment LIKE '%[phase3-supplementary-exam-eligibility]%';
SET @deferral_rows:=0;SET @event_rows:=0;
SET @sql:=IF(@deferral_exists=1,'SELECT COUNT(*) INTO @deferral_rows FROM `alrowad_uni_rust`.`supplementary_exam_theoretical_deferrals`','SET @deferral_rows=0');PREPARE row_stmt FROM @sql;EXECUTE row_stmt;DEALLOCATE PREPARE row_stmt;
SET @sql:=IF(@event_exists=1,'SELECT COUNT(*) INTO @event_rows FROM `alrowad_uni_rust`.`supplementary_exam_theoretical_deferral_events`','SET @event_rows=0');PREPARE row_stmt FROM @sql;EXECUTE row_stmt;DEALLOCATE PREPARE row_stmt;
SET @blocked_in_use:=@deferral_rows>0 OR @event_rows>0;
SET @sql:=IF(NOT @blocked_in_use AND @event_exists=1 AND @event_owned=1,'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_theoretical_deferral_events`','SELECT ''PRESERVED_EVENTS''');PREPARE drop_stmt FROM @sql;EXECUTE drop_stmt;DEALLOCATE PREPARE drop_stmt;
SET @sql:=IF(NOT @blocked_in_use AND @deferral_exists=1 AND @deferral_owned=1 AND (@event_exists=0 OR @event_owned=1),'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_theoretical_deferrals`','SELECT ''PRESERVED_DEFERRALS''');PREPARE drop_stmt FROM @sql;EXECUTE drop_stmt;DEALLOCATE PREPARE drop_stmt;
START TRANSACTION;
DELETE rp FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id WHERE NOT @blocked_in_use AND p.permission_code IN ('supplementary_exams.eligibility.view','supplementary_exams.deferrals.self') AND p.description LIKE '%[phase3-supplementary-exam-eligibility]%';
DELETE FROM `alrowad_uni_rust`.`permissions` WHERE NOT @blocked_in_use AND permission_code IN ('supplementary_exams.eligibility.view','supplementary_exams.deferrals.self') AND description LIKE '%[phase3-supplementary-exam-eligibility]%';
COMMIT;
SELECT 'ROLLBACK_RESULT' AS report_section,IF(@blocked_in_use,'BLOCKED_IN_USE','ROLLED_BACK_OR_PRESERVED') AS result,@deferral_exists AS deferral_table_existed,@event_exists AS event_table_existed,@deferral_owned AS deferral_owned,@event_owned AS event_owned;
