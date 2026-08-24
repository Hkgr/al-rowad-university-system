<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $servicePath = $backendRoot.'/app/Services/AcademicCalendarPolicyService.php';
    $resultPath = $backendRoot.'/app/Support/AcademicCalendarPolicyResult.php';
    $statusPath = $backendRoot.'/app/Support/AcademicCalendarPolicyStatus.php';

    foreach ([$servicePath, $resultPath, $statusPath] as $file) {
        if (! is_file($file)) {
            $errors[] = 'Missing Phase 3 policy file: '.basename($file);
        }
    }
    if ($errors !== []) {
        return $errors;
    }

    $service = file_get_contents($servicePath);
    $result = file_get_contents($resultPath);
    $status = file_get_contents($statusPath);
    $routes = file_get_contents($backendRoot.'/routes/api.php');

    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) {
            $errors[] = $message;
        }
    };

    foreach (['OPEN', 'CLOSED', 'INVALID_EVENT_TYPE', 'INVALID_ACADEMIC_YEAR', 'INVALID_SEMESTER_CONTEXT', 'CALENDAR_CONFIGURATION_ERROR'] as $case) {
        $expect(str_contains($status, 'case '.$case.' ='), 'Missing typed policy status: '.$case);
    }
    foreach (['eventTypeCode', 'academicYearId', 'semesterId', 'evaluatedAt', 'matchingWindowCount', 'reasonCode'] as $field) {
        $expect(str_contains($result, 'public ') && str_contains($result, '$'.$field), 'Missing narrow policy result field: '.$field);
    }
    foreach (['changeReason', 'createdByUserId', 'publishedByUserId', 'cancellationReason'] as $privateField) {
        $expect(! str_contains($result, '$'.$privateField), 'Policy result exposes private audit data: '.$privateField);
    }
    $expect(str_contains($result, 'final readonly class AcademicCalendarPolicyResult'), 'Policy result must be immutable.');
    $expect(str_contains($result, 'public function isOpen(): bool'), 'Policy result must expose a typed open predicate.');

    $expect(str_contains($service, 'public function evaluate(') && str_contains($service, '?CarbonInterface $at = null') && str_contains($service, '): AcademicCalendarPolicyResult'), 'Canonical evaluate signature is missing.');
    $expect(substr_count($service, 'public function evaluate(') === 1, 'Policy must have one canonical evaluation implementation.');
    $expect(str_contains($service, "where('event_type_code', \$eventTypeCode)"), 'Event type must be resolved by stable machine code.');
    $expect(! preg_match('/academic_calendar_event_type_id[^\n]{0,80},\s*[0-9]+\b/', $service), 'Policy contains a hardcoded numeric calendar event type ID.');

    foreach ([
        "where('ace.academic_calendar_event_type_id'",
        "where('ace.academic_year_id'",
        "whereNull('ace.cancelled_at')",
        "where('acev.publication_status', 'published')",
        "whereNull('acev.superseded_at')",
        "where('acev.is_enforcement', true)",
        "where('acev.starts_at', '<='",
        "where('acev.ends_at', '>='",
        "whereNull('ace.semester_id')",
    ] as $predicate) {
        $expect(str_contains($service, $predicate), 'Missing policy predicate: '.$predicate);
    }
    $expect(! str_contains($service, 'acev.semester_id') && ! str_contains($service, 'acev.academic_calendar_event_type_id'), 'Revision rows must not own academic context.');
    $expect(! preg_match('/\bMAX\s*\(|max\s*\(\s*[\'\"]version_number/i', $service), 'Policy must not infer the current publication from maximum version number.');
    $expect(str_contains($service, 'CarbonImmutable::now(\'UTC\')') && str_contains($service, 'CarbonImmutable::instance($at)->utc()'), 'Policy must normalize the application clock and supplied time to UTC.');
    $expect(! str_contains($service, 'Asia/Damascus'), 'Backend policy must not use the display timezone.');

    foreach (['Schema::', 'schemaReady(', 'information_schema', 'QueryException', 'catch ('] as $forbidden) {
        $expect(! str_contains($service, $forbidden), 'Policy must not inspect or normalize physical schema failures: '.$forbidden);
    }

    $workflowFiles = glob($backendRoot.'/app/Services/*.php') ?: [];
    foreach ($workflowFiles as $workflowFile) {
        if (! preg_match('/(Registration|Withdrawal|Grade|Supplementary|Dean|Appeal)/', basename($workflowFile))) {
            continue;
        }
        $expect(! str_contains(file_get_contents($workflowFile), 'AcademicCalendarPolicyService'), 'Phase 3 policy is already integrated into workflow: '.basename($workflowFile));
    }
    $expect(! str_contains($routes, 'AcademicCalendarPolicyService') && ! str_contains($routes, 'academic-calendar/policy'), 'Phase 3 must not expose a policy diagnostic route.');

    $calendarMigrations = glob($backendRoot.'/database/migrations/*academic*calendar*') ?: [];
    $expect($calendarMigrations === [], 'Phase 3 must not add an Academic Calendar migration.');
    $expect(! is_dir($backendRoot.'/database/sql/academic-calendar-phase3-enforcement-core'), 'Phase 3 must not add a SQL package.');

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

    fwrite(STDOUT, "Academic Calendar Phase 3 policy contract passed.\n");
}

return $contract;
