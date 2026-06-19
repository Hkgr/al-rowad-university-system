<?php

namespace App\Http\Requests\StudentCourseResult;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentCourseResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_course_registration_id' => 'sometimes|nullable|integer|exists:student_course_registrations,student_course_registration_id',
            'theoretical_total' => 'sometimes|nullable|numeric',
            'practical_total' => 'sometimes|nullable|numeric',
            'coursework_total' => 'sometimes|nullable|numeric',
            'final_mark' => 'sometimes|nullable|numeric',
            'result_status_id' => 'sometimes|nullable|integer|exists:result_statuses,result_status_id',
            'is_deprived' => 'sometimes|nullable|integer',
            'calculated_at' => 'sometimes|nullable|date',
            'calculated_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
