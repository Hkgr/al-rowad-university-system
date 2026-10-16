<?php

/** Dependency-free boundary checks, NOT runtime/visual acceptance. */
$root = dirname(__DIR__, 3);
$read = fn (string $path): string => file_get_contents($root.'/'.$path);
$checks = [];
$check = function (bool $passed, string $name) use (&$checks): void { $checks[$name] = $passed; };
$service = $read('backend/app/Services/IncompleteResultService.php');
$projection = $read('backend/app/Services/IncompleteResultReadProjection.php');
$ui = $read('frontend/src/features/exam-board/pages/IncompleteResultsPage.jsx');
$helper = $read('frontend/src/features/exam-board/lib/incompleteResults.js');
$app = $read('frontend/src/app/App.jsx');
$check(substr_count($app, 'incomplete-results') === 6, 'six protected portal routes');
$check(str_contains($ui, 'ACCESS.manualGradeEntry') && str_contains($ui, 'OfficeLink as Link'), 'canonical frontend gate and office navigation');
$check(str_contains($service, 'ExamManualGradeEntryAccess') && str_contains($service, 'scopeManualGradeOfferings') && str_contains($service, 'scopeManualGradeStudents'), 'independent server scopes retained');
$check(str_contains($service, 'attributesToArray()') && str_contains($projection, "'full_name'") && !str_contains($projection, '->toArray()'), 'explicit safe identities, no full model serialization');
$check(!preg_match('/\b(insert|update|delete|save|create)\s*\(/i', $projection), 'projection does not write');
$check(str_contains($service, 'limit(101)') && str_contains($service, "'complete'") && str_contains($service, '->with(array_map'), 'bounded lookups and eager-loaded page');
$check(str_contains($ui, 'useBlocker') && str_contains($ui, 'beforeunload') && str_contains($ui, 'abort.abort()'), 'draft/pending navigation and read cancellation');
$check(str_contains($helper, 'Object.freeze') && str_contains($helper, 'body: JSON.stringify(body)') && str_contains($ui, 'sendAttempt(attempt)'), 'fixed explicit mutation envelope');
$check(str_contains($helper, "action === 'deadline' ? 'PATCH' : 'POST'") && str_contains($read('backend/routes/api.php'), "Route::patch('{case}/deadline'"), 'deadline uses existing PATCH route');
$check(str_contains($helper, "expiry_outcome: ''") && str_contains($helper, "timeZone: 'Asia/Damascus'"), 'explicit F/Z and named timezone');
$check(str_contains($helper, 'component.editable') && str_contains($helper, 'if (components.length)'), 'only outstanding real components, omit empty optional array');
$check(str_contains($read('frontend/src/features/academic-record/lib/transcriptPdf.js'), "from './resultSymbols'") && str_contains($read('frontend/src/features/student-dashboard/pages/StudentTranscript.jsx'), 'resultDescription'), 'shared screen/PDF presentation');
$check(!preg_match('/reason|requirements|actor_user_id|events/', $read('frontend/src/features/academic-record/lib/resultSymbols.js')), 'public presentation excludes internal case evidence');
$failed = array_keys(array_filter($checks, fn ($passed) => !$passed));
if ($failed) { fwrite(STDERR, implode("\n", $failed)."\n"); exit(1); }
echo 'course_result_symbols_phase2_contract: PASS ('.count($checks)." static checks only)\n";
