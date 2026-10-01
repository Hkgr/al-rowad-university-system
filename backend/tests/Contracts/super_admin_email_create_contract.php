<?php
declare(strict_types=1);
// Static checks supplement, never replace, HTTP and MariaDB behavior tests.
$root = dirname(__DIR__, 2);
$read = fn (string $path): string => file_get_contents($root.'/'.$path);
$check = function (bool $ok, string $why): void { if (! $ok) throw new RuntimeException($why); };
$user = $read('app/Models/User.php');
$scope = $read('app/Services/DataScopeService.php');
$email = $read('app/Services/UniversityEmailProvisioningService.php');
$check(str_contains($user, "status_code === 'active' && \$this->hasRoleCode('super_admin')"), 'Actual active administrator only');
$check(str_contains($user, '$this->isSuperAdmin() || $this->effectivePermissions()->contains($permission)'), 'Central permissions');
$check(str_contains($user, "\$this->student_id !== null && \$this->hasRoleCode('student')") && str_contains($user, "\$this->employee_id !== null && \$this->hasRoleCode('doctor_instructor')"), 'No invented self identities');
foreach (['PresidentPortal','MinistryPortal','ExecutiveReportAccess','UniversityEmailAccess','AdministrativeGovernance','ScientificCourseAccess','ScientificProgramAccess','ExamManualGradeEntryAccess','PortalReportRegistry'] as $helper) $check(str_contains($read('app/Support/'.$helper.'.php'), 'isSuperAdmin()'), 'Administrative authority missing: '.$helper);
$check(str_contains($scope, 'scopeUniversityEmailStudents') && str_contains($scope, 'scopeActualAcademicStudents') && str_contains($scope, "hasRoleCode('technical_team')"), 'Dedicated email actual scopes');
$routes = $read('routes/api.php');
$check(str_contains($routes, "prefix('technical/university-email/students/{student}')") && str_contains($routes, "Route::post('create', 'create')") && str_contains($email, 'public function create(User $user, int $student, string $name)'), 'Explicit one-step endpoint');
$create = substr($email, strpos($email, 'public function create('), strpos($email, 'private function describe(') - strpos($email, 'public function create('));
$check(str_contains($create, 'lockForUpdate()') && str_contains($create, "where('status', '!=', 'cancelled')") && str_contains($create, 'university_email_operation_requires_review'), 'Never implicitly execute or replace a previous attempt');
$check(strpos($create, '$state = $this->execute(') > strpos($create, '});'), 'Remote workflow only after preparation commits');
$check(!preg_match('/Http::|->retry\(|->delete\(|Log::|session\(/', $create), 'Reuse canonical transport without unsafe bypass');
$check(str_contains($read('app/Services/AccountAdministrationService.php'), 'last_super_admin'), 'Last administrator protection');
echo "Super administrator and one-step email source contract passed (static only)\n";
