<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\GradingPolicy */
class GradingPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'grading_policy_id' => $this->grading_policy_id,
            'policy_name' => $this->policy_name,
            'theoretical_max_mark' => $this->theoretical_max_mark,
            'practical_max_mark' => $this->practical_max_mark,
            'minimum_theoretical_mark' => $this->minimum_theoretical_mark,
            'minimum_practical_mark' => $this->minimum_practical_mark,
            'minimum_final_mark' => $this->minimum_final_mark,
            'absence_deprivation_percentage' => $this->absence_deprivation_percentage,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
