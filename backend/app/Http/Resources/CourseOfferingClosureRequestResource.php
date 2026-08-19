<?php

namespace App\Http\Resources;

use App\Models\CourseOfferingClosureReview;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CourseOfferingClosureRequest */
class CourseOfferingClosureRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $offering = $this->courseOffering;
        $course = $offering?->course;
        $program = $offering?->academicProgram;
        $department = $offering?->department ?? $program?->department;
        $college = $department?->college ?? $program?->department?->college;

        return [
            'course_offering_closure_request_id' => $this->course_offering_closure_request_id,
            'status' => $this->status,
            'submission_version' => $this->submission_version,
            'reason' => $this->request_reason,
            'submitted_at' => $this->submitted_at,
            'approved_at' => $this->approved_at,
            'materialized_at' => $this->materialized_at,
            'superseded_at' => $this->superseded_at,
            'supersede_reason' => $this->supersede_reason,
            'requester' => $this->safeUser($this->requester),
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

    private function reviewPayload(?CourseOfferingClosureReview $review): ?array
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
