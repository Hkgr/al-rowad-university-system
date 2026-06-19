<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentGradeComponent */
class StudentGradeComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_grade_component_id' => $this->student_grade_component_id,
            'student_course_registration_id' => $this->student_course_registration_id,
            'grade_component_id' => $this->grade_component_id,
            'mark' => $this->mark,
            'grade_status' => $this->grade_status,
            'entered_by_user_id' => $this->entered_by_user_id,
            'entered_at' => $this->entered_at,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
