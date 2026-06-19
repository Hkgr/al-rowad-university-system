<?php

namespace App\Http\Requests\GradingPolicy;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGradingPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'policy_name' => 'sometimes|nullable|string|max:150',
            'theoretical_max_mark' => 'sometimes|nullable|numeric',
            'practical_max_mark' => 'sometimes|nullable|numeric',
            'minimum_theoretical_mark' => 'sometimes|nullable|numeric',
            'minimum_practical_mark' => 'sometimes|nullable|numeric',
            'minimum_final_mark' => 'sometimes|nullable|numeric',
            'absence_deprivation_percentage' => 'sometimes|nullable|numeric',
            'is_default' => 'sometimes|nullable|integer',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
