<?php

/** Dependency-free SOURCE contract; behavior/SQL/concurrency are separate checks. */
$root = dirname(__DIR__, 3);
$read = fn ($path) => file_get_contents($root.'/'.$path);
$check = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$routes = $read('backend/routes/api.php');
$check(str_contains($routes, 'ScientificAcademicEntityController') && str_contains($routes, "whereIn('kind', ['colleges', 'departments'])"), 'Narrow entity route allowlist');
$service = $read('backend/app/Services/ScientificAcademicEntityService.php');
$check(str_contains($service, 'ScientificProgramAccess::MANAGE') && str_contains($service, '->authorize($actor)'), 'Existing Scientific role/permission/scope guard');
$shared = $read('backend/app/Services/AcademicStructureEntityService.php');
$check(str_contains($shared, 'StoreCollegeRequest') && str_contains($shared, 'StoreDepartmentRequest'), 'Reuse existing validation definitions');
$check(str_contains($shared, '->run($work, $revision)') && str_contains($shared, "increment('revision')"), 'Control-first reliable revision, not value fingerprint');
foreach (['College', 'Department'] as $kind) $check(str_contains($read('backend/app/Http/Controllers/Api/'.$kind.'Controller.php'), 'AcademicStructureEntityService::class'), 'All existing structural CRUD writers share persistence');
$check(str_contains($shared, '->units($actor)->lockForUpdate()') && str_contains($shared, '->owns($actor'), 'Selected relationships and owner scope revalidated locked');
$check(!preg_match('/student_course_results|GradeService|graduation.*formula/i', $service.$shared), 'No parallel academic result decisions');
$app = $read('frontend/src/app/App.jsx');
foreach (['colleges', 'departments', 'programs', 'courses'] as $kind) $check(str_contains($app, '/vp/scientific/programs-courses/'.$kind.'/:entityId'), 'Independent detail route: '.$kind);
$page = $read('frontend/src/features/scientific-programs/UnifiedProgramsPage.jsx');
$check(!str_contains($page, '/academic-structure/') && !str_contains($page, 'advanced=1'), 'No out-of-portal egress');
$check(str_contains($page, 'changePayload(crypto.randomUUID(), revision, drafts, newCourses)') && str_contains($page, '/plan-changes/${uncertain.request_id}'), 'Preserve canonical atomic staging and lost-response inspection');
$check(str_contains($page, 'courseEditorDirty') && str_contains($page, 'useBlocker'), 'Draft/SPA navigation protection');
$check(str_contains($read('frontend/src/features/scientific-programs/WorkspaceCurriculum.jsx'), 'advisoryYears') && str_contains($page, 'إضافة مادة'), 'Primary add and nested year/term display');
echo "PASS Scientific academic entities source contract (not behavior or visual evidence)\n";
