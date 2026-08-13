<?php

namespace App\Http\Requests\GradeApproval;

use Illuminate\Foundation\Http\FormRequest;

class ListGradeApprovalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('exams.manage') === true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:pending,approved,returned_for_correction,rejected'],
            'academic_year_id' => ['nullable', 'integer'],
            'semester_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'course_offering_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
