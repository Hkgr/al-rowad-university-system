<?php

namespace App\Http\Requests\Course;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_code' => 'required|string|max:50|unique:courses,course_code',
            'course_name' => 'required|string|max:200',
            'credit_hours' => 'required|integer|min:1',
            'theoretical_hours' => 'nullable|integer|min:0',
            'practical_hours' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'is_active' => 'required|boolean',
        ];
    }
}
