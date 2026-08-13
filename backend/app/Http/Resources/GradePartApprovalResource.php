<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradePartApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->grade_part_approval_id, 'course_offering_id' => $this->course_offering_id,
            'component_type' => $this->component_type, 'status' => $this->status,
            'submission_version' => $this->submission_version, 'submitted_by_user_id' => $this->submitted_by_user_id,
            'submitted_at' => $this->submitted_at?->toISOString(), 'reviewed_by_user_id' => $this->reviewed_by_user_id,
            'reviewed_at' => $this->reviewed_at?->toISOString(), 'review_notes' => $this->review_notes,
            'course_offering' => $this->whenLoaded('courseOffering'),
        ];
    }
}
