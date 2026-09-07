<?php

$root = dirname(__DIR__, 3);
$read = fn ($path) => file_get_contents($root.'/'.$path);
$failures = [];
$check = function ($ok, $message) use (&$failures) { if (!$ok) $failures[] = $message; };
$service = $read('backend/app/Services/ExamManualGradePreparationService.php');
$readPart = substr($service, 0, strpos($service, 'public function save('));
foreach (['->create(', '::create(', '->update(', 'lockForUpdate', 'DB::transaction'] as $token) $check(!str_contains($readPart, $token), 'Preview must not write or lock: '.$token);
foreach (['canMutateProgram', 'scopeManualGradeCourses', 'authorizeOffering', 'resolveContext', 'createOffering', 'prepareManualGradeRegistration', 'saveManualMarks', 'RECORDING_EXEMPTIONS', 'hash_equals', 'assertAcademicRegistrationCandidate', 'assertCourseOfferingConfigurationsReadable', 'assertCourseOfferingConfigurationsMutable'] as $token) $check(str_contains($service, $token), 'Missing canonical boundary: '.$token);
$registration = $read('backend/app/Services/RegistrationService.php');
$check(substr_count($registration, 'RegistrationMaterializationContext::EXAM_MANUAL_RECORDING') === 3, 'Recording context must be selected once and exempt only offering state/timetable in shared core.');
$check(str_contains($registration, '$context === RegistrationMaterializationContext::STUDENT_WINDOW'), 'Ordinary calendar guard must remain.');
$check(str_contains($registration, '$context !== RegistrationMaterializationContext::EXAM_MANUAL_RECORDING'), 'Timetable exemption must be domain-derived.');
foreach (['getMissingPrerequisites', 'getHoursSnapshot', 'assertRegistrationCandidateAllowed', 'courseAlreadyPassed'] as $token) $check(str_contains($registration, $token), 'Academic gate removed: '.$token);
foreach (['StudentCourseResult::', 'AttendanceSession::', 'CourseOfferingScheduleSlot::', 'StudentRegistrationRequest::', 'GradeApproval::', 'CourseOfferingInstructor::'] as $token) $check(!str_contains($service, $token), 'Coordinator must not fabricate downstream evidence: '.$token);
$request = $read('backend/app/Http/Requests/GradePart/ExamManualGradeEntryRequest.php');
$check(str_contains($request, 'array:key,mark') && str_contains($request, "'distinct'"), 'Strict component request allowlist required.');
$check(!str_contains($request, 'skip_timetable') && !str_contains($request, 'bypass'), 'No client exception flag.');
$context = $read('backend/app/Services/CourseOfferingContextService.php');
$check(str_contains($context, "\$payload['status'] = CourseOfferingOpeningService::STATUS_CLOSED"), 'New offerings stay closed through shared creation.');
if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL); exit(1); }
echo "Exam manual grade preparation contract: PASS (static only)\n";
