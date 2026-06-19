<?php

namespace App\Http\Requests\Course;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('courses', 'course_code')->ignoreModel($this->route('course'), 'course_id'),
            ],
            'course_name' => 'sometimes|nullable|string|max:200',
            'credit_hours' => 'sometimes|nullable|integer|min:1',
            'theoretical_hours' => 'sometimes|nullable|integer|min:0',
            'practical_hours' => 'sometimes|nullable|integer|min:0',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
