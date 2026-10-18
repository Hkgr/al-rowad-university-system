<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $frontendRoot = dirname($backendRoot).'/frontend/src';
    $paths = [
        'dean' => $frontendRoot.'/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx',
        'planner' => $frontendRoot.'/features/dean-dashboard/utils/deanOfferingPlanner.js',
        'exam_board' => $frontendRoot.'/features/exam-board/pages/CourseTablePage.jsx',
        'course_catalog' => $frontendRoot.'/features/exam-board/lib/courseCatalog.js',
        'student_ui' => $frontendRoot.'/features/student-dashboard/pages/StudentRegistration.jsx',
        'dean_service' => $backendRoot.'/app/Services/DeanRegistrationOfferingService.php',
        'context' => $backendRoot.'/app/Services/CourseOfferingContextService.php',
        'opening' => $backendRoot.'/app/Services/CourseOfferingOpeningService.php',
        'registration' => $backendRoot.'/app/Services/RegistrationService.php',
        'requirements' => $backendRoot.'/app/Services/AcademicRequirementService.php',
        'professor' => $backendRoot.'/app/Services/ProfessorGradeAssignmentService.php',
        'grades' => $backendRoot.'/app/Services/GradePartWorkflowService.php',
        'teaching' => $backendRoot.'/app/Services/TeachingAssignmentWorkflowService.php',
        'exception' => $backendRoot.'/app/Services/CourseOfferingExceptionWorkflowService.php',
        'closure' => $backendRoot.'/app/Services/CourseOfferingClosureWorkflowService.php',
        'supplementary' => $backendRoot.'/app/Services/SupplementaryExamOfferingSourceService.php',
    ];

    foreach ($paths as $name => $path) {
        if (! is_file($path)) {
            $errors[] = 'Missing advisory audit source: '.$name;
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

    $dean = $sources['dean'];
    $planner = $sources['planner'];
    $deanService = $sources['dean_service'];
    $selected = $method($deanService, 'selectedProgramCoursesForBulkPrepare');
    $findOrCreate = $method($deanService, 'findOrCreateClosedOffering');
    $catalog = $method($deanService, 'curriculumLevels');
    $context = $method($sources['context'], 'resolveFromProgramCourse');
    $registrationConstraint = $method($sources['registration'], 'constrainSelfRegistrationOfferings');
    $registrationEligibility = $method($sources['registration'], 'annotateOfferingEligibility');

    // OFFER-ADV-01: the dialog starts from the whole active program catalog.
    $expect(str_contains($planner, 'export function flattenCatalogCourses('), 'OFFER-ADV-01 missing complete catalog flattener.');
    $expect(str_contains($planner, 'export function catalogCoursesForAdvisoryLevel('), 'OFFER-ADV-01 missing optional advisory filter.');
    $expect(str_contains($planner, 'if (academicLevelId == null || academicLevelId === \'\') return rows'), 'OFFER-ADV-01 all-level filter must return the complete candidate universe.');
    $expect(str_contains($dean, 'levels={levels}'), 'OFFER-ADV-01 dialog must receive the complete curriculum levels.');
    $expect(! str_contains($dean, 'courses={coursesForAcademicLevel('), 'OFFER-ADV-01 dialog must not receive a section-limited course list.');
    $expect(str_contains($dean, 'كل المستويات'), 'OFFER-ADV-01 dialog must expose the all-level option.');
    $expect(str_contains($dean, 'advisorySemesterDiffers(row, selectedSemesterId)'), 'Advisory warning must compare only the recommended semester with the actual selected semester.');
    $expect(! str_contains($dean, 'advisorySemesterDiffers(row, selectedSemesterId,'), 'Advisory level filter must not trigger a mismatch warning.');

    // OFFER-ADV-02/03/04: selected preparation accepts active program rows and preserves metadata.
    $expect(str_contains($selected, "whereIn('program_course_id', \$requested)"), 'OFFER-ADV-02 selected mode must use explicit ProgramCourse IDs.');
    $expect(str_contains($selected, '(int) $row->academic_program_id !== $programId'), 'OFFER-ADV-02 selected mode must retain program membership.');
    $expect(str_contains($selected, '! $row->is_active'), 'OFFER-ADV-02 selected mode must retain active curriculum membership.');
    $expect(! str_contains($selected, 'academic_level_id') && ! str_contains($selected, 'recommended_semester_id'), 'OFFER-ADV-02 selected mode must not gate by advisory metadata.');
    $expect(str_contains($findOrCreate, '$yearId') && str_contains($findOrCreate, '$semesterId'), 'OFFER-ADV-03 actual offering term must come from the selected year and semester.');
    $expect(! str_contains($findOrCreate, "where('recommended_semester_id'") && ! str_contains($context, 'recommended_semester_id'), 'OFFER-ADV-03 recommendation must not constrain actual offering context.');
    $expect(! str_contains($findOrCreate, '$programCourse->update(') && ! str_contains($findOrCreate, '$programCourse->save('), 'OFFER-ADV-04 offering creation must not rewrite ProgramCourse metadata.');

    // OFFER-ADV-05/06: student eligibility ignores level metadata but retains real rules.
    $expect(! str_contains($registrationConstraint, 'academic_level_id') && ! str_contains($registrationConstraint, 'recommended_semester_id'), 'OFFER-ADV-05 student visibility must not use advisory level or semester.');
    $expect(! str_contains($registrationEligibility, 'current_academic_level_id') && ! str_contains($registrationEligibility, 'recommended_semester_id'), 'OFFER-ADV-05 eligibility must not compare student level to advisory metadata.');
    $expect(str_contains($registrationEligibility, 'getMissingPrerequisites(') && str_contains($registrationEligibility, 'missing_prerequisites'), 'OFFER-ADV-06 prerequisite enforcement must remain active.');
    $expect(str_contains($sources['requirements'], 'evaluateRegistrationCandidate'), 'OFFER-ADV-06 academic requirement evaluation must remain active.');

    // OFFER-ADV-07..10: operational workflows are keyed to CourseOffering, not ProgramCourse advice.
    foreach ([
        'OFFER-ADV-07 professor' => $sources['professor'],
        'OFFER-ADV-08 exam board' => $sources['grades'],
        'OFFER-ADV-09 scientific VP' => $sources['teaching'],
        'OFFER-ADV-10 administrative VP exception' => $sources['exception'],
        'OFFER-ADV-10 administrative VP closure' => $sources['closure'],
    ] as $label => $source) {
        $expect(str_contains($source, 'course_offering_id'), $label.' must use actual CourseOffering identity.');
        $expect(! str_contains($source, 'recommended_semester_id') && ! str_contains($source, 'current_academic_level_id'), $label.' must not add advisory mismatch gates.');
    }

    // OFFER-ADV-11: exceptional opening remains about actual offering workflow evidence.
    $expect(! str_contains($sources['opening'], 'recommended_semester_id') && ! str_contains($sources['opening'], 'academic_level_id'), 'OFFER-ADV-11 opening must not require an exception for advisory mismatch.');

    // OFFER-ADV-12: supplementary sources retain actual offering term authority.
    $expect(str_contains($sources['supplementary'], 'CourseOffering::query()'), 'OFFER-ADV-12 supplementary source must query actual offerings.');
    $expect(str_contains($sources['supplementary'], "->where('academic_year_id', \$yearId)"), 'OFFER-ADV-12 supplementary source must use the actual offering year.');
    $expect(str_contains($sources['supplementary'], "return \$query->where('semester_id', \$periodSemesterId)"), 'OFFER-ADV-12 supplementary source must use the actual offering semester.');
    $expect(! str_contains($sources['supplementary'], 'recommended_semester_id'), 'OFFER-ADV-12 supplementary source must not use curriculum recommendation.');

    // OFFER-ADV-13: null advice remains a valid active curriculum row and is labeled explicitly.
    $expect(str_contains($catalog, "where('is_active', true)") && ! str_contains($catalog, 'whereNotNull(\'academic_level_id\')'), 'OFFER-ADV-13 catalog must include active rows with null advisory level.');
    $expect(! str_contains($selected, 'whereNotNull') && ! str_contains($context, 'whereNotNull'), 'OFFER-ADV-13 selected preparation must accept null advisory metadata.');
    $expect(str_contains($planner, 'المستوى الإرشادي غير محدد'), 'OFFER-ADV-13 missing null advisory-level label.');
    $expect(str_contains($planner, 'الفصل الإرشادي غير محدد'), 'OFFER-ADV-13 missing null advisory-semester label.');

    // The second discovered UI hard gate: the Exam Board table must show actual-term offerings.
    $expect(str_contains($sources['exam_board'], 'pc.is_active === true'), 'Exam Board table must keep active curriculum membership.');
    $expect(! preg_match('/filter\([^\n]*recommended_semester_id[^\n]*semId/', $sources['exam_board']), 'Exam Board table must not filter rows by recommended semester.');
    $expect(str_contains($sources['exam_board'], 'loadCourseTableCatalog({ request: apiRequest, canViewHr })'), 'Exam Board table must consume the complete canonical catalog loader.');
    $expect(str_contains($sources['course_catalog'], 'fetchAllPaginated(path, { request, primaryKey })'), 'Exam Board catalog loader must use the bounded canonical paginator.');
    foreach ([
        "'/v1/academic-years', 'academic_year_id'",
        "'/v1/semesters', 'semester_id'",
        "'/v1/colleges', 'college_id'",
        "'/v1/departments', 'department_id'",
        "'/v1/academic-programs', 'academic_program_id'",
        "'/v1/courses', 'course_id'",
        "'/v1/academic-levels', 'academic_level_id'",
        "'/v1/program-courses', 'program_course_id'",
        "'/v1/course-offerings', 'course_offering_id'",
        "'/v1/faculty-members', 'faculty_member_id'",
        "'/v1/employees', 'employee_id'",
    ] as $catalogContract) {
        $expect(str_contains($sources['course_catalog'], $catalogContract), 'Exam Board catalog loader is missing '.$catalogContract.'.');
    }
    $expect(! preg_match('/per_page=(?:200|500)/', $sources['exam_board']), 'Exam Board table must not rely on backend-clamped oversized page requests.');
    $expect(str_contains($sources['exam_board'], 'clearCatalog()') && str_contains($sources['exam_board'], 'catalogError'), 'Exam Board catalog failure must clear partial state and expose retryable error UI.');
    $expect(str_contains($sources['course_catalog'], 'String(offering.academic_year_id) === String(academicYearId)'), 'Exam Board table must retain actual offering year identity.');
    $expect(str_contains($sources['course_catalog'], 'String(offering.semester_id) === String(semesterId)'), 'Exam Board table must retain actual offering semester identity.');
    $expect(str_contains($sources['exam_board'], 'recommendedSemesterName'), 'Exam Board table should retain advisory semester as presentation metadata.');

    // Existing advisory presentation remains non-blocking.
    $expect(str_contains($sources['student_ui'], 'splitAdvisoryCourses('), 'Student UI should retain advisory grouping.');
    $expect(str_contains($sources['student_ui'], 'disabled={!eligible || busy}'), 'Student add control must remain driven by canonical backend eligibility.');
    $expect(! preg_match('/disabled=\{[^}]*advisory/', $sources['student_ui']), 'Student UI must not disable registration because of advisory metadata.');

    $expect((glob($backendRoot.'/database/migrations/*advisory*') ?: []) === [], 'Advisory audit must not add migrations.');
    $expect(! is_dir($backendRoot.'/database/sql/course-offering-advisory-metadata-audit'), 'Advisory audit must not add a SQL package.');

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

    fwrite(STDOUT, "Course Offering advisory metadata audit contract passed.\n");
}

return $contract;
