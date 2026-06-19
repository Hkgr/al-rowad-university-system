<?php

namespace App\Http\Requests\SupplementaryExamResult;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplementaryExamResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplementary_exam_period_id' => 'sometimes|nullable|integer|exists:supplementary_exam_periods,supplementary_exam_period_id',
            'student_course_registration_id' => 'sometimes|nullable|integer|exists:student_course_registrations,student_course_registration_id',
            'theoretical_mark' => 'sometimes|nullable|numeric',
            'entered_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'entered_at' => 'sometimes|nullable|date',
            'created_at' => 'sometimes|nullable|date',
        ];
    }
}
