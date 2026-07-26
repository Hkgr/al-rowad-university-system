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

    private const STUDENT_RECORD_ROLES = ['registration_officer', 'exam_officer', 'academic_advisor'];

    public function assertStudentRecord(User $user, Student $student): void
    {
        if ($user->student_id !== null && (int) $user->student_id === (int) $student->student_id) {
            return;
        }

        $this->assertRole($user, [...self::ADMIN_ROLES, ...self::STUDENT_RECORD_ROLES]);
    }

    public function assertCanViewGrades(User $user, StudentCourseRegistration $registration): void
    {
        if ($user->student_id !== null && (int) $user->student_id === (int) $registration->student_id) {
            return;
        }

        if ($user->hasPermission('grades.view') && $this->hasRole($user, [...self::ADMIN_ROLES, 'exam_officer'])) {
            return;
        }

        $this->assertAssignedInstructor($user, (int) $registration->course_offering_id);
    }

    public function assertCanEnterGrades(User $user, StudentCourseRegistration $registration): void
    {
        if ($user->hasPermission('grades.manage') && $this->hasRole($user, [...self::ADMIN_ROLES, 'exam_officer'])) {
            return;
        }

        $this->assertAssignedInstructor($user, (int) $registration->course_offering_id);
    }

    public function assertExaminationCommittee(User $user): void
    {
        if (! $user->hasPermission('exams.manage') || ! $this->hasRole($user, [...self::ADMIN_ROLES, 'exam_officer'])) {
            throw new AccessDeniedHttpException('This operation requires Examination Committee permission.');
        }
    }

    public function assertStudentAffairs(User $user): void
    {
        $this->assertRole($user, [...self::ADMIN_ROLES, 'registration_officer']);
    }

    public function assertSystemAdministrator(User $user): void
    {
        $this->assertRole($user, self::ADMIN_ROLES);
    }

    public function assertCanSearchStudents(User $user): void
    {
        $this->assertRole($user, [...self::ADMIN_ROLES, ...self::STUDENT_RECORD_ROLES]);
    }

    public function assertCanAccessOffering(User $user, int $courseOfferingId): void
    {
        if ($this->hasRole($user, [...self::ADMIN_ROLES, 'exam_officer'])) {
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
        if (! $this->hasRole($user, ['doctor_instructor'])) {
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
