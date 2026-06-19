<?php

namespace App\Http\Requests\AdmissionApplication;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdmissionApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'applicant_id' => 'sometimes|nullable|integer|exists:applicants,applicant_id',
            'academic_program_id' => 'sometimes|nullable|integer|exists:academic_programs,academic_program_id',
            'academic_year_id' => 'sometimes|nullable|integer|exists:academic_years,academic_year_id',
            'application_date' => 'sometimes|nullable|date',
            'decision_status' => 'sometimes|nullable|string|max:50',
            'decision_date' => 'sometimes|nullable|date',
            'decided_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'notes' => 'sometimes|nullable|string',
        ];
    }
}
