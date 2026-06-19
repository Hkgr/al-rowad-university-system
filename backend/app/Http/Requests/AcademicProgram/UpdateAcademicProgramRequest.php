<?php

namespace App\Http\Requests\AcademicProgram;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => 'sometimes|nullable|integer|exists:departments,department_id',
            'program_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('academic_programs', 'program_code')->ignoreModel($this->route('academic_program'), 'academic_program_id'),
            ],
            'program_name' => 'sometimes|nullable|string|max:200',
            'degree_level' => 'sometimes|nullable|string|max:80',
            'total_credit_hours' => 'sometimes|nullable|integer|min:0',
            'duration_years' => 'sometimes|nullable|integer|min:1',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
