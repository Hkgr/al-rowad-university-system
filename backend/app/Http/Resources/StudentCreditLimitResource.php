<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentCreditLimit */
class StudentCreditLimitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'credit_limit_id' => $this->credit_limit_id,
            'student_id' => $this->student_id,
            'academic_year_id' => $this->academic_year_id,
            'semester_id' => $this->semester_id,
            'min_credit_hours' => $this->min_credit_hours,
            'max_credit_hours' => $this->max_credit_hours,
            'is_excellent_student' => $this->is_excellent_student,
            'approved_by_user_id' => $this->approved_by_user_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
