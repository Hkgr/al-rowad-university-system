<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Semester */
class SemesterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'semester_id' => $this->semester_id,
            'semester_code' => $this->semester_code,
            'semester_name' => $this->semester_name,
            'semester_order' => $this->semester_order,
            'is_active' => $this->is_active,
            'course_offerings' => $this->relationLoaded('courseOfferings') ? CourseOfferingResource::collection($this->courseOfferings) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
