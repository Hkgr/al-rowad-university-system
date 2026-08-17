<?php

namespace App\Http\Resources;

use App\Models\CourseOfferingInstructor;
use App\Models\FacultyMember;
use App\Models\TeachingAssignmentReview;
use App\Models\User;
use App\Support\TeachingAssignmentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TeachingAssignmentRequest */
class TeachingAssignmentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $offering = $this->courseOffering;
        $course = $offering?->course;
        $program = $offering?->academicProgram;
        $department = $offering?->department ?? $program?->department;
        $college = $department?->college ?? $program?->department?->college;
        $effective = $this->effectiveFaculty($offering);

        return [
            'teaching_assignment_request_id' => $this->teaching_assignment_request_id,
            'status' => $this->status,
            'submission_version' => $this->submission_version,
            'instructor_role' => $this->instructor_role,
            'submitted_at' => $this->submitted_at,
            'approved_at' => $this->approved_at,
            'superseded_at' => $this->superseded_at,
            'course_offering' => $offering === null ? null : [
                'course_offering_id' => $offering->course_offering_id,
                'status' => $offering->status,
                'course' => $course === null ? null : [
                    'course_id' => $course->course_id,
                    'course_code' => $course->course_code,
                    'course_name' => $course->course_name,
                    'theoretical_hours' => $course->theoretical_hours,
                    'practical_hours' => $course->practical_hours,
                ],
                'academic_program' => $program === null ? null : [
                    'academic_program_id' => $program->academic_program_id,
                    'program_name' => $program->program_name,
                ],
                'department' => $department === null ? null : [
                    'department_id' => $department->department_id,
                    'department_name' => $department->department_name,
                ],
                'college' => $college === null ? null : [
                    'college_id' => $college->college_id,
                    'college_name' => $college->college_name,
                ],
                'academic_year' => $offering->academicYear === null ? null : [
                    'academic_year_id' => $offering->academicYear->academic_year_id,
                    'year_name' => $offering->academicYear->year_name,
                ],
                'semester' => $offering->semester === null ? null : [
                    'semester_id' => $offering->semester->semester_id,
                    'semester_name' => $offering->semester->semester_name,
                ],
            ],
            'proposed_faculty_member' => $this->safeFaculty($this->facultyMember),
            'effective_faculty_member' => $this->safeFaculty($effective),
            'requester' => $this->safeUser($this->requester),
            'scientific_review' => $this->reviewPayload($this->scientificReview()),
            'administrative_review' => $this->reviewPayload($this->administrativeReview()),
            'events' => $this->whenLoaded('events', fn () => $this->events
                ->sortBy('created_at')
                ->values()
                ->map(fn ($event) => [
                    'event_type' => $event->event_type,
                    'submission_version' => $event->submission_version,
                    'notes' => $event->notes,
                    'created_at' => $event->created_at,
                    'actor' => $this->safeUser($event->actor),
                ])),
        ];
    }

    private function reviewPayload(?TeachingAssignmentReview $review): ?array
    {
        if ($review === null) {
            return null;
        }

        return [
            'review_authority' => $review->review_authority,
            'status' => $review->status,
            'reason' => $review->reason,
            'reviewed_at' => $review->reviewed_at,
            'reviewer' => $this->safeUser($review->reviewer),
        ];
    }

    private function effectiveFaculty($offering): ?FacultyMember
    {
        if ($offering === null || ! $offering->relationLoaded('offeringInstructors')) {
            return null;
        }

        $slot = $offering->offeringInstructors->first(
            fn (CourseOfferingInstructor $row): bool => $row->is_active
                && (string) $row->instructor_role === (string) $this->instructor_role
        );

        return $slot?->facultyMember;
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
            'full_name' => $fullName !== '' ? $fullName : null,
            'academic_rank' => $facultyMember->academic_rank,
            'home_unit' => $employee?->organizationalUnit === null ? null : [
                'unit_code' => $employee->organizationalUnit->unit_code,
                'unit_name' => $employee->organizationalUnit->unit_name,
            ],
        ];
    }

    private function safeUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
        ];
    }
}
