<?php

namespace App\Http\Requests\AcademicYear;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year_name' => 'required|string|max:50|unique:academic_years,year_name',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_current' => 'prohibited',
            'is_active' => 'required|boolean',
            'calendar_lifecycle_status' => 'prohibited',
            'calendar_active_slot' => 'prohibited',
        ];
    }
}
