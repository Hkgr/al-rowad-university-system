<?php

namespace App\Http\Requests\AcademicLevel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'level_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('academic_levels', 'level_code')->ignoreModel($this->route('academic_level'), 'academic_level_id'),
            ],
            'level_name' => 'sometimes|nullable|string|max:100',
            'level_order' => 'sometimes|nullable|integer|min:1',
            'is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
