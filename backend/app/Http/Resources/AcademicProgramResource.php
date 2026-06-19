<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AcademicProgram */
class AcademicProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'academic_program_id' => $this->academic_program_id,
            'department_id' => $this->department_id,
            'program_code' => $this->program_code,
            'program_name' => $this->program_name,
            'degree_level' => $this->degree_level,
            'total_credit_hours' => $this->total_credit_hours,
            'duration_years' => $this->duration_years,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'department' => $this->relationLoaded('department') ? new DepartmentResource($this->department) : null,
            'courses' => $this->relationLoaded('courses') ? CourseResource::collection($this->courses) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
