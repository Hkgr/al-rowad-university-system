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

        $typeIds = OrganizationalUnitType::query()
            ->whereIn('type_code', ['presidency', 'vice_presidency', 'directorate', 'office', 'administration'])
            ->pluck('unit_type_id', 'type_code');

        foreach (['presidency', 'vice_presidency', 'directorate', 'office'] as $requiredType) {
            if (! $typeIds->has($requiredType)) {
                throw new \RuntimeException("Missing organizational unit type: {$requiredType}");
            }
        }
        if (! $typeIds->has('administration')) {
            $typeIds['administration'] = OrganizationalUnitType::query()->create([
                'type_code' => 'administration',
                'type_name' => 'إدارة',
                'description' => 'Administrative unit',
                'is_active' => true,
            ])->unit_type_id;
        }

        // Source of truth: the complete approved P0-1 chart. Parents are processed
        // before children so this remains idempotent even when the table is empty.
        $units = [
            ['PRES', 'رئيس الجامعة', 'presidency', null],
            ['7', 'نائب رئيس الجامعة للشؤون الإدارية', 'vice_presidency', 'PRES'],
            ['71', 'مديرية الشؤون الإدارية', 'directorate', '7'],
            ['72', 'مديرية الشؤون المالية', 'directorate', '7'],
            ['73', 'مديرية شؤون الطلاب', 'directorate', '7'],
            ['731', 'مكتب الإرشاد والتوجيه', 'office', '73'],
            ['732', 'مكتب القبول والتسجيل', 'office', '73'],
            ['733', 'مكتب الخدمات الطلابية', 'office', '73'],
            ['734', 'مكتب المنح والإيفاد والتبادل الطلابي', 'office', '73'],
            ['735', 'إدارة الامتحانات', 'administration', '73'],
            ['736', 'مكتب التوثيق والتصديق', 'office', '73'],
        ];

        foreach ($units as [$code, $name, $typeCode, $parentCode]) {
            $parentId = $parentCode === null ? null : OrganizationalUnit::query()
                ->where('unit_code', $parentCode)->value('organizational_unit_id');
            OrganizationalUnit::query()->updateOrCreate(['unit_code' => $code], [
                'unit_name' => $name,
                'unit_type_id' => $typeIds[$typeCode],
                'parent_unit_id' => $parentId,
                'is_active' => true,
            ]);
        }
    }
}
