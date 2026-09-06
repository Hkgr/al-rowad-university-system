<?php

$root = dirname(__DIR__, 3);
$read = static fn (string $path): string => file_get_contents($root.'/'.$path) ?: '';
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void { if (! $condition) $failures[] = $message; };

$routes = $read('backend/routes/api.php');
$access = $read('backend/app/Support/ExecutiveReportAccess.php');
$query = $read('backend/app/Services/ExecutiveReportQueryService.php');
$registry = $read('backend/app/Support/ExecutiveReportRegistry.php');
$request = $read('backend/app/Http/Requests/ExecutiveReportQueryRequest.php');
$grade = $read('backend/app/Services/GradeService.php');
$scale = $read('backend/app/Support/OfficialGradeScale.php');
require_once $root.'/backend/app/Support/OfficialGradeScale.php';

foreach (['vice-presidency/reports/definitions','vice-presidency/reports/filters','vice-presidency/reports/query','vice-presidency/analytics/overview'] as $route) $expect(str_contains($routes, $route), "Missing route {$route}");
$expect(str_contains($access, 'effectivePermissions()') && ! str_contains($access, 'hasPermission('), 'Access must use assigned effective permissions, not virtual grants.');
$expect(str_contains($access, 'effectiveRoles()') && str_contains($access, 'hasActualUniversityScope'), 'Access must pair an actual VP role with actual university DataScope.');
$expect(str_contains($access, "status_code !== 'active'"), 'Inactive accounts must fail closed in the centralized guard.');
foreach (['students','enrollments','faculty','course_offerings','academic_performance','grade_workflow'] as $subject) $expect(str_contains($registry, "'{$subject}'"), "Missing report subject {$subject}");
$expect(str_contains($request, 'Unsupported report fields') && str_contains($request, "'max:50'") && str_contains($request, "'max:100'"), 'Query input must be strictly allowlisted and bounded.');
$expect(str_contains($query, 'scopeOfficialApprovedResults') && str_contains($query, 'ROW_NUMBER() OVER'), 'Official analytics must reuse GradeService approval scope and canonical repeated-attempt ranking.');
$expect(str_contains($query, "['registered','completed']") && str_contains($query, 'OfficialGradeScale::pointsSql'), 'Academic attempts and GPA must use canonical statuses and grade scale.');
$expect(str_contains($query, "'reason'=>'zero_denominator'") && str_contains($query, "'numerator'") && str_contains($query, "'denominator'"), 'Rates must expose numerator, denominator, and null reason.');
$expect(str_contains($query, 'historical_data_unavailable'), 'Unavailable history must be explicit.');
$expect(str_contains($grade, 'OfficialGradeScale::letter') && str_contains($grade, 'OfficialGradeScale::points'), 'GradeService must consume the shared canonical scale.');
$expect(str_contains($scale, "'A+' => 4.0") && str_contains($scale, '$mark >= 98'), 'Shared scale must retain existing bands and points.');
$expect(\App\Support\OfficialGradeScale::letter(98.0) === 'A+' && \App\Support\OfficialGradeScale::letter(49.99) === 'F', 'Canonical grade bands changed.');
$expect(\App\Support\OfficialGradeScale::points('A', 'passed') === 3.75 && \App\Support\OfficialGradeScale::points('A', 'incomplete') === 0.0, 'Canonical grade points or exclusions changed.');
$expect(! preg_match('/Schema::|insert\(|update\(|delete\(|lockForUpdate/', $query), 'Reporting coordinator must remain read-only and avoid runtime schema inspection.');
$expect(! str_contains($request, 'orderByRaw') && ! str_contains($query, '$input[\'sort\'][\'field\']'), 'Client-controlled SQL identifiers must not be interpolated.');

if ($failures !== []) { fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL); exit(1); }
echo "Executive Reports Phase 1 contract: PASS\n";
