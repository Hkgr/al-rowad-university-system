<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $frontendRoot = dirname($backendRoot).'/frontend/src';
    $paths = [
        'routes' => $backendRoot.'/routes/api.php',
        'controller' => $backendRoot.'/app/Http/Controllers/Api/ExamStudentAcademicRecordController.php',
        'aggregate' => $backendRoot.'/app/Services/ExamStudentAcademicRecordService.php',
        'graduation' => $backendRoot.'/app/Services/GraduationEligibilityService.php',
        'identity' => $backendRoot.'/app/Services/UserIdentityService.php',
        'student_policy' => $backendRoot.'/app/Policies/StudentPolicy.php',
        'app' => $frontendRoot.'/app/App.jsx',
        'search' => $frontendRoot.'/features/exam-board/pages/GradeSheetPage.jsx',
        'student_picker' => $frontendRoot.'/features/exam-board/components/StudentPicker.jsx',
        'student_picker_search' => $frontendRoot.'/features/exam-board/lib/studentPickerSearch.js',
        'record' => $frontendRoot.'/features/exam-board/pages/ExamStudentAcademicRecordPage.jsx',
        'student_requirements' => $frontendRoot.'/features/student-dashboard/pages/StudentRequirements.jsx',
        'shared_requirements' => $frontendRoot.'/components/academic/AcademicRequirementProgress.jsx',
        'requirements_helper' => $frontendRoot.'/components/academic/requirementProgress.js',
        'pdf' => $frontendRoot.'/features/exam-board/lib/transcriptPdf.js',
        'record_presentation' => $frontendRoot.'/features/exam-board/lib/academicRecordPresentation.js',
    ];

    foreach ($paths as $name => $path) {
        if (! is_file($path)) {
            $errors[] = 'Missing academic-record source: '.$name;
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

    $expect(str_contains($sources['routes'], "Route::get('students/{student}/academic-record'"), 'Missing aggregate academic-record GET route.');
    foreach ([
        "Route::get('student/transcript'",
        "Route::get('student/requirements'",
        "Route::get('student/graduation-eligibility'",
        "Route::get('students/{student}/transcript'",
        "Route::get('students/{student}/requirements'",
    ] as $existingRoute) {
        $expect(str_contains($sources['routes'], $existingRoute), 'Existing read route changed or disappeared: '.$existingRoute);
    }

    $permissionAt = strpos($sources['controller'], "hasPermission('grades.view')");
    $policyAt = strpos($sources['controller'], "Gate::authorize('view', \$student)");
    $snapshotAt = strpos($sources['controller'], '->snapshot($student, $request->user())');
    $expect($permissionAt !== false && $policyAt !== false && $snapshotAt !== false && $permissionAt < $policyAt && $policyAt < $snapshotAt, 'Authorization must be grades.view, then Student policy/DataScope, then snapshot.');
    foreach (['DB::', '::query()', 'selectRaw(', 'SUM(', 'AVG('] as $forbidden) {
        $expect(! str_contains($sources['controller'], $forbidden), 'Controller contains query/formula logic: '.$forbidden);
    }
    $expect(str_contains($sources['student_policy'], "hasPermission('students.view')") && str_contains($sources['student_policy'], 'canAccessStudent($user, $student)'), 'Student policy must compose students.view with actual DataScope access.');

    $expect(substr_count($sources['aggregate'], 'getTranscript($student)') === 1, 'Aggregate must obtain the canonical transcript exactly once.');
    $expect(substr_count($sources['aggregate'], 'getStudentRequirementProgress($student)') === 1, 'Aggregate must obtain canonical requirement progress exactly once.');
    foreach (['DB::transaction', 'beginTransaction', 'lockForUpdate', 'sharedLock'] as $forbidden) {
        $expect(! str_contains($sources['aggregate'], $forbidden), 'Read-only aggregate must not introduce transaction/locking primitive: '.$forbidden);
    }
    $expect(str_contains($sources['aggregate'], 'evaluateFromProgress($student, $progress)'), 'Aggregate must reuse precomputed progress for graduation eligibility.');
    $expect(str_contains($sources['aggregate'], 'catch (AcademicRequirementConfigurationException $exception)'), 'Only the expected requirement configuration failure may make the subsection unavailable.');
    foreach (['Throwable', 'QueryException', 'schemaReady', 'information_schema', 'Schema::has'] as $forbidden) {
        $expect(! str_contains($sources['aggregate'], $forbidden), 'Aggregate must not hide infrastructure/schema failures: '.$forbidden);
    }
    $expect(str_contains($sources['aggregate'], "CarbonImmutable::now('UTC')->toIso8601String()"), 'Generation time must be server-authoritative UTC.');
    $expect(str_contains($sources['aggregate'], "DISPLAY_TIMEZONE = 'Asia/Damascus'"), 'Missing fixed display timezone.');
    $expect(str_contains($sources['identity'], "'display_name'") && str_contains($sources['identity'], "'username'") && str_contains($sources['identity'], "'organizational_unit'"), 'Generated-by identity is incomplete.');

    $expect(str_contains($sources['app'], 'path="/exam-board/grade-sheet/:studentId"'), 'Missing protected standalone academic-record route.');
    $expect(str_contains($sources['search'], 'navigate(`/exam-board/grade-sheet/${student.student_id}`)'), 'Grade sheet search must navigate using only the selected student ID.');
    $expect(! str_contains($sources['search'], '/transcript') && ! str_contains($sources['search'], '/cgpa'), 'Search gateway must not load transcript or CGPA itself.');
    $expect(str_contains($sources['student_picker'], 'studentPickerSearchPath(debouncedQuery)') && str_contains($sources['student_picker'], 'STUDENT_PICKER_DEBOUNCE_MS'), 'Student picker must use debounced server-side search.');
    $expect(str_contains($sources['student_picker'], 'requestSequenceRef') && str_contains($sources['student_picker'], 'isLatestStudentPickerRequest'), 'Student picker must reject stale search responses.');
    $expect(str_contains($sources['student_picker_search'], "params.set('q', normalized)") && str_contains($sources['student_picker_search'], 'STUDENT_PICKER_PER_PAGE = 25'), 'Student picker search must use bounded existing q pagination.');
    $expect(! str_contains($sources['student_picker'], 'per_page=100') && ! str_contains($sources['student_picker'], '.filter('), 'Student picker must not search only a browser-filtered first-100 snapshot.');
    $expect(str_contains($sources['record'], 'apiRequest(endpoint)') && str_contains($sources['record'], 'const fresh = await apiRequest(endpoint)'), 'Page and export must use the same aggregate endpoint, with one fresh export snapshot.');
    $expect(! str_contains($sources['record'], '/cgpa'), 'Academic-record workflow must not issue a separate CGPA request.');
    $expect(str_contains($sources['student_requirements'], 'AcademicRequirementProgress') && str_contains($sources['record'], 'AcademicRequirementProgress'), 'Student and Exam Board pages must share requirement presentation.');
    $expect(str_contains($sources['requirements_helper'], 'REQUIREMENT_SCOPE_ORDER') && str_contains($sources['requirements_helper'], 'groupRequirementsByScope'), 'Missing dynamic requirement-scope presentation helper.');

    foreach (['academicRecord?.student', 'academicRecord?.transcript', 'academicRecord?.requirements', 'academicRecord?.generation'] as $officialSource) {
        $expect(str_contains($sources['pdf'], $officialSource), 'PDF does not consume official aggregate source: '.$officialSource);
    }
    $expect(str_contains($sources['pdf'], 'transcriptGenerationMetadata(academicRecord?.generation)'), 'PDF must format aggregate generation metadata.');
    $expect(str_contains($sources['record_presentation'], 'generation.generated_at') && str_contains($sources['record_presentation'], "timeZone: generation.timezone || 'Asia/Damascus'"), 'Generation formatter must use the server timestamp and declared display timezone.');
    $expect(! preg_match('/generationTimestamp\s*\([^)]*new Date/', $sources['pdf'].$sources['record_presentation']), 'PDF must not default generation metadata to the browser clock.');
    $expect(str_contains($sources['pdf'], 'requirements-unavailable') && str_contains($sources['pdf'], 'empty-terms'), 'PDF must remain valid for unavailable requirements and an empty transcript.');
    $expect(str_contains($sources['pdf'], 'paginateMeasuredSections') && str_contains($sources['pdf'], 'PDF_PAGE_CONFIGS.portrait'), 'PDF must retain the page-safe portrait paginator.');

    $expect((glob($backendRoot.'/database/migrations/*academic*record*transcript*') ?: []) === [], 'Academic-record feature must not add migrations.');
    $expect(! is_dir($backendRoot.'/database/sql/exam-board-academic-record'), 'Academic-record feature must not add SQL.');

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
    fwrite(STDOUT, "Exam Board academic-record contract passed.\n");
}

return $contract;
