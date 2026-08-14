<?php

namespace App\Services;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ResourceAuthorizationService
{
    private const GLOBAL_ACADEMIC_REFERENCE_TABLES = ['academic_levels', 'academic_years', 'semesters'];

    private const GLOBAL_ACADEMIC_READ_PERMISSIONS = [
        'academic_structure.view',
        'registration.view',
        'grades.view',
    ];

    private const MODULE_TABLE_PREFIXES = [
        'students' => ['students', 'student_statuses', 'student_documents', 'student_academic_terms'],
        'admissions' => ['admission_', 'applicants'],
        'academic_structure' => ['academic_', 'colleges', 'departments', 'program_'],
        'courses' => ['courses', 'course_'],
        'registration' => ['registration_', 'student_credit_', 'student_course_registrations'],
        'exams' => ['exam_', 'supplementary_'],
        'grades' => ['grade_', 'grading_', 'result_', 'student_course_results', 'student_grade_'],
        'attendance' => ['attendance_', 'student_attendance'],
        'hr' => ['employees', 'employee_', 'faculty_', 'positions'],
        'library' => ['library_'],
        'boards' => ['boards', 'board_', 'meeting_'],
        'organizational_structure' => ['organizational_'],
        'users_permissions' => ['users', 'user_', 'roles', 'role_', 'permissions'],
        'system_settings' => ['account_statuses', 'system_'],
    ];

    public function authorize(User $user, string $modelClass, bool $write): void
    {
        $table = (new $modelClass())->getTable();
        if (in_array($table, self::GLOBAL_ACADEMIC_REFERENCE_TABLES, true)) {
            $allowed = $write
                ? $user->hasPermission('academic_structure.manage')
                : collect(self::GLOBAL_ACADEMIC_READ_PERMISSIONS)->contains(
                    fn (string $permission): bool => $user->hasPermission($permission)
                );
            if (! $allowed) {
                throw new AccessDeniedHttpException('You do not have permission to access this academic reference resource.');
            }

            return;
        }

        $module = $this->moduleFor($table);
        $permission = $module.'.'.($write ? 'manage' : 'view');

        if (! $user->hasPermission($permission)) {
            throw new AccessDeniedHttpException('You do not have permission to access this resource.');
        }
    }

    private function moduleFor(string $table): string
    {
        foreach (self::MODULE_TABLE_PREFIXES as $module => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($table === $prefix || str_starts_with($table, $prefix)) {
                    return $module;
                }
            }
        }

        return 'system_settings';
    }
}
