<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CoursePrerequisite */
class CoursePrerequisiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'course_prerequisite_id' => $this->course_prerequisite_id,
            'course_id' => $this->course_id,
            'prerequisite_course_id' => $this->prerequisite_course_id,
            'minimum_result_status_id' => $this->minimum_result_status_id,
            'course_code' => $this->relationLoaded('course') ? $this->course?->course_code : null,
            'course_name' => $this->relationLoaded('course') ? $this->course?->course_name : null,
            'prerequisite_course_code' => $this->relationLoaded('prerequisiteCourse') ? $this->prerequisiteCourse?->course_code : null,
            'prerequisite_course_name' => $this->relationLoaded('prerequisiteCourse') ? $this->prerequisiteCourse?->course_name : null,
            'course' => $this->relationLoaded('course') ? new CourseResource($this->course) : null,
            'prerequisite_course' => $this->relationLoaded('prerequisiteCourse') ? new CourseResource($this->prerequisiteCourse) : null,
            'minimum_result_status' => $this->relationLoaded('minimumResultStatus') ? new ResultStatusResource($this->minimumResultStatus) : null,
            'created_at' => $this->created_at,
        ];
    }
}
