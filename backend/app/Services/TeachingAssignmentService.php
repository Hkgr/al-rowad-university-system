<?php

namespace App\Services;

use App\Exceptions\TeachingAssignmentException;
use App\Models\College;
use App\Models\Course;
use App\Models\CourseInstructor;
use App\Models\CourseOffering;
use App\Models\CourseOfferingInstructor;
use App\Models\FacultyMember;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            && ! $user->hasPermission('teaching_assignments.view')
            && ! $user->hasPermission('teaching_assignments.manage')
            && ! $user->hasPermission('courses.view')
            && ! $user->hasPermission('courses.manage')) {
            throw new AccessDeniedHttpException('You are not authorized to view teaching assignments.');
        }

        $this->assertOfferingAccess($user, $offering);
    }

    public function assertCanManageAssignments(User $user, CourseOffering $offering): void
    {
        if (! $user->hasPermission('teaching_staff.manage')
            && ! $user->hasPermission('teaching_assignments.manage')
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

        $this->assertComponentExists($offering->course, $role);
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

    /**
     * Retired Dean/direct writer. Must not mutate course_offering_instructors.
     * Dual-approval materialization uses materializeApprovedSlot() instead.
     */
    public function syncOfferingAssignmentSlots(
        User $user,
        CourseOffering $courseOffering,
        ?int $theoreticalFacultyMemberId,
        ?int $practicalFacultyMemberId
    ): CourseOffering {
        throw TeachingAssignmentException::workflowRequired();
    }

    public function materializeApprovedSlot(
        CourseOffering $offering,
        string $role,
        int $facultyMemberId
    ): void {
        $offering->loadMissing('course');
        $course = $offering->course;
        if ($course === null) {
            throw ValidationException::withMessages([
                'course_offering' => ['The course offering course cannot be resolved.'],
            ]);
        }

        $facultyMember = FacultyMember::query()
            ->with('employee.employeeStatus')
            ->findOrFail($facultyMemberId);
        $this->assertValidAssignment($offering, $facultyMember, $role);

        $slots = CourseOfferingInstructor::query()
            ->where('course_offering_id', $offering->course_offering_id)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (CourseOfferingInstructor $slot): string => (string) $slot->instructor_role);

        $this->assignSlot($offering, $course, $slots, $role, $facultyMember);
        $this->ensureGenericCourseInstructor((int) $offering->course_id, $facultyMemberId);
        $this->normalizePrimaryFlags($offering);
        $this->syncLegacyFacultyPointer($offering);
    }

    public function offeringsInAccessibleCollegesQuery(array $collegeIds)
    {
        return CourseOffering::idsResolvedToColleges($collegeIds);
    }

    public function accessibleCollegeIdList(User $user): array
    {
        return array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $this->dataScope->accessibleCollegeIds($user)
        )));
    }

    private function assignSlot(
        CourseOffering $offering,
        Course $course,
        Collection $slots,
        string $role,
        FacultyMember $facultyMember
    ): void {
        $slot = $slots->get($role);
        $isPrimary = $this->deriveIsPrimary($course, $role);
        $facultyMemberId = (int) $facultyMember->faculty_member_id;

        if ($slot === null) {
            $created = new CourseOfferingInstructor([
                'course_offering_id' => $offering->course_offering_id,
                'faculty_member_id' => $facultyMemberId,
                'instructor_role' => $role,
                'is_primary' => $isPrimary,
                'is_active' => true,
            ]);
            $created->save();
            $slots->put($role, $created);

            return;
        }

        $dirty = false;
        if ((int) $slot->faculty_member_id !== $facultyMemberId) {
            $slot->faculty_member_id = $facultyMemberId;
            $dirty = true;
        }
        if (! $slot->is_active) {
            $slot->is_active = true;
            $dirty = true;
        }
        if ((bool) $slot->is_primary !== $isPrimary) {
            $slot->is_primary = $isPrimary;
            $dirty = true;
        }

        if ($dirty) {
            $slot->save();
        }
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
