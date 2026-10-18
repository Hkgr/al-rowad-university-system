<?php

namespace App\Services;

use App\Exceptions\GradeException;
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

    public function assertCanAccessStudent(User $user, Student $student): void
    {
        if (! app(DataScopeService::class)->canAccessStudent($user, $student)) {
            throw new AccessDeniedHttpException('You are not authorized to access this student.');
        }
    }

    public function assertCanViewGrades(User $user, StudentCourseRegistration $registration): void
    {
        if (! $user->hasPermission('grades.view')) {
            throw new AccessDeniedHttpException('Grade view permission is required.');
        }

        if ($this->hasInternalGradeViewAccess($user, $registration)) {
            return;
        }

        if ($this->isRestrictedToOfficialStudentGrades($user)
            && (int) $user->student_id === (int) $registration->student_id) {
            $registration->loadMissing('courseOffering');
            if (! app(GradeService::class)->isOfficiallyApprovedOffering($registration->courseOffering)) {
                throw new AccessDeniedHttpException('النتيجة غير متاحة قبل اعتمادها رسمياً.');
            }

            return;
        }

        throw new AccessDeniedHttpException('This operation is restricted to the assigned section instructor.');
    }

    public function isRestrictedToOfficialStudentGrades(User $user): bool
    {
        return $user->student_id !== null && $user->employee_id === null;
    }

    public function canExposeStudentCourseResult(User $user, StudentCourseRegistration $registration): bool
    {
        if (! $this->isRestrictedToOfficialStudentGrades($user)) {
            return true;
        }

        if ((int) $user->student_id !== (int) $registration->student_id) {
            return false;
        }

        $registration->loadMissing('courseOffering');

        return app(GradeService::class)->isOfficiallyApprovedOffering($registration->courseOffering);
    }

    public function assertCanEnterGrades(User $user, StudentCourseRegistration $registration): void
    {
        if ($user->hasPermission('exams.manage')) {
            $this->assertExaminationCommitteeCanAccessOffering(
                $user,
                CourseOffering::query()->findOrFail($registration->course_offering_id)
            );

            return;
        }

        if ($user->hasPermission('grades.manage')) {
            throw new GradeException(
                'Grade mutations must use the grade-part workflow.',
                status: 403,
                errorCode: 'grade_part_workflow_required'
            );
        }

        throw new AccessDeniedHttpException('Grade management permission is required.');
    }

    public function assertPrimaryInstructor(User $user, int $courseOfferingId): void
    {
        if ($user->employee_id === null) {
            throw new GradeException(
                'Only the active primary instructor assigned to this section may manage its grades.',
                status: 403,
                errorCode: 'not_primary_instructor'
            );
        }

        $facultyIds = FacultyMember::query()
            ->where('employee_id', $user->employee_id)
            ->where('is_active', true)
            ->pluck('faculty_member_id');

        $assigned = $facultyIds->isNotEmpty() && CourseOffering::query()
            ->whereKey($courseOfferingId)
            ->where(function ($query) use ($facultyIds): void {
                $query->whereIn('faculty_member_id', $facultyIds)
                    ->orWhereHas('offeringInstructors', fn ($instructors) => $instructors
                        ->whereIn('faculty_member_id', $facultyIds)
                        ->where('is_primary', true)
                        ->where('is_active', true));
            })
            ->exists();

        if (! $assigned) {
            throw new GradeException(
                'Only the active primary instructor assigned to this section may manage its grades.',
                status: 403,
                errorCode: 'not_primary_instructor'
            );
        }
    }

    /**
     * @return list<string>
     */
    public function assignedGradeParts(User $user, int $courseOfferingId): array
    {
        return $this->gradeAssignments()->assignedGradeParts($user, $courseOfferingId);
    }

    public function canManageGradePart(User $user, int $courseOfferingId, string $part): bool
    {
        return $this->gradeAssignments()->canManageGradePart($user, $courseOfferingId, $part);
    }

    public function assertCanManageGradePart(User $user, int $courseOfferingId, string $part): void
    {
        $this->gradeAssignments()->assertCanManageGradePart($user, $courseOfferingId, $part);
    }

    public function assertAssignedInstructor(User $user, int $courseOfferingId): void
    {
        $this->gradeAssignments()->assertAssignedInstructor($user, $courseOfferingId);
    }

    public function assertCanViewGradeParts(User $user, int $courseOfferingId): void
    {
        if ($user->hasPermission('exams.manage')) {
            $this->assertExaminationCommitteeCanAccessOffering($user, CourseOffering::query()->findOrFail($courseOfferingId));
            return;
        }
        if (! $user->hasPermission('grades.view') && ! $user->hasPermission('grades.manage')) {
            throw new GradeException('Grade view permission is required.', status: 403, errorCode: 'unauthorized_grade_part');
        }
        $this->assertAssignedInstructor($user, $courseOfferingId);
    }

    public function assertCanViewGradeWorkflow(User $user, int $courseOfferingId): void
    {
        if ($user->hasPermission('exams.manage')) {
            $this->assertExaminationCommitteeCanAccessOffering(
                $user,
                CourseOffering::query()->findOrFail($courseOfferingId)
            );

            return;
        }

        $this->assertAssignedInstructor($user, $courseOfferingId);
    }

    public function assertExaminationCommittee(User $user): void
    {
        if (! $user->hasPermission('exams.manage')) {
            throw new AccessDeniedHttpException('This operation requires Examination Committee permission.');
        }
    }

    public function assertExaminationCommitteeCanAccessOffering(User $user, CourseOffering $offering): void
    {
        $this->assertExaminationCommittee($user);

        if (! app(DataScopeService::class)->canAccessOffering($user, $offering)) {
            throw new AccessDeniedHttpException('You are not authorized to access this course offering.');
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
        if (app(DataScopeService::class)->canAccessOffering($user, $offering)) {
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

    private function hasInternalGradeViewAccess(User $user, StudentCourseRegistration $registration): bool
    {
        if ($user->employee_id !== null && app(DataScopeService::class)
            ->scopeRegistrationsForStaff(StudentCourseRegistration::query(), $user)
            ->whereKey($registration->student_course_registration_id)
            ->exists()) {
            return true;
        }

        return $this->isAssignedInstructor($user, (int) $registration->course_offering_id);
    }

    private function isAssignedInstructor(User $user, int $courseOfferingId): bool
    {
        return $this->gradeAssignments()->isAssignedInstructor($user, $courseOfferingId);
    }

    private function gradeAssignments(): ProfessorGradeAssignmentService
    {
        return app(ProfessorGradeAssignmentService::class);
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
