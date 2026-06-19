<?php

namespace App\Http\Requests\SupplementaryExamResult;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplementaryExamResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplementary_exam_period_id' => 'required|integer|exists:supplementary_exam_periods,supplementary_exam_period_id',
            'student_course_registration_id' => 'required|integer|exists:student_course_registrations,student_course_registration_id',
            'theoretical_mark' => 'nullable|numeric',
            'entered_by_user_id' => 'required|integer|exists:users,user_id',
            'entered_at' => 'nullable|date',
            'created_at' => 'nullable|date',
        ];
    }
}
