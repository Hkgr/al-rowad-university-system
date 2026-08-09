<?php

namespace Database\Seeders;

use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;

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
            RolePermission::query()->where('role_id', $role->role_id)
                ->whereNotIn('permission_id', $permissions->pluck('permission_id'))->delete();
            foreach ($permissions as $permission) {
                RolePermission::query()->firstOrCreate([
                    'role_id' => $role->role_id,
                    'permission_id' => $permission->permission_id,
                ], ['granted_at' => now()]);
            }
        }

        $directorateType = OrganizationalUnitType::query()->where('type_code', 'directorate')->value('unit_type_id');
        $officeType = OrganizationalUnitType::query()->where('type_code', 'office')->value('unit_type_id');
        $units = [
            ['73', 'Student Affairs Directorate', $directorateType, null],
            ['731', 'Guidance and Counseling Office', $officeType, '73'],
            ['732', 'Admissions and Registration Office', $officeType, '73'],
            ['733', 'Student Services Office', $officeType, '73'],
            ['734', 'Scholarships, Delegations and Student Exchange Office', $officeType, '73'],
            ['735', 'Examinations Administration', OrganizationalUnitType::query()->where('type_code', 'administration')->value('unit_type_id'), '73'],
            ['736', 'Documentation and Audit Office', $officeType, '73'],
        ];
        foreach ($units as [$code, $name, $type, $parentCode]) {
            $parentId = $parentCode ? OrganizationalUnit::query()->where('unit_code', $parentCode)->value('organizational_unit_id') : null;
            OrganizationalUnit::query()->updateOrCreate(['unit_code' => $code], [
                'unit_name' => $name, 'unit_type_id' => $type, 'parent_unit_id' => $parentId, 'is_active' => true,
            ]);
        }
    }
}
