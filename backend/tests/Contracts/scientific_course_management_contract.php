<?php

// Dependency-free source/schema boundary verification, NOT a runtime or lock test.
$root = dirname(__DIR__, 3);
$read = fn (string $path) => file_get_contents($root.'/'.$path);
$check = function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$service = $read('backend/app/Services/ScientificCourseManagementService.php');
$access = $read('backend/app/Support/ScientificCourseAccess.php');
$transaction = $read('backend/app/Services/AcademicCatalogTransaction.php');
$sql = $read('backend/database/sql/scientific-course-management/01_apply.sql');
$routes = $read('backend/routes/api.php');
$check(str_contains($routes, "prefix('vice-presidency/scientific/course-management')"), 'Dedicated route group');
foreach (['effectiveRoles()', 'effectivePermissions()', 'vice_president_scientific', 'vice_presidency.scientific.access', 'scopeProgramsForMutation'] as $token) $check(str_contains($access, $token), 'Actual assigned authority: '.$token);
$check(!str_contains($access, 'hasPermission('), 'No virtual super-admin permission');
$check(str_contains($access, 'canEditOrigin') && str_contains($access, 'university'), 'Shared origin ownership is distinct from visibility');
foreach (['revision=revision+1', 'FOR UPDATE', 'academic_catalog_history_locked', 'sc_catalog_membership_program', 'is_ready=0', 'is_ready=1'] as $token) $check(str_contains($sql, $token), 'Database invariant: '.$token);
foreach (['courses', 'program_courses', 'academic_programs', 'academic_requirement_groups', 'program_course_requirement_groups', 'course_departments', 'course_prerequisites'] as $table) foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) $check(str_contains($sql, "BEFORE $event ON $table FOR EACH ROW"), 'All catalog writers are protected: '.$table.' '.$event);
foreach (['students', 'course_offerings', 'supplementary_exam_offerings', 'admission_applications', 'ministry_placement_records', 'student_graduation_decisions', 'student_progression_decisions'] as $table) $check(str_contains($sql, "BEFORE INSERT ON $table"), 'First-use serialization: '.$table);
$check(str_contains($transaction, 'lockForUpdate()') && !str_contains($transaction, 'hash('), 'Monotonic epoch, not a value fingerprint');
$check(str_contains($read('backend/app/Services/CourseOfferingContextService.php'), '$context->catalogRevision'), 'Cached first-use offering context is revalidated');
$check(str_contains($read('backend/app/Http/Controllers/Api/CourseOfferingController.php'), 'retainCatalogProofWithinTransaction($context)'), 'Generic identity update retains the destination-curriculum proof in its canonical transaction');
foreach (['Course', 'ProgramCourse', 'CourseDepartment', 'CoursePrerequisite', 'AcademicProgram'] as $model) $check(str_contains($read("backend/app/Http/Controllers/Api/{$model}Controller.php"), 'extends CatalogCrudController'), 'Legacy controller serialization: '.$model);
foreach (['saveGroups', 'saveMembership', 'saveCourse', 'deleteCourse', 'assertProgramGraduationConfiguration', 'UserActivityLog::create', 'deleteReasons', 'academic_lock_reason', 'lock_reason'] as $token) $check(str_contains($service, $token), 'Workflow surface: '.$token);
$check(!str_contains($service, 'GradeService::') && !str_contains($service, 'StudentCourseResult::'), 'No grade/result writes or parallel grading rules');
$check(!preg_match('/(?:UPDATE|DELETE FROM)\s+(?:`?alrowad_uni_rust`?\.)?`?(?:courses|program_courses|students|student_course_results)`?\s/i', $sql), 'Manual SQL does not rewrite academic data');
$check(substr_count($sql, 'CREATE TABLE IF NOT EXISTS') === 1, 'One control table, no parallel curriculum/version tables');
foreach (['00_preflight.sql', '02_verify.sql'] as $file) {
    $source = $read('backend/database/sql/scientific-course-management/'.$file);
    $check(str_contains($source, "'OVERALL'"), 'Visible terminal report');
    $check(!preg_match('/^\s*(UPDATE|INSERT|DELETE|CREATE|DROP|ALTER|CALL)\b/im', $source), 'Read-only verifier: '.$file);
}
echo "PASS scientific course management source/SQL contract (static only)\n";
