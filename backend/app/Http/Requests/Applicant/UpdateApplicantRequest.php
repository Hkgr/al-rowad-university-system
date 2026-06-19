<?php

namespace App\Http\Requests\Applicant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'applicant_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('applicants', 'applicant_number')->ignoreModel($this->route('applicant'), 'applicant_id'),
            ],
            'first_name' => 'sometimes|nullable|string|max:100',
            'last_name' => 'sometimes|nullable|string|max:100',
            'father_name' => 'sometimes|nullable|string|max:100',
            'mother_name' => 'sometimes|nullable|string|max:100',
            'date_of_birth' => 'sometimes|nullable|date',
            'gender' => 'sometimes|nullable|string|max:20',
            'phone_number' => 'sometimes|nullable|string|max:30',
            'email' => 'sometimes|nullable|email|max:150',
            'address' => 'sometimes|nullable|string|max:255',
            'nationality' => 'sometimes|nullable|string|max:100',
        ];
    }
}
