<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SupplementaryExamPeriod */
class SupplementaryExamPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'supplementary_exam_period_id' => $this->supplementary_exam_period_id,
            'academic_year_id' => $this->academic_year_id,
            'semester_id' => $this->semester_id,
            'period_name' => $this->period_name,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
