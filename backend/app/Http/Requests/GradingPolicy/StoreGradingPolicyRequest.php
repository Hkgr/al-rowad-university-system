<?php

namespace App\Http\Requests\GradingPolicy;

use Illuminate\Foundation\Http\FormRequest;

class StoreGradingPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'policy_name' => 'required|string|max:150',
            'theoretical_max_mark' => 'nullable|numeric',
            'practical_max_mark' => 'nullable|numeric',
            'minimum_theoretical_mark' => 'nullable|numeric',
            'minimum_practical_mark' => 'nullable|numeric',
            'minimum_final_mark' => 'nullable|numeric',
            'absence_deprivation_percentage' => 'nullable|numeric',
            'is_default' => 'required|integer',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
