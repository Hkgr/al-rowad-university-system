<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('roles')
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('role_permissions')
        ) {
            return;
        }

        $grantMatrix = [
            'registration_officer' => [
                'students.view',
                'students.manage',
                'academic_structure.view',
                'courses.view',
                'registration.view',
                'registration.manage',
                'grades.view',
                'attendance.view',
                'reports.view',
                'dashboards.view',
            ],
            'exam_officer' => [
                'students.view',
                'academic_structure.view',
                'courses.view',
                'exams.view',
                'exams.manage',
                'grades.view',
                'grades.manage',
                'attendance.view',
                'reports.view',
                'dashboards.view',
            ],
            'doctor_instructor' => [
                'academic_structure.view',
                'courses.view',
                'grades.view',
                'grades.manage',
                'attendance.view',
                'attendance.manage',
                'dashboards.view',
            ],
            'academic_advisor' => [
                'students.view',
                'academic_structure.view',
                'courses.view',
                'registration.view',
                'registration.manage',
                'grades.view',
                'dashboards.view',
            ],
            'dean' => [
                'students.view',
                'academic_structure.view',
                'academic_structure.manage',
                'courses.view',
                'courses.manage',
                'grades.view',
                'reports.view',
                'dashboards.view',
            ],
            'head_of_department' => [
                'students.view',
                'academic_structure.view',
                'academic_structure.manage',
                'courses.view',
                'courses.manage',
                'grades.view',
                'reports.view',
                'dashboards.view',
            ],
            'hr_officer' => [
                'hr.view',
                'hr.manage',
                'organizational_structure.view',
                'dashboards.view',
            ],
        ];

        foreach ($grantMatrix as $roleCode => $permissionCodes) {
            $roleId = DB::table('roles')
                ->where('role_code', $roleCode)
                ->where('is_active', true)
                ->value('role_id');

            if (! $roleId) {
                continue;
            }

            $permissionIds = DB::table('permissions')
                ->whereIn('permission_code', $permissionCodes)
                ->where('is_active', true)
                ->pluck('permission_id');

            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'granted_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Authorization grants are intentionally preserved on rollback so a
        // deployment rollback cannot silently remove production access.
    }
};
