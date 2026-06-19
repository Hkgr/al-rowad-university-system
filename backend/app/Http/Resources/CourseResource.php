<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Course */
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'course_id' => $this->course_id,
            'course_code' => $this->course_code,
            'course_name' => $this->course_name,
            'credit_hours' => $this->credit_hours,
            'theoretical_hours' => $this->theoretical_hours,
            'practical_hours' => $this->practical_hours,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'departments' => $this->relationLoaded('departments') ? DepartmentResource::collection($this->departments) : null,
            'academic_programs' => $this->relationLoaded('academicPrograms') ? AcademicProgramResource::collection($this->academicPrograms) : null,
            'prerequisites' => $this->relationLoaded('prerequisites') ? CourseResource::collection($this->prerequisites) : null,
            'course_offerings' => $this->relationLoaded('courseOfferings') ? CourseOfferingResource::collection($this->courseOfferings) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
