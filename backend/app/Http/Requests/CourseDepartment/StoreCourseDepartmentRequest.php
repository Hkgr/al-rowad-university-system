<?php

namespace App\Http\Requests\CourseDepartment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => [
                'required',
                'integer',
                'exists:courses,course_id',
                Rule::unique('course_departments', 'course_id')->where(fn ($query) => $query->where('department_id', $this->input('department_id'))),
            ],
            'department_id' => 'required|integer|exists:departments,department_id',
            'is_primary' => 'required|boolean',
        ];
    }
}
