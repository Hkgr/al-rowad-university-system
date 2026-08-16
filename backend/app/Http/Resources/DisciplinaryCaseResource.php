<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentDisciplinaryCase */
class DisciplinaryCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'case_id' => $this->case_id,
            'student_id' => $this->student_id,
            'violation_type_id' => $this->violation_type_id,
            'trigger_course_offering_id' => $this->trigger_course_offering_id,
            'violation_description' => $this->violation_description,
            'violation_date' => $this->violation_date,
            'reported_by_user_id' => $this->reported_by_user_id,
            'investigation_status' => $this->investigation_status,
            'investigation_date' => $this->investigation_date,
            'investigation_notes' => $this->investigation_notes,
            'decided_by_authority' => $this->decided_by_authority,
            'decided_by_user_id' => $this->decided_by_user_id,
            'decision_number' => $this->decision_number,
            'decision_date' => $this->decision_date,
            'penalty_type_id' => $this->penalty_type_id,
            'penalty_start_date' => $this->penalty_start_date,
            'penalty_end_date' => $this->penalty_end_date,
            'is_in_absentia' => $this->is_in_absentia,
            'guardian_notified_at' => $this->guardian_notified_at,
            'case_status' => $this->case_status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'student' => $this->whenLoaded('student'),
            'violation_type' => $this->whenLoaded('violationType'),
            'penalty_type' => $this->whenLoaded('penaltyType'),
            'trigger_course_offering' => $this->whenLoaded('triggerCourseOffering'),
            'affected_courses' => $this->whenLoaded('affectedCourses'),
            'appeals' => DisciplinaryCaseAppealResource::collection($this->whenLoaded('appeals')),
        ];
    }
}
