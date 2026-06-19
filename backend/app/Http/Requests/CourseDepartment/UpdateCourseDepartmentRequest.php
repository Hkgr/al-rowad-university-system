<?php

namespace App\Http\Requests\CourseDepartment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currentCourseDepartment = $this->route('course_department');

        return [
            'course_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:courses,course_id',
                Rule::unique('course_departments', 'course_id')
                    ->where(fn ($query) => $query->where('department_id', $this->input('department_id', $currentCourseDepartment?->department_id)))
                    ->ignoreModel($this->route('course_department'), 'course_department_id'),
            ],
            'department_id' => 'sometimes|nullable|integer|exists:departments,department_id',
            'is_primary' => 'sometimes|nullable|boolean',
        ];
    }
}
