<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SupplementaryExamResult */
class SupplementaryExamResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'supplementary_exam_result_id' => $this->supplementary_exam_result_id,
            'supplementary_exam_period_id' => $this->supplementary_exam_period_id,
            'student_course_registration_id' => $this->student_course_registration_id,
            'theoretical_mark' => $this->theoretical_mark,
            'entered_by_user_id' => $this->entered_by_user_id,
            'entered_at' => $this->entered_at,
            'created_at' => $this->created_at,
        ];
    }
}
