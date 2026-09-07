<?php

$root = dirname(__DIR__, 3);
$read = fn ($path) => file_get_contents($root.'/'.$path);
$failures = [];
$check = function ($condition, $message) use (&$failures) { if (!$condition) $failures[] = $message; };
$catalog = $read('backend/app/Services/ExamManualGradeContextService.php');
$registration = $read('backend/app/Services/RegistrationService.php');
$exception = substr($registration, strpos($registration, 'public function prepareManualGradeRegistration('));
$exception = substr($exception, 0, strpos($exception, 'public function registerStudentWithinTransaction('));
foreach (['authorizeOffering', 'lockStudent', 'lockOffering', 'isOfficiallyApprovedOffering', 'assertCourseOfferingConfigurationsMutable',
    'assertSelfRegistrationAllowed', 'registerStudentWithinTransaction', 'UserActivityLog', 'student_request_advisor_approval', 'academicAttempts(false)'] as $token) $check(str_contains($exception, $token), 'Missing exception guard '.$token);
$check(!str_contains($exception, 'StudentRegistrationRequest::'), 'Exception cannot require or fabricate a student/advisor request.');
$check(!str_contains($exception, 'ADVISOR_APPROVAL'), 'Exception must retain the student calendar gate.');
$check(strpos($exception, 'lockStudent') < strpos($exception, 'lockOffering'), 'Registration lock ordering must remain canonical.');
foreach (['scopeManualGradeCourses', "Course::query()", "whereHas('departments'", "orWhereHas('academicPrograms.department'", 'indexActiveForProgram', 'manualSnapshots', 'limit(501)', 'limit(1001)'] as $token) $check(str_contains($catalog, $token), 'Missing catalog contract '.$token);
$reads = substr($catalog, 0, strpos($catalog, 'public function prepare('));
foreach (['::create(', '->update(', '->delete(', 'lockForUpdate', 'DB::transaction'] as $token) $check(!str_contains($reads, $token), 'GET must never mutate/lock: '.$token);
foreach (['requiredRoles', 'gradingPolicyLimits', 'assertRequiredPartsPolicyCompatible', 'assertCourseOfferingConfigurationsMutable', 'lockDefaultGradingPolicy', 'hash_equals', 'manual_components_incompatible'] as $token) $check(str_contains($catalog, $token), 'Missing preparation invariant '.$token);
foreach (['StudentCourseResult::', 'GradeApproval::', 'Schema::create', 'available_seats', 'faculty_member_id'] as $token) $check(!str_contains($catalog, $token), 'Preparation must not own academic results or offering governance: '.$token);
$routes = $read('backend/routes/api.php');
$check(str_contains($routes, "Route::get('students/{student}/periods', 'periods')"), 'Independent authorized period lookup is required.');
$periods = substr($catalog, strpos($catalog, 'public function periods('));
$periods = substr($periods, 0, strpos($periods, 'public function preview('));
foreach (['$this->access->authorize($actor, $student)', 'scopeManualGradeOfferings', '->distinct()'] as $token) $check(str_contains($periods, $token), 'Period lookup must retain authorization and scoped distinct context: '.$token);
$check(!str_contains($periods, 'limit(501)') && !str_contains($periods, '->catalog('), 'Period choices cannot depend on catalog limits.');
$fixture = $read('backend/tests/Feature/ExamManualGradeEntryBehaviorTest.php');
$check(str_contains($fixture, "'course_offering_id' => \$i, 'registration_status_id' => 1") && str_contains($fixture, 'assertLessThanOrEqual($first + 2'), 'Bounded registration fixture must increase distinct offering contexts.');
foreach (["Route::get('students/{student}/catalog'", "Route::get('students/{student}/offerings/{offering}/component-preview'", "Route::post('students/{student}/offerings/{offering}/registration'", "Route::post('students/{student}/offerings/{offering}/components'"] as $route) $check(str_contains($routes, $route), 'Missing explicit route '.$route);
$check(str_contains($registration, "public function registerStudent(array \$data, ?int \$authenticatedUserId = null): array\n    {\n        throw RegistrationException::liveWorkflowRequired();")
    || str_contains(str_replace("\r\n", "\n", $registration), "public function registerStudent(array \$data, ?int \$authenticatedUserId = null): array\n    {\n        throw RegistrationException::liveWorkflowRequired();"), 'Disabled legacy endpoint must stay disabled.');
if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL); exit(1); }
echo "Exam manual grade grid contract: PASS (source checks, not runtime acceptance)\n";
