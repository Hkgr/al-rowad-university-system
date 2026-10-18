<?php

namespace App\Http\Resources;

use App\Models\CourseOfferingInstructor;
use App\Models\FacultyMember;
use App\Models\TeachingAssignmentRequest;
use App\Support\CourseRequirementClassification;
use App\Support\TeachingAssignmentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CourseOffering */
class TeachingStaffAssignmentOfferingResource extends JsonResource
{
    public static function collection($resource)
    {
        CourseRequirementClassification::hydrateOfferings(
            CourseRequirementClassification::modelsFromResource($resource)
        );

        return tap(new AnonymousResourceCollection($resource, static::class), function ($collection) {
            if (property_exists(static::class, 'preserveKeys')) {
                $collection->preserveKeys = (new static([]))->preserveKeys === true;
            }
        });
    }

    public function toArray(Request $request): array
    {
        $course = $this->course;
        $academicYear = $this->academicYear;
        $semester = $this->semester;
        $department = $this->department;
        $program = $this->academicProgram;
        $slots = $this->relationLoaded('offeringInstructors')
            ? $this->offeringInstructors->keyBy(fn (CourseOfferingInstructor $slot) => (string) $slot->instructor_role)
            : collect();

        return [
            'course_offering_id' => $this->course_offering_id,
            'course_offering' => [
                'course_offering_id' => $this->course_offering_id,
                'status' => $this->status,
                'course' => $course === null ? null : [
                    'course_id' => $course->course_id,
                    'course_code' => $course->course_code,
                    'course_name' => $course->course_name,
                    'theoretical_hours' => $course->theoretical_hours,
                    'practical_hours' => $course->practical_hours,
                    'credit_hours' => $course->credit_hours,
                ],
                'academic_year' => $academicYear === null ? null : [
                    'academic_year_id' => $academicYear->academic_year_id,
                    'year_name' => $academicYear->year_name,
                ],
                'semester' => $semester === null ? null : [
                    'semester_id' => $semester->semester_id,
                    'semester_name' => $semester->semester_name,
                ],
                'department' => $department === null ? null : [
                    'department_id' => $department->department_id,
                    'department_name' => $department->department_name,
                ],
                'academic_program' => $program === null ? null : [
                    'academic_program_id' => $program->academic_program_id,
                    'program_name' => $program->program_name,
                ],
            ],
            'requirement_classification' => CourseRequirementClassification::forOffering($this->resource),
            'components' => [
                'theoretical' => $this->componentPayload(
                    (int) ($course?->theoretical_hours ?? 0) > 0,
                    $slots->get('theoretical'),
                    $this->currentRequestForRole('theoretical')
                ),
                'practical' => $this->componentPayload(
                    (int) ($course?->practical_hours ?? 0) > 0,
                    $slots->get('practical'),
                    $this->currentRequestForRole('practical')
                ),
            ],
        ];
    }

    private function componentPayload(
        bool $available,
        ?CourseOfferingInstructor $slot,
        ?TeachingAssignmentRequest $workflow
    ): array {
        if (! $available) {
            return [
                'available' => false,
                'assignment_id' => null,
                'is_active' => false,
                'faculty_member' => null,
                'workflow' => null,
            ];
        }

        $active = $slot !== null && $slot->is_active;

        return [
            'available' => true,
            'assignment_id' => $slot?->course_offering_instructor_id,
            'is_active' => $active,
            'faculty_member' => $active ? $this->safeFaculty($slot?->facultyMember) : null,
            'workflow' => $this->workflowPayload($workflow),
        ];
    }

    private function currentRequestForRole(string $role): ?TeachingAssignmentRequest
    {
        if (! $this->relationLoaded('teachingAssignmentRequests')) {
            return null;
        }

        return $this->teachingAssignmentRequests->first(
            fn (TeachingAssignmentRequest $request): bool => (int) $request->current_slot === 1
                && (string) $request->instructor_role === $role
                && $request->status !== TeachingAssignmentWorkflow::STATUS_SUPERSEDED
        );
    }

    private function workflowPayload(?TeachingAssignmentRequest $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $scientific = $request->relationLoaded('reviews') ? $request->scientificReview() : null;
        $administrative = $request->relationLoaded('reviews') ? $request->administrativeReview() : null;

        return [
            'teaching_assignment_request_id' => $request->teaching_assignment_request_id,
            'status' => $request->status,
            'submission_version' => $request->submission_version,
            'proposed_faculty_member' => $this->safeFaculty($request->facultyMember),
            'scientific_review' => $scientific === null ? null : [
                'status' => $scientific->status,
                'reason' => $scientific->reason,
                'reviewed_at' => $scientific->reviewed_at,
            ],
            'administrative_review' => $administrative === null ? null : [
                'status' => $administrative->status,
                'reason' => $administrative->reason,
                'reviewed_at' => $administrative->reviewed_at,
            ],
        ];
    }

    private function safeFaculty(?FacultyMember $facultyMember): ?array
    {
        if ($facultyMember === null) {
            return null;
        }

        $employee = $facultyMember->employee;
        $fullName = trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? ''));

        return [
            'faculty_member_id' => $facultyMember->faculty_member_id,
            'employee_number' => $employee?->employee_number,
            'full_name' => $fullName !== '' ? $fullName : null,
            'academic_rank' => $facultyMember->academic_rank,
            'home_unit' => $employee?->organizationalUnit === null ? null : [
                'unit_code' => $employee->organizationalUnit->unit_code,
                'unit_name' => $employee->organizationalUnit->unit_name,
            ],
        ];
    }
}
