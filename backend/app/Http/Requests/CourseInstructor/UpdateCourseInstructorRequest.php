<?php

namespace App\Http\Requests\CourseInstructor;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseInstructorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => 'sometimes|nullable|integer|exists:courses,course_id',
            'faculty_member_id' => 'sometimes|nullable|integer|exists:faculty_members,faculty_member_id',
            'is_primary' => 'sometimes|nullable|integer',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
        ];
    }
}
