<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $repositoryRoot = dirname($backendRoot);
    $paths = [
        'service' => $backendRoot.'/app/Services/SupplementaryExamOccurrenceService.php',
        'snapshot' => $backendRoot.'/app/Support/SupplementaryExamOccurrenceSnapshot.php',
        'grading_controller' => $backendRoot.'/app/Http/Controllers/Api/SupplementaryExamGradingController.php',
        'period_controller' => $backendRoot.'/app/Http/Controllers/Api/SupplementaryExamPeriodController.php',
        'vp_period_controller' => $backendRoot.'/app/Http/Controllers/Api/ScientificVicePresidentSupplementaryExamPeriodController.php',
        'frontend' => $repositoryRoot.'/frontend/src/features/professor-dashboard/pages/ProfessorSupplementaryExams.jsx',
    ];

    foreach ($paths as $name => $path) {
        if (! is_file($path)) {
            $errors[] = 'Missing Phase 6 source: '.$name;
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
    $snapshot = $sources['snapshot'];
    $gradingController = $sources['grading_controller'];
    $periodController = $sources['period_controller'];
    $vpPeriodController = $sources['vp_period_controller'];
    $frontend = $sources['frontend'];

    $expect(str_contains($service, 'private readonly AcademicCalendarPolicyService $academicCalendarPolicy'), 'Occurrence facade must depend only on the Phase 3 policy service.');
    $expect(str_contains($service, "private const EVENT_TYPE_CODE = 'supplementary_exams';"), 'Supplementary occurrence machine code must be stable.');
    $expect(! preg_match('/academic_calendar_event_type_id[^\n]{0,80}\b\d+\b/', $service), 'Occurrence facade must not hardcode an event-type ID.');

    $evaluate = $method($service, 'evaluate');
    $evaluateForPeriod = $method($service, 'evaluateForPeriod');
    $snapshotForPeriod = $method($service, 'snapshotForPeriod');
    $expect(str_contains($evaluate, 'int $academicYearId') && str_contains($evaluate, 'int $semesterId') && str_contains($evaluate, '?CarbonInterface $at = null'), 'Canonical occurrence signature must accept explicit year, semester, and instant.');
    $expect(substr_count($evaluate, '$this->academicCalendarPolicy->evaluate(') === 1, 'Occurrence evaluation must delegate exactly once to Phase 3.');
    $expect(str_contains($evaluate, 'self::EVENT_TYPE_CODE') && str_contains($evaluate, '$academicYearId') && str_contains($evaluate, '$semesterId') && str_contains($evaluate, '$at'), 'Occurrence evaluation must forward its complete explicit context.');
    $expect(str_contains($evaluateForPeriod, '(int) $period->academic_year_id') && str_contains($evaluateForPeriod, '(int) $period->semester_id'), 'Period evaluation must use its explicit year and semester.');
    $expect(! str_contains($evaluateForPeriod.$snapshotForPeriod, 'start_date') && ! str_contains($evaluateForPeriod.$snapshotForPeriod, 'end_date'), 'Legacy period dates must not determine occurrence.');
    $expect(str_contains($snapshotForPeriod, "CarbonImmutable::now('UTC')") && str_contains($snapshotForPeriod, 'CarbonImmutable::instance($at)->utc()'), 'Snapshot must resolve one immutable UTC instant.');
    $expect(substr_count($snapshotForPeriod, 'evaluateForPeriod(') === 1 && str_contains($snapshotForPeriod, '$evaluatedAt'), 'Snapshot must perform one bounded evaluation at the resolved instant.');

    $expect(str_contains($snapshot, 'final readonly class SupplementaryExamOccurrenceSnapshot'), 'Occurrence snapshot must be immutable.');
    foreach (['supplementaryExamPeriodId', 'academicYearId', 'semesterId', 'evaluatedAt', 'result'] as $field) {
        $expect(str_contains($snapshot, '$'.$field), 'Occurrence snapshot is missing field: '.$field);
    }
    $expect(str_contains($snapshot, 'AcademicCalendarPolicyResult $result'), 'Snapshot must retain the complete typed Phase 3 result.');
    foreach (['supplementary_exam_period_id', 'academic_year_id', 'semester_id', 'evaluated_at', 'status', 'is_occurring', 'reason_code'] as $field) {
        $expect(str_contains($snapshot, "'".$field."'"), 'Public occurrence payload is missing field: '.$field);
    }
    foreach (['change_reason', 'cancellation_reason', 'created_by_user_id', 'published_by_user_id'] as $privateField) {
        $expect(! str_contains($snapshot, "'".$privateField."'"), 'Public occurrence payload exposes calendar audit data: '.$privateField);
    }

    foreach (['academic_calendar_events', 'academic_calendar_event_versions', 'academic_calendar_event_types', 'AcademicCalendarEvent::', 'AcademicCalendarEventVersion::'] as $storage) {
        $expect(! str_contains($service, $storage), 'Occurrence facade queries calendar storage directly: '.$storage);
    }
    foreach (['Schema::', 'schemaReady(', 'information_schema', 'QueryException', 'catch ('] as $schemaMarker) {
        $expect(! str_contains($service, $schemaMarker), 'Occurrence facade must not inspect or normalize schema failures: '.$schemaMarker);
    }
    foreach (['Cache::', 'remember(', 'policyCache', 'evaluationCache', 'cacheScope', 'static $'] as $cacheMarker) {
        $expect(! str_contains($service, $cacheMarker), 'Occurrence facade must not cache policy results: '.$cacheMarker);
    }
    foreach (['App\\Models\\User', 'Auth::', 'Gate::', 'DataScopeService', 'hasRole(', 'hasPermission('] as $roleMarker) {
        $expect(! str_contains($service, $roleMarker), 'Occurrence facade must contain no role logic: '.$roleMarker);
    }

    $professorRead = $method($gradingController, 'professorGrades');
    $rosterAt = strpos($professorRead, '$payload = $service->roster(');
    $occurrenceAt = strpos($professorRead, 'snapshotForPeriod(');
    $returnAt = strpos($professorRead, "return response()->json(['success' => true, 'data' => \$payload])");
    $expect($rosterAt !== false && $occurrenceAt !== false && $rosterAt < $occurrenceAt, 'Professor authorization/read must precede occurrence evaluation.');
    $expect($occurrenceAt !== false && $returnAt !== false && $occurrenceAt < $returnAt, 'Professor response must append occurrence to the existing payload.');
    $expect(! str_contains($professorRead, "'roster' => \$payload"), 'Professor roster payload must not be wrapped or renamed.');
    foreach (['save', 'submit', 'resubmit', 'return', 'approve', 'publish', 'assign', 'open'] as $mutation) {
        $expect(! str_contains($method($gradingController, $mutation), 'SupplementaryExamOccurrenceService') && ! str_contains($method($gradingController, $mutation), 'snapshotForPeriod('), 'Grading mutation must not evaluate occurrence: '.$mutation);
    }

    foreach ([$periodController, $vpPeriodController] as $controller) {
        $show = $method($controller, 'show');
        $authorizedAt = strpos($show, '$this->governance->findPeriod(');
        $showOccurrenceAt = strpos($show, 'snapshotForPeriod(');
        $expect($authorizedAt !== false && $showOccurrenceAt !== false && $authorizedAt < $showOccurrenceAt, 'Period authorization/read must precede occurrence evaluation.');
        $expect(! str_contains($method($controller, 'index'), 'snapshotForPeriod('), 'Period indexes must not introduce N+1 occurrence evaluations.');
        $expect(! str_contains($method($controller, 'store'), 'snapshotForPeriod('), 'Period mutations must not evaluate occurrence.');
    }

    $workflowServices = [
        'SupplementaryExamPeriodGovernanceService.php',
        'SupplementaryExamRegistrationWindowService.php',
        'SupplementaryExamRegistrationService.php',
        'SupplementaryExamEligibilityService.php',
        'SupplementaryExamOfferingService.php',
        'SupplementaryExamGradingService.php',
        'SupplementaryExamMaterializationService.php',
        'SupplementaryExamReconciliationService.php',
    ];
    foreach ($workflowServices as $workflowFile) {
        $source = file_get_contents($backendRoot.'/app/Services/'.$workflowFile);
        $expect(! str_contains($source, 'SupplementaryExamOccurrenceService') && ! str_contains($source, "'supplementary_exams'"), $workflowFile.' contains a supplementary occurrence gate.');
    }
    foreach (['GradeService.php', 'GradePartWorkflowService.php', 'GradeWorkflowService.php', 'GradeApprovalWorkflowService.php', 'ProfessorGradeAssignmentService.php', 'RegistrationService.php', 'RegistrationRequestService.php', 'RegistrationWithdrawalService.php', 'RegularExamOccurrenceService.php'] as $unrelatedFile) {
        $source = file_get_contents($backendRoot.'/app/Services/'.$unrelatedFile);
        $expect(! str_contains($source, 'SupplementaryExamOccurrenceService'), $unrelatedFile.' must remain outside Phase 6.');
    }
    $expect(! str_contains($service.$snapshot.$gradingController.$periodController.$vpPeriodController.$frontend, 'supplementary_practical_mark'), 'Phase 6 must not introduce supplementary practical grading.');

    $expect(str_contains($frontend, 'function SupplementaryExamOccurrenceIndicator({ occurrence })'), 'Professor UI must include a supplementary occurrence indicator.');
    foreach (['فترة الامتحانات التكميلية جارية', 'خارج فترة الامتحانات التكميلية', 'حالة فترة الامتحانات التكميلية غير متاحة'] as $copy) {
        $expect(str_contains($frontend, $copy), 'Professor UI is missing occurrence copy: '.$copy);
    }
    $expect(str_contains($frontend, 'const [occurrence, setOccurrence] = useState(null)'), 'Occurrence must use separate local React state.');
    $expect(str_contains($frontend, 'setOccurrence(nextSheet?.supplementary_exam_occurrence ?? null)'), 'Authorized GET must refresh occurrence state.');
    $indicatorStart = strpos($frontend, 'function SupplementaryExamOccurrenceIndicator(');
    $indicatorEnd = strpos($frontend, 'export default function ProfessorSupplementaryExams', $indicatorStart);
    $indicator = $indicatorStart === false || $indicatorEnd === false ? '' : substr($frontend, $indicatorStart, $indicatorEnd - $indicatorStart);
    foreach (['new Date(', 'Date.now(', 'starts_at', 'ends_at', 'editable', 'canSave', 'canSubmit', 'disabled='] as $forbiddenUi) {
        $expect(! str_contains($indicator, $forbiddenUi), 'Occurrence indicator must remain informational: '.$forbiddenUi);
    }
    $expect(str_contains($frontend, "const editable = Boolean(serverCanEdit && periodStatus === 'grading_open')"), 'Existing professor grading-open edit rule must remain unchanged.');
    foreach (['save', 'submit'] as $mutation) {
        $mutationMethod = strpos($frontend, 'const '.$mutation.' = async () =>');
        $mutationEnd = strpos($frontend, $mutation === 'save' ? 'const submit = async () =>' : 'return (', $mutationMethod);
        $mutationSource = $mutationMethod === false || $mutationEnd === false ? '' : substr($frontend, $mutationMethod, $mutationEnd - $mutationMethod);
        $expect(! str_contains($mutationSource, 'occurrence') && ! str_contains($mutationSource, 'is_occurring'), 'Frontend mutation must not depend on occurrence: '.$mutation);
    }

    $expect((glob($backendRoot.'/database/migrations/*supplementary*occurrence*') ?: []) === [], 'Phase 6 must not add a migration.');
    $expect(! is_dir($backendRoot.'/database/sql/academic-calendar-phase6-supplementary-integration-hardening'), 'Phase 6 must not add an SQL package.');

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

    fwrite(STDOUT, "Academic Calendar Phase 6 supplementary integration contract passed.\n");
}

return $contract;
