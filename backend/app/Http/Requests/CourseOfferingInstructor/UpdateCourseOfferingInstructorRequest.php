<?php

namespace App\Http\Requests\CourseOfferingInstructor;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseOfferingInstructorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'faculty_member_id' => ['sometimes', 'integer', 'exists:faculty_members,faculty_member_id'],
            'instructor_role' => ['prohibited'],
            'is_primary' => ['prohibited'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
