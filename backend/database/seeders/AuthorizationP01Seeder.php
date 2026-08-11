<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\EmployeeType;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AuthorizationP01Seeder extends Seeder
{
    public function run(): void
    {
        $matrix = [
            'exam_officer' => ['students.view', 'academic_structure.view', 'courses.view', 'registration.view', 'exams.view', 'exams.manage', 'grades.view', 'grades.manage', 'system_settings.view'],
            'registration_officer' => ['students.view', 'students.manage', 'admissions.view', 'admissions.manage', 'academic_structure.view', 'courses.view', 'registration.view', 'registration.manage', 'system_settings.view'],
            'doctor_instructor' => ['courses.view', 'students.view', 'attendance.view', 'attendance.manage', 'grades.view', 'grades.manage'],
            'student' => ['students.view', 'registration.view', 'grades.view', 'attendance.view'],
        ];

        foreach ($matrix as $roleCode => $permissionCodes) {
            $role = Role::query()->where('role_code', $roleCode)->firstOrFail();
            $permissions = Permission::query()->whereIn('permission_code', $permissionCodes)->get();
            if ($permissions->count() !== count($permissionCodes)) {
                throw new \RuntimeException("Missing one or more P0-1 permissions for role {$roleCode}.");
            }
            foreach ($permissions as $permission) {
                RolePermission::query()->firstOrCreate([
                    'role_id' => $role->role_id,
                    'permission_id' => $permission->permission_id,
                ], ['granted_at' => now()]);
            }
        }

        $chart = require __DIR__.'/data/p01_official_chart.php';
        if (count($chart) !== 58) {
            throw new \RuntimeException('The official P0-1 chart must contain exactly 58 units.');
        }

        DB::transaction(function () use ($chart): void {
            $requiredTypes = collect($chart)->pluck(2)->unique()->values();
            $typeIds = OrganizationalUnitType::query()
                ->whereIn('type_code', $requiredTypes)
                ->where('is_active', true)
                ->pluck('unit_type_id', 'type_code');
            if ($typeIds->count() !== $requiredTypes->count()) {
                throw new \RuntimeException('One or more official organizational unit types are missing or inactive.');
            }

            foreach ($chart as [$code, $name, $typeCode, $parentCode]) {
                $parentId = $parentCode === null ? null : OrganizationalUnit::query()
                    ->where('unit_code', $parentCode)->value('organizational_unit_id');
                if ($parentCode !== null && $parentId === null) {
                    throw new \RuntimeException("Missing official parent {$parentCode} for unit {$code}.");
                }
                OrganizationalUnit::query()->updateOrCreate(['unit_code' => $code], [
                    'unit_name' => $name,
                    'unit_type_id' => $typeIds[$typeCode],
                    'parent_unit_id' => $parentId,
                    'is_active' => true,
                ]);
            }

            $employeeTypeId = EmployeeType::query()->where('type_code', 'administrative')->value('employee_type_id');
            $employeeStatusId = EmployeeStatus::query()->where('status_code', 'active')->value('employee_status_id');
            if ($employeeTypeId === null || $employeeStatusId === null) {
                throw new \RuntimeException('Active administrative employee prerequisites are missing.');
            }

            $identities = [
                ['registrar', 'registration_officer', 'P01-REGISTRAR', 'Registrar', 'registrar@rowad.edu', '732'],
                ['exam.board', 'exam_officer', 'P01-EXAM-OFFICER', 'Exam Officer', 'exam.officer@rowad.edu', '735'],
            ];
            $rootId = OrganizationalUnit::query()->where('unit_code', 'PRES')->value('organizational_unit_id');
            foreach ($identities as [$username, $roleCode, $employeeNumber, $lastName, $email, $unitCode]) {
                $user = User::query()->where('username', $username)->firstOrFail();
                $unitId = OrganizationalUnit::query()->where('unit_code', $unitCode)->value('organizational_unit_id');
                $employee = Employee::query()->updateOrCreate(['employee_number' => $employeeNumber], [
                    'first_name' => 'Test',
                    'last_name' => $lastName,
                    'email' => $email,
                    'hire_date' => now()->toDateString(),
                    'employee_type_id' => $employeeTypeId,
                    'employee_status_id' => $employeeStatusId,
                    'organizational_unit_id' => $unitId,
                ]);
                $user->update(['employee_id' => $employee->employee_id]);
                $roleId = Role::query()->where('role_code', $roleCode)->value('role_id');
                UserRole::query()->updateOrCreate(['user_id' => $user->user_id, 'role_id' => $roleId], [
                    'assigned_by_user_id' => null,
                    'assigned_at' => now(),
                    'is_active' => true,
                ]);
                $user->accessScopes()->updateOrCreate([
                    'scope_type' => 'university',
                    'scope_id' => $rootId,
                ], ['is_active' => true]);
            }
        });
    }
}
