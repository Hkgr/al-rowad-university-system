<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $paths = [
        'registration' => $backendRoot.'/app/Services/RegistrationService.php',
        'requests' => $backendRoot.'/app/Services/RegistrationRequestService.php',
        'exception' => $backendRoot.'/app/Exceptions/RegistrationException.php',
        'policy' => $backendRoot.'/app/Services/AcademicCalendarPolicyService.php',
    ];

    foreach ($paths as $name => $path) {
        if (! is_file($path)) {
            $errors[] = 'Missing Phase 4 source: '.$name;
        }
    }
    if ($errors !== []) {
        return $errors;
    }

    $sources = array_map('file_get_contents', $paths);
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) {
            $errors[] = $message;
        }
    };
    $method = static function (string $source, string $name) use (&$errors): string {
        if (preg_match(
            '/\n    (?:private|public|protected) function '.preg_quote($name, '/').'\(.*?(?=\n    (?:private|public|protected) function |\n})/s',
            $source,
            $matches,
        ) !== 1) {
            $errors[] = 'Could not inspect method '.$name;

            return '';
        }

        return $matches[0];
    };

    $registration = $sources['registration'];
    $requests = $sources['requests'];
    $exception = $sources['exception'];
    $policy = $sources['policy'];
    $window = $method($registration, 'courseRegistrationWindow');
    $assertWindow = $method($registration, 'assertCourseRegistrationWindowOpen');
    $materialize = $method($registration, 'performRegisterStudent');

    $expect(str_contains($registration, 'private AcademicCalendarPolicyService $academicCalendarPolicy'), 'RegistrationService must own the Phase 3 dependency.');
    $expect(! str_contains($requests, 'AcademicCalendarPolicyService'), 'RegistrationRequestService must depend on RegistrationService, not Phase 3 directly.');
    $expect(str_contains($window, 'AcademicCalendarPolicyResult'), 'Registration window method must retain the typed Phase 3 result.');
    $expect(str_contains($window, "self::COURSE_REGISTRATION_EVENT_TYPE") && str_contains($registration, "'course_registration'"), 'The stable course_registration machine code is required.');
    $expect(str_contains($window, '$academicYearId') && str_contains($window, '$semesterId'), 'Registration window evaluation must receive explicit year and semester context.');
    $expect(substr_count($window, '$this->academicCalendarPolicy->evaluate(') === 1, 'Registration window method must delegate once to the canonical evaluator.');

    foreach (['OPEN', 'CLOSED', 'INVALID_EVENT_TYPE', 'INVALID_ACADEMIC_YEAR', 'INVALID_SEMESTER_CONTEXT', 'CALENDAR_CONFIGURATION_ERROR'] as $status) {
        $expect(str_contains($assertWindow, 'AcademicCalendarPolicyStatus::'.$status), 'Missing exhaustive Phase 4 status mapping: '.$status);
    }
    $expect(! str_contains($assertWindow, 'default =>'), 'Policy mapping must remain exhaustive when the enum changes.');

    foreach ([
        'COURSE_REGISTRATION_WINDOW_CLOSED' => 'course_registration_window_closed',
        'ACADEMIC_CALENDAR_CONFIGURATION_INVALID' => 'academic_calendar_configuration_invalid',
        'ACADEMIC_CALENDAR_YEAR_CONTEXT_INVALID' => 'academic_calendar_year_context_invalid',
        'ACADEMIC_CALENDAR_SEMESTER_CONTEXT_INVALID' => 'academic_calendar_semester_context_invalid',
    ] as $constant => $code) {
        $expect(str_contains($exception, 'public const '.$constant." = '".$code."'"), 'Missing stable registration error code: '.$code);
    }

    $gate = strpos($materialize, '$this->assertCourseRegistrationWindowOpen(');
    $offeringLock = strpos($materialize, "->lockForUpdate()\n            ->first();", strpos($materialize, 'CourseOffering::query()'));
    $reactivation = strpos($materialize, '$this->findReactivatableRegistration(');
    $create = strpos($materialize, 'StudentCourseRegistration::query()->create([');
    $seat = strpos($materialize, '$this->decrementAvailableSeats(');
    $expect($gate !== false && $offeringLock !== false && $gate > $offeringLock, 'Final policy gate must follow the locked CourseOffering lookup.');
    $expect($gate !== false && $reactivation !== false && $gate < $reactivation, 'Final policy gate must precede reactivation selection and write.');
    $expect($gate !== false && $create !== false && $gate < $create, 'Final policy gate must precede registration creation.');
    $expect($gate !== false && $seat !== false && $gate < $seat, 'Final policy gate must precede seat decrement.');
    $expect(str_contains($materialize, '(int) $courseOffering->academic_year_id') && str_contains($materialize, '(int) $courseOffering->semester_id'), 'The locked offering must supply both policy context IDs.');

    foreach (['policyCache', 'policy_cache', 'evaluationCache', 'cacheScope', 'beginPolicy', 'resetPolicy'] as $cacheMarker) {
        $expect(! str_contains($registration, $cacheMarker), 'Mutable policy caching is forbidden: '.$cacheMarker);
    }
    foreach (['AcademicCalendarEvent::', 'AcademicCalendarEventVersion::', 'academic_calendar_events', 'academic_calendar_event_versions'] as $directQuery) {
        $expect(! str_contains($registration, $directQuery), 'Registration enforcement must not query calendar storage directly: '.$directQuery);
    }
    $expect(! preg_match('/academic_calendar_event_type_id[^\n]{0,80}\b\d+\b/', $registration), 'Registration enforcement must not hardcode an event type ID.');

    $workspace = $method($requests, 'studentWorkspace');
    $add = $method($requests, 'addItem');
    $remove = $method($requests, 'removeItem');
    $notes = $method($requests, 'updateNotes');
    $submit = $method($requests, 'submit');
    $approve = $method($requests, 'approve');
    $expect(str_contains($workspace, 'courseRegistrationWindow(') && str_contains($workspace, '->isOpen()'), 'registration_open must include the calendar window result.');
    $expect(str_contains($workspace, '$calendarWindowBySemesterId') && str_contains($workspace, '->mapWithKeys('), 'Workspace must build a local evaluation map for every workflow-open semester.');
    $expect(str_contains($workspace, '$liveOpenSemesters') && str_contains($workspace, 'resolveWorkspaceSemester($selectableSemesters, $liveOpenSemesters'), 'Workspace selection must use calendar-filtered live-open semesters.');
    $expect(str_contains($workspace, '$liveOpenSemesters->contains('), 'registration_open must be derived from the filtered live-open semester collection.');
    foreach (['addItem' => $add, 'updateNotes' => $notes, 'submit' => $submit] as $name => $source) {
        $expect(str_contains($source, 'assertCourseRegistrationWindowOpen('), $name.' must reject closed preparation mutations.');
    }
    $expect(! str_contains($remove, 'courseRegistrationWindow') && ! str_contains($remove, 'assertCourseRegistrationWindowOpen'), 'removeItem must remain ungated by Academic Calendar.');
    $expect(str_contains($approve, 'DB::transaction(') && str_contains($approve, 'registerStudentWithinTransaction('), 'Approval must preserve canonical transactional materialization.');
    $expect(str_contains($requests, 'approvalErrorCode(') && str_contains($requests, 'COURSE_REGISTRATION_WINDOW_CLOSED'), 'Approval must preserve calendar machine codes.');

    foreach (['isCourseRegistrationOpen', 'assertCourseRegistrationOpen'] as $registrationSpecificPolicyMethod) {
        $expect(! str_contains($policy, $registrationSpecificPolicyMethod), 'Phase 3 policy must remain generic: '.$registrationSpecificPolicyMethod);
    }
    foreach ([
        'RegistrationWithdrawalService.php',
        'DeanRegistrationOfferingService.php',
        'CourseOfferingOpeningService.php',
        'GradeService.php',
        'GradeWorkflowService.php',
        'SupplementaryExamRegistrationService.php',
        'SupplementaryExamMaterializationService.php',
    ] as $unrelated) {
        $path = $backendRoot.'/app/Services/'.$unrelated;
        $expect(! is_file($path) || ! str_contains(file_get_contents($path), 'AcademicCalendarPolicyService'), $unrelated.' must not be calendar-gated in Phase 4.');
    }

    $expect((glob($backendRoot.'/database/migrations/*academic*calendar*phase4*') ?: []) === [], 'Phase 4 must not add a migration.');
    $expect(! is_dir($backendRoot.'/database/sql/academic-calendar-phase4-registration-enforcement'), 'Phase 4 must not add a SQL package.');

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

    fwrite(STDOUT, "Academic Calendar Phase 4 registration contract passed.\n");
}

return $contract;
