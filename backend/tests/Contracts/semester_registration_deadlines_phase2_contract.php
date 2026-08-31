<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) {
            $errors[] = $message;
        }
    };
    $read = static fn (string $path): string => is_file($path) ? (string) file_get_contents($path) : '';
    $method = static function (string $source, string $name): string {
        return preg_match('/\n    (?:private|public|protected) function '.preg_quote($name, '/').'\(.*?(?=\n    (?:private|public|protected) function |\n})/s', $source, $match) === 1 ? $match[0] : '';
    };

    $sqlRoot = $backendRoot.'/database/sql/semester-registration-deadlines-phase2';
    $sqlFiles = ['00_preflight.sql', '01_apply.sql', '02_verify.sql'];
    $expect(is_dir($sqlRoot), 'Missing Phase 2 manual SQL package.');
    $expect(array_values(array_map('basename', glob($sqlRoot.'/*') ?: [])) === $sqlFiles, 'Phase 2 SQL package must contain exactly preflight/apply/verify.');
    foreach ($sqlFiles as $file) {
        $sql = $read($sqlRoot.'/'.$file);
        $expect(str_contains($sql, 'USE `alrowad_uni_rust`;'), $file.' must target the explicit production database.');
        $expect(! preg_match('/\b(?:DELETE|UPDATE)\s+(?!RULE)|\bDROP\b|\bTRUNCATE\b|\bSIGNAL\b|\bDELIMITER\b|DATABASE\s*\(/i', $sql), $file.' contains forbidden destructive or fragile SQL.');
    }
    $preflight = $read($sqlRoot.'/00_preflight.sql');
    $apply = $read($sqlRoot.'/01_apply.sql');
    $verify = $read($sqlRoot.'/02_verify.sql');
    $expect(str_contains($preflight, "SELECT 'OVERALL'") && str_contains($preflight, "'READY','BLOCKED'"), 'Preflight must visibly end READY/BLOCKED.');
    $expect(str_contains($verify, "SELECT 'OVERALL'") && str_contains($verify, "'PASS','FAIL'"), 'Verify must visibly end PASS/FAIL.');
    foreach (['student_registration_ends_at', 'advisor_approval_ends_at', 'expired_at'] as $column) {
        $expect(str_contains($preflight, $column) && str_contains($apply, $column) && str_contains($verify, $column), 'Missing SQL contract for '.$column);
    }
    $expect(! preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $preflight.$verify), 'Preflight and verify must remain read-only.');
    $expect(str_contains($apply, 'ADD COLUMN IF NOT EXISTS') && ! preg_match('/\b(?:INSERT|UPDATE|DELETE|CREATE TABLE)\b/i', $apply), 'Apply must be additive, rerunnable DDL without historical rewrites.');
    $expect(str_contains($verify, 'legacy_fallback_rows'), 'Verify must report rather than rewrite legacy null deadlines.');
    $expect(str_contains($preflight, '@srd_request_text_contract=4') && str_contains($apply, '@srd_apply_runtime_contract=5') && str_contains($verify, '@srd_verify_runtime_contract=5'), 'SQL guards must prove expired status/event storage and nullable system provenance are compatible.');
    $expect(str_contains($preflight, "@srd_root_duplicates=0") && str_contains($verify, "@srd_verify_root_duplicates=0"), 'One non-cancelled registration root per term must be verified.');
    foreach (['registration_requests.view', 'registration_requests.review', 'academic_advisor'] as $rbac) {
        $expect(str_contains($preflight, $rbac) && str_contains($verify, $rbac), 'Existing advisor authority must be preserved: '.$rbac);
    }

    $policy = $read($backendRoot.'/app/Services/AcademicCalendarPolicyService.php');
    $calendar = $read($backendRoot.'/app/Services/AcademicCalendarService.php');
    $registration = $read($backendRoot.'/app/Services/RegistrationService.php');
    $requests = $read($backendRoot.'/app/Services/RegistrationRequestService.php');
    $requestModel = $read($backendRoot.'/app/Models/StudentRegistrationRequest.php');
    $requestEvent = $read($backendRoot.'/app/Models/StudentRegistrationRequestEvent.php');
    $deadlineResult = $read($backendRoot.'/app/Support/CourseRegistrationDeadlineResult.php');
    $phase = $read($backendRoot.'/app/Support/CourseRegistrationPhase.php');

    foreach (['NOT_STARTED', 'STUDENT_OPEN', 'ADVISOR_REVIEW', 'CLOSED', 'CONFIGURATION_ERROR'] as $state) {
        $expect(str_contains($phase, $state), 'Missing typed registration phase '.$state);
    }
    foreach (['startsAt', 'studentRegistrationEndsAt', 'advisorApprovalEndsAt', 'evaluatedAt', 'academicCalendarEventId', 'academicCalendarEventVersionId', 'reasonCode'] as $field) {
        $expect(str_contains($deadlineResult, $field), 'Deadline result is missing '.$field);
    }
    $deadlineEvaluation = $method($policy, 'courseRegistrationDeadlines');
    $expect(str_contains($deadlineEvaluation, 'whereNull(\'cancelled_at\')') && str_contains($deadlineEvaluation, "publication_status', 'published") && str_contains($deadlineEvaluation, "whereNull('acev.superseded_at')"), 'Deadline evaluation must use the current non-cancelled published version.');
    $expect(str_contains($deadlineEvaluation, '$legacyFallback') && str_contains($deadlineEvaluation, '$genericEndsAt'), 'Legacy null deadlines must fall back to generic ends_at.');
    $expect(str_contains($deadlineEvaluation, '->lte($studentEndsAt)') && str_contains($deadlineEvaluation, '->lte($advisorEndsAt)'), 'Student and advisor boundaries must be inclusive.');
    $expect(str_contains($deadlineEvaluation, "where('semester_id', \$semesterId)"), 'Registration deadlines must require the explicit semester root.');

    $expect(str_contains($calendar, 'assertUniqueRegistrationRoot') && str_contains($calendar, 'enforceRegistrationDeadlineSemantics'), 'Academic Calendar must own root and deadline write validation.');
    $expect(str_contains($calendar, '$data[\'ends_at\'] = $advisorEnds'), 'Generic ends_at must equal the advisor deadline server-side.');
    $expect(str_contains($calendar, '$data[\'is_enforcement\'] = true'), 'Course registration windows must remain enforcement windows.');
    $expect(str_contains($calendar, 'المواعيد النهائية المتخصصة متاحة لنافذة تسجيل المقررات فقط'), 'Other event types must reject specialized deadlines.');

    $studentEntry = $method($registration, 'registerStudentWithinTransaction');
    $advisorEntry = $method($registration, 'materializeAdvisorApprovedRequestItemWithinTransaction');
    $materialize = $method($registration, 'performRegisterStudent');
    $expect(str_contains($studentEntry, 'RegistrationMaterializationContext::STUDENT_WINDOW'), 'Student materialization must use the explicit student context.');
    $expect(str_contains($advisorEntry, 'RegistrationMaterializationContext::ADVISOR_APPROVAL'), 'Advisor materialization must use a distinct trusted context.');
    $expect(! preg_match('/ignoreCalendar|bypassCalendar|skipCalendar/i', $registration), 'A generic calendar bypass is forbidden.');
    $gate = strpos($materialize, 'assertCourseRegistrationStudentWindowOpen(');
    $offeringLock = strpos($materialize, 'CourseOffering::query()');
    $create = strpos($materialize, 'StudentCourseRegistration::query()->create([');
    $expect($offeringLock !== false && $gate > $offeringLock && $create > $gate, 'The fresh student deadline evaluation must follow the offering lock and precede registration writes.');

    foreach (['addItem', 'removeItem', 'updateNotes', 'submit'] as $mutation) {
        $expect(str_contains($method($requests, $mutation), 'assertCourseRegistrationStudentWindowOpen('), $mutation.' must use the canonical student cutoff.');
    }
    $approve = $method($requests, 'approve');
    $return = $method($requests, 'returnForModification');
    foreach ([$approve, $return] as $decision) {
        $lock = strpos($decision, 'lockForUpdate()');
        $deadline = strpos($decision, 'courseRegistrationDeadlines(');
        $expect($lock !== false && $deadline > $lock, 'Advisor deadline must be decided after locking the request.');
        $expect(str_contains($decision, 'expireLockedIfDeadlineClosed('), 'Advisor decision must reconcile expiration under lock.');
    }
    $expect(str_contains($approve, 'materializeAdvisorApprovedRequestItemWithinTransaction('), 'Advisor approval must use the trusted advisor materialization entry.');
    $expect(str_contains($requests, 'last_submitted_at') && str_contains($requests, 'studentRegistrationEndsAt'), 'Advisor review must defensively validate on-time submission.');
    $expect(str_contains($requestModel, "STATUS_EXPIRED = 'expired'") && str_contains($requestModel, "'expired_at'"), 'Request model must expose terminal expiration.');
    $expect(str_contains($requestEvent, "TYPE_EXPIRED_DEADLINE = 'expired_deadline'"), 'Expiration must reuse request history with a stable event type.');
    $expire = $method($requests, 'expireLockedIfDeadlineClosed');
    $expect(str_contains($expire, 'STATUS_EXPIRED') && str_contains($expire, 'TYPE_EXPIRED_DEADLINE') && str_contains($expire, 'null,'), 'Expiration must persist once with system actor support.');
    $expect(! preg_match('/\b(?:delete|forceDelete)\s*\(/i', $expire), 'Expiration must not delete requests, items, or history.');
    $expect(str_contains($requests, 'registration_requests.view') && str_contains($requests, 'registration_requests.review'), 'Advisor permissions must remain the existing permission pair.');
    $expect(! str_contains($approve.$return, "hasRoleCode('dean')") && ! str_contains($approve.$return, 'isDean()'), 'Advisor decisions must not require the Dean role.');

    $expect((glob($backendRoot.'/database/migrations/*semester*registration*deadline*') ?: []) === [], 'Phase 2 must not add a migration.');
    $expect((glob($backendRoot.'/database/seeders/*Semester*Registration*Deadline*') ?: []) === [], 'Phase 2 must not add a seeder.');
    foreach (['semester_offering_requests','semester_offering_reviews','semester_offering_events'] as $phaseOneTable) {
        $expect(! str_contains($apply, 'ALTER TABLE `alrowad_uni_rust`.`'.$phaseOneTable.'`'), 'Phase 1 governance tables must not be changed.');
    }

    return $errors;
};

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = $contract(dirname(__DIR__, 2));
    if ($errors !== []) {
        foreach ($errors as $error) {
            fwrite(STDERR, $error.PHP_EOL);
        }
        exit(1);
    }
    fwrite(STDOUT, "Semester registration deadlines Phase 2 contract passed.\n");
}

return $contract;
