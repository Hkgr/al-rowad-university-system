<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $frontendRoot = dirname($backendRoot).'/frontend/src';
    $paths = [
        'dean' => $frontendRoot.'/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx',
        'planner' => $frontendRoot.'/features/dean-dashboard/utils/deanOfferingPlanner.js',
        'exam' => $frontendRoot.'/features/exam-board/pages/CourseOfferingsPage.jsx',
        'catalog' => $frontendRoot.'/features/exam-board/lib/courseCatalog.js',
        'nav' => $frontendRoot.'/features/exam-board/nav.js',
        'home' => $frontendRoot.'/features/exam-board/pages/ExamBoardHome.jsx',
        'routes' => $backendRoot.'/routes/api.php',
        'dean_service' => $backendRoot.'/app/Services/DeanRegistrationOfferingService.php',
        'registration' => $backendRoot.'/app/Services/RegistrationService.php',
        'requirements' => $backendRoot.'/app/Services/AcademicRequirementService.php',
        'professor' => $backendRoot.'/app/Services/ProfessorGradeAssignmentService.php',
        'grades' => $backendRoot.'/app/Services/GradePartWorkflowService.php',
        'scientific' => $backendRoot.'/app/Services/TeachingAssignmentWorkflowService.php',
        'administrative_exception' => $backendRoot.'/app/Services/CourseOfferingExceptionWorkflowService.php',
        'administrative_closure' => $backendRoot.'/app/Services/CourseOfferingClosureWorkflowService.php',
        'supplementary' => $backendRoot.'/app/Services/SupplementaryExamOfferingSourceService.php',
    ];

    foreach ($paths as $name => $path) {
        if (! is_file($path)) {
            $errors[] = 'Missing operational source-of-truth source: '.$name;
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

    // OPSRC-DEAN-01..10: one cross-level actual-term list and local-only Add.
    $expect(str_contains($sources['planner'], 'export function actualTermPreparationRows(levels, draftIds)'), 'Missing actual-term preparation helper.');
    $expect(str_contains($sources['planner'], 'flattenCatalogCourses(levels).forEach'), 'Actual-term helper must inspect the complete program catalog.');
    $expect(str_contains($sources['planner'], '!row.offering && !draftSet.has(id)'), 'Actual-term helper must combine persisted offerings and drafts.');
    $expect(! preg_match('/actualTermPreparationRows[\s\S]{0,1800}recommended_semester_id/', $sources['planner']), 'Actual-term helper must not filter by recommended semester.');
    $expect(str_contains($sources['dean'], 'المواد المحددة للفصل الأكاديمي'), 'Dean page is missing the authoritative actual-term section.');
    $expect(str_contains($sources['dean'], 'الخطة الدراسية الإرشادية'), 'Dean page is missing the separate advisory curriculum section.');
    $expect(str_contains($sources['dean'], 'actualTermRows.map(row => courseCard(row))'), 'Dean page must render the unified actual-term rows.');
    $expect(str_contains($sources['dean'], '(level.courses ?? []).map(row => ('), 'Advisory section must retain the complete original curriculum grouping.');
    $expect(str_contains($sources['dean'], 'إضافة إلى التجهيز'), 'Manual Add must use preparation terminology.');
    $expect(str_contains($sources['dean'], 'اضغط «حفظ التجهيز» لإنشاء الطرح الفعلي'), 'Manual Add must explain that the draft is not saved.');
    $expect(str_contains($sources['dean'], "label: 'غير محفوظ'") && str_contains($sources['dean'], "label: 'محفوظ — مغلق'"), 'Actual-term cards must distinguish draft and persisted-closed states.');
    $expect(str_contains($sources['dean'], "label: 'بانتظار اكتمال التكليف'"), 'Actual-term cards must retain pending coverage state.');
    $addStart = strpos($sources['dean'], 'function addCourseToDraft');
    $addEnd = strpos($sources['dean'], 'function courseCard', $addStart === false ? 0 : $addStart);
    $add = $addStart === false || $addEnd === false ? '' : substr($sources['dean'], $addStart, $addEnd - $addStart);
    $expect($add !== '' && ! str_contains($add, 'apiRequest'), 'Dean Add must remain local UI state only.');
    $saveStart = strpos($sources['dean'], 'async function savePreparation');
    $saveEnd = strpos($sources['dean'], 'const confirmBusy', $saveStart === false ? 0 : $saveStart);
    $save = $saveStart === false || $saveEnd === false ? '' : substr($sources['dean'], $saveStart, $saveEnd - $saveStart);
    $expect(str_contains($save, "mode: 'selected'") && str_contains($save, '/v1/dean/registration-offerings/bulk-prepare'), 'Dean Save must retain the selected bulk-prepare workflow.');
    $expect(strpos($save, 'await reloadCatalog()') < strpos($save, 'setDraftIds(outcome.draftIds)'), 'Dean Save must reload authoritative data before final draft state.');

    // OPSRC-EXAM-01..11: persisted offering first, optional advisory metadata only.
    foreach ([
        'handleAddCurriculumCourse',
        'handleRemoveCurriculumCourse',
        'handleOpenOffering',
        'handleToggleStatus',
        'InstructorAssignment',
        "method: 'POST'",
        "method: 'PUT'",
        "method: 'PATCH'",
        "method: 'DELETE'",
    ] as $forbidden) {
        $expect(! str_contains($sources['exam'], $forbidden), 'Exam Board offerings page retains mutation surface: '.$forbidden);
    }
    $expect(str_contains($sources['exam'], 'loadCourseOfferingsCatalog({ request: apiRequest })'), 'Exam Board page must use the complete atomic catalog loader.');
    $expect(str_contains($sources['catalog'], 'return (offerings ?? []).map(offering =>'), 'Actual offerings must drive the read projection.');
    $expect(str_contains($sources['catalog'], "programCourse?.academic_level_id ?? null"), 'ProgramCourse advisory level must be optional.');
    $expect(str_contains($sources['catalog'], "programCourse?.recommended_semester_id ?? null"), 'ProgramCourse advisory semester must be optional.');
    $expect(str_contains($sources['catalog'], 'row.is_active === true'), 'Only active ProgramCourse rows may enrich current advisory metadata.');
    $expect(str_contains($sources['exam'], "advisory.academic_level_name || 'غير محدد'") && str_contains($sources['exam'], "advisory.recommended_semester_name || 'غير محدد'"), 'Missing advisory metadata must render as unspecified.');
    foreach (['offering.status', 'offering.capacity', 'offering.available_seats', 'offering.instructor_coverage'] as $authority) {
        $expect(str_contains($sources['exam'], $authority), 'Exam Board page must read operational authority from '.$authority.'.');
    }
    foreach (['offering.course_id', 'offering.academic_program_id', 'offering.academic_year_id', 'offering.semester_id'] as $identity) {
        $expect(str_contains($sources['catalog'], $identity), 'Actual offering filtering must retain '.$identity.'.');
    }
    $expect(! preg_match('/recommended_semester_id[^\n]*(?:===|!==)[^\n]*(?:semesterId|academicYearId)/', $sources['catalog'].$sources['exam']), 'Recommended semester must never gate actual offerings.');
    $expect(str_contains($sources['exam'], 'الطروحات الأكاديمية') && ! str_contains($sources['exam'], 'فتح المواد الدراسية'), 'Exam Board page must use read-only offering terminology.');
    $expect(str_contains($sources['nav'], "ar: 'الطروحات الأكاديمية'") && str_contains($sources['home'], "ar: 'الطروحات الأكاديمية'"), 'Exam Board navigation and home card must use read-only terminology.');
    $expect(str_contains($sources['home'], 'access: ACCESS.courseManagement') && str_contains($sources['home'], 'canAccess(access, user)'), 'Home-card visibility must match the existing view access contract.');

    // Other actors retain actual CourseOffering authority and real eligibility rules.
    $expect(str_contains($sources['dean_service'], "'status' => self::STATUS_CLOSED"), 'Dean preparation must still create closed offerings.');
    $expect(str_contains($sources['registration'], 'course_offering_id') && str_contains($sources['requirements'], 'evaluateRegistrationCandidate'), 'Student registration must retain actual offering identity and academic requirements.');
    foreach (['professor', 'grades', 'scientific', 'administrative_exception', 'administrative_closure'] as $name) {
        $expect(str_contains($sources[$name], 'course_offering_id'), $name.' workflow must retain actual offering identity.');
        $expect(! str_contains($sources[$name], 'recommended_semester_id'), $name.' workflow must not add an advisory-semester gate.');
    }
    $expect(str_contains($sources['supplementary'], "->where('academic_year_id', \$yearId)") && str_contains($sources['supplementary'], "return \$query->where('semester_id', \$periodSemesterId)"), 'Supplementary sources must retain the actual offering term.');

    // Generic CRUD remains deliberately unchanged pending a separate API/RBAC repair.
    $expect(str_contains($sources['routes'], "Route::apiResource('course-offerings', CourseOfferingController::class)"), 'This UI cleanup must not silently remove the generic CourseOffering API.');
    $expect((glob($backendRoot.'/database/migrations/*operational*source*') ?: []) === [], 'Operational cleanup must not add migrations.');
    $expect(! is_dir($backendRoot.'/database/sql/course-offering-operational-source-of-truth'), 'Operational cleanup must not add a SQL package.');

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

    fwrite(STDOUT, "Course Offering operational source-of-truth contract passed.\n");
}

return $contract;
