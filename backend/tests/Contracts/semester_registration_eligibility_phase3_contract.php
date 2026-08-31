<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) {
            $errors[] = $message;
        }
    };
    $read = static fn (string $path): string => is_file($path) ? (string) file_get_contents($path) : '';
    $method = static function (string $source, string $name): string {
        return preg_match('/\n    (?:private|public|protected) function '.preg_quote($name, '/').'\(.*?(?=\n    (?:private|public|protected) function |\n})/s', $source, $match) === 1 ? $match[0] : '';
    };

    $registration = $read($backendRoot.'/app/Services/RegistrationService.php');
    $requests = $read($backendRoot.'/app/Services/RegistrationRequestService.php');
    $grades = $read($backendRoot.'/app/Services/GradeService.php');
    $requirements = $read($backendRoot.'/app/Services/AcademicRequirementService.php');
    $exception = $read($backendRoot.'/app/Exceptions/RegistrationException.php');
    $studentUi = $read(dirname($backendRoot).'/frontend/src/features/student-dashboard/pages/StudentRegistration.jsx');
    $advisorUi = $read(dirname($backendRoot).'/frontend/src/features/dean-dashboard/pages/DeanRegistrationRequestDetail.jsx');
    $examBoardRegistrationUi = $read(dirname($backendRoot).'/frontend/src/features/exam-board/pages/CourseRegistrationPage.jsx');

    foreach ([
        'DEFAULT_MAX_CREDIT_HOURS = 18',
        'HIGH_CGPA_MAX_CREDIT_HOURS = 21',
        'HIGH_CGPA_THRESHOLD = 3.0',
        'RECOMMENDED_MINIMUM_CREDIT_HOURS = 12',
    ] as $constant) {
        $expect(str_contains($registration, $constant), 'Missing Phase 3 credit policy constant: '.$constant);
    }

    $standing = $method($registration, 'officialRegistrationAcademicStanding');
    $expect(str_contains($standing, '$this->grades->officialCumulativeMetrics($student)'), 'Registration must reuse GradeService official cumulative metrics exactly once per standing snapshot.');
    $expect(str_contains($standing, '$cgpa >= self::HIGH_CGPA_THRESHOLD'), 'CGPA exactly 3.0 must receive the 21-hour cap.');
    $expect(str_contains($standing, '$metrics[\'official_completed_courses\']'), 'Official completed courses must drive passed-course eligibility.');
    $expect(! str_contains($registration, 'StudentCreditLimit'), 'Legacy StudentCreditLimit must not control normal registration.');
    $expect(! str_contains($registration, 'GradeApproval::') && ! str_contains($registration, 'grade_approvals'), 'Registration must not duplicate official grade-approval queries.');
    $expect(! preg_match('/grade_points\s*[+*\/]|cumulative.*(?:sum|divide)/i', $standing), 'Registration must not duplicate CGPA arithmetic.');

    $passed = $method($registration, 'hasPassedCourse');
    $missing = $method($registration, 'getMissingPrerequisites');
    $expect(str_contains($passed, "in_array(\$courseId, \$academicStanding['official_passed_course_ids'], true)"), 'Passed-course checks must use canonical course IDs.');
    $expect(str_contains($missing, "'course_id'") && str_contains($missing, "'course_code'") && str_contains($missing, "'course_name'"), 'Missing prerequisites must retain structured course details.');
    $expect(str_contains($exception, "COURSE_ALREADY_PASSED = 'course_already_passed'") && str_contains($exception, 'courseAlreadyPassed()'), 'Missing stable course-already-passed exception contract.');
    $expect(str_contains($registration, 'RegistrationException::courseAlreadyPassed()'), 'Final materialization must block an officially passed course.');
    $expect(str_contains($requests, 'RegistrationException::COURSE_ALREADY_PASSED'), 'Request preparation must use the same passed-course code.');

    foreach ([$registration, $requests, $studentUi, $advisorUi, $examBoardRegistrationUi] as $source) {
        $expect(! str_contains($source, 'no_available_seats'), 'Seat exhaustion must not remain an eligibility reason.');
    }
    foreach ([$registration, $requests] as $source) {
        $expect(! str_contains($source, 'decrementAvailableSeats') && ! str_contains($source, 'incrementAvailableSeats'), 'Registration lifecycle must not mutate legacy seat counters.');
        $expect(! str_contains($source, "where('available_seats', '>', 0)"), 'Registration lifecycle must not gate on capacity.');
    }
    $expect(! str_contains($registration, 'available_seats'), 'Canonical registration service must not read or return legacy seat counters.');
    $expect(! str_contains($requests, 'available_seats'), 'Request preparation/approval must not read or expose legacy seat counters.');

    $expect(str_contains($requests, "'below_recommended_minimum' =>"), 'Backend must expose the 12-hour recommendation as a warning.');
    $expect(str_contains($requests, '$liveRequestHours > 0 && $liveProjected < $recommendedMinimum'), 'Live minimum-load advice must compare the projected term load, not request hours alone.');
    $expect(str_contains($requests, "\$approvedSnapshot['projected_hours_at_approval'] < \$recommendedMinimum"), 'Approved minimum-load advice must preserve the projected-hours snapshot semantics.');
    $expect(str_contains($studentUi, 'below_recommended_minimum') && str_contains($studentUi, 'يمكنك متابعة إرسال الطلب'), 'Student UI must render the non-blocking load warning.');
    $expect(str_contains($advisorUi, 'below_recommended_minimum'), 'Advisor detail must render the authoritative load warning.');
    $expect(str_contains($studentUi, 'course_already_passed') && str_contains($advisorUi, 'course_already_passed'), 'Both UIs must explain the official passed-course block.');
    $expect(str_contains($studentUi, 'missing_prerequisites') && str_contains($advisorUi, 'missing_prerequisites'), 'Both UIs must retain structured prerequisite presentation.');

    $expect(str_contains($grades, 'public function officialCumulativeMetrics('), 'Canonical GradeService helper must remain available.');
    $expect(str_contains($grades, "'repeated_courses_handling' => 'highest_attempt_only'"), 'Canonical repeated-attempt semantics must remain owned by GradeService.');
    $expect(str_contains($requirements, '$this->grades->isOfficiallyPassedAttempt($registration)'), 'AcademicRequirementService must continue using GradeService official-pass semantics.');
    $expect(str_contains($requests, 'materializeAdvisorApprovedRequestItemWithinTransaction'), 'Phase 2 advisor materialization path must remain the live registration path.');
    $expect(str_contains($registration, 'assertCourseRegistrationStudentWindowOpen('), 'Academic Calendar student-window enforcement must remain active.');

    $appFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backendRoot.'/app'));
    foreach ($appFiles as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $source = $read($file->getPathname());
        foreach (['register_student_course', 'check_student_credit_limit', 'check_course_prerequisites'] as $procedure) {
            $expect(! str_contains(strtolower($source), $procedure), 'Active Laravel code must not call legacy registration stored procedure '.$procedure.': '.$file->getFilename());
        }
    }
    $expect(! is_dir($backendRoot.'/database/sql/semester-registration-eligibility-phase3'), 'Phase 3 must not add a SQL package.');
    $expect((glob($backendRoot.'/database/migrations/*semester*registration*eligibility*') ?: []) === [], 'Phase 3 must not add migrations.');
    $expect((glob($backendRoot.'/database/seeders/*Semester*Registration*Eligibility*') ?: []) === [], 'Phase 3 must not add seeders.');

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

    fwrite(STDOUT, "Semester Registration Phase 3 eligibility contract passed.\n");
}

return $contract;
