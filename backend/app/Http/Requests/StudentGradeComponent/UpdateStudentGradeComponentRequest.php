<?php

namespace App\Http\Requests\StudentGradeComponent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentGradeComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_course_registration_id' => 'sometimes|nullable|integer|exists:student_course_registrations,student_course_registration_id',
            'grade_component_id' => 'sometimes|nullable|integer|exists:grade_components,grade_component_id',
            'mark' => 'sometimes|nullable|numeric',
            'grade_status' => 'sometimes|nullable|string|max:50',
            'entered_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'entered_at' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string|max:255',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
