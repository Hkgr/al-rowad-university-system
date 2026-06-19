<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\GradeAuditLog */
class GradeAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'grade_audit_log_id' => $this->grade_audit_log_id,
            'student_grade_component_id' => $this->student_grade_component_id,
            'old_mark' => $this->old_mark,
            'new_mark' => $this->new_mark,
            'changed_by_user_id' => $this->changed_by_user_id,
            'change_reason' => $this->change_reason,
            'changed_at' => $this->changed_at,
        ];
    }
}
