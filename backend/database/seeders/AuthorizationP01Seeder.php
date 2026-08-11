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

        $types = OrganizationalUnitType::query()->whereIn('type_code', ['administration', 'center', 'club', 'college', 'directorate', 'institute', 'lab', 'office', 'presidency', 'unit', 'vice_presidency'])->get()->keyBy('type_code');
        $units = [['PRES', 'رئيس الجامعة', 'presidency', null], ['1', 'إدارة البحوث والدراسات', 'administration', 'PRES'], ['11', 'مركز البحوث والدراسات', 'center', '1'], ['12', 'مجلة جامعة الرواد', 'unit', '1'], ['13', 'المكتبة', 'center', '1'], ['2', 'إدارة التطوير ودعم القرار', 'administration', 'PRES'], ['21', 'وحدة نظم المعلومات والتخطيط الاستراتيجي', 'unit', '2'], ['22', 'الجودة والاعتماد الأكاديمي', 'unit', '2'], ['23', 'مشاريع إنتاجية', 'unit', '2'], ['3', 'إدارة التعليم الإلكتروني', 'administration', 'PRES'], ['31', 'التعليم عن بعد', 'unit', '3'], ['32', 'التعليم الافتراضي', 'unit', '3'], ['4', 'الأمين العام للجامعة', 'administration', 'PRES'], ['41', 'مكتب الشؤون القانونية', 'office', '4'], ['42', 'مكتب الأمن والسلامة', 'office', '4'], ['5', 'مديرية العلاقات العامة والإعلام', 'directorate', 'PRES'], ['51', 'مكتب العلاقات العامة', 'office', '5'], ['52', 'مكتب الإعلام والاتصال', 'office', '5'], ['6', 'وحدة التقييم والمتابعة', 'unit', 'PRES'], ['7', 'نائب رئيس الجامعة للشؤون الإدارية', 'vice_presidency', 'PRES'], ['71', 'مديرية الشؤون الإدارية', 'directorate', '7'], ['711', 'مكتب الموارد البشرية', 'office', '71'], ['712', 'مكتب الديوان والأرشيف', 'office', '71'], ['713', 'مكتب الرعاية الصحية', 'office', '71'], ['714', 'مكتب الخدمات الإدارية', 'office', '71'], ['715', 'المكتب التقني', 'office', '71'], ['72', 'مديرية الشؤون المالية', 'directorate', '7'], ['721', 'مكتب المحاسبة', 'office', '72'], ['722', 'أمين الصندوق', 'office', '72'], ['723', 'أمين المستودع', 'office', '72'], ['73', 'مديرية شؤون الطلاب', 'directorate', '7'], ['731', 'مكتب الإرشاد والتوجيه', 'office', '73'], ['732', 'مكتب القبول والتسجيل', 'office', '73'], ['733', 'مكتب الخدمات الطلابية', 'office', '73'], ['734', 'مكتب المنح والإيفاد والتبادل الطلابي', 'office', '73'], ['735', 'إدارة الامتحانات', 'administration', '73'], ['736', 'مكتب التوثيق والتصديق', 'office', '73'], ['8', 'نائب رئيس الجامعة للشؤون العلمية', 'vice_presidency', 'PRES'], ['81', 'إدارة التعليم الجامعي', 'administration', '8'], ['811', 'الكليات', 'college', '81'], ['812', 'المعاهد', 'institute', '81'], ['813', 'المخابر', 'lab', '81'], ['82', 'إدارة الدراسات العليا والبحث العلمي', 'administration', '8'], ['821', 'الماجستير', 'unit', '82'], ['822', 'الدكتوراه', 'unit', '82'], ['823', 'التعليم المهني', 'unit', '82'], ['9', 'نائب رئيس الجامعة للشؤون المجتمعية', 'vice_presidency', 'PRES'], ['91', 'إدارة تنمية وبناء القدرات', 'administration', '9'], ['911', 'مركز التأهيل والتدريب', 'center', '91'], ['912', 'مركز اللغات الأجنبية', 'center', '91'], ['913', 'مركز تقنية المعلومات', 'center', '91'], ['914', 'مركز ريادة الأعمال', 'center', '91'], ['92', 'إدارة الأنشطة المجتمعية', 'administration', '9'], ['921', 'نادي الشباب والرياضة', 'club', '92'], ['922', 'نادي التطوع والأنشطة المجتمعية', 'club', '92'], ['923', 'نادي أصدقاء البيئة', 'club', '92'], ['924', 'مكتب المسؤولية المجتمعية', 'office', '92'], ['925', 'مكتب العدالة وحقوق الإنسان', 'office', '92']];

        foreach ($units as [$code, $name, $typeCode, $parentCode]) {
            $type = $types->get($typeCode) ?? throw new \RuntimeException("Missing organizational unit type: {$typeCode}");
            $parentId = $parentCode === null ? null : OrganizationalUnit::query()->where('unit_code', $parentCode)->value('organizational_unit_id');
            OrganizationalUnit::query()->updateOrCreate(['unit_code' => $code], [
                'unit_name' => $name,
                'unit_type_id' => $type->unit_type_id,
                'parent_unit_id' => $parentId,
                'description' => 'Official organizational chart',
                'is_active' => true,
            ]);
        }
    }
}
