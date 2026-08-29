<?php

$contract = static function (string $backendRoot): array {
    $errors = [];
    $expect = static function (bool $condition, string $message) use (&$errors): void {
        if (! $condition) $errors[] = $message;
    };
    $actionPath = $backendRoot.'/app/Http/Controllers/Api/ActionApiController.php';
    $controllerNames = [
        'MinistryPlacementController',
        'MinistryPlacementApplicantConversionController',
        'MinistryPlacementStudentEnrollmentController',
        'MinistryPlacementReconciliationController',
    ];

    $expect(is_file($actionPath), 'The non-CRUD ActionApiController is missing.');
    $action = is_file($actionPath) ? file_get_contents($actionPath) : '';
    $expect(str_contains($action, 'abstract class ActionApiController extends Controller'), 'ActionApiController must extend the framework Controller.');
    $expect(str_contains($action, 'protected function successResponse('), 'ActionApiController is missing successResponse().');
    $expect(str_contains($action, 'protected function errorResponse('), 'ActionApiController is missing errorResponse().');
    $expect(! str_contains($action, 'HandlesApiCrud'), 'ActionApiController must not use HandlesApiCrud.');
    $expect(! preg_match('/public function (index|store|show|update|destroy)\s*\(/', $action), 'ActionApiController must not declare CRUD actions.');

    foreach ($controllerNames as $controllerName) {
        $path = $backendRoot.'/app/Http/Controllers/Api/'.$controllerName.'.php';
        $expect(is_file($path), 'Missing Ministry workflow controller: '.$controllerName);
        $source = is_file($path) ? file_get_contents($path) : '';
        $expect(str_contains($source, 'class '.$controllerName.' extends ActionApiController'), $controllerName.' must extend ActionApiController.');
        $expect(! preg_match('/class '.$controllerName.' extends ApiController\b/', $source), $controllerName.' still extends the generic CRUD ApiController.');
    }

    require_once $backendRoot.'/app/Http/Controllers/Controller.php';
    require_once $actionPath;
    foreach ($controllerNames as $controllerName) {
        require_once $backendRoot.'/app/Http/Controllers/Api/'.$controllerName.'.php';
        $class = 'App\\Http\\Controllers\\Api\\'.$controllerName;
        $expect(is_subclass_of($class, App\Http\Controllers\Api\ActionApiController::class), $controllerName.' failed dependency-free runtime loading against ActionApiController.');
        if (in_array($controllerName, ['MinistryPlacementController', 'MinistryPlacementReconciliationController'], true)) {
            $expect((new ReflectionClass($class))->getMethod('index')->getDeclaringClass()->getName() === $class, $controllerName.' index() is not owned by the workflow controller.');
        }
    }

    $scope = file_get_contents($backendRoot.'/app/Services/DataScopeService.php');
    $expect(! str_contains($scope, 'orWhereKey('), 'DataScopeService still calls unsupported orWhereKey().');
    $expect(str_contains($scope, "orWhere('student_id', \$user->student_id)"), 'The linked Student self-scope path is missing.');
    $applicationFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backendRoot.'/app'));
    foreach ($applicationFiles as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') continue;
        $expect(! str_contains(file_get_contents($file->getPathname()), 'orWhereKey('), 'Unsupported orWhereKey() remains in '.$file->getPathname());
    }

    $phase5Sql = file_get_contents($backendRoot.'/database/sql/ministry-placement/40_phase5_reconciliation.sql');
    $unitCodeClause = preg_match("/\(table_name = 'organizational_units' AND column_name = 'unit_code'[^\n]+/", $phase5Sql, $match) ? $match[0] : '';
    $expect($unitCodeClause !== '', 'Phase 5 verifier no longer checks organizational_units.unit_code.');
    $expect(str_contains($unitCodeClause, "data_type = 'varchar'") && str_contains($unitCodeClause, 'character_maximum_length >= 50'), 'Phase 5 unit_code type/length checks changed.');
    $expect(! str_contains($unitCodeClause, 'is_nullable'), 'Phase 5 unit_code must accept the nullable production column.');
    $expect(str_contains($phase5Sql, "units.unit_code = 'PRES' AND units.is_active = 1"), 'The active PRES runtime/reference check must remain.');

    $routes = file_get_contents($backendRoot.'/routes/api.php');
    foreach ([
        "Route::get('ministry-placements'",
        "Route::get('ministry-placement-academic-years'",
        "Route::get('ministry-placement-reconciliation'",
    ] as $route) {
        $expect(str_contains($routes, $route), 'A production Ministry route changed or disappeared: '.$route);
    }
    $access = file_get_contents($backendRoot.'/app/Support/MinistryPlacementAccess.php');
    foreach (['effectivePermissions()', 'hasActualUniversityScope', 'admissions.view', 'admissions.manage'] as $authority) {
        $expect(str_contains($access, $authority), 'Ministry authorization contract changed: '.$authority);
    }
    $expect(! str_contains($access, 'super_admin'), 'Ministry access must not add a super-admin shortcut.');
    $expect((glob($backendRoot.'/database/migrations/*ministry*') ?: []) === [], 'The hotfix must not add a Ministry migration.');

    return $errors;
};

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $errors = $contract(dirname(__DIR__, 2));
    if ($errors !== []) {
        foreach ($errors as $error) fwrite(STDERR, $error.PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, "Ministry Placement production hotfix contract passed.\n");
}

return $contract;
