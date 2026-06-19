<?php

namespace App\Http\Requests\StudentGradeComponent;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentGradeComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_course_registration_id' => 'required|integer|exists:student_course_registrations,student_course_registration_id',
            'grade_component_id' => 'required|integer|exists:grade_components,grade_component_id',
            'mark' => 'nullable|numeric',
            'grade_status' => 'required|string|max:50',
            'entered_by_user_id' => 'nullable|integer|exists:users,user_id',
            'entered_at' => 'nullable|date',
            'notes' => 'nullable|string|max:255',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
