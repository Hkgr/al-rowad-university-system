<?php

namespace App\Services;

use App\Exceptions\OfferingInstructorCoverageException;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseOfferingInstructor;
use App\Models\FacultyMember;
use Illuminate\Support\Collection;

class CourseOfferingInstructorCoverageService
{
    public const ROLE_THEORETICAL = 'theoretical';

    public const ROLE_PRACTICAL = 'practical';

    public const SOURCE_EFFECTIVE_SLOT = 'course_offering_instructors';

    public const SOURCE_LEGACY_POINTER = 'legacy_faculty_member_id';

    /**
     * Relations needed to describe coverage without per-offering queries.
     *
     * @return list<string>
     */
    public static function eagerLoadRelations(): array
    {
        return [
            'course',
            'facultyMember.employee.employeeStatus',
            'offeringInstructors.facultyMember.employee.employeeStatus',
        ];
    }

    public function __construct(private TeachingAssignmentService $teachingAssignments)
    {
    }

    /**
     * Required teaching roles from Course academic hour fields.
     * Grade components are not a delivery definition.
     *
     * @return list<string>
     */
    public function requiredRoles(?Course $course): array
    {
        if ($course === null) {
            return [];
        }

        $roles = [];
        if ((int) $course->theoretical_hours > 0) {
            $roles[] = self::ROLE_THEORETICAL;
        }
        if ((int) $course->practical_hours > 0) {
            $roles[] = self::ROLE_PRACTICAL;
        }

        return $roles;
    }

    public function componentsDefined(?Course $course): bool
    {
        return $this->requiredRoles($course) !== [];
    }

    /**
     * @return array{
     *     required_roles: list<string>,
     *     covered_roles: list<string>,
     *     missing_roles: list<string>,
     *     complete: bool,
     *     components_defined: bool,
     *     roles: array<string, array<string, mixed>>
     * }
     */
    public function describe(CourseOffering $offering): array
    {
        $offering->loadMissing('course');
        $course = $offering->course;
        $required = $this->requiredRoles($course);
        $slots = $this->activeSlotsByRole($offering);
        $legacyFaculty = $this->legacyFacultyMember($offering);
        $legacyEligible = $slots->isEmpty() && $legacyFaculty !== null;
        $primaryRole = $legacyEligible
            ? $this->teachingAssignments->primaryRoleForCourse($course)
            : null;

        $rolePayload = [];
        $covered = [];
        $missing = [];

        foreach ([self::ROLE_THEORETICAL, self::ROLE_PRACTICAL] as $role) {
            $requiredRole = in_array($role, $required, true);
            $slot = $slots->get($role);
            $coveredBySlot = $requiredRole && $this->slotCoversRole($slot);
            $coveredByLegacy = $requiredRole
                && ! $coveredBySlot
                && $legacyEligible
                && $primaryRole === $role
                && $this->teachingAssignments->isEffectiveFacultyValid($legacyFaculty);

            $isCovered = $coveredBySlot || $coveredByLegacy;
            if ($requiredRole && $isCovered) {
                $covered[] = $role;
            }
            if ($requiredRole && ! $isCovered) {
                $missing[] = $role;
            }

            $faculty = $coveredBySlot
                ? $slot?->facultyMember
                : ($coveredByLegacy ? $legacyFaculty : null);

            $rolePayload[$role] = [
                'required' => $requiredRole,
                'covered' => $isCovered,
                'source' => $coveredBySlot
                    ? self::SOURCE_EFFECTIVE_SLOT
                    : ($coveredByLegacy ? self::SOURCE_LEGACY_POINTER : null),
                'faculty_member_id' => $faculty?->faculty_member_id,
                'name' => $this->facultyName($faculty),
            ];
        }

        $defined = $required !== [];

        return [
            'required_roles' => $required,
            'covered_roles' => $covered,
            'missing_roles' => $missing,
            'complete' => $defined && $missing === [],
            'components_defined' => $defined,
            'roles' => $rolePayload,
        ];
    }

    /**
     * @return list<string>
     */
    public function missingRoles(CourseOffering $offering): array
    {
        return $this->describe($offering)['missing_roles'];
    }

    public function isComplete(CourseOffering $offering): bool
    {
        return $this->describe($offering)['complete'] === true;
    }

    public function assertCompleteForNormalOpening(CourseOffering $offering): void
    {
        $coverage = $this->describe($offering);

        if ($coverage['components_defined'] !== true) {
            throw OfferingInstructorCoverageException::componentsUndefined($coverage);
        }

        if ($coverage['complete'] !== true) {
            throw OfferingInstructorCoverageException::incomplete($coverage);
        }
    }

    /**
     * @return Collection<string, CourseOfferingInstructor>
     */
    private function activeSlotsByRole(CourseOffering $offering): Collection
    {
        $instructors = $offering->relationLoaded('offeringInstructors')
            ? $offering->offeringInstructors
            : $offering->offeringInstructors()
                ->with('facultyMember.employee.employeeStatus')
                ->get();

        return $instructors
            ->filter(fn (CourseOfferingInstructor $slot): bool => (bool) $slot->is_active)
            ->keyBy(fn (CourseOfferingInstructor $slot): string => (string) $slot->instructor_role);
    }

    private function slotCoversRole(?CourseOfferingInstructor $slot): bool
    {
        if ($slot === null || ! $slot->is_active) {
            return false;
        }

        $slot->loadMissing('facultyMember.employee.employeeStatus');

        return $this->teachingAssignments->isEffectiveFacultyValid($slot->facultyMember);
    }

    private function legacyFacultyMember(CourseOffering $offering): ?FacultyMember
    {
        if ($offering->faculty_member_id === null) {
            return null;
        }

        if ($offering->relationLoaded('facultyMember')) {
            return $offering->facultyMember;
        }

        $offering->load('facultyMember.employee.employeeStatus');

        return $offering->facultyMember;
    }

    private function facultyName(?FacultyMember $facultyMember): ?string
    {
        if ($facultyMember === null) {
            return null;
        }

        $facultyMember->loadMissing('employee');
        $fullName = trim(($facultyMember->employee?->first_name ?? '').' '.($facultyMember->employee?->last_name ?? ''));

        return $fullName !== '' ? $fullName : null;
    }
}
