<?php

namespace App\Http\Requests\GradeAppeal;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGradeAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'sometimes|nullable|integer|exists:students,student_id',
            'student_course_registration_id' => 'sometimes|nullable|integer|exists:student_course_registrations,student_course_registration_id',
            'appeal_reason' => 'sometimes|nullable|string',
            'appeal_status_id' => 'sometimes|nullable|integer|exists:appeal_statuses,appeal_status_id',
            'submitted_at' => 'sometimes|nullable|date',
            'reviewed_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'review_notes' => 'sometimes|nullable|string',
            'decision_date' => 'sometimes|nullable|date',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
