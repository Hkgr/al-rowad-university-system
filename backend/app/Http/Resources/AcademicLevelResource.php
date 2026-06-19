<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AcademicLevel */
class AcademicLevelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'academic_level_id' => $this->academic_level_id,
            'level_code' => $this->level_code,
            'level_name' => $this->level_name,
            'level_order' => $this->level_order,
            'is_active' => $this->is_active,
            'program_courses' => $this->relationLoaded('programCourses') ? ProgramCourseResource::collection($this->programCourses) : null,
            'students' => $this->relationLoaded('students') ? StudentResource::collection($this->students) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
