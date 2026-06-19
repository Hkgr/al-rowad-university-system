<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AcademicYear */
class AcademicYearResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'academic_year_id' => $this->academic_year_id,
            'year_name' => $this->year_name,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'is_current' => $this->is_current,
            'is_active' => $this->is_active,
            'course_offerings' => $this->relationLoaded('courseOfferings') ? CourseOfferingResource::collection($this->courseOfferings) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
