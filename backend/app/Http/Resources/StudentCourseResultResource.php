<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentCourseResult */
class StudentCourseResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_course_result_id' => $this->student_course_result_id,
            'student_course_registration_id' => $this->student_course_registration_id,
            'theoretical_total' => $this->theoretical_total,
            'practical_total' => $this->practical_total,
            'coursework_total' => $this->coursework_total,
            'final_mark' => $this->final_mark,
            'result_status_id' => $this->result_status_id,
            'is_deprived' => $this->is_deprived,
            'calculated_at' => $this->calculated_at,
            'calculated_by_user_id' => $this->calculated_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
