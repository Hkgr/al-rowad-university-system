<?php

namespace App\Services;

use App\Models\College;
use App\Models\Course;
use App\Models\CourseInstructor;
use App\Models\CourseOffering;
use App\Models\CourseOfferingInstructor;
use App\Models\FacultyMember;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TeachingAssignmentService
{
    public function __construct(private DataScopeService $dataScope)
    {
    }

    public function resolveOfferingCollege(CourseOffering $offering): ?College
    {
        return $offering->resolveCollege();
    }

    public function deriveIsPrimary(Course $course, string $role): bool
    {
        if ($role === 'theoretical') {
            return true;
        }

        if ($role === 'practical') {
            return (int) $course->theoretical_hours <= 0;
        }

        return false;
    }

    public function assertCanViewAssignments(User $user, CourseOffering $offering): void
    {
        if (! $user->hasPermission('teaching_staff.view')
            && ! $user->hasPermission('teaching_staff.manage')
            && ! $user->hasPermission('courses.view')
            && ! $user->hasPermission('courses.manage')) {
            throw new AccessDeniedHttpException('You are not authorized to view teaching assignments.');
        }

        $this->assertOfferingAccess($user, $offering);
    }

    public function assertCanManageAssignments(User $user, CourseOffering $offering): void
    {
        if (! $user->hasPermission('teaching_staff.manage')
            && ! $user->hasPermission('courses.manage')) {
            throw new AccessDeniedHttpException('You are not authorized to manage teaching assignments.');
        }

        $this->assertOfferingAccess($user, $offering);
    }

    public function assertValidAssignment(
        CourseOffering $offering,
        FacultyMember $facultyMember,
        string $role
    ): void {
        $offering->loadMissing('course');
        $facultyMember->loadMissing('employee.employeeStatus');

        if (! $facultyMember->is_active) {
            throw ValidationException::withMessages([
                'faculty_member_id' => ['The faculty member is not active.'],
            ]);
        }

        $employee = $facultyMember->employee;
        if ($employee === null
            || $employee->employeeStatus === null
            || $employee->employeeStatus->status_code !== 'active'
            || ! $employee->employeeStatus->is_active) {
            throw ValidationException::withMessages([
                'faculty_member_id' => ['The faculty member employee record is not active.'],
            ]);
        }

        $college = $this->resolveOfferingCollege($offering);
        if ($college === null || $college->organizational_unit_id === null) {
            throw new AccessDeniedHttpException('The course offering college cannot be resolved.');
        }

        $this->assertComponentExists($offering->course, $role);

        if (! $this->dataScope->facultyMemberBelongsToCollege($facultyMember, $college)) {
            throw ValidationException::withMessages([
                'faculty_member_id' => ['The faculty member does not belong to the course offering college.'],
            ]);
        }
    }

    public function assertComponentExists(Course $course, string $role): void
    {
        if ($role === 'theoretical' && (int) $course->theoretical_hours <= 0) {
            throw ValidationException::withMessages([
                'instructor_role' => ['هذا المقرر لا يحتوي على شق نظري'],
            ]);
        }

        if ($role === 'practical' && (int) $course->practical_hours <= 0) {
            throw ValidationException::withMessages([
                'instructor_role' => ['هذا المقرر لا يحتوي على شق عملي'],
            ]);
        }
    }

    public function syncLegacyFacultyPointer(CourseOffering $offering): void
    {
        $offering->loadMissing('course');
        $course = $offering->course;
        $primaryRole = $this->primaryRoleForCourse($course);

        $facultyMemberId = null;
        if ($primaryRole !== null) {
            $facultyMemberId = CourseOfferingInstructor::query()
                ->where('course_offering_id', $offering->course_offering_id)
                ->where('instructor_role', $primaryRole)
                ->where('is_active', true)
                ->value('faculty_member_id');
        }

        $current = $offering->faculty_member_id === null ? null : (int) $offering->faculty_member_id;
        $next = $facultyMemberId === null ? null : (int) $facultyMemberId;
        if ($current !== $next) {
            $offering->update(['faculty_member_id' => $next]);
        }
    }

    public function ensureGenericCourseInstructor(int $courseId, int $facultyMemberId): void
    {
        $instructor = CourseInstructor::query()->firstOrNew([
            'course_id' => $courseId,
            'faculty_member_id' => $facultyMemberId,
        ]);

        if (! $instructor->exists) {
            $instructor->is_primary = false;
            $instructor->is_active = true;
            $instructor->save();

            return;
        }

        if (! $instructor->is_active) {
            $instructor->is_active = true;
            $instructor->save();
        }
    }

    public function normalizePrimaryFlags(CourseOffering $offering): void
    {
        $offering->loadMissing('course');
        $course = $offering->course;

        CourseOfferingInstructor::query()
            ->where('course_offering_id', $offering->course_offering_id)
            ->get()
            ->each(function (CourseOfferingInstructor $slot) use ($course): void {
                $expected = $this->deriveIsPrimary($course, (string) $slot->instructor_role);
                if ((bool) $slot->is_primary !== $expected) {
                    $slot->update(['is_primary' => $expected]);
                }
            });
    }

    private function primaryRoleForCourse(?Course $course): ?string
    {
        if ($course === null) {
            return null;
        }

        if ((int) $course->theoretical_hours > 0) {
            return 'theoretical';
        }

        if ((int) $course->practical_hours > 0) {
            return 'practical';
        }

        return null;
    }

    private function assertOfferingAccess(User $user, CourseOffering $offering): void
    {
        if (! $this->dataScope->canAccessOffering($user, $offering)) {
            throw new AccessDeniedHttpException('You are not authorized to access this course offering.');
        }
    }
}
