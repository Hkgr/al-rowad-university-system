<?php

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\CourseOffering;
use App\Models\FacultyMember;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AcademicAuthorizationService
{
    private const ADMIN_ROLES = ['super_admin'];

    public function assertStudentRecord(User $user, Student $student): void
    {
        if (! $user->hasPermission('students.view')
            || ! app(DataScopeService::class)->canAccessStudent($user, $student)) {
            throw new AccessDeniedHttpException('You are not authorized to access this student record.');
        }
    }

    public function assertCanViewGrades(User $user, StudentCourseRegistration $registration): void
    {
        if ($user->student_id !== null && (int) $user->student_id === (int) $registration->student_id) {
            return;
        }

        if ($user->hasPermission('grades.view')
            && app(DataScopeService::class)->scopeRegistrations(StudentCourseRegistration::query(), $user)
                ->whereKey($registration->student_course_registration_id)->exists()) {
            return;
        }

        $this->assertAssignedInstructor($user, (int) $registration->course_offering_id);
    }

    public function assertCanEnterGrades(User $user, StudentCourseRegistration $registration): void
    {
        if ($user->hasPermission('grades.manage')
            && app(DataScopeService::class)->scopeRegistrations(StudentCourseRegistration::query(), $user)
                ->whereKey($registration->student_course_registration_id)->exists()) {
            return;
        }

        $this->assertAssignedInstructor($user, (int) $registration->course_offering_id);
    }

    public function assertExaminationCommittee(User $user): void
    {
        if (! $user->hasPermission('exams.manage')) {
            throw new AccessDeniedHttpException('This operation requires Examination Committee permission.');
        }
    }

    public function assertStudentAffairs(User $user): void
    {
        if (! $user->hasPermission('students.manage')) throw new AccessDeniedHttpException('Student management permission is required.');
    }

    public function assertSystemAdministrator(User $user): void
    {
        $this->assertRole($user, self::ADMIN_ROLES);
    }

    public function assertCanSearchStudents(User $user): void
    {
        if (! $user->hasPermission('students.view')) throw new AccessDeniedHttpException('Student view permission is required.');
    }

    public function assertCanAccessOffering(User $user, int $courseOfferingId): void
    {
        $offering = CourseOffering::query()->findOrFail($courseOfferingId);
        if (($user->hasPermission('courses.view') || $user->hasPermission('exams.view'))
            && app(DataScopeService::class)->canAccessOffering($user, $offering)) {
            return;
        }

        $this->assertAssignedInstructor($user, $courseOfferingId);
    }

    public function assertCanAccessAttendanceSession(User $user, int $sessionId): void
    {
        $offeringId = AttendanceSession::query()->whereKey($sessionId)->value('course_offering_id');
        if ($offeringId === null) {
            return;
        }

        $this->assertCanAccessOffering($user, (int) $offeringId);
    }

    private function assertAssignedInstructor(User $user, int $courseOfferingId): void
    {
        if ($user->employee_id === null) {
            throw new AccessDeniedHttpException('This operation is restricted to the assigned section instructor.');
        }

        $facultyIds = FacultyMember::query()
            ->where('employee_id', $user->employee_id)
            ->pluck('faculty_member_id');

        $assigned = CourseOffering::query()
            ->whereKey($courseOfferingId)
            ->where(function ($query) use ($facultyIds): void {
                $query->whereIn('faculty_member_id', $facultyIds)
                    ->orWhereHas('offeringInstructors', fn ($instructors) =>
                        $instructors->whereIn('faculty_member_id', $facultyIds)->where('is_active', true));
            })
            ->exists();

        if (! $assigned) {
            throw new AccessDeniedHttpException('This operation is restricted to the assigned section instructor.');
        }
    }

    private function assertRole(User $user, array $roles): void
    {
        if (! $this->hasRole($user, $roles)) {
            throw new AccessDeniedHttpException('You are not authorized to perform this academic operation.');
        }
    }

    private function hasRole(User $user, array $roles): bool
    {
        return $user->userRoleRecords()
            ->where('is_active', true)
            ->whereHas('role', fn ($role) => $role
                ->whereIn('role_code', $roles)
                ->where('is_active', true))
            ->exists();
    }
}
