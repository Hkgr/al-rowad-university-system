<?php

namespace App\Http\Requests\AdmissionApplication;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdmissionApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'applicant_id' => 'required|integer|exists:applicants,applicant_id',
            'academic_program_id' => 'required|integer|exists:academic_programs,academic_program_id',
            'academic_year_id' => 'required|integer|exists:academic_years,academic_year_id',
            'application_date' => 'required|date',
            'decision_status' => 'required|string|max:50',
            'decision_date' => 'nullable|date',
            'decided_by_user_id' => 'nullable|integer|exists:users,user_id',
            'notes' => 'nullable|string',
        ];
    }
}
