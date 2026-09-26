<?php

// Dependency-free structural checks complement, never replace, PortalReportsTest HTTP/SQLite tests.
$root = dirname(__DIR__, 2);
$read = fn ($p) => file_get_contents($root.'/'.$p);
$assert = function ($ok, $message) {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
$routes = $read('routes/api.php');
$registry = $read('app/Support/PortalReportRegistry.php');
$service = $read('app/Services/PortalReportService.php');
$controller = $read('app/Http/Controllers/Api/PortalReportController.php');
$assert(str_contains($routes, 'portal-reports/{portal}'), 'Report definitions route missing');
$assert(! preg_match('/Route::(?:post|put|patch|delete)\([^\n]*portal-reports/', $routes), 'Report routes must be read-only');
$assert(str_contains($registry, 'effectivePermissions()') && ! str_contains($registry, 'hasPermission('), 'Assigned permissions only');
foreach (['MinistryPortal', 'PresidentPortal', 'scopes($user)', 'student_id', 'employee_id'] as $token) {
    $assert(str_contains($registry, $token), 'Missing guard '.$token);
}
foreach (['MinistryQueries::officialResults', 'offeringsForProfessor', 'ExamStudentAcademicRecordService', 'forPage', 'fromSub', 'groupBy'] as $token) {
    $assert(str_contains($service, $token), 'Missing canonical projection '.$token);
}
$assert(! preg_match('/->(?:insert|update|delete|save|create|lockForUpdate)\s*\(|DB::transaction/', $service), 'No writes or locks in read service');
$assert(str_contains($controller, 'array_diff') && str_contains($controller,'max:100'), 'Strict bounded request required');
echo "Portal reports structural contract: PASS (not runtime verification)\n";
