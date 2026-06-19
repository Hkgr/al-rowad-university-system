<?php

namespace App\Http\Requests\StudentStatus;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('student_statuses', 'status_code')->ignoreModel($this->route('student_status'), 'student_status_id'),
            ],
            'status_name' => 'sometimes|nullable|string|max:100',
            'description' => 'sometimes|nullable|string|max:255',
            'is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
