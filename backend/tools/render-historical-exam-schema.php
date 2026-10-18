<?php

/** Deterministic SQL rendering only. No database connection or source-dump execution. */
require dirname(__DIR__).'/app/Support/HistoricalExamSchema.php';
use App\Support\HistoricalExamSchema as Contract;

$directory=dirname(__DIR__).'/database/sql/historical-exam-approved-reconciliation/';
$header="-- Rendered by tools/render-historical-exam-schema.php; no migration or source-data import.\n-- Selected isolated/reviewed database only. Stop affected writers/workers during installation.\n";
$partial=Contract::issuesSql(false);$complete=Contract::issuesSql(true);
$count=fn($sql)=>"(SELECT COUNT(*) FROM (\n$sql\n) hx_issues)";
$prerequisites=<<<'SQL'
SET @hx_db=DATABASE();
SET @hx_core=(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@hx_db AND ENGINE='InnoDB' AND TABLE_NAME IN ('users','students','courses','academic_programs','academic_years','semesters','course_offerings','student_course_registrations','academic_plan_events','program_courses','academic_plan_versions','academic_catalog_control'));
SET @hx_keys=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@hx_db AND IS_NULLABLE='NO' AND COLUMN_KEY='PRI' AND COLUMN_TYPE NOT LIKE '%unsigned%' AND ((DATA_TYPE='int' AND ((TABLE_NAME='users' AND COLUMN_NAME='user_id') OR (TABLE_NAME='students' AND COLUMN_NAME='student_id') OR (TABLE_NAME='courses' AND COLUMN_NAME='course_id') OR (TABLE_NAME='academic_programs' AND COLUMN_NAME='academic_program_id') OR (TABLE_NAME='academic_years' AND COLUMN_NAME='academic_year_id') OR (TABLE_NAME='semesters' AND COLUMN_NAME='semester_id') OR (TABLE_NAME='course_offerings' AND COLUMN_NAME='course_offering_id') OR (TABLE_NAME='student_course_registrations' AND COLUMN_NAME='student_course_registration_id') OR (TABLE_NAME='program_courses' AND COLUMN_NAME='program_course_id'))) OR (DATA_TYPE='bigint' AND TABLE_NAME='academic_plan_events' AND COLUMN_NAME='academic_plan_event_id')));
SET @hx_control_sql=IF(@hx_core=12,'SELECT COUNT(*) INTO @hx_control FROM academic_catalog_control WHERE control_id=1 AND schema_version=1 AND is_ready=1','SET @hx_control=0');
PREPARE hx_control_read FROM @hx_control_sql;
EXECUTE hx_control_read;
DEALLOCATE PREPARE hx_control_read;
SQL;
$tables='';$triggers='';
foreach(Contract::columns() as $table=>$columns) {
    $lines=[];
    foreach($columns as $name=>$spec)$lines[]=' '.$name.' '.strtoupper($spec[0]).(($spec[1]??false)?' NULL DEFAULT NULL':' NOT NULL').(($spec[2]??false)?' AUTO_INCREMENT':'');
    foreach(Contract::indexes()[$table] as $name=>$spec)$lines[]=$name==='PRIMARY'?' PRIMARY KEY('.$spec[1].')':' '.($spec[0]?'KEY':'UNIQUE KEY').' '.$name.'('.$spec[1].')';
    foreach(Contract::foreignKeys()[$table] as $name=>$spec)$lines[]=" CONSTRAINT $name FOREIGN KEY($spec[0]) REFERENCES $spec[1]($spec[2]) ON DELETE RESTRICT ON UPDATE RESTRICT";
    foreach(Contract::checks()[$table] as $name=>$body)$lines[]=" CONSTRAINT $name CHECK($body)";
    $tables.=" CREATE TABLE IF NOT EXISTS $table (\n".implode(",\n",$lines)."\n ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='".Contract::OWNER."';\n";
}
foreach(Contract::triggers() as $name=>[$table,$operation,$body]) {
    $sql="CREATE TRIGGER $name BEFORE $operation ON $table FOR EACH ROW $body";
    $triggers.=" IF NOT EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name') THEN\n  EXECUTE IMMEDIATE ".Contract::literal($sql).";\n END IF;\n";
}
$guard=" IF @hx_db IS NULL OR @hx_core<>12 OR @hx_keys<>10 OR @hx_control<>1 OR @hx_issues<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='historical_exam_schema_incompatible'; END IF;\n";
$preflight=$header."-- READ ONLY; inspect existing owned definitions before any mutation.\n".$prerequisites."\n".$partial.";\nSET @hx_issues=".$count($partial).";\nSELECT VERSION() AS server_version,IF(@hx_db IS NOT NULL AND @hx_core=12 AND @hx_keys=10 AND @hx_control=1 AND @hx_issues=0,'READY','BLOCKED') AS result;\n";
$apply=$header."-- Sole schema owner. DDL is not transactional; compatible missing objects may resume.\n".$prerequisites."\nSET @hx_issues=".$count($partial).";\nDELIMITER $$\nBEGIN NOT ATOMIC\n".$guard.$tables." -- Never overwrite a trigger: every existing owned definition was inspected first.\n".$triggers."END$$\nDELIMITER ;\nSELECT 'APPLIED_OR_ALREADY_COMPATIBLE' AS result;\n";
$verify=$header."-- READ ONLY; full runtime contract, not a name/count check.\n".$complete.";\nSET @hx_issues=".$count($complete).";\nSELECT VERSION() AS server_version,IF(@hx_issues=0,'PASS','FAIL') AS result;\n";
$rollback=$header."-- Refuse rollback whenever real evidence exists; never discard receipts/history or invent marks.\n".$prerequisites."\nSET @hx_issues=".$count($partial).";\nSET @hx_history=0;\n";
foreach(array_keys(Contract::columns()) as $table)$rollback.="SET @hx_read=IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'),'SELECT @hx_history+COUNT(*) INTO @hx_history FROM $table','SET @hx_history=@hx_history');\nPREPARE hx_history_read FROM @hx_read;\nEXECUTE hx_history_read;\nDEALLOCATE PREPARE hx_history_read;\n";
$rollback.="DELIMITER $$\nBEGIN NOT ATOMIC\n".$guard." IF @hx_history<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='historical_exam_rollback_would_erase_history'; END IF;\n";
foreach(array_reverse(array_keys(Contract::columns())) as $table)$rollback.=" DROP TABLE IF EXISTS $table;\n";
$rollback.="END$$\nDELIMITER ;\nSELECT 'EMPTY_PACKAGE_REMOVED_ONLY' AS result;\n";
$files=['00_preflight.sql'=>$preflight,'01_apply.sql'=>$apply,'02_verify.sql'=>$verify,'03_rollback.sql'=>$rollback];
$write=($argv[1]??'')==='--write';$different=[];
if($write&&!is_dir($directory))mkdir($directory,0770,true);
foreach($files as $name=>$text) {
    if($write)file_put_contents($directory.$name,$text);
    elseif(!is_file($directory.$name)||str_replace("\r\n","\n",file_get_contents($directory.$name))!==$text)$different[]=$name;
}
if($different){fwrite(STDERR,'Historical exam SQL differs from manifest: '.implode(', ',$different)."\n");exit(1);}
echo $write?"Manual SQL rendered only; nothing applied.\n":"Historical exam SQL matches manifest.\n";
