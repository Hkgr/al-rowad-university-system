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

        $officialAdmin = OrganizationalUnit::query()->where('unit_code', '7')->first();
        $legacyAdmin = OrganizationalUnit::query()->where('unit_code', 'VP_ADMIN')->first();
        if ($officialAdmin === null && $legacyAdmin !== null) {
            $legacyAdmin->update(['unit_code' => '7', 'unit_name' => 'نائب رئيس الجامعة للشؤون الإدارية']);
        } elseif ($officialAdmin === null && $legacyAdmin === null) {
            $presidency = OrganizationalUnit::query()->where('unit_code', 'PRES')->firstOrFail();
            $viceType = OrganizationalUnitType::query()->where('type_code', 'vice_presidency')->firstOrFail();
            OrganizationalUnit::query()->create(['unit_code' => '7', 'unit_name' => 'نائب رئيس الجامعة للشؤون الإدارية', 'unit_type_id' => $viceType->unit_type_id, 'parent_unit_id' => $presidency->organizational_unit_id, 'is_active' => true]);
        }

        $directorateType = OrganizationalUnitType::query()->where('type_code', 'directorate')->value('unit_type_id');
        $officeType = OrganizationalUnitType::query()->where('type_code', 'office')->value('unit_type_id');
        $administrationType = OrganizationalUnitType::query()->firstOrCreate(
            ['type_code' => 'administration'],
            ['type_name' => 'إدارة', 'description' => 'Administrative unit', 'is_active' => true]
        )->unit_type_id;
        $units = [
            ['73', 'مديرية شؤون الطلاب', $directorateType, '7'],
            ['731', 'مكتب الإرشاد والتوجيه', $officeType, '73'],
            ['732', 'مكتب القبول والتسجيل', $officeType, '73'],
            ['733', 'مكتب الخدمات الطلابية', $officeType, '73'],
            ['734', 'مكتب المنح والإيفاد والتبادل الطلابي', $officeType, '73'],
            ['735', 'إدارة الامتحانات', $administrationType, '73'],
            ['736', 'مكتب التوثيق والتدقيق', $officeType, '73'],
        ];
        foreach ($units as [$code, $name, $type, $parentCode]) {
            $parentId = $parentCode ? OrganizationalUnit::query()->where('unit_code', $parentCode)->value('organizational_unit_id') : null;
            OrganizationalUnit::query()->updateOrCreate(['unit_code' => $code], [
                'unit_name' => $name, 'unit_type_id' => $type, 'parent_unit_id' => $parentId, 'is_active' => true,
            ]);
        }
    }
}
