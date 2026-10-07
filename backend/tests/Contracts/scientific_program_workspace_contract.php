<?php

// Dependency-free source contract. Behavior/locks/rendering require their separate runtime suites.
$root = dirname(__DIR__, 3);
$read = fn ($path) => file_get_contents($root.'/'.$path);
$check = function (bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); };
$save = $read('backend/app/Services/ScientificPlanChangeService.php');
$workspace = $read('backend/app/Services/ScientificProgramWorkspaceService.php');
$routes = $read('backend/routes/api.php');
$controller = $read('backend/app/Http/Controllers/Api/ScientificProgramManagementController.php');
foreach (["get('workspace'", "post('plan-changes'", "get('plan-changes/{requestId}'", "get('{program}/history'"] as $route) $check(str_contains($routes, $route), 'Dedicated scoped route '.$route);
foreach (['ScientificProgramAccess::PLANS', 'ScientificProgramAccess::APPROVE', 'ScientificProgramAccess::ASSIGN', 'authorizeReceipt', 'actor_id', 'program_ids'] as $token) $check(str_contains($save, $token), 'Save/result authority '.$token);
$check(strpos($save, "if (\$receipt = \$this->receipt") < strpos($save, "if (!hash_equals(\$this->transaction->revision()"), 'Replay under catalog lock before epoch rejection');
foreach (['fixTransition(', 'copy(', 'saveRequirements(', 'saveMembership(', 'approve(', 'setDefault(', 'academic_plan.workspace_result', 'workspace_saved', "'before'", "'after'", "'new_students_only'"] as $token) $check(str_contains($save, $token), 'Canonical coordinated operation '.$token);
$check(strpos($save, 'fixTransition(') < strpos($save, 'saveCourse('), 'Pin old references before creating/completing origins');
$check(str_contains($save, 'if ($bases !== [])') && str_contains($save, "'changed' => \$outcomes !== []"), 'No-op has no plan/origin/change event');
$check(str_contains($save, 'targetValues($target, $before)') && str_contains($save, "requirements.groups.*.is_active' => 'sometimes|boolean'"), 'Explicit activity participates in target values and validation');
$workflow = $read('backend/app/Services/AcademicPlanWorkflow.php');
$check(str_contains($workflow, "'is_active' => \$group->exists ? (bool) \$group->is_active : true"), 'Canonical requirements save preserves omitted existing activity');
$check(str_contains($workflow, "'requirements_saved', ['before' => \$before, 'after' => \$this->requirementValues(\$version)]"), 'Canonical activity choices carry actual requirement snapshots');
$check(!preg_match('/(?:->(?:insert|update|delete|save|create)\(|lockForUpdate)/', $workspace), 'Workspace/history are nonlocking reads');
$check(!str_contains($controller, 'DB::') && !str_contains($save, 'calculateGpa'), 'No controller queries or parallel academic formulas');
$distribution = $read('backend/app/Services/ScientificCourseDistribution.php');
$check(str_contains($distribution, "'academic_program_ids' => 'required|array|min:1|max:50'") && str_contains($distribution, "whereIn('academic_program_id', \$v['academic_program_ids'])"), 'No implicit classification-based distribution');
$page = $read('frontend/src/features/scientific-programs/UnifiedProgramsPage.jsx');
foreach (['useBlocker', 'beforeunload', 'تُطبّق على الطلاب الجدد فقط', 'plan-changes/${uncertain.request_id}', 'retry ? uncertain', 'snapshot.revision !== revision'] as $token) $check(str_contains($page, $token), 'Explicit staging/uncertainty handling '.$token);
echo "PASS scientific program workspace source boundaries (static only)\n";
