<?php

/** Deterministic mechanical renderer; --check is read-only, --write renders the
 * reviewed manual package. Never connects to or applies anything to a database.
 */
require dirname(__DIR__).'/app/Support/IncompleteSchemaContract.php';
use App\Support\IncompleteSchemaContract as Contract;

$directory=dirname(__DIR__).'/database/sql/course-result-symbols-phase1/';
$partial=Contract::issuesSql(false,false); $complete=Contract::issuesSql(true,true);
$count=fn($sql)=>"(SELECT COUNT(*) FROM (\n$sql\n) crs_issues)";
$header="-- Rendered from app/Support/IncompleteSchemaContract.php by tools/render-incomplete-schema.php.\n-- Selected database only; maintenance must stop affected writers and workers. No production execution by this tool.\n";
$prerequisites=<<<'SQL'
SET @crs_db=DATABASE();
SET @crs_core=(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@crs_db AND ENGINE='InnoDB' AND TABLE_NAME IN
 ('students','users','courses','academic_years','semesters','student_course_registrations','student_course_results','result_statuses','grade_components','student_grade_components','grade_audit_logs','grade_approvals','grade_part_approvals'));
SET @crs_keys=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@crs_db AND DATA_TYPE='int' AND COLUMN_TYPE NOT LIKE '%unsigned%' AND IS_NULLABLE='NO' AND COLUMN_KEY='PRI' AND
 ((TABLE_NAME='students' AND COLUMN_NAME='student_id') OR (TABLE_NAME='users' AND COLUMN_NAME='user_id') OR (TABLE_NAME='courses' AND COLUMN_NAME='course_id') OR
 (TABLE_NAME='academic_years' AND COLUMN_NAME='academic_year_id') OR (TABLE_NAME='semesters' AND COLUMN_NAME='semester_id') OR
 (TABLE_NAME='student_course_registrations' AND COLUMN_NAME='student_course_registration_id') OR (TABLE_NAME='student_course_results' AND COLUMN_NAME='student_course_result_id')));
SET @crs_status_sql=IF(@crs_core=13,'SELECT COUNT(*) INTO @crs_statuses FROM result_statuses WHERE status_code IN (''passed'',''failed'',''deprived'',''incomplete'',''pending_approval'') AND is_active=1','SET @crs_statuses=0');
PREPARE crs_status_check FROM @crs_status_sql;
EXECUTE crs_status_check;
DEALLOCATE PREPARE crs_status_check;
SQL;
$preflight=$header."-- READ ONLY: compare all existing owned definitions before mutation. Missing compatible objects may resume.\n".$prerequisites."\n"
    ."SELECT 'DATABASE' AS check_name,@crs_db AS observed,VERSION() AS database_version;\n".$partial.";\n"
    .'SET @crs_schema_issues='.$count($partial).";\n"
    ."SELECT 'OVERALL' AS check_name,IF(@crs_db IS NOT NULL AND @crs_core=13 AND @crs_keys=7 AND @crs_statuses=5 AND @crs_schema_issues=0,'READY','BLOCKED') AS result;\n";

$tables='';
foreach(Contract::columns() as $table=>$columns){
    $lines=[];
    foreach($columns as $name=>$spec)$lines[]=' '.$name.' '.strtoupper($spec[0]).(($spec[1]??false)?' NULL DEFAULT NULL':' NOT NULL').(($spec[2]??false)?' AUTO_INCREMENT':'');
    foreach(Contract::indexes()[$table] as $name=>$spec)$lines[]=$name==='PRIMARY'?' PRIMARY KEY('.$spec[1].')':' '.($spec[0]?'KEY':'UNIQUE KEY').' '.$name.'('.$spec[1].')';
    foreach(Contract::foreignKeys()[$table] as $name=>$spec)$lines[]=" CONSTRAINT $name FOREIGN KEY($spec[0]) REFERENCES $spec[1]($spec[2]) ON DELETE RESTRICT ON UPDATE RESTRICT";
    foreach(Contract::checks()[$table] as $name=>$body)$lines[]=" CONSTRAINT $name CHECK($body)";
    $tables.=" CREATE TABLE IF NOT EXISTS $table (\n".implode(",\n",$lines)."\n ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='".Contract::OWNER."';\n";
}
$triggerCreates='';
foreach(Contract::triggers() as $name=>$spec){
    $ddl="CREATE TRIGGER $name BEFORE $spec[1] ON $spec[0] FOR EACH ROW $spec[2]";
    $triggerCreates.=" IF NOT EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name') THEN\n  EXECUTE IMMEDIATE ".Contract::literal($ddl).";\n END IF;\n";
}
$apply=$header."-- Sole installation owner. No migration/seeder/backfill. DDL is not transactional.\n"
    ."SET @crs_apply_result=NULL;\n".$prerequisites."\nSET @crs_schema_issues=".$count($partial).";\n"
    ."DELIMITER $$\nBEGIN NOT ATOMIC\n DECLARE prior_count INT DEFAULT 0;\n DECLARE prior_triggers INT DEFAULT 0;\n DECLARE was_ready INT DEFAULT 0;\n"
    ." IF @crs_db IS NULL OR @crs_core<>13 OR @crs_keys<>7 OR @crs_statuses<>5 OR @crs_schema_issues<>0 THEN\n  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='course_result_symbols_schema_incompatible';\n END IF;\n"
    ." SELECT COUNT(*) INTO prior_count FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('".Contract::CASES."','".Contract::EVENTS."');\n"
    ." SELECT COUNT(*) INTO prior_triggers FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('crs_events_immutable_u','crs_events_immutable_d','crs_case_history_u','crs_case_history_d');\n"
    ." SELECT COUNT(*) INTO was_ready FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_results' AND COLUMN_NAME IN ('theoretical_total','practical_total','final_mark') AND IS_NULLABLE='YES';\n"
    .$tables
    ." IF was_ready<>3 THEN\n  ALTER TABLE student_course_results MODIFY theoretical_total DECIMAL(5,2) NULL DEFAULT NULL, MODIFY practical_total DECIMAL(5,2) NULL DEFAULT NULL, MODIFY final_mark DECIMAL(5,2) NULL DEFAULT NULL;\n END IF;\n"
    ." -- Never replace any existing trigger: it was recognized above or the whole block refused.\n".$triggerCreates
    ." SET @crs_apply_result=CASE WHEN prior_count=0 THEN 'APPLIED' WHEN prior_count<2 OR was_ready<>3 OR prior_triggers<>4 THEN 'RESUMED' ELSE 'ALREADY_APPLIED' END;\nEND$$\nDELIMITER ;\nSELECT @crs_apply_result AS result;\n";
$verify=$header."-- READ ONLY; require complete exact definitions, never repair academic evidence.\n".$complete.";\n"
    .'SET @crs_schema_issues='.$count($complete).";\nSELECT 'OVERALL' AS check_name,IF(@crs_schema_issues=0,'PASS','FAIL') AS result;\n";
$files=['00_preflight.sql'=>$preflight,'01_apply.sql'=>$apply,'02_verify.sql'=>$verify];
$write=($argv[1]??'')==='--write';$different=[];
foreach($files as $name=>$text){
    if($write)file_put_contents($directory.$name,$text);
    elseif(!is_file($directory.$name)||str_replace("\r\n","\n",file_get_contents($directory.$name))!==$text)$different[]=$name;
}
if($different){fwrite(STDERR,'Schema package differs from manifest: '.implode(', ',$different)."\n");exit(1);}
echo $write?"Manual SQL rendered only; nothing applied.\n":"Incomplete schema package matches manifest.\n";
