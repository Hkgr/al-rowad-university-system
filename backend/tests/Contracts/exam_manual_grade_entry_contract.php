<?php

$root = dirname(__DIR__, 3);
$read = static fn ($path) => file_get_contents($root.'/'.$path);
$errors = [];
$check = static function ($condition, $message) use (&$errors): void { if (! $condition) $errors[] = $message; };
$routes = $read('backend/routes/api.php');
$access = $read('backend/app/Support/ExamManualGradeEntryAccess.php');
$workflow = $read('backend/app/Services/GradePartWorkflowService.php');
$manual = $read('backend/app/Services/Concerns/ExamManualGradeOperations.php');
$request = $read('backend/app/Http/Requests/GradePart/ExamManualGradeEntryRequest.php');
$controller = $read('backend/app/Http/Controllers/Api/ExamManualGradeEntryController.php');
foreach (['exams/manual-grade-entry', 'students/{student}/registrations/{registration}/marks', 'submission-readiness'] as $route) $check(str_contains($routes, $route), 'Missing route '.$route);
foreach (['effectiveRoles()', 'effectivePermissions()', "'exam_officer'", "'exams.manage'", "'grades.manage'", "status_code === 'active'", 'scopeManualGradeStudents', 'scopeManualGradeOfferings'] as $term) $check(str_contains($access, $term), 'Missing access invariant '.$term);
$check(! str_contains($access, 'hasPermission('), 'Manual authorization must not use virtual permission grants.');
foreach (['revision', 'acknowledged', 'correction_confirmed', 'correction_reason', 'array:grade_component_id,mark', 'array_diff'] as $term) $check(str_contains($request, $term), 'Missing strict request invariant '.$term);
foreach (['saveManualMarks', 'persistPartInTransaction', 'submitPartInTransaction', 'manual_grade_entry_stale', 'hash_equals', 'exam_board_manual_entry', 'lockForUpdate', 'manualSnapshots'] as $term) $check(str_contains($manual, $term), 'Missing canonical integration '.$term);
$check(str_contains($workflow, '$this->assignments->assertCanManageGradePart'), 'Instructor guard must remain.');
$check(str_contains($workflow, 'assertNotSupplementaryMaterialized') && str_contains($workflow, 'resolveInvalidCurrentDeferral'), 'Canonical supplementary locks must remain.');
$check(str_contains($workflow, 'requiredMarksComplete'), 'Readiness and submission must share mark completeness.');
foreach (['StudentCourseResult::', 'GradeApproval::', 'DB::', '::query()'] as $forbidden) $check(! str_contains($controller, $forbidden), 'Controller cannot own academic queries or writes.');
foreach (['StudentCourseResult::', 'GradeApproval::', 'Schema::create', 'CourseOffering::create', 'StudentCourseRegistration::create'] as $forbidden) $check(! str_contains($manual, $forbidden), 'Manual entry must not create official results or registrations.');
$saveStart = strpos($workflow, 'public function savePart(');
$saveEnd = strpos($workflow, 'private function persistPartInTransaction', $saveStart);
$save = substr($workflow, $saveStart, $saveEnd - $saveStart);
$check(strpos($save, 'CourseOfferingLock::lock') < strpos($save, 'StudentCourseRegistration::query()'), 'Save must lock offering before registration.');
$readinessStart = strpos($manual, 'public function manualSubmissionReadiness(');
$submitStart = strpos($manual, 'public function submitManualPart(');
$readiness = substr($manual, $readinessStart, $submitStart - $readinessStart);
$submit = substr($manual, $submitStart);
$check(str_contains($readiness, 'isOfficiallyApprovedOffering') && str_contains($readiness, "'official_result_locked'"), 'Readiness must enforce offering finality independently of exemptions.');
$check(strpos($submit, 'lockManualContext(') < strpos($submit, 'isOfficiallyApprovedOffering')
    && strpos($submit, 'isOfficiallyApprovedOffering') < strpos($submit, 'submitPartInTransaction('), 'Final approval must be rechecked under the offering lock before submission.');
if ($errors) { fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL); exit(1); }
echo "Exam manual grade entry contract: PASS (static only)\n";
