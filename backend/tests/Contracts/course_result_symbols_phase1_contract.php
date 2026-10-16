<?php

/** Dependency-free source/SQL contract. Not a substitute for HTTP/MariaDB behavior tests. */
require_once dirname(__DIR__,2).'/app/Support/OfficialGradeScale.php';
$root=dirname(__DIR__,2);
$failures=[];
$check=function(bool $ok,string $name)use(&$failures){if(!$ok)$failures[]=$name;};
$read=fn($path)=>file_get_contents($root.'/'.$path);
$scale=App\Support\OfficialGradeScale::class;
$check(!in_array('deprived',$scale::EXCLUDED_STATUSES,true),'Z denominator eligibility');
$check(($scale::points('B','passed')*3+$scale::points('Z','deprived')*3)/6===1.5,'B + Z example');
$check($scale::points('B','deprived')===0.0,'Deprivation overrides retained numeric grade');
$guard=$read('app/Support/IncompleteOutcomeGuard.php');
$check(str_contains($guard,'incomplete_outcome_locked')&&str_contains($guard,"'resolved'")&&str_contains($guard,"'expired'"),'All-state authoritative guard');
$service=$read('app/Services/IncompleteResultService.php');
$check(strpos($service,"where('request_id'")<strpos($service,"input['expected_revision']"),'Idempotency replay before revision');
$check(str_contains($service,'actorId === null ? \'system\'')&&str_contains($service,"'final_mark' => null"),'System expiration and nullable final');
$check(str_contains($service,'ExamManualGradeEntryAccess')&&!str_contains($service,'GradeApproval::'),'Scoped authority; no fabricated approval');
$parts=$read('app/Services/GradePartWorkflowService.php');
$check(str_contains($parts,'preservesOutcome($registration, true)')&&str_contains($parts,'ordinaryComponents(StudentGradeComponent::query())'),'Preserved finalization and components');
foreach(['StudentCourseResult','StudentGradeComponent','StudentCourseRegistration']as$model)$check(str_contains($read('app/Models/'.$model.'.php'),'IncompleteOutcomeGuard::assertOrdinaryWrite'),'Legacy model guard '.$model);
$sql=$read('database/sql/course-result-symbols-phase1/01_apply.sql');
$check(substr_count($sql,'CREATE TABLE IF NOT EXISTS')===2,'Two business tables only');
$check(!preg_match('/CASCADE|INSERT\s+INTO\s+(permissions|role_permissions|student_course_results)/i',$sql),'No cascade/RBAC writes/backfill');
$check(str_contains($sql,'ON DELETE RESTRICT')&&str_contains($sql,'incomplete_case_history_immutable')&&str_contains($sql,'incomplete_events_append_only'),'History constraints');
$check(!str_contains($sql,'CREATE OR REPLACE TRIGGER')&&str_contains($sql,'course_result_symbols_schema_incompatible'),'Reject incompatible definitions, never replace unrecognized triggers');
$manifest=$read('app/Support/IncompleteSchemaContract.php');
foreach(['COLUMN_TYPE','IS_NULLABLE','SEQ_IN_INDEX','REFERENCED_TABLE_SCHEMA','DELETE_RULE','UPDATE_RULE','CHECK_CLAUSE','ACTION_STATEMENT','ACTION_TIMING','EVENT_OBJECT_TABLE']as$field)$check(str_contains($manifest,$field),'Schema manifest validates '.$field);
$check(str_contains($guard,'IncompleteSchemaContract::issuesSql'),'Runtime uses the same exact schema contract');
$check(str_contains($guard,'incomplete_registration_transition_locked')&&str_contains($read('app/Services/RegistrationService.php'),"assertRegistrationTransition"),'Canonical registration transitions respect ownership');
$check(str_contains($read('app/Console/Commands/ExpireIncompleteResults.php'),"\$cache->forever(\$key")&&str_contains($read('app/Console/Commands/ExpireIncompleteResults.php'),'persistent cache'),'Bounded durable scan cursor is not case history');
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/render-incomplete-schema.php').' --check',$rendererOutput,$rendererExit);
$check($rendererExit===0,'Manual SQL is rendered from the runtime manifest without drift');
$check(str_contains($read('database/sql/course-result-symbols-phase1/03_rollback.sql'),'rollback_refused'),'Rollback refusal');
$check(str_contains($read('routes/api.php'),"prefix('exams/incomplete-results')"),'Scoped API exposure');
$check(str_contains($read('routes/console.php'),"everyMinute()->withoutOverlapping()"),'Bounded scheduled expiration');
$check(str_contains($read('app/Services/ExecutiveReportQueryService.php'),'OfficialGradeScale::gpaEligibilitySql'),'PHP/SQL eligibility parity');
$check(!preg_match('/CALL\s+(calculate_final_grade|mark_deprived_students)\b/i',$service.$parts.$read('app/Services/GradeService.php')),'No active legacy routine integration');
if($failures){fwrite(STDERR,implode("\n",$failures)."\n");exit(1);} echo "course_result_symbols_phase1_contract: PASS (source/SQL checks only)\n";
