<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) {
            $errors[] = $message;
        }
    };
    $read = static fn (string $path): string => is_file($path) ? (string) file_get_contents($path) : '';

    $sqlRoot = $backendRoot.'/database/sql/semester-registration-timetable-phase4';
    $expectedSql = ['00_preflight.sql', '01_apply.sql', '02_verify.sql'];
    $actualSql = is_dir($sqlRoot)
        ? array_values(array_map('basename', glob($sqlRoot.'/*') ?: []))
        : [];
    sort($actualSql);
    $expect($actualSql === $expectedSql, 'Phase 4 SQL package must contain exactly preflight, apply, and verify scripts.');

    $preflight = $read($sqlRoot.'/00_preflight.sql');
    $apply = $read($sqlRoot.'/01_apply.sql');
    $verify = $read($sqlRoot.'/02_verify.sql');
    foreach ([$preflight, $apply, $verify] as $sql) {
        $expect(str_contains($sql, '`alrowad_uni_rust`'), 'Every Phase 4 SQL script must target alrowad_uni_rust explicitly.');
        $expect(! preg_match('/\bDATABASE\s*\(/i', $sql), 'Phase 4 SQL must not use DATABASE().');
        $expect(! preg_match('/^\s*(?:INSERT\s+INTO|UPDATE\s+|DELETE\s+FROM)\b/im', $sql), 'Phase 4 SQL must not mutate production data.');
        $expect(! preg_match('/\b(?:PROCEDURE|DELIMITER|SIGNAL)\b/i', $sql), 'Phase 4 SQL must not use procedures, DELIMITER, or SIGNAL.');
    }
    $expect(str_contains($preflight, "SELECT 'OVERALL'") && str_contains($preflight, "'READY','BLOCKED'"), 'Preflight must visibly end READY/BLOCKED.');
    $expect(substr_count(strtoupper($apply), 'CREATE TABLE') === 1, 'Apply must create exactly one Phase 4 table.');
    $expect(str_contains($apply, 'course_offering_schedule_slots'), 'Apply must create the canonical schedule-slot table.');
    foreach (['chk_coss_component', 'chk_coss_day', 'chk_coss_interval', 'fk_coss_offering', 'fk_coss_created_by', 'uq_coss_exact_slot'] as $marker) {
        $expect(str_contains($apply, $marker) && str_contains($verify, $marker), 'Missing SQL contract marker: '.$marker);
    }
    $expect(str_contains($verify, "SELECT 'OVERALL'") && str_contains($verify, "'PASS','FAIL'"), 'Verify must visibly end PASS/FAIL.');

    $service = $read($backendRoot.'/app/Services/CourseOfferingScheduleService.php');
    $calendar = $read($backendRoot.'/app/Services/AcademicCalendarPolicyService.php');
    $registration = $read($backendRoot.'/app/Services/RegistrationService.php');
    $availableResource = $read($backendRoot.'/app/Http/Resources/AvailableCourseOfferingResource.php');
    $summaryResource = $read($backendRoot.'/app/Http/Resources/RegistrationSummaryItemResource.php');
    $requests = $read($backendRoot.'/app/Services/RegistrationRequestService.php');
    $dean = $read($backendRoot.'/app/Services/DeanRegistrationOfferingService.php');
    $routes = $read($backendRoot.'/routes/api.php');

    $expect(str_contains($service, '$this->coverage->requiredRoles('), 'Timetable components must come from the canonical instructor-coverage service.');
    $expect(str_contains($service, "'components_defined' => \$componentsDefined"), 'Canonical descriptions must expose components_defined.');
    $expect(str_contains($service, "'complete' => \$componentsDefined && \$missing === [] && \$invalid === [] && ! \$hasInternalOverlap"), 'Undefined, incompatible, or internally overlapping schedules must never be complete.');
    $expect(str_contains($service, '! in_array($component, $required, true)'), 'Writes must reject syntactically valid but non-required components.');
    $expect(str_contains($service, 'new EloquentCollection($offerings->all())') && str_contains($service, '$offerings->loadMissing(\'course\')'), 'Batch descriptions must eager-load Course rows once rather than once per Offering.');
    $expect(str_contains($service, "(string) \$a['start_time'] < (string) \$b['end_time']") && str_contains($service, "(string) \$b['start_time'] < (string) \$a['end_time']"), 'Conflict detection must use half-open intervals.');
    $expect(str_contains($service, "->current()\n                    ->pluck('course_offering_id')"), 'Canonical current registrations must be resolved inside the central timetable service.');
    $expect(str_contains($service, 'Schema::hasTable(\'course_offering_schedule_slots\')'), 'Missing Phase 4 schema must be detected before schedule queries.');
    $expect(str_contains($service, 'LOCK_REGISTRATION_STARTED') && str_contains($service, 'LOCK_OFFICIAL_REGISTRATION') && str_contains($service, 'LOCK_REQUEST_RELIANCE'), 'All irreversible timetable locking boundaries must be represented.');
    $expect(str_contains($service, '$this->scope->canMutateProgram(') && ! str_contains($service, "hasRoleCode('super_admin')"), 'Dean timetable writes must require actual mutation DataScope without a super-admin shortcut.');
    $expect(! str_contains($service, 'AttendanceSession') && ! str_contains($service, 'attendance_sessions'), 'The canonical timetable service must not read attendance sessions.');

    $expect(str_contains($calendar, 'courseRegistrationHasEverStarted(') && str_contains($calendar, "orWhereNull('ace.semester_id')"), 'Historical registration-start detection must reuse calendar data and year-wide wildcard semantics.');
    $expect(str_contains($registration, '$this->schedules->registrationEvaluations(') && str_contains($registration, '$this->assertTimetableEvaluation($timetable)'), 'Final registration materialization must freshly defend timetable completeness/conflicts.');
    $finalTimetableCheck = strpos($registration, '$timetable = $this->schedules->registrationEvaluations(');
    $registrationWrite = strpos($registration, '$registrationDate = $data[\'registration_date\']');
    $expect($finalTimetableCheck !== false && $registrationWrite !== false && $finalTimetableCheck < $registrationWrite, 'The fresh timetable defense must occur immediately before create/reactivation preparation.');
    $expect(str_contains($registration, '$this->schedules->describeMany($registrationOfferings)'), 'Official registrations must batch-load their recurring timetables.');
    $expect(str_contains($availableResource, "'official_timetable'") && str_contains($availableResource, "'timetable_conflicts'"), 'Available-course resources must expose additive timetable data.');
    $expect(str_contains($summaryResource, "'official_timetable'"), 'Official registration summaries must expose their timetable.');
    $expect(str_contains($requests, 'collectItemFailures') && str_contains($requests, 'timetableEvaluations('), 'Request add/submit/advisor approval must use the canonical timetable evaluation.');
    $expect(str_contains($dean, "'official_timetable'"), 'Dean catalog must expose additive official timetable data.');
    $expect(str_contains($routes, "registration-offerings/{courseOffering}/timetable") && str_contains($routes, 'replaceTimetable'), 'The narrow Dean timetable replacement route is missing.');

    $opening = $read($backendRoot.'/app/Services/CourseOfferingOpeningService.php');
    $expect(! str_contains($opening, 'CourseOfferingScheduleService'), 'Timetable completeness must not change normal/exceptional opening governance.');
    $attendance = $read($backendRoot.'/app/Services/AttendanceService.php');
    $expect(! str_contains($attendance, 'CourseOfferingScheduleService'), 'Attendance behavior must remain independent of the recurring timetable.');
    $expect((glob($backendRoot.'/database/migrations/*schedule*slot*') ?: []) === [], 'Phase 4 must not add a migration.');
    $expect((glob($backendRoot.'/database/seeders/*Timetable*') ?: []) === [], 'Phase 4 must not add a seeder.');

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
    fwrite(STDOUT, "Semester Registration Phase 4 timetable contract passed.\n");
}

return $contract;
