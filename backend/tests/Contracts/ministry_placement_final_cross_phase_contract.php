<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) $errors[] = $message;
    };
    $phases = [
        1 => file_get_contents($backendRoot.'/app/Services/MinistryPlacementService.php'),
        2 => file_get_contents($backendRoot.'/app/Services/MinistryPlacementProgramMatchingService.php'),
        3 => file_get_contents($backendRoot.'/app/Services/MinistryPlacementApplicantConversionService.php'),
        4 => file_get_contents($backendRoot.'/app/Services/MinistryPlacementStudentEnrollmentService.php'),
        5 => file_get_contents($backendRoot.'/app/Services/MinistryPlacementReconciliationService.php'),
    ];

    $expect(str_contains($phases[1], 'MinistryPlacementBatch::') && str_contains($phases[1], "DB::table('ministry_placement_records')->insert"), 'Phase 1 staging boundary changed.');
    $expect(str_contains($phases[2], "'processing_status' => 'program_matched'") && ! str_contains($phases[2], 'Applicant::query()->create'), 'Phase 2 program-match boundary changed.');
    $expect(str_contains($phases[3], 'Applicant::query()->create') && str_contains($phases[3], 'AdmissionApplication::query()->create') && ! str_contains($phases[3], 'Student::query()->create'), 'Phase 3 applicant boundary changed.');
    $expect(str_contains($phases[4], 'Student::query()->create') && ! str_contains($phases[4], 'User::query()->create') && ! str_contains($phases[4], 'StudentCourseRegistration::query()->create'), 'Phase 4 Student-only boundary changed.');
    foreach (['::create(', '->create(', '->update(', '->delete(', '->insert(', 'DB::transaction', 'lockForUpdate'] as $write) {
        $expect(! str_contains($phases[5], $write), 'Phase 5 must stay read-only: '.$write);
    }
    foreach (['User::query()->create', 'UserRole::query()->create', 'StudentAcademicTerm::query()->create', 'StudentCourseRegistration::query()->create', 'CourseOffering::query()->create'] as $forbidden) {
        $expect(! str_contains(implode("\n", $phases), $forbidden), 'Ministry workflow crosses an excluded boundary: '.$forbidden);
    }

    return $errors;
};

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = $contract(dirname(__DIR__, 2));
    if ($errors !== []) {
        foreach ($errors as $error) fwrite(STDERR, $error.PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, "Ministry Placement final cross-phase contract passed.\n");
}

return $contract;
