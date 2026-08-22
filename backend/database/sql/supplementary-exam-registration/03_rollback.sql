-- Emergency-only Phase 4 rollback: optional-table safe, ownership-aware, adopted-object preserving, and history blocking.
SET @reg_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_registrations');
SET @event_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_registration_events');
SET @reg_owned := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_registrations' AND table_comment LIKE '%[phase4-supplementary-exam-registration]%');
SET @event_owned := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_registration_events' AND table_comment LIKE '%[phase4-supplementary-exam-registration]%');
SET @reg_rows:=0;SET @event_rows:=0;
SET @sql:=IF(@reg_exists,'SELECT COUNT(*) INTO @reg_rows FROM `alrowad_uni_rust`.`supplementary_exam_registrations`','SET @reg_rows=0');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
SET @sql:=IF(@event_exists,'SELECT COUNT(*) INTO @event_rows FROM `alrowad_uni_rust`.`supplementary_exam_registration_events`','SET @event_rows=0');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
SET @in_use:=@reg_rows>0 OR @event_rows>0;
SET @sql:=IF(NOT @in_use AND @event_exists AND @event_owned,'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_registration_events`','SELECT ''EVENTS_PRESERVED''');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
SET @sql:=IF(NOT @in_use AND @reg_exists AND @reg_owned AND (NOT @event_exists OR @event_owned),'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_registrations`','SELECT ''REGISTRATIONS_PRESERVED''');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
DELETE rp FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id WHERE NOT @in_use AND p.permission_code LIKE 'supplementary_exams.registrations.%' AND p.description LIKE '%[phase4-supplementary-exam-registration]%';
DELETE FROM `alrowad_uni_rust`.`permissions` WHERE NOT @in_use AND permission_code LIKE 'supplementary_exams.registrations.%' AND description LIKE '%[phase4-supplementary-exam-registration]%';
SELECT 'ROLLBACK_RESULT' AS report_section,IF(@in_use,'BLOCKED_IN_USE','ROLLED_BACK_OR_PRESERVED') AS result,@reg_owned AS registrations_owned,@event_owned AS events_owned;
