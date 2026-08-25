<?php

$root = dirname(__DIR__, 2);
$repo = dirname($root);
$paths = [
    'request' => $root.'/app/Http/Requests/SupplementaryExamGrading/SaveSupplementaryExamGradesRequest.php',
    'controller' => $root.'/app/Http/Controllers/Api/SupplementaryExamGradingController.php',
    'grading' => $root.'/app/Services/SupplementaryExamGradingService.php',
    'materialization' => $root.'/app/Services/SupplementaryExamMaterializationService.php',
    'reconciliation' => $root.'/app/Services/SupplementaryExamReconciliationService.php',
    'registration' => $root.'/app/Services/SupplementaryExamRegistrationService.php',
    'office' => $root.'/app/Http/Controllers/Api/SupplementaryExamRegistrationOfficeController.php',
    'app' => $repo.'/frontend/src/app/App.jsx',
    'auth' => $repo.'/frontend/src/features/auth/auth.js',
    'student' => $repo.'/frontend/src/features/student-dashboard/pages/StudentSupplementaryExams.jsx',
    'professor' => $repo.'/frontend/src/features/professor-dashboard/pages/ProfessorSupplementaryExams.jsx',
    'exam' => $repo.'/frontend/src/features/exam-board/pages/SupplementaryGradesPage.jsx',
    'affairs' => $repo.'/frontend/src/features/student-affairs/pages/SupplementaryExamRegistrations.jsx',
    'shared' => $repo.'/frontend/src/features/supplementary-exams/SupplementaryUi.jsx',
];
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$source = [];
foreach ($paths as $name => $path) {
    $expect(is_file($path), 'Missing hardening source: '.$name);
    $source[$name] = is_file($path) ? file_get_contents($path) : '';
}

foreach (['marks', 'supplementary_exam_registration_id', 'theoretical_mark'] as $allowed) {
    $expect(str_contains($source['request'], $allowed), 'Theoretical request allowlist is missing '.$allowed);
}
$expect(str_contains($source['request'], "array:supplementary_exam_registration_id,theoretical_mark"), 'Nested marks must reject unknown keys.');
$expect(str_contains($source['request'], "array_diff(array_keys(\$this->all()), ['marks'])"), 'Top-level request must reject unknown keys before sanitization.');
foreach (['practical_mark', 'practical_total', 'practical_components', 'components'] as $forbidden) {
    $expect(! str_contains($source['controller'], "'{$forbidden}'"), 'Controller accepts forbidden mark field: '.$forbidden);
}
$expect(str_contains($source['controller'], 'SaveSupplementaryExamGradesRequest $request'), 'Save action does not use the strict Form Request.');

foreach (['grading', 'registration'] as $projection) {
    $expect(str_contains($source[$projection], "->orderByDesc('submission_version')")
        && str_contains($source[$projection], "->orderByDesc('supplementary_exam_grade_submission_id')"), 'Read projection ordering is incomplete: '.$projection);
}
$expect(str_contains($source['grading'], '$latestSubmissions->count() !== 1')
    && str_contains($source['grading'], 'supplementary_grade_submission_integrity_error'), 'Review/approve/publish ambiguity guard is missing.');
$expect(str_contains($source['materialization'], '$latestSubmissions->count() !== 1')
    && str_contains($source['materialization'], 'supplementary_materialization_stale_submission'), 'Materialization ambiguity guard is missing.');
$expect(str_contains($source['reconciliation'], '$latestSubmissions->count() === 1 ? $latestSubmissions->first() : null')
    && str_contains($source['reconciliation'], 'source_submission_version_ambiguous'), 'Reconciliation must not pick a winner on ambiguity.');

foreach (['published_supplementary_result', 'official_result', 'official_record_updated', 'preserved_practical_mark', 'practical_minimum'] as $field) {
    $expect(str_contains($source['registration'], "'{$field}'"), 'Student-safe result projection is missing '.$field);
}
foreach (['reviewed_by_user_id', 'published_by_user_id', 'change_reason'] as $privateField) {
    $expect(! str_contains($source['registration'], "'{$privateField}'"), 'Student projection exposes provenance: '.$privateField);
}
$expect(str_contains($source['office'], "'per_page' => ['nullable', 'integer', 'min:1', 'max:100']")
    && str_contains($source['office'], "'search' => ['nullable', 'string', 'max:100']")
    && str_contains($source['office'], "'summary'")
    && str_contains($source['office'], "'meta'"), 'Student Affairs bounded read contract is incomplete.');
$expect(str_contains($source['office'], 'hasActualUniversityScope($user)'), 'Student Affairs period DataScope guard is missing.');

foreach (['student', 'professor', 'exam', 'affairs'] as $page) {
    $expect(! str_contains($source[$page], 'window.confirm'), 'Browser confirm remains in '.$page);
    $expect(! str_contains($source[$page], 'window.prompt'), 'Browser prompt remains in '.$page);
}
$expect(str_contains($source['shared'], 'SupplementaryConfirmDialog'), 'Shared RTL confirmation/reason dialog is missing.');
$expect(str_contains($source['student'], 'لم يُحدّث السجل الأكاديمي الرسمي بعد')
    && str_contains($source['student'], 'تم تحديث نتيجتك الأكاديمية الرسمية'), 'Student UI does not distinguish published preview from official result.');
$expect(! str_contains($source['exam'], 'row?.candidates')
    && ! str_contains($source['exam'], 'row?.registrations')
    && ! str_contains($source['exam'], 'row.actions?.'), 'Exam Board still relies on legacy payload aliases.');
$expect(str_contains($source['exam'], 'row.action_flags?.can_publish')
    && str_contains($source['exam'], 'materialization.can_materialize'), 'Exam Board actions are not backend-capability driven.');
$expect(str_contains($source['auth'], 'assignedPermissions')
    && str_contains($source['app'], "allRoles: ['exam_officer'], assignedPermissions: ['supplementary_exams.grades.review']"), 'Route authority does not require actual role plus assigned permission.');

$diffNames = implode(PHP_EOL, [
    (string) shell_exec('git diff --name-only origin/develop...HEAD'),
    (string) shell_exec('git diff --name-only'),
    (string) shell_exec('git diff --cached --name-only'),
    (string) shell_exec('git ls-files --others --exclude-standard'),
]);
$expect(! preg_match('~(?:^|/)(?:database/migrations|database/sql)/~m', (string) $diffNames), 'Hardening includes a migration or SQL change.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Supplementary exams end-to-end hardening contract: PASS\n");
