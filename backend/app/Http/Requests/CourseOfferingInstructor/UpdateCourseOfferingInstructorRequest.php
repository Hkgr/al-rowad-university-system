<?php

namespace App\Http\Requests\CourseOfferingInstructor;

use App\Models\CourseOfferingInstructor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseOfferingInstructorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'instructor_role' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::in(CourseOfferingInstructor::ROLES),
            ],
            'is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
