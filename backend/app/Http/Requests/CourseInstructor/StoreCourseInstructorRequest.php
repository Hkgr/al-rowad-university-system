<?php

namespace App\Http\Requests\CourseInstructor;

use Illuminate\Foundation\Http\FormRequest;

class StoreCourseInstructorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => 'required|integer|exists:courses,course_id',
            'faculty_member_id' => 'required|integer|exists:faculty_members,faculty_member_id',
            'is_primary' => 'required|integer',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
        ];
    }
}
