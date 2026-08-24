<?php

$root = dirname(__DIR__, 2);
$files = [
    'service' => $root.'/app/Services/SupplementaryExamOverviewService.php',
    'grading' => $root.'/app/Services/SupplementaryExamGradingService.php',
    'controller' => $root.'/app/Http/Controllers/Api/SupplementaryExamOverviewController.php',
    'routes' => $root.'/routes/api.php',
    'page' => $root.'/../frontend/src/features/exam-board/pages/SupplementaryExamsPage.jsx',
    'helper' => $root.'/../frontend/src/features/exam-board/lib/supplementaryOverview.js',
];
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$source = [];
foreach ($files as $name => $path) {
    $expect(is_file($path), "Missing {$name}: {$path}");
    $source[$name] = is_file($path) ? file_get_contents($path) : '';
}

foreach ([
    "Route::get('exams/supplementary-overview', SupplementaryExamOverviewController::class)",
    'SupplementaryExamRegistrationGovernance::VIEW',
    "'period_id' => ['sometimes', 'integer', 'min:1']",
    "'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']",
] as $needle) {
    $expect(str_contains($source['routes'].$source['controller'], $needle), 'Endpoint contract missing: '.$needle);
}

foreach ([
    "->whereNotNull('status')", "->where('status', '<>', 'legacy')",
    "->where('status', 'registered')", "->where('current_slot', 1)",
    'scopePrograms($program, $actor)', 'scopeStudents($student, $actor)',
    'AcademicQueuePagination::perPage', 'AcademicQueuePagination::meta',
    "'supplementary_exam_occurrence'", "'can_access_grades'",
    "'offerings_count'", "'registered_students_count'", "'published_offerings_count'", "'materialized_students_count'",
] as $needle) {
    $expect(str_contains($source['service'], $needle), 'Overview projection missing: '.$needle);
}

foreach (['announced', 'registration_open', 'registration_closed', 'grading_open', 'grading_submitted', 'results_approved', 'results_published', 'results_materialized'] as $status) {
    $expect(str_contains($source['service'], "'{$status}'"), 'Canonical lifecycle status missing: '.$status);
}
$expect(str_contains($source['grading'], 'public function latestSubmissionsForOfferings'), 'Canonical latest-submission helper is missing.');
$expect(str_contains($source['grading'], "->orderByDesc('submission_version')")
    && str_contains($source['grading'], "->orderByDesc('supplementary_exam_grade_submission_id')"), 'Latest submission ordering is incomplete.');
$expect(substr_count($source['service'], 'latestSubmissionsForOfferings(') === 1, 'Overview must load latest submissions once.');
$expect(! str_contains($source['service'], "hasRoleCode('super_admin')"), 'Super Admin role must not bypass actual DataScope in the overview service.');
$expect(str_contains($source['service'], 'if ($this->scope->hasActualUniversityScope($actor))'), 'Actual university scope must remain the only full-catalog bypass.');
$expect(! str_contains($source['service'], 'MAX(submission_version)'), 'Published counts must not approximate the canonical latest submission with MAX(version).');
$expect(str_contains($source['service'], '$publishedWinners = $latestSubmissions'), 'Published counts must use the canonical latest-submission collection.');
$expect(! preg_match('/\b(?:insert|update|delete|create|save|forceFill)\s*\(/i', $source['service'].$source['controller']), 'Overview backend must remain read-only.');
$expect(! str_contains($source['service'], 'forPage('), 'Summary must not derive from the paginated roster page.');
$expect(str_contains($source['helper'], "'/v1/exams/supplementary-overview'"), 'Frontend helper must target the overview endpoint.');
$expect(str_contains($source['page'], 'apiRequest(overviewQuery('), 'Frontend must use apiRequest with the overview helper.');
$expect(! preg_match('/method:\s*[\'\"](?:POST|PUT|PATCH|DELETE)/', $source['page']), 'Overview page exposes a mutation request.');
$expect(str_contains($source['page'], 'requestSequenceRef') && str_contains($source['page'], 'successfulPeriodRef'), 'Stale-response or trusted-snapshot guard is missing.');
$expect(str_contains($source['page'], 'علامات مدخلة') && ! str_contains($source['page'], '<span>مصححون'), 'Offering graded metric must use a precise student-grade label.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Exam Board supplementary overview contract: PASS\n");
