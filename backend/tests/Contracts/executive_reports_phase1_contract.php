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
$expect(str_contains($query, 'grade_part_approval_events as gpe') && str_contains($query, 'gpe.performed_at'), 'Grade workflow history must use append-only event timestamps.');
$expect(str_contains($query, 'teaching_assignment_events as tae') && str_contains($query, 'tae.created_at'), 'Teaching-assignment history must use append-only event timestamps.');
$expect(! str_contains($query, "COALESCE(gpa.reviewed_at,gpa.submitted_at,gpa.created_at)"), 'Mutable approval timestamps must not reconstruct workflow history.');
$expect(str_contains($query, 'historyUnavailableReason') && str_contains($query, 'historicalMetrics'), 'Historical capability rules must be enforced before executing facts or baselines.');
$expect(str_contains($query, 'detail_official') && str_contains($query, 'applyDetailSort'), 'Student details must include requested official aggregates and deterministic allowlisted sorting.');
$expect(str_contains($query, "groupBy('dascr.student_id')->toBase()"), 'Official student aggregates must preserve the GradeService scope and expose a Query Builder-compatible subquery.');
$expect(str_contains($query, "\$historical?'gpe.action':\"COALESCE(gpa.status,'draft')\"") && str_contains($query, "'gc.component_type'"), 'Workflow dimensions and current detail identity must follow the actual current or historical projection.');
$expect(str_contains($query, 'rateSortSql') && str_contains($query, '/ NULLIF((') && str_contains($query, 'foreach($dimensions as$dimension)'), 'Aggregate rate sorting must use the computed ratio and every group key as a deterministic tie-breaker.');
$expect(substr_count($query, 'selectRaw($studentGpaSql)') === 2 && str_contains($query, "\$studentGpaSql='1.0 * SUM(grade_points * credit_hours) / NULLIF(SUM(credit_hours), 0) AS student_gpa'"), 'Both per-student GPA projections must force non-integer division while preserving null denominators.');
$expect(str_contains($query, "leftJoinSub(\$membership,'sm'") && str_contains($query, "whereIn('mrs.status_code',['registered','completed'])"), 'Academic-period student membership must use canonical registrations independently of official results.');
$expect(str_contains($query, "whereHas('registrationStatus'") && str_contains($query, "['registered','completed']"), 'Overview official results must apply canonical registration eligibility.');
$expect(str_contains($registry, 'executive-reports.v1') && str_contains($registry, 'DETAIL_SORTABLE') && str_contains($registry, 'HISTORICAL_METRICS'), 'Definitions must be versioned and accurately expose executable sort/history capabilities.');
$expect(str_contains($grade, 'OfficialGradeScale::letter') && str_contains($grade, 'OfficialGradeScale::points'), 'GradeService must consume the shared canonical scale.');
$expect(str_contains($scale, "['minimum' => 98, 'letter' => 'A+', 'points' => 4.0]") && str_contains($scale, 'foreach (self::BANDS'), 'Shared scale must retain one canonical band definition for PHP and SQL.');
$expect(\App\Support\OfficialGradeScale::letter(98.0) === 'A+' && \App\Support\OfficialGradeScale::letter(49.99) === 'F', 'Canonical grade bands changed.');
$expect(\App\Support\OfficialGradeScale::points('A', 'passed') === 3.75 && \App\Support\OfficialGradeScale::points('A', 'incomplete') === 0.0 && \App\Support\OfficialGradeScale::points('B', 'failed') === 0.0, 'Canonical grade points, failed-result parity, or exclusions changed.');
$expect(! preg_match('/Schema::|insert\(|update\(|delete\(|lockForUpdate/', $query), 'Reporting coordinator must remain read-only and avoid runtime schema inspection.');
$expect(! str_contains($request, 'orderByRaw') && str_contains($query, 'applyDetailSort') && str_contains($query, '$map[$sort[\'field\']]'), 'Sort fields must resolve through server-owned projection maps.');

if ($failures !== []) { fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL); exit(1); }
echo "Executive Reports Phase 1 contract: PASS\n";
