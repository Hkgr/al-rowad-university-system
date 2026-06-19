<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CourseDepartment */
class CourseDepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'course_department_id' => $this->course_department_id,
            'course_id' => $this->course_id,
            'department_id' => $this->department_id,
            'is_primary' => $this->is_primary,
            'course' => $this->relationLoaded('course') ? new CourseResource($this->course) : null,
            'department' => $this->relationLoaded('department') ? new DepartmentResource($this->department) : null,
            'created_at' => $this->created_at,
        ];
    }
}
