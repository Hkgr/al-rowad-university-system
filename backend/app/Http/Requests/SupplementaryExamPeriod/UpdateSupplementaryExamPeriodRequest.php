<?php

namespace App\Http\Requests\SupplementaryExamPeriod;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplementaryExamPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => 'sometimes|nullable|integer|exists:academic_years,academic_year_id',
            'semester_id' => 'sometimes|nullable|integer|exists:semesters,semester_id',
            'period_name' => 'sometimes|nullable|string|max:150',
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
