<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('students', 'student_number')->ignoreModel($this->route('student'), 'student_id'),
            ],
            'admission_application_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:admission_applications,admission_application_id',
                Rule::unique('students', 'admission_application_id')->ignoreModel($this->route('student'), 'student_id'),
            ],
            'first_name' => 'sometimes|nullable|string|max:100',
            'last_name' => 'sometimes|nullable|string|max:100',
            'father_name' => 'sometimes|nullable|string|max:100',
            'mother_name' => 'sometimes|nullable|string|max:100',
            'date_of_birth' => 'sometimes|nullable|date',
            'gender' => 'sometimes|nullable|string|max:20',
            'phone_number' => 'sometimes|nullable|string|max:30',
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:150',
                Rule::unique('students', 'email')->ignoreModel($this->route('student'), 'student_id'),
            ],
            'address' => 'sometimes|nullable|string|max:255',
            'nationality' => 'sometimes|nullable|string|max:100',
            'academic_program_id' => 'sometimes|nullable|integer|exists:academic_programs,academic_program_id',
            'current_academic_level_id' => 'sometimes|nullable|integer|exists:academic_levels,academic_level_id',
            'enrollment_date' => 'sometimes|nullable|date',
            'student_status_id' => 'sometimes|nullable|integer|exists:student_statuses,student_status_id',
        ];
    }
}
