<?php

namespace App\Http\Requests\AcademicProgram;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => 'required|integer|exists:departments,department_id',
            'program_code' => 'required|string|max:50|unique:academic_programs,program_code',
            'program_name' => 'required|string|max:200',
            'degree_level' => 'required|string|max:80',
            'total_credit_hours' => 'required|integer|min:0',
            'duration_years' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'is_active' => 'required|boolean',
        ];
    }
}
