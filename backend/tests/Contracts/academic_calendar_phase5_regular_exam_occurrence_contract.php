<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $repositoryRoot = dirname($backendRoot);
    $paths = [
        'service' => $backendRoot.'/app/Services/RegularExamOccurrenceService.php',
        'part' => $backendRoot.'/app/Support/RegularExamPart.php',
        'snapshot' => $backendRoot.'/app/Support/RegularExamOccurrenceSnapshot.php',
        'controller' => $backendRoot.'/app/Http/Controllers/Api/GradePartWorkflowController.php',
        'routes' => $backendRoot.'/routes/api.php',
        'frontend' => $repositoryRoot.'/frontend/src/features/professor-dashboard/pages/ProfessorGradesPage.jsx',
    ];

    foreach ($paths as $name => $path) {
        if (! is_file($path)) {
            $errors[] = 'Missing Phase 5 source: '.$name;
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

    $service = $sources['service'];
    $part = $sources['part'];
    $snapshot = $sources['snapshot'];
    $controller = $sources['controller'];
    $routes = $sources['routes'];
    $frontend = $sources['frontend'];

    $expect(str_contains($part, 'enum RegularExamPart: string'), 'RegularExamPart must be a typed enum.');
    $expect(substr_count($part, 'case PRACTICAL') === 1 && substr_count($part, 'case THEORETICAL') === 1, 'RegularExamPart must contain exactly the practical and theoretical cases.');
    $expect(substr_count($part, 'case ') === 2, 'RegularExamPart must not gain unrelated cases.');
    $expect(str_contains($part, "self::PRACTICAL => 'practical_exams'") && str_contains($part, "self::THEORETICAL => 'theoretical_exams'"), 'Regular exam machine-code mapping must remain centralized.');
    $expect(! preg_match('/academic_calendar_event_type_id[^\n]{0,80}\b\d+\b/', $part.$service), 'Regular exam occurrence must not hardcode numeric event-type IDs.');

    $expect(str_contains($snapshot, 'final readonly class RegularExamOccurrenceSnapshot'), 'Occurrence snapshot must be immutable.');
    foreach (['courseOfferingId', 'academicYearId', 'semesterId', 'evaluatedAt', 'practical', 'theoretical'] as $field) {
        $expect(str_contains($snapshot, '$'.$field), 'Occurrence snapshot is missing field: '.$field);
    }
    $expect(substr_count($snapshot, 'AcademicCalendarPolicyResult $') === 2, 'Both snapshot parts must retain typed Phase 3 results.');

    $expect(str_contains($service, 'private readonly AcademicCalendarPolicyService $academicCalendarPolicy'), 'Occurrence service must depend on the canonical Phase 3 policy.');
    $evaluate = $method($service, 'evaluate');
    $offering = $method($service, 'evaluateForOffering');
    $snapshotMethod = $method($service, 'snapshotForOffering');
    $expect(str_contains($evaluate, 'RegularExamPart $part') && str_contains($evaluate, '): AcademicCalendarPolicyResult'), 'Canonical occurrence evaluation signature is missing.');
    $expect(substr_count($evaluate, '$this->academicCalendarPolicy->evaluate(') === 1, 'Canonical occurrence evaluation must delegate exactly once.');
    $expect(str_contains($evaluate, '$part->calendarEventTypeCode()'), 'Occurrence evaluation must use the centralized machine-code mapping.');
    $expect(str_contains($offering, '(int) $offering->academic_year_id') && str_contains($offering, '(int) $offering->semester_id'), 'Offering evaluation must pass explicit year and semester IDs.');
    $expect(str_contains($snapshotMethod, "CarbonImmutable::now('UTC')") && str_contains($snapshotMethod, 'CarbonImmutable::instance($at)->utc()'), 'Snapshot must resolve one immutable UTC instant.');
    $expect(substr_count($snapshotMethod, '$evaluatedAt') >= 4, 'Snapshot must reuse its single evaluation instant.');
    $expect(substr_count($snapshotMethod, 'evaluateForOffering(') === 2, 'Snapshot must perform exactly two bounded occurrence evaluations.');
    $expect(str_contains($snapshotMethod, 'RegularExamPart::PRACTICAL, $evaluatedAt') && str_contains($snapshotMethod, 'RegularExamPart::THEORETICAL, $evaluatedAt'), 'Both exam parts must receive the same explicit instant.');

    foreach (['academic_calendar_events', 'academic_calendar_event_versions', 'academic_calendar_event_types', 'AcademicCalendarEvent::', 'AcademicCalendarEventVersion::'] as $storage) {
        $expect(! str_contains($service, $storage), 'Occurrence facade queries calendar storage directly: '.$storage);
    }
    foreach (['Schema::', 'schemaReady(', 'information_schema', 'QueryException', 'catch ('] as $schemaMarker) {
        $expect(! str_contains($service, $schemaMarker), 'Occurrence facade must not inspect or normalize schema failures: '.$schemaMarker);
    }
    foreach (['policyCache', 'policy_cache', 'evaluationCache', 'cacheScope', 'remember(', 'Cache::'] as $cacheMarker) {
        $expect(! str_contains($service, $cacheMarker), 'Occurrence facade must not cache policy results: '.$cacheMarker);
    }
    foreach (['App\\Models\\User', 'Auth::', 'Gate::', 'DataScopeService', 'hasRole(', 'hasPermission('] as $roleMarker) {
        $expect(! str_contains($service, $roleMarker), 'Temporal occurrence result must not vary by role: '.$roleMarker);
    }

    $show = $method($controller, 'show');
    $authorizationAt = strpos($show, 'assertCanViewGradeParts(');
    $workflowAt = strpos($show, '$payload = $service->workflow(');
    $appendAt = strpos($show, "\$payload['regular_exam_occurrence'] =");
    $returnAt = strpos($show, 'return $this->success($payload)');
    $expect($authorizationAt !== false && $workflowAt !== false && $authorizationAt < $workflowAt, 'Offering authorization must precede workflow and occurrence reads.');
    $expect($workflowAt !== false && $appendAt !== false && $workflowAt < $appendAt, 'Occurrence must be appended to the existing workflow payload.');
    $expect($appendAt !== false && $returnAt !== false && $appendAt < $returnAt, 'Controller must return the additively extended payload.');
    $expect(! str_contains($show, "'workflow' =>") && ! str_contains($show, "'grade_workflow' =>"), 'Existing workflow payload must not be wrapped or renamed.');
    foreach (['course_offering_id', 'academic_year_id', 'semester_id', 'evaluated_at', 'practical', 'theoretical', 'status', 'is_occurring', 'reason_code'] as $field) {
        $expect(str_contains($controller, "'".$field."'"), 'Occurrence response is missing field: '.$field);
    }
    foreach (['change_reason', 'cancellation_reason', 'created_by_user_id', 'published_by_user_id'] as $privateField) {
        $expect(! str_contains($controller, "'".$privateField."'"), 'Occurrence response exposes private calendar data: '.$privateField);
    }
    $expect(! str_contains($routes, 'regular-exam-occurrence'), 'Phase 5 must reuse the authorized grade-parts response rather than add another route.');

    $expect(str_contains($frontend, 'function RegularExamOccurrencePanel({ occurrence })'), 'Professor UI must include the informational occurrence panel.');
    $expect(str_contains($frontend, '<RegularExamOccurrencePanel occurrence={workflow.regular_exam_occurrence} />'), 'Professor UI must consume the additive occurrence field.');
    foreach (['فترة الامتحان ${label} جارية', 'خارج فترة الامتحان ${label}', 'حالة فترة الامتحان ${label} غير متاحة'] as $copy) {
        $expect(str_contains($frontend, $copy), 'Professor UI is missing occurrence display state: '.$copy);
    }
    $occurrenceStart = strpos($frontend, 'function occurrenceCopy(');
    $occurrenceEnd = strpos($frontend, 'function stepForPart(', $occurrenceStart);
    $occurrenceUi = $occurrenceStart === false || $occurrenceEnd === false ? '' : substr($frontend, $occurrenceStart, $occurrenceEnd - $occurrenceStart);
    foreach (['disabled=', 'canEdit', 'canSave', 'canSubmit', 'starts_at', 'ends_at', 'new Date(', 'Date.now('] as $forbiddenUi) {
        $expect(! str_contains($occurrenceUi, $forbiddenUi), 'Occurrence UI must remain informational: '.$forbiddenUi);
    }
    foreach (['const canSave =', 'const canSubmit ='] as $predicateName) {
        $start = strpos($frontend, $predicateName);
        $predicate = $start === false ? '' : substr($frontend, $start, 500);
        $expect($start !== false && ! str_contains($predicate, 'regular_exam_occurrence') && ! str_contains($predicate, 'is_occurring'), $predicateName.' must not depend on exam occurrence.');
    }

    foreach (['GradeService.php', 'GradePartWorkflowService.php', 'GradeWorkflowService.php', 'GradeApprovalWorkflowService.php', 'ProfessorGradeAssignmentService.php'] as $gradeFile) {
        $source = file_get_contents($backendRoot.'/app/Services/'.$gradeFile);
        foreach (['RegularExamOccurrenceService', 'practical_exams', 'theoretical_exams'] as $gateMarker) {
            $expect(! str_contains($source, $gateMarker), $gradeFile.' contains a regular-exam temporal gate: '.$gateMarker);
        }
    }
    foreach (glob($backendRoot.'/app/Services/SupplementaryExam*.php') ?: [] as $supplementaryFile) {
        $expect(! str_contains(file_get_contents($supplementaryFile), 'RegularExamOccurrenceService'), basename($supplementaryFile).' must remain outside Phase 5.');
    }
    foreach (['RegistrationService.php', 'RegistrationRequestService.php', 'RegistrationWithdrawalService.php'] as $workflowFile) {
        $source = file_get_contents($backendRoot.'/app/Services/'.$workflowFile);
        $expect(! str_contains($source, 'RegularExamOccurrenceService'), $workflowFile.' must remain outside Phase 5.');
    }

    foreach (['ExamSchedule.php', 'ExamSession.php', 'CourseExamDate.php', 'ExamRoom.php', 'ExamInvigilator.php'] as $forbiddenModel) {
        $expect(! is_file($backendRoot.'/app/Models/'.$forbiddenModel), 'Detailed exam scheduling model is forbidden: '.$forbiddenModel);
    }
    $expect((glob($backendRoot.'/database/migrations/*regular*exam*') ?: []) === [], 'Phase 5 must not add a regular-exam migration.');
    $expect(! is_dir($backendRoot.'/database/sql/academic-calendar-phase5-regular-exam-occurrence'), 'Phase 5 must not add an SQL package.');

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

    fwrite(STDOUT, "Academic Calendar Phase 5 regular exam occurrence contract passed.\n");
}

return $contract;
