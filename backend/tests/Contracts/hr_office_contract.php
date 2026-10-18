<?php

// Dependency-free source boundaries; not a substitute for the executed HTTP/MariaDB suites.
$root = dirname(__DIR__, 2);
$read = fn (string $path): string => file_get_contents($root.'/'.$path);
$check = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$service = $read('app/Services/HrOfficeService.php');
$guard = $read('app/Support/HrOffice.php');
$migration = $read('database/migrations/2026_10_09_100000_create_administrative_hr_office.php');
$routes = $read('routes/api.php');
$check(substr_count($migration, "Schema::create('hr_") === 8, 'Eight additive HR tables, no replacement person/payroll store.');
foreach (['employees_hr_revision', 'OLD.hr_revision + 1', 'restrictOnDelete', 'hr_revision_at_link', 'hr_request_current_target', 'HR rollback refused'] as $needle) {
    $check(str_contains($migration, $needle), 'Missing migration invariant: '.$needle);
}
foreach (['effectiveRoles()', 'effectivePermissions()', 'isSuperAdmin()', 'hasActualUniversityScope', 'finance_officer'] as $needle) {
    $check(str_contains($guard, $needle), 'Missing access boundary: '.$needle);
}
foreach (['legacy_classification', 'approved_request', 'hr_context_stale', 'hr_temporary_duration', 'hr_employment_mode', 'hr_payroll_identity_conflict', 'before_items', 'before_participant_ids', 'financial_classification_unchanged'] as $needle) {
    $check(str_contains($service, $needle), 'Missing domain invariant: '.$needle);
}
foreach (["DB::table('users')->insert", 'User::create', 'TeachingAssignmentWorkflow::', 'GradeService::', 'PayrollEntry::create', "DB::table('payroll_entries')->update", "DB::table('payroll_employees')->delete", "DB::table('employees')->delete"] as $forbidden) {
    $check(! str_contains($service, $forbidden), 'Forbidden HR side effect: '.$forbidden);
}
$check(str_contains($routes, 'RequireAdministrativePayroll::class'), 'Financial actions must have their own guard.');
$check(str_contains($routes, 'OwnerPayrollController::class'), 'Reuse the canonical payroll controller.');
echo "HR office source boundaries PASS (static only).\n";
