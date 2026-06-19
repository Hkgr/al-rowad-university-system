<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\GradeApproval */
class GradeApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'grade_approval_id' => $this->grade_approval_id,
            'course_offering_id' => $this->course_offering_id,
            'approval_status_id' => $this->approval_status_id,
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'submitted_at' => $this->submitted_at,
            'approved_by_user_id' => $this->approved_by_user_id,
            'approval_role' => $this->approval_role,
            'approval_date' => $this->approval_date,
            'approval_notes' => $this->approval_notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
