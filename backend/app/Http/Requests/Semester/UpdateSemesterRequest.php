<?php

namespace App\Http\Requests\Semester;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSemesterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'semester_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('semesters', 'semester_code')->ignoreModel($this->route('semester'), 'semester_id'),
            ],
            'semester_name' => 'sometimes|nullable|string|max:100',
            'semester_order' => 'sometimes|nullable|integer|min:1',
            'is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
