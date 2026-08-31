<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $paths = [
        'registration' => $backendRoot.'/app/Services/RegistrationService.php',
        'requests' => $backendRoot.'/app/Services/RegistrationRequestService.php',
        'exception' => $backendRoot.'/app/Exceptions/RegistrationException.php',
        'policy' => $backendRoot.'/app/Services/AcademicCalendarPolicyService.php',
        'controller' => $backendRoot.'/app/Http/Controllers/Api/StudentSelfRegistrationController.php',
        'frontend' => dirname($backendRoot).'/frontend/src/features/student-dashboard/pages/StudentRegistration.jsx',
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
    $controller = $sources['controller'];
    $frontend = $sources['frontend'];
    $window = $method($registration, 'courseRegistrationWindow');
    $assertWindow = $method($registration, 'assertCourseRegistrationWindowOpen');
    $assertStudentWindow = $method($registration, 'assertCourseRegistrationStudentWindowOpen');
    $materialize = $method($registration, 'performRegisterStudent');

    $expect(str_contains($registration, 'private AcademicCalendarPolicyService $academicCalendarPolicy'), 'RegistrationService must own the Phase 3 dependency.');
    $expect(! str_contains($requests, 'AcademicCalendarPolicyService'), 'RegistrationRequestService must depend on RegistrationService, not Phase 3 directly.');
    $expect(str_contains($window, 'AcademicCalendarPolicyResult'), 'Registration window method must retain the typed Phase 3 result.');
    $expect(str_contains($window, "self::COURSE_REGISTRATION_EVENT_TYPE") && str_contains($registration, "'course_registration'"), 'The stable course_registration machine code is required.');
    $expect(str_contains($window, '$academicYearId') && str_contains($window, '$semesterId'), 'Registration window evaluation must receive explicit year and semester context.');
    $expect(substr_count($window, '$this->academicCalendarPolicy->evaluate(') === 1, 'Registration window method must delegate once to the canonical evaluator.');

    $expect(str_contains($assertWindow, 'assertCourseRegistrationStudentWindowOpen('), 'The legacy Phase 4 assertion must delegate to the Phase 2 student-deadline assertion.');
    $expect(str_contains($assertStudentWindow, 'CourseRegistrationPhase::STUDENT_OPEN') && str_contains($assertStudentWindow, 'CourseRegistrationPhase::CONFIGURATION_ERROR'), 'Student registration must retain typed fail-closed calendar mapping.');

    foreach ([
        'COURSE_REGISTRATION_WINDOW_CLOSED' => 'course_registration_window_closed',
        'ACADEMIC_CALENDAR_CONFIGURATION_INVALID' => 'academic_calendar_configuration_invalid',
        'ACADEMIC_CALENDAR_YEAR_CONTEXT_INVALID' => 'academic_calendar_year_context_invalid',
        'ACADEMIC_CALENDAR_SEMESTER_CONTEXT_INVALID' => 'academic_calendar_semester_context_invalid',
    ] as $constant => $code) {
        $expect(str_contains($exception, 'public const '.$constant." = '".$code."'"), 'Missing stable registration error code: '.$code);
    }

    $gate = strpos($materialize, '$this->assertCourseRegistrationStudentWindowOpen(');
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
    $expect(str_contains($workspace, 'courseRegistrationDeadlines(') && str_contains($workspace, '->isStudentOpen()'), 'registration_open must include the canonical student-deadline result.');
    $expect(str_contains($workspace, '$calendarWindowBySemesterId') && str_contains($workspace, '->mapWithKeys('), 'Workspace must build a local evaluation map for every workflow-open semester.');
    $expect(str_contains($workspace, '$liveOpenSemesters') && str_contains($workspace, 'resolveWorkspaceSemester($selectableSemesters, $liveOpenSemesters'), 'Workspace selection must use calendar-filtered live-open semesters.');
    $expect(str_contains($workspace, '$liveOpenSemesters->contains('), 'registration_open must be derived from the filtered live-open semester collection.');
    $expect(str_contains($workspace, "'request_item_removal_open' => \$requestItemRemovalOpen"), 'Workspace must expose the separate request-item removal capability.');
    $expect(str_contains($workspace, '$requestItemRemovalOpen = $registrationOpen'), 'Removal capability must now close at the canonical student deadline.');
    $expect(str_contains($controller, "'request_item_removal_open' => \$workspace['request_item_removal_open']"), 'Student registration API must expose the removal capability.');
    foreach (['addItem' => $add, 'updateNotes' => $notes, 'submit' => $submit] as $name => $source) {
        $expect(str_contains($source, 'assertCourseRegistrationStudentWindowOpen('), $name.' must reject mutations outside the student deadline.');
    }
    $expect(str_contains($remove, 'assertCourseRegistrationStudentWindowOpen('), 'removeItem must close at the same canonical student deadline as every other edit.');
    $expect(str_contains($approve, 'DB::transaction(') && str_contains($approve, 'materializeAdvisorApprovedRequestItemWithinTransaction('), 'Approval must preserve canonical materialization through the explicit advisor context.');
    $expect(str_contains($requests, 'approvalErrorCode(') && str_contains($requests, 'COURSE_REGISTRATION_WINDOW_CLOSED'), 'Approval must preserve calendar machine codes.');

    $expect(str_contains($frontend, 'const requestItemRemovalOpen = payload?.request_item_removal_open === true'), 'Frontend must consume the removal capability.');
    $expect(str_contains($frontend, 'const canRemoveItem = requestItemRemovalOpen'), 'Frontend must derive a distinct removal predicate.');
    $removeButton = strpos($frontend, 'onClick={() => removeItem(item)}');
    $removeContext = $removeButton === false ? '' : substr($frontend, max(0, $removeButton - 240), 480);
    $expect(str_contains($removeContext, '{canRemoveItem ? ('), 'Remove button must depend on canRemoveItem.');
    $expect(! str_contains($removeContext, '{canEdit ? ('), 'Remove button must not depend on calendar-gated canEdit.');
    $expect(str_contains($frontend, 'const canEdit = registrationOpen && termReady'), 'Preparation capability must remain tied to registration_open.');
    $expect(str_contains($frontend, 'canEdit={canEdit}'), 'Course add controls must remain tied to canEdit.');
    $expect(str_contains($frontend, 'if (!canEdit) return') && str_contains($frontend, 'disabled={!canEdit}'), 'Notes editing must remain tied to canEdit.');
    $submitButton = strpos($frontend, 'onClick={() => setConfirm({ type: \'submit\' })}');
    $submitContext = $submitButton === false ? '' : substr($frontend, max(0, $submitButton - 240), 480);
    $expect(str_contains($submitContext, '{canEdit ? ('), 'Submit control must remain tied to canEdit.');

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
