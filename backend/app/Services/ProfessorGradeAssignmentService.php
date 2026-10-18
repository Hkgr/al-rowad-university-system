<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\CourseOffering;
use App\Models\CourseOfferingInstructor;
use App\Models\FacultyMember;
use App\Models\User;
use App\Support\CourseRequirementClassification;
use Illuminate\Support\Collection;

class ProfessorGradeAssignmentService
{
    public const PART_THEORETICAL = 'theoretical';

    public const PART_PRACTICAL = 'practical';

    public const PARTS = [self::PART_THEORETICAL, self::PART_PRACTICAL];

    /** @var array<string, array> */
    private array $assignmentCache = [];

    public function activeFacultyMemberIds(User $user): Collection
    {
        if ($user->employee_id === null) {
            return collect();
        }

        return FacultyMember::query()
            ->where('employee_id', $user->employee_id)
            ->where('is_active', true)
            ->pluck('faculty_member_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    public function resolveFacultyMember(User $user): ?FacultyMember
    {
        if ($user->employee_id === null) {
            return null;
        }

        return FacultyMember::query()
            ->where('employee_id', $user->employee_id)
            ->where('is_active', true)
            ->orderBy('faculty_member_id')
            ->first()
            ?? FacultyMember::query()
                ->where('employee_id', $user->employee_id)
                ->orderBy('faculty_member_id')
                ->first();
    }

    /**
     * @return list<string>
     */
    public function assignedGradeParts(User $user, int $courseOfferingId): array
    {
        return $this->describeAssignment($user, $courseOfferingId)['assigned_parts'];
    }

    public function canManageGradePart(User $user, int $courseOfferingId, string $part): bool
    {
        if (! $user->hasPermission('grades.manage')) {
            return false;
        }

        return in_array($part, $this->assignedGradeParts($user, $courseOfferingId), true);
    }

    public function assertCanManageGradePart(User $user, int $courseOfferingId, string $part): void
    {
        if (! in_array($part, self::PARTS, true)) {
            throw new GradeException('The grade part must be practical or theoretical.', status: 422, errorCode: 'invalid_grade_part');
        }

        if (! $user->hasPermission('grades.manage')) {
            throw new GradeException('Grade management permission is required.', status: 403, errorCode: 'unauthorized_grade_part');
        }

        if (! in_array($part, $this->assignedGradeParts($user, $courseOfferingId), true)) {
            throw new GradeException('You are not authorized to manage this grade part.', status: 403, errorCode: 'unauthorized_grade_part');
        }
    }

    public function isAssignedInstructor(User $user, int $courseOfferingId): bool
    {
        return $this->assignedGradeParts($user, $courseOfferingId) !== [];
    }

    public function assertAssignedInstructor(User $user, int $courseOfferingId): void
    {
        if (! $this->isAssignedInstructor($user, $courseOfferingId)) {
            throw new GradeException(
                'This operation is restricted to the assigned section instructor.',
                status: 403,
                errorCode: 'unauthorized_grade_part'
            );
        }
    }

    /**
     * @return array{
     *     assigned_parts: list<string>,
     *     assignment_mode: string|null,
     *     part_assignments: array<string, array{assigned_to_me: bool, faculty_member_id: int|null, instructor_name: string|null}>
     * }
     */
    public function describeAssignment(User $user, int|CourseOffering $offering): array
    {
        $model = $offering instanceof CourseOffering
            ? $offering
            : CourseOffering::query()->findOrFail($offering);
        $offeringId = (int) $model->course_offering_id;
        $cacheKey = (int) $user->user_id.':'.$offeringId;

        if (isset($this->assignmentCache[$cacheKey])) {
            return $this->assignmentCache[$cacheKey];
        }

        $facultyIds = $this->activeFacultyMemberIds($user);
        $slots = $this->activeRoleSlots($model);
        $partAssignments = [
            self::PART_THEORETICAL => $this->emptyPartAssignment(),
            self::PART_PRACTICAL => $this->emptyPartAssignment(),
        ];

        if ($slots->isNotEmpty()) {
            foreach (self::PARTS as $role) {
                $slot = $slots->firstWhere('instructor_role', $role);
                if ($slot === null) {
                    continue;
                }

                $partAssignments[$role] = [
                    'assigned_to_me' => $facultyIds->contains((int) $slot->faculty_member_id),
                    'faculty_member_id' => (int) $slot->faculty_member_id,
                    'instructor_name' => $this->instructorName($slot->facultyMember),
                ];
            }
        } elseif ($facultyIds->isNotEmpty() && $model->faculty_member_id !== null && $facultyIds->contains((int) $model->faculty_member_id)) {
            $model->loadMissing('course', 'facultyMember.employee');
            $legacyPart = $this->legacyFallbackPart($model);
            if ($legacyPart !== null) {
                $partAssignments[$legacyPart] = [
                    'assigned_to_me' => true,
                    'faculty_member_id' => (int) $model->faculty_member_id,
                    'instructor_name' => $this->instructorName($model->facultyMember),
                ];
            }
        }

        $assignedParts = array_values(array_filter(
            self::PARTS,
            fn (string $part): bool => $partAssignments[$part]['assigned_to_me'] === true
        ));

        return $this->assignmentCache[$cacheKey] = [
            'assigned_parts' => $assignedParts,
            'assignment_mode' => $this->assignmentMode($assignedParts),
            'part_assignments' => $partAssignments,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function offeringsForProfessor(User $user): array
    {
        $facultyIds = $this->activeFacultyMemberIds($user);
        if ($facultyIds->isEmpty()) {
            return [];
        }

        $offerings = CourseOffering::query()
            ->with([
                'course',
                'academicYear',
                'semester',
                'department',
                'academicProgram',
                'facultyMember.employee',
                'offeringInstructors' => fn ($instructors) => $instructors
                    ->where('is_active', true)
                    ->whereIn('instructor_role', self::PARTS)
                    ->with('facultyMember.employee'),
            ])
            ->withCount([
                'studentCourseRegistrations as registered_students_count' => fn ($registrations) => $registrations->current(),
            ])
            ->where('status', 'open')
            ->where(function ($query) use ($facultyIds): void {
                $query->whereHas(
                    'offeringInstructors',
                    fn ($instructors) => $instructors
                        ->whereIn('faculty_member_id', $facultyIds)
                        ->where('is_active', true)
                        ->whereIn('instructor_role', self::PARTS)
                )->orWhere(function ($legacy) use ($facultyIds): void {
                    $legacy->whereIn('faculty_member_id', $facultyIds)
                        ->whereDoesntHave(
                            'offeringInstructors',
                            fn ($instructors) => $instructors->where('is_active', true)
                        );
                });
            })
            ->orderByDesc('course_offering_id')
            ->get();
        CourseRequirementClassification::hydrateOfferings($offerings);

        return $offerings
            ->map(function (CourseOffering $offering) use ($user): ?array {
                $assignment = $this->describeAssignment($user, $offering);
                if ($assignment['assigned_parts'] === []) {
                    return null;
                }

                return [
                    'course_offering_id' => $offering->course_offering_id,
                    'status' => $offering->status,
                    'registered_students_count' => (int) ($offering->registered_students_count ?? 0),
                    'course' => [
                        'course_id' => $offering->course_id,
                        'course_code' => $offering->course?->course_code,
                        'course_name' => $offering->course?->course_name,
                    ],
                    'requirement_classification' => CourseRequirementClassification::forOffering($offering),
                    'academic_year' => $offering->academicYear === null ? null : [
                        'academic_year_id' => $offering->academicYear->academic_year_id,
                        'year_name' => $offering->academicYear->year_name,
                    ],
                    'semester' => $offering->semester === null ? null : [
                        'semester_id' => $offering->semester->semester_id,
                        'semester_name' => $offering->semester->semester_name,
                    ],
                    'department' => $offering->department === null ? null : [
                        'department_id' => $offering->department->department_id,
                        'department_name' => $offering->department->department_name,
                    ],
                    'academic_program' => $offering->academicProgram === null ? null : [
                        'academic_program_id' => $offering->academicProgram->academic_program_id,
                        'program_name' => $offering->academicProgram->program_name,
                    ],
                    'section' => [
                        'course_offering_id' => $offering->course_offering_id,
                    ],
                    'assigned_parts' => $assignment['assigned_parts'],
                    'assignment_mode' => $assignment['assignment_mode'],
                    'part_assignments' => $assignment['part_assignments'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, CourseOfferingInstructor>
     */
    private function activeRoleSlots(CourseOffering $offering): Collection
    {
        if ($offering->relationLoaded('offeringInstructors')) {
            return $offering->offeringInstructors
                ->where('is_active', true)
                ->filter(fn (CourseOfferingInstructor $slot): bool => in_array($slot->instructor_role, self::PARTS, true))
                ->values();
        }

        $offering->setRelation(
            'offeringInstructors',
            $offering->offeringInstructors()
                ->where('is_active', true)
                ->whereIn('instructor_role', self::PARTS)
                ->with('facultyMember.employee')
                ->get()
        );

        return $offering->offeringInstructors;
    }

    private function legacyFallbackPart(CourseOffering $offering): ?string
    {
        $theoreticalHours = (float) ($offering->course?->theoretical_hours ?? 0);
        $practicalHours = (float) ($offering->course?->practical_hours ?? 0);

        if ($theoreticalHours > 0) {
            return self::PART_THEORETICAL;
        }

        if ($practicalHours > 0) {
            return self::PART_PRACTICAL;
        }

        return null;
    }

    /**
     * @param  list<string>  $assignedParts
     */
    private function assignmentMode(array $assignedParts): ?string
    {
        if ($assignedParts === self::PARTS) {
            return 'both';
        }

        if ($assignedParts === [self::PART_THEORETICAL]) {
            return 'theoretical_only';
        }

        if ($assignedParts === [self::PART_PRACTICAL]) {
            return 'practical_only';
        }

        return null;
    }

    /**
     * @return array{assigned_to_me: bool, faculty_member_id: null, instructor_name: null}
     */
    private function emptyPartAssignment(): array
    {
        return [
            'assigned_to_me' => false,
            'faculty_member_id' => null,
            'instructor_name' => null,
        ];
    }

    private function instructorName(?FacultyMember $facultyMember): ?string
    {
        $employee = $facultyMember?->employee;
        if ($employee === null) {
            return null;
        }

        $name = trim((string) $employee->first_name.' '.(string) $employee->last_name);

        return $name !== '' ? $name : null;
    }
}
