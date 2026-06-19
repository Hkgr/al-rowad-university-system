<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\GradeAppeal */
class GradeAppealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'grade_appeal_id' => $this->grade_appeal_id,
            'student_id' => $this->student_id,
            'student_course_registration_id' => $this->student_course_registration_id,
            'appeal_reason' => $this->appeal_reason,
            'appeal_status_id' => $this->appeal_status_id,
            'submitted_at' => $this->submitted_at,
            'reviewed_by_user_id' => $this->reviewed_by_user_id,
            'review_notes' => $this->review_notes,
            'decision_date' => $this->decision_date,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
