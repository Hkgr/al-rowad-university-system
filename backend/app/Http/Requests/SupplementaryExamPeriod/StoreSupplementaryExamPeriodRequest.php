<?php

namespace App\Http\Requests\SupplementaryExamPeriod;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplementaryExamPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => 'required|integer|exists:academic_years,academic_year_id',
            'semester_id' => 'required|integer|exists:semesters,semester_id',
            'period_name' => 'required|string|max:150',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
