<?php

namespace App\Http\Requests\AcademicYear;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year_name' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('academic_years', 'year_name')->ignoreModel($this->route('academic_year'), 'academic_year_id'),
            ],
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date|after_or_equal:start_date',
            'is_current' => 'sometimes|nullable|boolean',
            'is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
