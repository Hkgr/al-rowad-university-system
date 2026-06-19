<?php

namespace App\Http\Requests\GradeAppeal;

use Illuminate\Foundation\Http\FormRequest;

class StoreGradeAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'required|integer|exists:students,student_id',
            'student_course_registration_id' => 'required|integer|exists:student_course_registrations,student_course_registration_id',
            'appeal_reason' => 'required|string',
            'appeal_status_id' => 'required|integer|exists:appeal_statuses,appeal_status_id',
            'submitted_at' => 'nullable|date',
            'reviewed_by_user_id' => 'nullable|integer|exists:users,user_id',
            'review_notes' => 'nullable|string',
            'decision_date' => 'nullable|date',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
