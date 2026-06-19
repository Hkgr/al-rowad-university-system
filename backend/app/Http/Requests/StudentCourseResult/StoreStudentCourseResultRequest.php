<?php

namespace App\Http\Requests\StudentCourseResult;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentCourseResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_course_registration_id' => 'required|integer|exists:student_course_registrations,student_course_registration_id',
            'theoretical_total' => 'nullable|numeric',
            'practical_total' => 'nullable|numeric',
            'coursework_total' => 'nullable|numeric',
            'final_mark' => 'nullable|numeric',
            'result_status_id' => 'required|integer|exists:result_statuses,result_status_id',
            'is_deprived' => 'required|integer',
            'calculated_at' => 'nullable|date',
            'calculated_by_user_id' => 'nullable|integer|exists:users,user_id',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
