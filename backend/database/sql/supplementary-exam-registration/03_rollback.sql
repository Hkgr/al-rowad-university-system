-- Emergency-only Phase 4 rollback: optional-table safe, ownership-aware, adopted-object preserving, and history blocking.
SET @reg_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_registrations');
SET @event_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_registration_events');
SET @reg_owned := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_registrations' AND table_comment LIKE '%[phase4-supplementary-exam-registration]%');
SET @event_owned := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='alrowad_uni_rust' AND table_name='supplementary_exam_registration_events' AND table_comment LIKE '%[phase4-supplementary-exam-registration]%');
SET @reg_rows:=0;SET @event_rows:=0;
SET @sql:=IF(@reg_exists,'SELECT COUNT(*) INTO @reg_rows FROM `alrowad_uni_rust`.`supplementary_exam_registrations`','SET @reg_rows=0');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
SET @sql:=IF(@event_exists,'SELECT COUNT(*) INTO @event_rows FROM `alrowad_uni_rust`.`supplementary_exam_registration_events`','SET @event_rows=0');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
SET @in_use:=@reg_rows>0 OR @event_rows>0;
SET @event_action := IF(@event_exists=0,'ABSENT',IF(@in_use,'PRESERVED',IF(@event_owned,'DROPPED','PRESERVED')));
SET @reg_action := IF(@reg_exists=0,'ABSENT',IF(@in_use,'PRESERVED',IF(@reg_owned AND (NOT @event_exists OR @event_owned),'DROPPED','PRESERVED')));
SET @rbac_action := IF(@in_use,'PRESERVED','REMOVED_IF_OWNED');
SET @sql:=IF(NOT @in_use AND @event_exists AND @event_owned,'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_registration_events`','SELECT ''EVENTS_PRESERVED''');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
SET @sql:=IF(NOT @in_use AND @reg_exists AND @reg_owned AND (NOT @event_exists OR @event_owned),'DROP TABLE `alrowad_uni_rust`.`supplementary_exam_registrations`','SELECT ''REGISTRATIONS_PRESERVED''');PREPARE s FROM @sql;EXECUTE s;DEALLOCATE PREPARE s;
DELETE rp FROM `alrowad_uni_rust`.`role_permissions` rp JOIN `alrowad_uni_rust`.`permissions` p ON p.permission_id=rp.permission_id WHERE NOT @in_use AND p.permission_code LIKE 'supplementary_exams.registrations.%' AND p.description LIKE '%[phase4-supplementary-exam-registration]%';
DELETE FROM `alrowad_uni_rust`.`permissions` WHERE NOT @in_use AND permission_code LIKE 'supplementary_exams.registrations.%' AND description LIKE '%[phase4-supplementary-exam-registration]%';
SET @rollback_guard := IF(@in_use,'BLOCKED_IN_USE',IF((@reg_exists AND NOT @reg_owned) OR (@event_exists AND NOT @event_owned),'BLOCKED_ADOPTED',IF(@reg_exists=0 AND @event_exists=0,'NOTHING_TO_DO','SAFE')));
SET @rollback_result := IF(@in_use,'BLOCKED_IN_USE',IF((@reg_exists AND NOT @reg_owned) OR (@event_exists AND NOT @event_owned),'BLOCKED_ADOPTED',IF(@reg_exists=0 AND @event_exists=0,'NOTHING_TO_DO','ROLLED_BACK')));
SELECT 'REGISTRATIONS_TABLE_EXISTS' AS report_section,IF(@reg_exists,'YES','NO') AS result,CONCAT('rows=',@reg_rows) AS detail,'CONTINUE' AS next_action
UNION ALL SELECT 'REGISTRATION_EVENTS_TABLE_EXISTS',IF(@event_exists,'YES','NO'),CONCAT('rows=',@event_rows),'CONTINUE'
UNION ALL SELECT 'REGISTRATIONS_OWNERSHIP',IF(@reg_exists=0,'ABSENT',IF(@reg_owned,'OWNED','ADOPTED_OR_UNKNOWN')),IF(@reg_owned,'phase4 marker present','phase4 marker absent'),'CONTINUE'
UNION ALL SELECT 'REGISTRATION_EVENTS_OWNERSHIP',IF(@event_exists=0,'ABSENT',IF(@event_owned,'OWNED','ADOPTED_OR_UNKNOWN')),IF(@event_owned,'phase4 marker present','phase4 marker absent'),'CONTINUE'
UNION ALL SELECT 'ROLLBACK_GUARD',@rollback_guard,CONCAT('registration_rows=',@reg_rows,', event_rows=',@event_rows),IF(@rollback_guard='SAFE','CONTINUE','STOP - MANUAL REVIEW REQUIRED')
UNION ALL SELECT 'REGISTRATION_EVENTS_ACTION',@event_action,IF(@event_action='DROPPED','owned empty event table dropped','event table preserved'),'CONTINUE'
UNION ALL SELECT 'REGISTRATIONS_ACTION',@reg_action,IF(@reg_action='DROPPED','owned empty registration table dropped','registration table preserved'),'CONTINUE'
UNION ALL SELECT 'RBAC_ACTION',@rbac_action,IF(@in_use,'business data exists; RBAC preserved','owned Phase 4 RBAC removed when present'),'CONTINUE'
UNION ALL SELECT 'ROLLBACK_RESULT',@rollback_result,IF(@rollback_result='ROLLED_BACK','Phase 4 owned empty schema removed safely',IF(@rollback_result='NOTHING_TO_DO','No Phase 4 target tables were present','Rollback did not remove protected data/objects')),IF(@rollback_result IN ('ROLLED_BACK','NOTHING_TO_DO'),'DONE','STOP - MANUAL REVIEW REQUIRED');
