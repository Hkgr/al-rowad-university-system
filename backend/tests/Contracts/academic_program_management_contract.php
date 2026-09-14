<?php

// Dependency-free boundary checks only; not proof of runtime, visual behavior or database locking.
$root = dirname(__DIR__, 3);
$read = fn (string $p) => file_get_contents($root.'/'.$p);
$check = function (bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); };
$workflow = $read('backend/app/Services/AcademicPlanWorkflow.php');
$context = $read('backend/app/Services/AcademicPlanContext.php');
$sql = $read('backend/database/sql/academic-program-management/01_apply.sql');
$access = $read('backend/app/Support/ScientificProgramAccess.php');
foreach (['effectiveRoles()', 'effectivePermissions()', 'vice_president_scientific', 'scopeProgramsForMutation'] as $token) $check(str_contains($access, $token), 'Assigned scoped authority: '.$token);
$check(!str_contains($access, 'hasPermission('), 'No virtual permission bypass');
foreach (['previewTransition', 'fixTransition', 'previewTransfer', 'transferProjection', 'transferBlockers', 'setDefault', 'pinTransition', 'current_state_not_historical_approval'] as $token) $check(str_contains($workflow, $token), 'Explicit workflow: '.$token);
$studentContext = substr($context, strpos($context, 'public static function forStudent'), strpos($context, 'public static function forProgram') - strpos($context, 'public static function forStudent'));
$check(!str_contains($studentContext, 'default_academic_plan_version_id'), 'Existing student never resolves default');
$check(str_contains($studentContext, 'student_academic_plan_assignments'), 'Actual assignment');
$check(str_contains($context, 'return DB::transaction($work, $attempts)') && str_contains($context, 'app(AcademicCatalogTransaction::class)->run($work)'), 'Legacy transaction compatibility and installed outer lock');
foreach (['RegistrationService', 'RegistrationRequestService', 'RegistrationModificationService', 'RegistrationReplacementService', 'RegistrationWithdrawalService',
    'AcademicProgressionService', 'GraduationDecisionService', 'AcademicTermSnapshotService', 'ExamManualGradeContextService', 'ExamManualGradePreparationService',
    'MinistryPlacementStudentEnrollmentService', 'SupplementaryExamRegistrationService'] as $service) {
    $source = $read('backend/app/Services/'.$service.'.php');
    $check(str_contains($source, 'AcademicPlanContext::transaction(') && !str_contains($source, 'DB::transaction('), 'Existing student-locking writer enters common outer lock: '.$service);
}
foreach (['AcademicRequirementService', 'GraduationEligibilityService', 'getStudentRequirementProgress', 'evaluatePlanProgress'] as $token) $check(str_contains($workflow, $token), 'Canonical transfer calculations: '.$token);
foreach (['StudentController', 'AdmissionApplicationController'] as $controller) {
    $source = $read('backend/app/Http/Controllers/Api/'.$controller.'.php');
    $check(str_contains($source, 'AcademicPlanContext::transaction(') && !str_contains($source, 'DB::transaction('), 'Generic student/admission writer acquires outer lock: '.$controller);
}
$check(!str_contains($workflow, 'StudentCourseResult::') && !str_contains($workflow, 'calculateGpa('), 'No new result/GPA formula');
$check(substr_count($sql, 'CREATE TABLE IF NOT EXISTS') === 4, 'Exactly version, assignment, event and readiness tables');
foreach (['academic_plan_versions', 'student_academic_plan_assignments', 'academic_plan_events', 'plan_scope_key', 'academic_plan_version_id', 'plan_program_course_id', 'FOR UPDATE', 'academic_plan_locked', 'academic_plan_initialization_incomplete', 'BIGINT', 'is_ready=0', 'is_ready=1'] as $token) $check(str_contains($sql, $token), 'SQL invariant: '.$token);
foreach (['student_course_registrations', 'student_registration_requests', 'student_registration_modification_requests', 'student_registration_replacement_requests', 'student_progression_decisions', 'student_graduation_decisions'] as $table) $check(str_contains($sql, 'BEFORE INSERT ON '.$table), 'Raw operation provenance: '.$table);
$outsideTriggers = preg_replace('/CREATE OR REPLACE TRIGGER.*?END\/\//s', '', $sql);
$check(!preg_match('/^\s*INSERT\s+INTO\s+(?:alrowad_uni_rust\.)?(?:role_permissions|student_academic_plan_assignments|academic_plan_versions)\b/im', $outsideTriggers), 'No automatic grants, plans or assignment backfill');
foreach (['00_preflight.sql', '02_verify.sql'] as $name) {
    $source = $read('backend/database/sql/academic-program-management/'.$name);
    $check(str_contains($source, "'OVERALL'"), 'Visible result: '.$name);
    $check(!preg_match('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|CALL)\b/im', $source), 'Read-only report: '.$name);
}
$check(str_contains($read('backend/routes/api.php'), "prefix('vice-presidency/scientific/program-management')"), 'Dedicated API');
echo "PASS academic program management source/SQL boundaries (static only)\n";
