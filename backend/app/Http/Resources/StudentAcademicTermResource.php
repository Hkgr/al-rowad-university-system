<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentAcademicTerm */
class StudentAcademicTermResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_academic_term_id' => $this->student_academic_term_id,
            'student_id' => $this->student_id,
            'academic_year_id' => $this->academic_year_id,
            'semester_id' => $this->semester_id,
            'academic_level_id' => $this->academic_level_id,
            'term_gpa' => $this->term_gpa,
            'cumulative_gpa' => $this->cumulative_gpa,
            'total_registered_hours' => $this->total_registered_hours,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
