<?php

$contract = static function (string $backendRoot): array {
    $errors=[];
    $expect=static function(bool $condition,string $message)use(&$errors):void{if(!$condition)$errors[]=$message;};
    $read=static fn(string $path):string=>is_file($path)?(string)file_get_contents($path):'';
    $sqlRoot=$backendRoot.'/database/sql/semester-registration-minimum-cancellation-replacement-phase6';
    $files=is_dir($sqlRoot)?array_map('basename',glob($sqlRoot.'/*')?:[]):[];sort($files);
    $expect($files===['00_preflight.sql','01_apply.sql','02_verify.sql'],'Phase 6 SQL package must contain exactly preflight/apply/verify.');
    $preflight=$read($sqlRoot.'/00_preflight.sql');$apply=$read($sqlRoot.'/01_apply.sql');$verify=$read($sqlRoot.'/02_verify.sql');
    foreach([$preflight,$apply,$verify] as $sql){$expect(str_contains($sql,'alrowad_uni_rust'),'Every SQL file must target alrowad_uni_rust.');$expect(!preg_match('/\b(?:PROCEDURE|DELIMITER|SIGNAL|DATABASE\s*\()\b/i',$sql),'SQL must avoid procedures, DELIMITER, SIGNAL, and DATABASE().');$expect(!preg_match('/ON\s+DELETE\s+CASCADE/i',$sql),'Phase 6 foreign keys must be restrictive.');}
    $expect(substr_count(strtoupper($apply),'CREATE TABLE IF NOT EXISTS')===5,'Apply must create exactly five Phase 6 business tables.');
    foreach(['course_offering_minimum_enrollment_reviews','course_offering_minimum_enrollment_events','student_registration_replacement_requests','student_registration_replacement_items','student_registration_replacement_events'] as $table){$expect(str_contains($apply,$table)&&str_contains($verify,$table),"Missing table contract {$table}.");}
    foreach(['uq_srrpi_source_consumed','uq_srrpi_source_in_request','uq_srrpi_target_in_request','chk_srrpi_consumed','chk_srrpr_return','chk_srrpr_snapshot','chk_comer_dean_status'] as $marker)$expect(str_contains($apply,$marker)&&str_contains($verify,$marker),"Missing Phase 6 invariant {$marker}.");
    $expect(str_contains($apply,'`source_student_course_registration_id`,`source_consumed_slot`')&&str_contains($verify,"cols='source_student_course_registration_id,source_consumed_slot'"),'Consumed-source unique column order is incorrect.');
    $expect(!preg_match('/UNIQUE KEY[^\n]+\(`source_student_course_registration_id`\)\s*[,)]/i',$apply),'Source registration must not be globally unique merely for historical reference.');
    foreach(['target_pk','target_checks','target_fks','target_uniques'] as $guard)$expect(str_contains($preflight,$guard),"Preflight partial-schema compatibility is missing {$guard}.");
    foreach(['existing_pk','existing_checks','existing_fks','existing_uniques'] as $guard)$expect(str_contains($apply,$guard),"Apply partial-schema compatibility is missing {$guard}.");
    $expect(str_contains($apply,"SELECT 'course_registration_replacement'")&&str_contains($apply,'s.event_type_kind,s.default_is_enforcement,s.is_active'),'Replacement event type must clone canonical behavioral metadata.');
    $expect(str_contains($preflight,'SOURCE_EVENT_METADATA')&&str_contains($preflight,'event_type_kind,default_is_enforcement,is_active'),'Preflight must visibly report cloned source metadata.');
    $expect(str_contains($apply,"'cancelled','ملغى لعدم اكتمال الحد الأدنى'")&&str_contains($verify,"status_code='cancelled'"),'Cancelled status reference contract is missing.');
    $expect(!preg_match('/(?:permissions|role_permissions|user_roles)[^;]*(?:INSERT|UPDATE|DELETE)|(?:INSERT|UPDATE|DELETE)[^;]*(?:permissions|role_permissions|user_roles)/is',$apply),'Phase 6 must not write RBAC data.');
    $expect(str_contains($preflight,"SELECT 'OVERALL'")&&str_contains($preflight,"'READY','BLOCKED'"),'Preflight terminal result is missing.');
    foreach(['APPLIED','RESUMED','ALREADY_APPLIED'] as $result)$expect(str_contains($apply,$result),"Apply terminal result {$result} is missing.");
    $expect(str_contains($verify,"SELECT 'OVERALL'")&&str_contains($verify,"'PASS','FAIL'"),'Verify terminal result is missing.');

    $policy=$read($backendRoot.'/app/Services/AcademicCalendarPolicyService.php');$calendar=$read($backendRoot.'/app/Services/AcademicCalendarService.php');$minimum=$read($backendRoot.'/app/Services/MinimumEnrollmentReviewService.php');$materializer=$read($backendRoot.'/app/Services/MinimumEnrollmentCancellationMaterializer.php');$closure=$read($backendRoot.'/app/Services/CourseOfferingClosureWorkflowService.php');$replacement=$read($backendRoot.'/app/Services/RegistrationReplacementService.php');$phaseException=$read($backendRoot.'/app/Exceptions/SemesterRegistrationPhase6Exception.php');$registration=$read($backendRoot.'/app/Services/RegistrationService.php');$governance=$read($backendRoot.'/app/Services/SemesterOfferingGovernanceService.php');$routes=$read($backendRoot.'/routes/api.php');
    $expect(str_contains($policy,'courseRegistrationReplacementDeadlines')&&str_contains($policy,'registrationDeadlinesFor(self::COURSE_REGISTRATION_REPLACEMENT_EVENT_TYPE, false'),'Replacement deadlines must reject the original legacy fallback.');
    $expect(str_contains($policy,'courseRegistrationReplacementDeadlinesMissing')||str_contains($policy,'course_registration_replacement_deadlines_missing'),'Stable replacement deadline configuration reason is missing.');
    $expect(str_contains($minimum,"whereNotNull('minimum_enrollment')")&&str_contains($minimum,"\$offering->status === 'closed' ? 'superseded'")&&str_contains($minimum,'superseded_external_closure'),'Closed applicable Offerings must reconcile to terminal superseded history.');
    $expect(str_contains($minimum,'TERMINAL_MINIMUM_STATUSES')&&str_contains($minimum,'replacement_window_not_ready'),'Replacement publication readiness must wait for every minimum decision.');
    $expect(str_contains($calendar,'assertReplacementWindowReady')&&str_contains($calendar,"whereNotNull('first_submitted_at')"),'Calendar publication and post-submission freeze guards are missing.');
    $expect(str_contains($materializer,"transitionRegisteredToCancelled")&&str_contains($materializer,"status'=>'cancelled'")&&str_contains($materializer,'course_offering_closure_request_id'),'Only a linked formal closure may materialize cancellation.');
    $expect(str_contains($closure,"SemesterRegistrationPhase6::schemaReady()) \$relations[] = 'minimumEnrollmentReview'")&&str_contains($materializer,'if (! SemesterRegistrationPhase6::schemaReady()) return'),'Existing closure reads and ordinary closure must remain compatible before manual Phase 6 deployment.');
    $expect(str_contains($registration,'transitionRegisteredToCancelled')&&str_contains($registration,'CANCELLED_STATUS'),'Canonical cancelled transition is missing.');
    $expect(str_contains($replacement,"where('source_consumed_slot',1)")&&str_contains($replacement,"'source_consumed_slot'=>1")&&str_contains($phaseException,'replacement_source_already_consumed'),'Replacement source consumption must be rechecked and committed only with materialization.');
    $expect(str_contains($replacement,'materializeAdvisorApprovedReplacementItemWithinTransaction')&&str_contains($registration,'materializeAdvisorApprovedReplacementItemWithinTransaction'),'Trusted replacement materialization boundary is missing.');
    $expect(str_contains($replacement,"'expired'")&&str_contains($replacement,"'current_slot',1")&&str_contains($replacement,'$this->event($r,\'expired\''),'Replacement expiration must release the current slot without consuming historical sources.');
    $expect(str_contains($governance,'assertFinallyApprovedForReplacement'),'Replacement targets must retain final Phase 1 governance proof.');
    foreach(['dean/registration-offerings/minimum-enrollment','vice-presidency/scientific/semester-offerings/minimum-enrollment','student/registration/replacement','academic-advising/registration-replacements'] as $route)$expect(str_contains($routes,$route),"Missing Phase 6 route {$route}.");
    $expect(!str_contains($routes,'minimum_enrollment.manage')&&!str_contains($routes,'registration_replacement.manage'),'Phase 6 must add no permission code.');
    $expect((glob($backendRoot.'/database/migrations/*replacement*')?:[])===[]&&(glob($backendRoot.'/database/migrations/*minimum*enrollment*')?:[])===[],'Phase 6 must add no migrations.');
    $expect(!str_contains($replacement,'available_seats')&&!str_contains($replacement,'capacity'),'Replacement workflow must not implement seat capacity.');
    foreach(['GradeService.php','RegistrationWithdrawalService.php','AttendanceService.php'] as $unrelated){$source=$read($backendRoot.'/app/Services/'.$unrelated);$expect(!str_contains($source,'RegistrationReplacementService')&&!str_contains($source,'MinimumEnrollmentReviewService'),"{$unrelated} must remain independent.");}
    $behavior=$read($backendRoot.'/tests/Feature/SemesterRegistrationMinimumCancellationReplacementPhase6BehaviorTest.php');
    foreach(['test_closed_before_first_reconciliation_creates_terminal_superseded_without_cancellation_or_replacement_rights','test_expired_and_superseded_history_can_reuse_source_until_one_materialization_consumes_it','test_ordinary_closure_without_a_linked_phase6_review_does_not_cancel_registrations','test_linked_formal_minimum_closure_cancels_current_rows_and_finalizes_review_atomically'] as $name)$expect(str_contains($behavior,$name),"Missing real behavior regression {$name}.");
    return $errors;
};

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){$errors=$contract(dirname(__DIR__,2));if($errors){foreach($errors as $error)fwrite(STDERR,$error.PHP_EOL);exit(1);}fwrite(STDOUT,"Semester Registration Phase 6 contract passed.\n");}
return $contract;
