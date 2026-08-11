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
        foreach (['registrar' => 'P01-REGISTRAR', 'exam.board' => 'P01-EXAM-OFFICER'] as $username => $employeeNumber) {
            $user = User::query()->where('username', $username)->firstOrFail();
            if ($user->employee_id !== null
                && Employee::query()->whereKey($user->employee_id)->value('employee_number') !== $employeeNumber) {
                throw new \RuntimeException("{$username} is linked to an unexpected employee; manual review is required.");
            }
        }

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

            $legacyUnits = [
                'VP_ADMIN' => '7', 'VP_SCI' => '8', 'VP_COMM' => '9',
                'HR_OFFICE' => '711', 'LIBRARY' => '13',
                'REG_OFFICE' => '732', 'EXAM_OFFICE' => '735',
            ];
            $knownOrganizationalReferences = [
                'employees.organizational_unit_id',
                'employee_positions.organizational_unit_id',
                'employee_unit_assignments.organizational_unit_id',
                'boards.organizational_unit_id',
                'colleges.organizational_unit_id',
                'departments.organizational_unit_id',
                'organizational_units.parent_unit_id',
            ];
            $discoveredReferences = DB::table('information_schema.key_column_usage')
                ->where('table_schema', DB::getDatabaseName())
                ->where('referenced_table_name', 'organizational_units')
                ->where('referenced_column_name', 'organizational_unit_id')
                ->get(['table_name', 'column_name']);
            foreach ($discoveredReferences as $reference) {
                $key = "{$reference->table_name}.{$reference->column_name}";
                if (! in_array($key, $knownOrganizationalReferences, true)) {
                    throw new \RuntimeException("Unknown organizational-unit reference {$key}; reviewed migration is required.");
                }
            }

            $rootId = OrganizationalUnit::query()->where('unit_code', 'PRES')->value('organizational_unit_id');
            $legacyUniversityRootIds = OrganizationalUnit::query()
                ->whereIn('unit_code', ['VP_ADMIN', 'VP_SCI', 'VP_COMM'])
                ->pluck('organizational_unit_id');
            if ($legacyUniversityRootIds->isNotEmpty() && DB::getSchemaBuilder()->hasTable('user_access_scopes')) {
                $legacyScopesByUser = DB::table('user_access_scopes')
                    ->where('scope_type', 'university')
                    ->whereIn('scope_id', $legacyUniversityRootIds)
                    ->get()
                    ->groupBy('user_id');

                foreach ($legacyScopesByUser as $userId => $legacyScopes) {
                    $officialScope = DB::table('user_access_scopes')
                        ->where('user_id', $userId)
                        ->where('scope_type', 'university')
                        ->where('scope_id', $rootId)
                        ->first();
                    $finalIsActive = self::aggregateScopeActivity([
                        ...$legacyScopes->pluck('is_active')->all(),
                        ...($officialScope === null ? [] : [$officialScope->is_active]),
                    ]);

                    if ($officialScope === null) {
                        DB::table('user_access_scopes')->insert([
                            'user_id' => $userId,
                            'scope_type' => 'university',
                            'scope_id' => $rootId,
                            'is_active' => $finalIsActive,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('user_access_scopes')
                            ->where('user_access_scope_id', $officialScope->user_access_scope_id)
                            ->update(['is_active' => $finalIsActive, 'updated_at' => now()]);
                    }

                    $persistedActivity = DB::table('user_access_scopes')
                        ->where('user_id', $userId)
                        ->where('scope_type', 'university')
                        ->where('scope_id', $rootId)
                        ->value('is_active');
                    if ((bool) $persistedActivity !== $finalIsActive) {
                        throw new \RuntimeException("Failed to persist the PRES scope before removing legacy scopes for user {$userId}.");
                    }

                    DB::table('user_access_scopes')
                        ->where('user_id', $userId)
                        ->where('scope_type', 'university')
                        ->whereIn('scope_id', $legacyUniversityRootIds)
                        ->delete();
                }
            }

            foreach ($legacyUnits as $legacyCode => $officialCode) {
                $legacyId = OrganizationalUnit::query()->where('unit_code', $legacyCode)->value('organizational_unit_id');
                if ($legacyId === null) {
                    continue;
                }
                $officialId = OrganizationalUnit::query()->where('unit_code', $officialCode)->value('organizational_unit_id');
                foreach (['employees', 'employee_positions', 'employee_unit_assignments', 'boards', 'colleges', 'departments'] as $table) {
                    DB::table($table)->where('organizational_unit_id', $legacyId)->update(['organizational_unit_id' => $officialId]);
                }
                OrganizationalUnit::query()->where('parent_unit_id', $legacyId)->update(['parent_unit_id' => $officialId]);

                $remainingScopes = DB::getSchemaBuilder()->hasTable('user_access_scopes')
                    ? DB::table('user_access_scopes')->where('scope_type', 'university')->where('scope_id', $legacyId)->count()
                    : 0;
                if ($remainingScopes !== 0) {
                    throw new \RuntimeException("Legacy unit {$legacyCode} still owns ambiguous scopes; manual review is required.");
                }
                foreach ($discoveredReferences as $reference) {
                    $remaining = DB::table($reference->table_name)
                        ->where($reference->column_name, $legacyId)->count();
                    if ($remaining !== 0) {
                        throw new \RuntimeException(
                            "Legacy unit {$legacyCode} is still referenced by {$reference->table_name}.{$reference->column_name}."
                        );
                    }
                }
                OrganizationalUnit::query()->where('organizational_unit_id', $legacyId)->update(['is_active' => false]);
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
            foreach ($identities as [$username, $roleCode, $employeeNumber, $lastName, $email, $unitCode]) {
                $user = User::query()->where('username', $username)->firstOrFail();
                if ($user->employee_id !== null) {
                    $linkedNumber = Employee::query()->whereKey($user->employee_id)->value('employee_number');
                    if ($linkedNumber !== $employeeNumber) {
                        throw new \RuntimeException("{$username} is linked to an unexpected employee; manual review is required.");
                    }
                }
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
                if ($user->employee_id === null) {
                    $user->update(['employee_id' => $employee->employee_id]);
                }
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

    public static function aggregateScopeActivity(array $states): bool
    {
        return max([0, ...array_map(static fn ($state): int => (int) (bool) $state, $states)]) === 1;
    }
}
