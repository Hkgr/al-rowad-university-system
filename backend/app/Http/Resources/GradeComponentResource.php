<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\GradeComponent */
class GradeComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'grade_component_id' => $this->grade_component_id,
            'course_offering_id' => $this->course_offering_id,
            'component_name' => $this->component_name,
            'component_type' => $this->component_type,
            'max_mark' => $this->max_mark,
            'weight_percentage' => $this->weight_percentage,
            'is_required' => $this->is_required,
            'exam_date' => $this->exam_date,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
