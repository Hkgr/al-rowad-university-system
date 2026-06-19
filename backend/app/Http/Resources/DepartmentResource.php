<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Department */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'department_id' => $this->department_id,
            'college_id' => $this->college_id,
            'organizational_unit_id' => $this->organizational_unit_id,
            'department_code' => $this->department_code,
            'department_name' => $this->department_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'college' => $this->relationLoaded('college') ? new CollegeResource($this->college) : null,
            'academic_programs' => $this->relationLoaded('academicPrograms') ? AcademicProgramResource::collection($this->academicPrograms) : null,
            'courses' => $this->relationLoaded('courses') ? CourseResource::collection($this->courses) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
